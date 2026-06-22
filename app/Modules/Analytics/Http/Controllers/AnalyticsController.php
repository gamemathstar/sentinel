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

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
