<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Delivery\Contracts\AiGrader;

/**
 * A deterministic PLACEHOLDER implementation of AiGrader (docs/04 §11: AI capabilities
 * are designed via a contract; real models land later). It produces a defensible-looking
 * advisory mark from coverage of rubric keywords (or answer length when no rubric is
 * given) so the workflow can be built and tested today. Swap the binding in
 * AppServiceProvider for a real LLM-backed grader without touching the GradingService.
 */
class HeuristicAiGrader implements AiGrader
{
    public function suggest(string $questionStem, string $answerText, float $maxMark, ?array $rubric = null): array
    {
        $answer = trim($answerText);
        if ($answer === '') {
            return ['mark' => 0.0, 'rationale' => 'Empty answer.'];
        }

        $keywords = $rubric['keywords'] ?? [];
        if ($keywords !== []) {
            $hit = 0;
            foreach ($keywords as $kw) {
                if (stripos($answer, (string) $kw) !== false) {
                    $hit++;
                }
            }
            $fraction = $hit / count($keywords);

            return [
                'mark' => round($fraction * $maxMark, 2),
                'rationale' => "Matched {$hit}/".count($keywords).' rubric keywords (placeholder heuristic).',
            ];
        }

        // No rubric: scale by answer length up to a saturation point. Clearly a stand-in.
        $words = str_word_count($answer);
        $fraction = min(1.0, $words / 50);

        return [
            'mark' => round($fraction * $maxMark, 2),
            'rationale' => "Length-based estimate from {$words} words (placeholder heuristic).",
        ];
    }
}
