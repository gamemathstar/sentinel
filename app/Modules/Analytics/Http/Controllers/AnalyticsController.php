<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Models\AssessmentReliability;
use App\Modules\Analytics\Models\ItemStatistics;
use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\QuestionBank\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly PermissionResolver $permissions,
    ) {}

    /** Recompute psychometrics for an assessment from its graded sittings. */
    public function compile(string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::ANALYTICS_COMPUTE);

        $assessment = Assessment::findOrFail($assessmentId);
        $reliability = $this->analytics->compileAssessment($assessment);

        return response()->json($reliability, 201);
    }

    public function reliability(string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::ANALYTICS_READ);

        $assessment = Assessment::findOrFail($assessmentId); // tenant-scoped existence check
        $reliability = AssessmentReliability::where('assessment_id', $assessment->id)->firstOrFail();

        return response()->json($reliability);
    }

    public function itemStatistics(string $itemId): JsonResponse
    {
        $this->ensure(Permissions::ANALYTICS_READ);

        $item = Item::findOrFail($itemId); // tenant-scoped
        $stats = ItemStatistics::where('item_id', $item->id)->firstOrFail();

        return response()->json($stats);
    }

    /**
     * Per-item psychometrics for every item pinned into an assessment — the item-analysis
     * table behind the reliability headline (facility, discrimination, distractor health).
     * Items not yet compiled return null metrics so the UI can show "awaiting data".
     */
    public function items(string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::ANALYTICS_READ);

        $assessment = Assessment::findOrFail($assessmentId); // tenant-scoped existence check

        $rows = DB::table('section_item')
            ->join('assessment_sections', 'assessment_sections.id', '=', 'section_item.section_id')
            ->join('item_versions', 'item_versions.id', '=', 'section_item.item_version_id')
            ->join('items', 'items.id', '=', 'item_versions.item_id')
            ->leftJoin('item_statistics', 'item_statistics.item_id', '=', 'items.id')
            ->where('assessment_sections.assessment_id', $assessment->id)
            ->orderBy('section_item.position')
            ->get([
                'items.id as item_id',
                'items.type',
                'items.bloom_level',
                'item_versions.content',
                'item_statistics.sample_n',
                'item_statistics.facility_index',
                'item_statistics.discrimination_index',
                'item_statistics.distractor_analysis',
            ])
            ->map(function ($r) {
                $content = json_decode($r->content, true) ?? [];

                return [
                    'item_id' => $r->item_id,
                    'type' => $r->type,
                    'bloom_level' => $r->bloom_level,
                    'stem' => Str::limit(strip_tags($content['stem'] ?? ''), 120),
                    'sample_n' => $r->sample_n !== null ? (int) $r->sample_n : null,
                    'facility_index' => $r->facility_index !== null ? (float) $r->facility_index : null,
                    'discrimination_index' => $r->discrimination_index !== null ? (float) $r->discrimination_index : null,
                    'distractor_analysis' => $r->distractor_analysis ? json_decode($r->distractor_analysis, true) : null,
                ];
            });

        return response()->json(['data' => $rows->values()]);
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
