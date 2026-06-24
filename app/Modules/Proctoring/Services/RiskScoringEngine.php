<?php

namespace App\Modules\Proctoring\Services;

use App\Modules\Proctoring\Support\FlagCatalog;

/**
 * Turns flags into an EXPLAINABLE risk score (docs/05 §7). Pure: flags + weights in,
 * score + timeline out — so the calibration is testable and the result fully
 * reconstructable.
 *
 * Model: combine evidence with noisy-OR so signals accumulate but saturate in [0,1].
 *  - Per type, repeated weak detections combine: conf = 1 − Π(1 − confidence_i); the
 *    type's contribution is weight · conf (a lone noisy flag stays small).
 *  - Across types, corroboration emerges naturally: probability = 1 − Π(1 − contribution),
 *    so independent strong signals reinforce each other.
 * Calibration against reviewed outcomes is future work (docs/05 §7).
 */
class RiskScoringEngine
{
    /**
     * @param  array<int, array{type:string, confidence:float, id?:string}>  $flags
     * @param  array<string,float>  $weightOverrides
     * @return array{cheating_probability:float, suspicion_score:float, timeline:array, requires_review:bool}
     */
    public function assess(array $flags, array $weightOverrides = [], float $reviewThreshold = 0.6): array
    {
        // Group flags by type.
        $byType = [];
        foreach ($flags as $flag) {
            $byType[$flag['type']][] = $flag;
        }

        $timeline = [];
        foreach ($byType as $type => $typeFlags) {
            $weight = FlagCatalog::weight($type, $weightOverrides);

            $product = 1.0;
            $ids = [];
            $maxConf = 0.0;
            foreach ($typeFlags as $f) {
                $c = max(0.0, min(1.0, (float) ($f['confidence'] ?? 0)));
                $product *= (1 - $c);
                $maxConf = max($maxConf, $c);
                if (isset($f['id'])) {
                    $ids[] = $f['id'];
                }
            }
            $combinedConfidence = 1 - $product;
            $contribution = $weight * $combinedConfidence;

            $timeline[] = [
                'type' => $type,
                'weight' => round($weight, 4),
                'combined_confidence' => round($combinedConfidence, 4),
                'max_confidence' => round($maxConf, 4),
                'occurrences' => count($typeFlags),
                'contribution' => round($contribution, 4),
                'flag_ids' => $ids,
            ];
        }

        // Sort the timeline by contribution, descending — the "why" a reviewer reads first.
        usort($timeline, fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        $probProduct = 1.0;
        $suspicion = 0.0;
        foreach ($timeline as $entry) {
            $probProduct *= (1 - $entry['contribution']);
            $suspicion += $entry['contribution'];
        }
        $probability = 1 - $probProduct;

        return [
            'cheating_probability' => round($probability, 4),
            'suspicion_score' => round($suspicion, 4),
            'timeline' => $timeline,
            'requires_review' => $probability >= $reviewThreshold,
        ];
    }
}
