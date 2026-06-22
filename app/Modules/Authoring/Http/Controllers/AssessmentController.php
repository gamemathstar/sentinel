<?php

namespace App\Modules\Authoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Authoring\Models\AssessmentSection;
use App\Modules\Authoring\Models\Blueprint;
use App\Modules\Authoring\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Assessment::class);

        return response()->json(Assessment::with('sections')->paginate(25));
    }

    public function show(string $id): JsonResponse
    {
        $assessment = Assessment::with('sections.itemVersions')->findOrFail($id);
        $this->authorize('view', $assessment);

        return response()->json($assessment);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Assessment::class);

        $data = $request->validate([
            'title' => ['required', 'string'],
            'kind' => ['required', Rule::in(Assessment::KINDS)],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'org_node_id' => ['nullable', 'uuid'],
            'scoring_rule_id' => ['nullable', 'uuid'],
            'blueprint_id' => ['nullable', 'uuid'],
            'proctoring_policy_id' => ['nullable', 'uuid'],
            'window_opens_at' => ['nullable', 'date'],
            'window_closes_at' => ['nullable', 'date'],
        ]);

        return response()->json($this->assessments->create($data), 201);
    }

    public function addSection(Request $request, string $id): JsonResponse
    {
        $assessment = Assessment::findOrFail($id);
        $this->authorize('update', $assessment);

        $data = $request->validate(['title' => ['required', 'string']]);
        $section = $this->assessments->addSection($assessment, $data['title']);

        return response()->json($section, 201);
    }

    public function assembleSection(Request $request, string $id, string $sectionId): JsonResponse
    {
        $assessment = Assessment::findOrFail($id);
        $this->authorize('update', $assessment);

        $data = $request->validate(['blueprint_id' => ['required', 'uuid']]);
        $section = AssessmentSection::where('assessment_id', $assessment->id)->findOrFail($sectionId);
        $blueprint = Blueprint::findOrFail($data['blueprint_id']);

        $versionIds = $this->assessments->assembleSectionFromBlueprint($section, $blueprint);

        return response()->json(['assembled' => count($versionIds), 'item_version_ids' => $versionIds], 201);
    }

    public function publish(string $id): JsonResponse
    {
        $assessment = Assessment::findOrFail($id);
        $this->authorize('publish', $assessment);

        $this->assessments->publish($assessment);

        return response()->json(['status' => $assessment->status]);
    }
}
