<?php

namespace App\Modules\QuestionBank\Import;

use App\Modules\QuestionBank\Import\Parsers\AikenParser;
use App\Modules\QuestionBank\Import\Parsers\GiftParser;
use App\Modules\QuestionBank\Import\Parsers\LegionFormatParser;
use App\Modules\QuestionBank\Services\ItemService;
use InvalidArgumentException;
use Throwable;

/**
 * Orchestrates bulk import: select a parser by format, parse the blob, validate, drop
 * duplicates, and create items (routing answers to the vault via ItemService). Returns a
 * structured summary so the caller can report created/duplicate/error counts per row
 * (docs spec: "bulk validation before import", "automatic duplicate detection").
 */
class ImportManager
{
    /** @var array<string, QuestionFormatParser> */
    private array $parsers;

    public function __construct(
        private readonly ItemService $items,
        private readonly DuplicateDetector $duplicates,
        ?array $parsers = null,
    ) {
        $registered = $parsers ?? [new LegionFormatParser, new AikenParser, new GiftParser];
        foreach ($registered as $parser) {
            $this->parsers[$parser->format()] = $parser;
        }
    }

    public function supportedFormats(): array
    {
        return array_keys($this->parsers);
    }

    /**
     * @return array{created:int, duplicates:int, errors:int, results:array<int,array>}
     */
    public function import(string $raw, string $format, array $defaults = []): array
    {
        if (! isset($this->parsers[$format])) {
            throw new InvalidArgumentException("Unsupported import format: {$format}");
        }

        $parsed = $this->parsers[$format]->parse($raw);

        $summary = ['created' => 0, 'duplicates' => 0, 'errors' => 0, 'results' => []];

        foreach ($parsed as $index => $def) {
            try {
                // A parser emits ['_error' => …] for a question it could not parse;
                // surface it as a per-row error without aborting the rest of the batch.
                if (isset($def['_error'])) {
                    $summary['errors']++;
                    $summary['results'][] = ['index' => $index, 'status' => 'error', 'message' => $def['_error']];

                    continue;
                }

                if ($this->duplicates->isDuplicate($def['type'], $def['content'])) {
                    $summary['duplicates']++;
                    $summary['results'][] = ['index' => $index, 'status' => 'duplicate', 'stem' => $def['content']['stem'] ?? null];

                    continue;
                }

                $def['metadata'] = array_merge($defaults['metadata'] ?? [], $def['metadata'] ?? []);
                if (! empty($defaults['org_node_ids'])) {
                    $def['org_node_ids'] = $defaults['org_node_ids'];
                }

                $item = $this->items->createItem($def);
                $this->duplicates->remember($def['type'], $def['content']);

                $summary['created']++;
                $summary['results'][] = ['index' => $index, 'status' => 'created', 'item_id' => $item->id];
            } catch (Throwable $e) {
                $summary['errors']++;
                $summary['results'][] = ['index' => $index, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $summary;
    }
}
