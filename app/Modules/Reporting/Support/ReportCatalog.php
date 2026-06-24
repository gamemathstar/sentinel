<?php

namespace App\Modules\Reporting\Support;

/** The report types and output formats the engine supports (docs deliverables). */
final class ReportCatalog
{
    public const TYPE_RESULTS = 'results';

    public const TYPE_ITEM_QUALITY = 'item_quality';

    public const TYPE_ASSESSMENT_SUMMARY = 'assessment_summary';

    public const TYPE_RISK = 'risk';

    public const TYPES = [
        self::TYPE_RESULTS, self::TYPE_ITEM_QUALITY, self::TYPE_ASSESSMENT_SUMMARY, self::TYPE_RISK,
    ];

    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function isFormat(string $format): bool
    {
        return in_array($format, self::FORMATS, true);
    }

    public static function extension(string $format): string
    {
        return $format; // csv/xlsx/pdf map 1:1
    }

    public static function mime(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
