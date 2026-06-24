<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Exceptions\ReportingError;
use App\Modules\Reporting\Models\Report;
use App\Modules\Reporting\Renderers\CsvRenderer;
use App\Modules\Reporting\Renderers\PdfRenderer;
use App\Modules\Reporting\Renderers\ReportRenderer;
use App\Modules\Reporting\Renderers\XlsxRenderer;
use App\Modules\Reporting\Support\ReportCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates report artifacts: builds the data from read models, renders it to the
 * requested format, stores the file, and records a Report row. Reads only — it never
 * touches the transactional path (docs/02 §5). Synchronous here; at scale this is the
 * body of a queued job triggered off events.
 */
class ReportingService
{
    private const DISK = 'local';

    public function __construct(
        private readonly ReportDataBuilder $builder,
        private readonly TenantContext $tenant,
    ) {}

    public function generate(string $type, string $format, array $params = [], ?string $requestedBy = null): Report
    {
        if (! ReportCatalog::isType($type)) {
            throw new ReportingError("Unknown report type: {$type}");
        }
        if (! ReportCatalog::isFormat($format)) {
            throw new ReportingError("Unknown report format: {$format}");
        }

        $report = Report::create([
            'requested_by' => $requestedBy ?? $this->tenant->userId(),
            'type' => $type,
            'format' => $format,
            'status' => 'pending',
            'params' => $params,
        ]);

        try {
            $data = $this->builder->build($type, $params);
            $bytes = $this->rendererFor($format)->render($data);

            $path = sprintf('reports/%s/%s.%s', $this->tenant->institutionId() ?? 'platform', $report->id, ReportCatalog::extension($format));
            Storage::disk(self::DISK)->put($path, $bytes);

            $report->forceFill([
                'status' => 'completed',
                'title' => $data['title'],
                'disk' => self::DISK,
                'path' => $path,
                'rows' => count($data['rows']),
                'completed_at' => Carbon::now(),
            ])->save();
        } catch (Throwable $e) {
            $report->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            throw $e;
        }

        return $report;
    }

    public function downloadName(Report $report): string
    {
        return Str::slug($report->title ?? $report->type).'.'.ReportCatalog::extension($report->format);
    }

    private function rendererFor(string $format): ReportRenderer
    {
        return match ($format) {
            'csv' => new CsvRenderer,
            'xlsx' => new XlsxRenderer,
            'pdf' => new PdfRenderer,
            default => throw new ReportingError("Unknown report format: {$format}"),
        };
    }
}
