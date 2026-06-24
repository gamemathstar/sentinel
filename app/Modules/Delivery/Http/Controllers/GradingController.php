<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Models\GradingTask;
use App\Modules\Delivery\Services\GradingService;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Grading queue + marking for open-ended items. Tenant scoping is via the task's sitting
 * (whereHas('sitting') applies the Sitting tenant scope).
 */
class GradingController extends Controller
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly PermissionResolver $permissions,
    ) {}

    /** The pending/in-progress grading queue for the tenant. */
    public function index(Request $request): JsonResponse
    {
        $this->ensure(Permissions::GRADING_READ);

        $tasks = GradingTask::whereHas('sitting')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->whereIn('status', ['pending', 'double_marking'])
            ->paginate(25);

        return response()->json($tasks);
    }

    public function show(string $id): JsonResponse
    {
        $this->ensure(Permissions::GRADING_READ);
        $task = GradingTask::whereHas('sitting')->findOrFail($id);

        return response()->json($this->grading->detail($task));
    }

    public function aiSuggest(Request $request, string $id): JsonResponse
    {
        $this->ensure(Permissions::GRADING_READ);
        $data = $request->validate([
            'max_mark' => ['required', 'numeric', 'min:0'],
            'rubric' => ['sometimes', 'array'],
        ]);
        $task = GradingTask::whereHas('sitting')->findOrFail($id);

        $mark = $this->grading->aiSuggest($task, (float) $data['max_mark'], $data['rubric'] ?? null);

        return response()->json(['mark' => $mark->mark, 'rationale' => $mark->rubric_breakdown['rationale'] ?? null, 'advisory' => true], 201);
    }

    public function mark(Request $request, string $id): JsonResponse
    {
        $this->ensure(Permissions::GRADING_MARK);
        $data = $request->validate([
            'mark' => ['required', 'numeric'],
            'rubric' => ['sometimes', 'array'],
        ]);
        $task = GradingTask::whereHas('sitting')->findOrFail($id);

        $task = $this->grading->submitMark($task, Auth::id(), (float) $data['mark'], $data['rubric'] ?? null);

        return response()->json(['status' => $task->status, 'final_mark' => $task->final_mark], 201);
    }

    public function reconcile(Request $request, string $id): JsonResponse
    {
        $this->ensure(Permissions::GRADING_RECONCILE);
        $data = $request->validate(['final_mark' => ['required', 'numeric']]);
        $task = GradingTask::whereHas('sitting')->findOrFail($id);

        $task = $this->grading->reconcile($task, Auth::id(), (float) $data['final_mark']);

        return response()->json(['status' => $task->status, 'final_mark' => $task->final_mark]);
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
