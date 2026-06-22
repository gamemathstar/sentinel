<?php

namespace App\Modules\QuestionBank\Import;

/**
 * A parser turns a raw text blob in some exchange format into a list of normalized
 * question definitions ready for ItemService::createItem. Each returned element is:
 *
 *   ['type' => string, 'content' => array, 'answer' => array|null, 'metadata' => array]
 *
 * Crucially, parsers separate correctness into `answer` so it can be routed to the
 * vault — it is never left inside `content` (docs/04 §2).
 */
interface QuestionFormatParser
{
    /** @return array<int, array{type:string, content:array, answer:?array, metadata:array}> */
    public function parse(string $raw): array;

    public function format(): string;
}
