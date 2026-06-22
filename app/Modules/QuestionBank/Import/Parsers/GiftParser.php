<?php

namespace App\Modules\QuestionBank\Import\Parsers;

use App\Modules\QuestionBank\Import\QuestionFormatParser;
use InvalidArgumentException;
use Throwable;

/**
 * GIFT format (Moodle's native text format):
 *
 *   ::Title:: Who is buried in Grant's tomb? {~no one ~Grant =Ulysses S. Grant ~Napoleon}
 *   2 + 2 = 4 {T}
 *   What gas do plants consume? {=carbon dioxide =CO2}
 *
 * `=` marks correct answers, `~` marks distractors. `{T}`/`{F}` (or TRUE/FALSE) are
 * true/false. An all-`=` body with no `~` is a short-answer (fill-in-the-blank).
 */
class GiftParser implements QuestionFormatParser
{
    public function format(): string
    {
        return 'gift';
    }

    public function parse(string $raw): array
    {
        $questions = [];
        foreach (preg_split('/\R\s*\R/u', trim($raw)) as $block) {
            $block = trim($block);
            if ($block === '' || str_starts_with($block, '//')) {
                continue; // blank or comment
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
        if (! preg_match('/^(?:::(?<title>.*?)::)?\s*(?<stem>.*?)\s*\{(?<ans>.*)\}\s*$/su', $block, $m)) {
            throw new InvalidArgumentException('Malformed GIFT block (expected `stem {answers}`).');
        }

        $stem = trim($m['stem']);
        $ans = trim($m['ans']);
        $meta = $m['title'] !== '' ? ['title' => trim($m['title'])] : [];

        // True/False
        if (preg_match('/^(T|TRUE|F|FALSE)$/iu', $ans)) {
            $isTrue = strtoupper($ans[0]) === 'T';

            return [
                'type' => 'true_false',
                'content' => ['stem' => $stem, 'options' => ['true' => 'True', 'false' => 'False']],
                'answer' => ['correct' => [$isTrue ? 'true' : 'false']],
                'metadata' => $meta,
            ];
        }

        // Tokenize into =correct / ~wrong tokens, preserving order.
        preg_match_all('/([=~])\s*([^=~]*)/u', $ans, $tok, PREG_SET_ORDER);
        if (empty($tok)) {
            throw new InvalidArgumentException("GIFT answer block has no =/~ tokens: '{$stem}'");
        }

        $hasDistractor = false;
        $accept = [];
        $options = [];
        $correct = [];
        $i = 0;
        foreach ($tok as $t) {
            $sign = $t[1];
            $text = trim($t[2]);
            if ($text === '') {
                continue;
            }
            if ($sign === '~') {
                $hasDistractor = true;
            }
            if ($sign === '=') {
                $accept[] = $text;
            }
            $key = chr(ord('a') + $i++);
            $options[$key] = $text;
            if ($sign === '=') {
                $correct[] = $key;
            }
        }

        // All `=` and no `~` => short answer (fill-in-the-blank).
        if (! $hasDistractor) {
            return [
                'type' => 'fill_blank',
                'content' => ['stem' => $stem],
                'answer' => ['accept' => $accept],
                'metadata' => $meta,
            ];
        }

        return [
            'type' => count($correct) > 1 ? 'multiple' : 'single',
            'content' => ['stem' => $stem, 'options' => $options],
            'answer' => ['correct' => $correct],
            'metadata' => $meta,
        ];
    }
}
