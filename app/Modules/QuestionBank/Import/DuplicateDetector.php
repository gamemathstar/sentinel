<?php

namespace App\Modules\QuestionBank\Import;

use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\QuestionBank\Services\ItemService;

/**
 * Detects duplicate questions within the current tenant via the version content hash
 * (docs/03 §2). Checks both items already in the bank and items seen earlier in the
 * same import batch (so a file containing the same question twice imports it once).
 */
class DuplicateDetector
{
    /** @var array<string,bool> hashes seen so far in this batch */
    private array $batch = [];

    public function __construct(private readonly ItemService $items) {}

    public function hashFor(string $type, array $content): string
    {
        return $this->items->contentHash($type, $content);
    }

    public function isDuplicate(string $type, array $content): bool
    {
        $hash = $this->hashFor($type, $content);

        if (isset($this->batch[$hash])) {
            return true;
        }

        // whereHas('item') applies the Item global tenant scope, so dedup is per-tenant.
        return ItemVersion::whereHas('item')->where('content_hash', $hash)->exists();
    }

    public function remember(string $type, array $content): void
    {
        $this->batch[$this->hashFor($type, $content)] = true;
    }
}
