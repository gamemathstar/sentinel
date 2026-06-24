<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Analytics\Models\AssessmentReliability;
use App\Modules\Analytics\Models\ItemStatistics;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Proctoring\Models\ProctoringSession;
use App\Modules\Reporting\Support\ReportCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds the structured, tabular content for each report type from the FINALIZED read
 * models (docs/01 §4.8) — never the transactional hot path. Output is uniform:
 *   ['title' => string, 'columns' => string[], 'rows' => array<array>, 'meta' => array]
 * so any renderer (CSV/XLSX/PDF) can consume it.
 */
class ReportDataBuilder
{
    public function build(string $type, array $params): array
    {
        return match ($type) {
            ReportCatalog::TYPE_RESULTS => $this->results($params),
            ReportCatalog::TYPE_ITEM_QUALITY => $this->itemQuality($params),
            ReportCatalog::TYPE_ASSESSMENT_SUMMARY => $this->assessmentSummary($params),
            ReportCatalog::TYPE_RISK => $this->risk($params),
            default => throw new InvalidArgumentException("Unknown report type: {$type}"),
        };
    }

    private function results(array $params): array
    {
        $assessment = $this->assessment($params);
        $rows = Sitting::where('assessment_id', $assessment->id)
            ->with(['candidate', 'score'])
            ->get()
            ->map(fn ($s) => [
                $s->candidate?->full_name,
                $s->candidate?->email,
                $s->score?->raw_score,
                $s->score?->scaled_score,
                $s->score?->status ?? $s->status,
            ])->all();

        return [
            'title' => "Results — {$assessment->title}",
            'columns' => ['Candidate', 'Email', 'Raw Score', 'Scaled Score', 'Status'],
            'rows' => $rows,
            'meta' => ['assessment_id' => $assessment->id, 'candidates' => count($rows)],
        ];
    }

    private function itemQuality(array $params): array
    {
        $assessment = $this->assessment($params);
        $itemIds = $this->assessmentItemIds($assessment->id);

        $rows = ItemStatistics::whereIn('item_id', $itemIds)
            ->with('item.currentVersion')
            ->get()
            ->map(fn ($s) => [
                Str::limit($s->item?->currentVersion?->content['stem'] ?? '—', 60),
                $s->item?->type,
                $s->facility_index,
                $s->discrimination_index,
                $s->sample_n,
            ])->all();

        return [
            'title' => "Item Quality — {$assessment->title}",
            'columns' => ['Item', 'Type', 'Facility (p)', 'Discrimination', 'Sample N'],
            'rows' => $rows,
            'meta' => ['assessment_id' => $assessment->id, 'items' => count($rows)],
        ];
    }

    private function assessmentSummary(array $params): array
    {
        $assessment = $this->assessment($params);
        $reliability = AssessmentReliability::where('assessment_id', $assessment->id)->first();
        $scores = Sitting::where('assessment_id', $assessment->id)->with('score')->get()
            ->map(fn ($s) => (float) ($s->score?->raw_score ?? 0));
        $meanRaw = $scores->isNotEmpty() ? round($scores->avg(), 4) : null;

        $rows = [
            ['Title', $assessment->title],
            ['Kind', $assessment->kind],
            ['Status', $assessment->status],
            ['Candidates', $scores->count()],
            ['Mean raw score', $meanRaw],
            ['KR-20', $reliability?->kr20],
            ['Cronbach alpha', $reliability?->cronbach_alpha],
            ['SEM', $reliability?->sem],
        ];

        return [
            'title' => "Assessment Summary — {$assessment->title}",
            'columns' => ['Metric', 'Value'],
            'rows' => $rows,
            'meta' => ['assessment_id' => $assessment->id],
        ];
    }

    private function risk(array $params): array
    {
        $assessment = $this->assessment($params);
        $sittingIds = Sitting::where('assessment_id', $assessment->id)->pluck('id');

        $rows = ProctoringSession::whereIn('sitting_id', $sittingIds)
            ->with(['riskAssessment', 'sitting.candidate'])
            ->get()
            ->filter(fn ($sess) => $sess->riskAssessment !== null)
            ->map(function ($sess) {
                $risk = $sess->riskAssessment;
                $top = $risk->timeline[0]['type'] ?? '—';

                return [
                    $sess->sitting?->candidate?->full_name,
                    $risk->cheating_probability,
                    $risk->suspicion_score,
                    $risk->status,
                    $top,
                ];
            })->values()->all();

        return [
            'title' => "Proctoring Risk — {$assessment->title}",
            'columns' => ['Candidate', 'Cheating Probability', 'Suspicion', 'Review Status', 'Top Signal'],
            'rows' => $rows,
            'meta' => ['assessment_id' => $assessment->id, 'flagged' => count($rows)],
        ];
    }

    private function assessment(array $params): Assessment
    {
        if (empty($params['assessment_id'])) {
            throw new InvalidArgumentException('assessment_id is required for this report.');
        }

        return Assessment::findOrFail($params['assessment_id']);
    }

    /** @return string[] distinct item ids pinned into the assessment */
    private function assessmentItemIds(string $assessmentId): array
    {
        return DB::table('section_item')
            ->join('assessment_sections', 'assessment_sections.id', '=', 'section_item.section_id')
            ->join('item_versions', 'item_versions.id', '=', 'section_item.item_version_id')
            ->where('assessment_sections.assessment_id', $assessmentId)
            ->distinct()
            ->pluck('item_versions.item_id')
            ->all();
    }
}
