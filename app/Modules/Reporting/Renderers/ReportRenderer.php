<?php

namespace App\Modules\Reporting\Renderers;

/**
 * Renders the uniform report data (title/columns/rows) into a concrete file format.
 * Implementations return the raw file bytes; the service persists them.
 */
interface ReportRenderer
{
    /** @param array{title:string, columns:array, rows:array, meta:array} $report */
    public function render(array $report): string;

    public function format(): string;
}
