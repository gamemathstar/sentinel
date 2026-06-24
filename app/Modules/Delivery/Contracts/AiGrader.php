<?php

namespace App\Modules\Delivery\Contracts;

/**
 * Anti-corruption interface to an AI grading model (docs/02 §4: AI is reached through an
 * interface so the provider can be swapped without leaking into domain logic). Its output
 * is ALWAYS advisory — a suggested mark a human reconciles, never an authoritative score
 * (the AI-is-a-suggestion invariant, docs/01 §13).
 */
interface AiGrader
{
    /**
     * Suggest a mark for an open-ended answer.
     *
     * @return array{mark: float, rationale: string}
     */
    public function suggest(string $questionStem, string $answerText, float $maxMark, ?array $rubric = null): array;
}
