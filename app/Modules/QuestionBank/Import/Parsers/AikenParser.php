<?php

namespace App\Modules\QuestionBank\Import\Parsers;

use App\Modules\QuestionBank\Import\QuestionFormatParser;
use InvalidArgumentException;
use Throwable;

/**
 * Aiken format (widely used, Moodle-importable):
 *
 *   What is the capital of France?
 *   A. London
 *   B. Paris
 *   C. Berlin
 *   ANSWER: B
 *
 * The stem is the first non-option line; options are `LETTER. text` or `LETTER) text`;
 * `ANSWER:` gives the correct letter(s). Blank lines separate questions.
 */
class AikenParser implements QuestionFormatParser
{
    public function format(): string
    {
        return 'aiken';
    }

    public function parse(string $raw): array
    {
        $questions = [];
        $blocks = preg_split('/\R\s*\R/u', trim($raw));

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            try {
                $questions[] = $this->parseBlock($block);
            } catch (Throwable $e) {
                $questions[] = ['_error' => $e->getMessage()];
            }
        }

        return $questions;
    }

    private function parseBlock(string $block): array
    {
        $stem = '';
        $options = [];
        $answerLetters = [];

        foreach (preg_split('/\R/u', $block) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^ANSWER\s*:\s*(.+)$/iu', $line, $m)) {
                foreach (preg_split('/[\s,]+/', trim($m[1])) as $letter) {
                    if ($letter !== '') {
                        $answerLetters[] = strtolower($letter);
                    }
                }

                continue;
            }

            if (preg_match('/^([A-Za-z])[.)]\s+(.*)$/u', $line, $m)) {
                $options[strtolower($m[1])] = trim($m[2]);

                continue;
            }

            // Anything before the first option is (more of) the stem.
            if (empty($options)) {
                $stem = $stem === '' ? $line : $stem.' '.$line;
            }
        }

        if ($stem === '' || empty($options) || empty($answerLetters)) {
            throw new InvalidArgumentException('Malformed Aiken block (need stem, options, and ANSWER).');
        }
        foreach ($answerLetters as $l) {
            if (! isset($options[$l])) {
                throw new InvalidArgumentException("ANSWER references unknown option '{$l}'.");
            }
        }

        return [
            'type' => count($answerLetters) > 1 ? 'multiple' : 'single',
            'content' => ['stem' => $stem, 'options' => $options],
            'answer' => ['correct' => $answerLetters],
            'metadata' => [],
        ];
    }
}
