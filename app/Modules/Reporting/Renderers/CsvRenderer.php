<?php

namespace App\Modules\Reporting\Renderers;

/** CSV output — native PHP, no dependency. */
class CsvRenderer implements ReportRenderer
{
    public function format(): string
    {
        return 'csv';
    }

    public function render(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $report['columns']);
        foreach ($report['rows'] as $row) {
            fputcsv($handle, array_map(fn ($v) => $v ?? '', $row));
        }
        rewind($handle);

        return stream_get_contents($handle);
    }
}
