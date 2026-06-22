<?php

namespace App\Modules\Authoring\Services;

use App\Modules\Authoring\Models\Blueprint;
use App\Modules\Authoring\Support\DifficultyBand;
use InvalidArgumentException;

/**
 * Creates blueprints and validates that their constraints are internally coherent
 * (docs/01 §4.4). Whether the bank actually *contains* enough items to satisfy a
 * blueprint is checked at assembly time by PaperAssembler — this is the static check.
 */
class BlueprintService
{
    public function create(string $name, array $constraints): Blueprint
    {
        $this->validate($constraints);

        return Blueprint::create(['name' => $name, 'constraints' => $constraints]);
    }

    /** @throws InvalidArgumentException if the constraints are incoherent */
    public function validate(array $constraints): void
    {
        $total = $constraints['total'] ?? null;
        if (! is_int($total) || $total < 1) {
            throw new InvalidArgumentException('Blueprint must specify a positive integer "total".');
        }

        if (isset($constraints['difficulty'])) {
            $this->validateDistribution($constraints['difficulty'], DifficultyBand::ALL, 'difficulty');
        }
        if (isset($constraints['topics'])) {
            $this->validateDistribution($constraints['topics'], array_keys($constraints['topics']), 'topics');
        }
    }

    private function validateDistribution(array $dist, array $allowedKeys, string $label): void
    {
        foreach ($dist as $key => $fraction) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException("Unknown {$label} key: {$key}");
            }
            if (! is_numeric($fraction) || $fraction < 0 || $fraction > 1) {
                throw new InvalidArgumentException("{$label} fraction for {$key} must be between 0 and 1.");
            }
        }

        $sum = array_sum($dist);
        if (abs($sum - 1.0) > 0.001) {
            throw new InvalidArgumentException("{$label} fractions must sum to 1.0 (got {$sum}).");
        }
    }
}
