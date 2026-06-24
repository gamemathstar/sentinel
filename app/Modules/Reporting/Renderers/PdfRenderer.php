<?php

namespace App\Modules\Reporting\Renderers;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Real PDF output via dompdf, rendered from a simple HTML table. */
class PdfRenderer implements ReportRenderer
{
    public function format(): string
    {
        return 'pdf';
    }

    public function render(array $report): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false); // no external fetches from report HTML
        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml($this->html($report));
        $dompdf->render();

        return $dompdf->output();
    }

    private function html(array $report): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);

        $head = '';
        foreach ($report['columns'] as $c) {
            $head .= '<th>'.$e($c).'</th>';
        }

        $body = '';
        foreach ($report['rows'] as $row) {
            $body .= '<tr>';
            foreach (array_values($row) as $cell) {
                $body .= '<td>'.$e($cell).'</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $colspan = max(1, count($report['columns']));
            $body = '<tr><td colspan="'.$colspan.'" class="empty">No data.</td></tr>';
        }

        return <<<HTML
        <html><head><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
            h1 { font-size: 16px; margin: 0 0 4px; }
            .gen { color: #777; font-size: 9px; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #c9c9c9; padding: 5px 7px; text-align: left; }
            th { background: #E8EEF7; }
            td.empty { text-align: center; color: #999; }
        </style></head><body>
            <h1>{$e($report['title'])}</h1>
            <div class="gen">Legion CBT — generated report</div>
            <table><thead><tr>{$head}</tr></thead><tbody>{$body}</tbody></table>
        </body></html>
        HTML;
    }
}
