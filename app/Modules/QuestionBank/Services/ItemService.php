<?php

namespace App\Modules\QuestionBank\Services;

use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Application service for the Item aggregate (docs/01 §4.3).
 *
 * The defining behavior: an incoming question definition is split into
 *   - `content` (stem + option texts, NO correctness) -> item_versions.content
 *   - `answer`  (the scoring truth)                    -> AnswerKeyVault (vault schema)
 * so the question bank table alone never reveals which answer is correct (docs/04 §2).
 */
class ItemService
{
    public function __construct(
        private readonly AnswerKeyVault $vault,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Create a new Item with its first version.
     *
     * @param  array{type:string, content:array, answer?:array|null, metadata?:array, org_node_ids?:array, stimulus_id?:?string}  $def
     */
    public function createItem(array $def): Item
    {
        $this->assertValidType($def['type']);
        $content = $this->sanitizeContent($def['content'] ?? []);
        $meta = $def['metadata'] ?? [];

        return DB::transaction(function () use ($def, $content, $meta) {
            $item = Item::create([
                'type' => $def['type'],
                'status' => 'draft',
                'question_bank_id' => $def['question_bank_id'] ?? null,
                'course_org_node_id' => $def['course_org_node_id'] ?? null,
                'specialization_org_node_id' => $def['specialization_org_node_id'] ?? null,
                'tags' => $def['tags'] ?? [],
                'difficulty' => $meta['difficulty'] ?? null,
                'bloom_level' => $meta['bloom_level'] ?? null,
                'expected_seconds' => $meta['expected_seconds'] ?? null,
                'default_weight' => $meta['weight'] ?? 1,
            ]);

            $version = $this->makeVersion($item, 1, $content, $def);

            $item->current_version_id = $version->id;
            $item->save();

            if (! empty($def['org_node_ids'])) {
                $item->orgNodes()->sync($def['org_node_ids']);
            }

            return $item->load('currentVersion');
        });
    }

    /**
     * Add a new immutable version to an existing item (editing = new version, docs/01 §4.3).
     *
     * @param  array{content:array, answer?:array|null, stimulus_id?:?string}  $def
     */
    public function addVersion(Item $item, array $def): ItemVersion
    {
        $content = $this->sanitizeContent($def['content'] ?? []);

        return DB::transaction(function () use ($item, $content, $def) {
            $next = ((int) $item->versions()->max('version_no')) + 1;
            $version = $this->makeVersion($item, $next, $content, $def);

            $item->current_version_id = $version->id;
            $item->save();

            return $version;
        });
    }

    private function makeVersion(Item $item, int $versionNo, array $content, array $def): ItemVersion
    {
        $version = new ItemVersion([
            'item_id' => $item->id,
            'version_no' => $versionNo,
            'stimulus_id' => $def['stimulus_id'] ?? null,
            'content' => $content,
            'author_id' => $this->tenant->userId(),
            'state' => 'draft',
            'content_hash' => $this->contentHash($item->type, $content),
        ]);
        $version->save();

        // Correctness goes to the vault, NEVER into the version row.
        if (! empty($def['answer'])) {
            $this->vault->store($version->id, $def['answer']);
        }

        return $version;
    }

    /** Stable hash of the question body for duplicate detection (docs/03 §2). */
    public function contentHash(string $type, array $content): string
    {
        return hash('sha256', $type.'|'.$this->canonical($content));
    }

    /** Defense in depth: reject any correctness-bearing keys that slipped into content. */
    private function sanitizeContent(array $content): array
    {
        foreach (['correct', 'answer', 'answers', 'is_correct', 'correct_options'] as $forbidden) {
            if (array_key_exists($forbidden, $content)) {
                throw new InvalidArgumentException(
                    "Content must not contain correctness key '{$forbidden}'; pass it as the separate 'answer' payload."
                );
            }
        }

        return $content;
    }

    private function assertValidType(string $type): void
    {
        if (! in_array($type, Item::TYPES, true)) {
            throw new InvalidArgumentException("Unknown item type: {$type}");
        }
    }

    private function canonical(array $data): string
    {
        $normalize = function (&$value) use (&$normalize) {
            if (is_array($value)) {
                ksort($value);
                foreach ($value as &$v) {
                    $normalize($v);
                }
            }
        };
        $normalize($data);

        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
