<?php

namespace App\Modules\Authoring\Services;

use App\Modules\Authoring\Models\ScoringRule;
use App\Support\Tenancy\TenantContext;

/**
 * Creates and versions scoring rules (docs/03 §3). A rule is immutable once it may have
 * produced scores; "editing" creates a new version under the same name, so every score
 * can pin the exact version that produced it.
 */
class ScoringRuleService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function create(string $name, array $policy): ScoringRule
    {
        return ScoringRule::create([
            'name' => $name,
            'version' => 1,
            'policy' => $this->normalize($policy),
        ]);
    }

    /** Create the next version of an existing named rule. */
    public function newVersion(string $name, array $policy): ScoringRule
    {
        $latest = ScoringRule::where('name', $name)->max('version') ?? 0;

        return ScoringRule::create([
            'name' => $name,
            'version' => $latest + 1,
            'policy' => $this->normalize($policy),
        ]);
    }

    private function normalize(array $policy): array
    {
        return [
            'correct' => (float) ($policy['correct'] ?? 1),
            'wrong' => (float) ($policy['wrong'] ?? 0),
            'blank' => (float) ($policy['blank'] ?? 0),
            'partial' => (bool) ($policy['partial'] ?? false),
        ] + (isset($policy['scale']) ? ['scale' => (float) $policy['scale']] : []);
    }
}
