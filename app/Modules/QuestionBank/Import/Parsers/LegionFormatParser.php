<?php

namespace App\Modules\QuestionBank\Import\Parsers;

use App\Modules\QuestionBank\Import\QuestionFormatParser;
use InvalidArgumentException;
use Throwable;

/**
 * The platform's native import format (spec):
 *
 *   ?? Question text {Difficulty}
 *   ** Option A
 *   ** Option B ==
 *   ** Option C
 *   ** Option D
 *
 * `??` opens a question (the trailing {tag} is optional difficulty metadata).
 * `**` is an option; a trailing `==` marks it correct. Blank lines separate questions.
 * One correct option => single; multiple `==` => multiple-correct.
 *
 * Parsing is per-question and resilient: a malformed question yields an `['_error' => …]`
 * entry rather than aborting the whole batch (spec: "bulk validation before import").
 */
class LegionFormatParser implements QuestionFormatParser
{
    public function format(): string
    {
        return 'legion';
    }

    public function parse(string $raw): array
    {
        $results = [];
        foreach ($this->segment($raw) as $blockLines) {
            try {
                $results[] = $this->parseBlock($blockLines);
            } catch (Throwable $e) {
                $results[] = ['_error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** Group lines into per-question blocks, starting each block at a `??` line. */
    private function segment(string $raw): array
    {
        $blocks = [];
        $current = null;

        foreach (preg_split('/\R/u', $raw) as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '??')) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = [$trimmed];
            } elseif ($current !== null) {
                $current[] = $trimmed;
            }
        }
        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    private function parseBlock(array $lines): array
    {
        $stem = '';
        $options = [];
        $correct = [];
        $difficulty = null;
        $sawOption = false;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '??')) {
                [$stem, $difficulty] = $this->splitStem(substr($line, 2));

                continue;
            }

            if (str_starts_with($line, '**')) {
                $sawOption = true;
                $this->addOption($options, $correct, substr($line, 2));

                continue;
            }

            // Continuation of the stem before any option line.
            if (! $sawOption) {
                $stem = trim($stem.' '.$line);
            }
        }

        if ($stem === '' || empty($options)) {
            throw new InvalidArgumentException("Malformed question: '{$stem}'");
        }
        if (empty($correct)) {
            throw new InvalidArgumentException("Question has no correct option marked (`==`): '{$stem}'");
        }

        return [
            'type' => count($correct) > 1 ? 'multiple' : 'single',
            'content' => ['stem' => $stem, 'options' => $options],
            'answer' => ['correct' => $correct],
            'metadata' => array_filter(['difficulty_label' => $difficulty]),
        ];
    }

    private function splitStem(string $text): array
    {
        $difficulty = null;
        if (preg_match('/\{([^}]*)\}\s*$/u', $text, $m)) {
            $difficulty = trim($m[1]);
            $text = preg_replace('/\{[^}]*\}\s*$/u', '', $text);
        }

        return [trim($text), $difficulty];
    }

    private function addOption(array &$options, array &$correct, string $text): void
    {
        $text = trim($text);
        $isCorrect = false;
        if (preg_match('/\s*==\s*$/u', $text)) {
            $isCorrect = true;
            $text = trim(preg_replace('/\s*==\s*$/u', '', $text));
        }

        $key = chr(ord('a') + count($options));
        $options[$key] = $text;
        if ($isCorrect) {
            $correct[] = $key;
        }
    }
}
