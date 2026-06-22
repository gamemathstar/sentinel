<?php

namespace App\Modules\Authoring\Models;

/**
 * A pure, side-effect-free evaluator for a scoring rule policy (docs spec: positive/
 * negative/partial/weighted scoring). Lives in Authoring because the rule is authored
 * here; the Exam Delivery / Scoring module reuses it at submit time so the score is
 * reproducible from (responses, policy).
 *
 * Policy shape (all optional, sane defaults):
 *   correct: marks for a fully-correct answer      (default 1)
 *   wrong:   marks for a wrong answer (negative ok) (default 0)
 *   blank:   marks for an unanswered question       (default 0)
 *   partial: award fraction*correct for partial?    (default false)
 *   scale:   optional rescale of the raw total to this maximum
 */
class ScoringPolicy
{
    public function __construct(private readonly array $policy) {}

    public function correct(): float
    {
        return (float) ($this->policy['correct'] ?? 1);
    }

    public function wrong(): float
    {
        return (float) ($this->policy['wrong'] ?? 0);
    }

    public function blank(): float
    {
        return (float) ($this->policy['blank'] ?? 0);
    }

    public function allowsPartial(): bool
    {
        return (bool) ($this->policy['partial'] ?? false);
    }

    /**
     * Score a set of answered questions.
     *
     * @param  array<int, array{fraction: float|null, weight?: float}>  $questions
     *                                                                              fraction: 1 correct, 0 wrong, 0<f<1 partial, null = blank/unanswered.
     * @return array{raw: float, max: float, scaled: float}
     */
    public function evaluate(array $questions): array
    {
        $raw = 0.0;
        $max = 0.0;

        foreach ($questions as $q) {
            $weight = (float) ($q['weight'] ?? 1);
            $max += $this->correct() * $weight;
            $raw += $this->scoreOne($q['fraction'] ?? null) * $weight;
        }

        $scaled = $raw;
        if (isset($this->policy['scale']) && $max > 0) {
            $scaled = $raw / $max * (float) $this->policy['scale'];
        }

        return ['raw' => round($raw, 4), 'max' => round($max, 4), 'scaled' => round($scaled, 4)];
    }

    private function scoreOne(?float $fraction): float
    {
        if ($fraction === null) {
            return $this->blank();
        }
        if ($fraction >= 1.0) {
            return $this->correct();
        }
        if ($fraction <= 0.0) {
            return $this->wrong();
        }

        // 0 < fraction < 1
        return $this->allowsPartial() ? $fraction * $this->correct() : $this->wrong();
    }
}
