<?php

namespace App\Modules\Reporting\Renderers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Real .xlsx output via PhpSpreadsheet. */
class XlsxRenderer implements ReportRenderer
{
    public function format(): string
    {
        return 'xlsx';
    }

    public function render(array $report): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        // Title row.
        $sheet->setCellValue('A1', $report['title']);
        $lastCol = chr(ord('A') + max(0, count($report['columns']) - 1));
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Header row.
        $headerRow = 3;
        foreach ($report['columns'] as $i => $heading) {
            $cell = chr(ord('A') + $i).$headerRow;
            $sheet->setCellValue($cell, $heading);
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('E8EEF7');
        }

        // Data rows.
        $r = $headerRow + 1;
        foreach ($report['rows'] as $row) {
            foreach (array_values($row) as $i => $value) {
                $sheet->setCellValue(chr(ord('A') + $i).$r, $value ?? '');
            }
            $r++;
        }

        foreach (range(0, count($report['columns']) - 1) as $i) {
            $sheet->getColumnDimension(chr(ord('A') + $i))->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        $spreadsheet->disconnectWorksheets();

        return $bytes;
    }
}
