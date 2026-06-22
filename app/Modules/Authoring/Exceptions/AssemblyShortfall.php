<?php

namespace App\Modules\Authoring\Exceptions;

use RuntimeException;

/**
 * Raised when the bank does not contain enough approved items to satisfy a blueprint.
 * Carries the per-band (or per-bucket) shortfall so the exam officer knows exactly what
 * to author or import before assembly can succeed.
 */
class AssemblyShortfall extends RuntimeException
{
    /** @param array<string, array{needed:int, available:int}> $shortfall */
    public function __construct(public readonly array $shortfall)
    {
        $parts = [];
        foreach ($shortfall as $bucket => $counts) {
            $parts[] = "{$bucket}: need {$counts['needed']}, have {$counts['available']}";
        }
        parent::__construct('Blueprint cannot be satisfied — '.implode('; ', $parts));
    }
}
