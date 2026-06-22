<?php

namespace App\Modules\Analytics\Services;

/**
 * Pure, side-effect-free classical test theory calculations (docs/01 §4.8). Arrays in,
 * numbers out — so the formulas are unit-testable against hand-computed values. The
 * AnalyticsService gathers the data from finalized scores and delegates the math here.
 *
 * Conventions: population variance (÷n). Item scores are dichotomous 0/1 (correct).
 */
class PsychometricsCalculator
{
    /** Facility index (p): proportion answering correctly. @param float[] $itemScores */
    public function facilityIndex(array $itemScores): float
    {
        return $this->mean($itemScores);
    }

    /**
     * Discrimination index as the point-biserial correlation between an item's 0/1 score
     * and candidates' total scores. Returns 0 when either series has no variance.
     *
     * @param  float[]  $itemScores
     * @param  float[]  $totalScores
     */
    public function pointBiserial(array $itemScores, array $totalScores): float
    {
        return $this->pearson($itemScores, $totalScores);
    }

    /**
     * KR-20 reliability for dichotomous items.
     *   KR20 = k/(k-1) · (1 − Σ p_i q_i / σ²_total)
     *
     * @param  float[]  $pValues  facility index per item
     */
    public function kr20(array $pValues, float $totalVariance, int $k): float
    {
        if ($k < 2 || $totalVariance <= 0.0) {
            return 0.0;
        }
        $sumPq = 0.0;
        foreach ($pValues as $p) {
            $sumPq += $p * (1 - $p);
        }

        return ($k / ($k - 1)) * (1 - $sumPq / $totalVariance);
    }

    /**
     * Cronbach's alpha — the general form. For dichotomous items (item variance = p·q)
     * it equals KR-20.
     *
     * @param  float[]  $itemVariances
     */
    public function cronbachAlpha(array $itemVariances, float $totalVariance, int $k): float
    {
        if ($k < 2 || $totalVariance <= 0.0) {
            return 0.0;
        }

        return ($k / ($k - 1)) * (1 - array_sum($itemVariances) / $totalVariance);
    }

    /** Standard error of measurement: σ_total · √(1 − reliability). */
    public function sem(float $totalSd, float $reliability): float
    {
        $r = max(0.0, min(1.0, $reliability));

        return $totalSd * sqrt(1 - $r);
    }

    public function variance(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mean = $this->mean($values);
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return $sum / $n;
    }

    /**
     * Distractor analysis: per option, how many candidates chose it, the proportion, and
     * whether it is the keyed (correct) option (docs spec).
     *
     * @param  array<string,int>  $countsByOption  canonical option key => chosen count
     * @param  string[]  $correctKeys
     * @return array<string, array{count:int, proportion:float, correct:bool}>
     */
    public function distractorAnalysis(array $countsByOption, array $correctKeys, int $responders): array
    {
        $out = [];
        foreach ($countsByOption as $key => $count) {
            $out[$key] = [
                'count' => $count,
                'proportion' => $responders > 0 ? round($count / $responders, 4) : 0.0,
                'correct' => in_array($key, $correctKeys, true),
            ];
        }

        return $out;
    }

    private function mean(array $values): float
    {
        $n = count($values);

        return $n === 0 ? 0.0 : array_sum($values) / $n;
    }

    /** Pearson correlation; 0 if either series is constant. */
    private function pearson(array $x, array $y): float
    {
        $n = count($x);
        if ($n === 0 || $n !== count($y)) {
            return 0.0;
        }
        $mx = $this->mean($x);
        $my = $this->mean($y);
        $cov = $sx = $sy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $mx;
            $dy = $y[$i] - $my;
            $cov += $dx * $dy;
            $sx += $dx ** 2;
            $sy += $dy ** 2;
        }
        if ($sx <= 0.0 || $sy <= 0.0) {
            return 0.0;
        }

        return $cov / sqrt($sx * $sy);
    }
}
