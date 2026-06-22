<?php

namespace App\Modules\Delivery\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's concrete, pre-assembled paper (docs/01 §4.5, docs/02 §3): the ordered
 * item versions plus the per-question presentation (shuffled option order) and seeds.
 * The option-order mapping here is what lets the server score a shuffled answer back to
 * the canonical key — per-session answer mapping (docs/04 §8).
 *
 * manifest shape (options array is display-order -> canonical keys):
 *   { "items": [ { "iv": "<item_version_id>", "type": "single", "options": ["c","a","b"] } ] }
 */
class VariantManifest extends Model
{
    use HasUuidv7;

    protected $table = 'variant_manifests';

    protected $fillable = ['sitting_id', 'manifest', 's3_key'];

    protected $casts = ['manifest' => 'array'];

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(Sitting::class);
    }

    /** Map a candidate's displayed option positions back to canonical option keys. */
    public function canonicalKeysFor(string $itemVersionId, array $displayIndices): array
    {
        $order = $this->optionOrderFor($itemVersionId);

        return array_values(array_filter(array_map(
            fn ($i) => $order[$i] ?? null,
            $displayIndices
        ), fn ($k) => $k !== null));
    }

    public function optionOrderFor(string $itemVersionId): array
    {
        foreach ($this->manifest['items'] ?? [] as $entry) {
            if ($entry['iv'] === $itemVersionId) {
                return $entry['options'] ?? [];
            }
        }

        return [];
    }
}
