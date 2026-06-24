<?php

namespace App\Modules\Proctoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Proctoring\Models\ProctoringSession;
use App\Modules\Proctoring\Models\RiskAssessment;
use App\Modules\Proctoring\Services\ProctoringService;
use App\Modules\Proctoring\Support\FlagCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProctoringController extends Controller
{
    public function __construct(
        private readonly ProctoringService $proctoring,
        private readonly PermissionResolver $permissions,
    ) {}

    public function openSession(string $sittingId): JsonResponse
    {
        $this->ensure(Permissions::PROCTOR_MONITOR);
        $sitting = Sitting::findOrFail($sittingId);

        return response()->json($this->proctoring->openSession($sitting), 201);
    }

    public function recordFlag(Request $request, string $sessionId): JsonResponse
    {
        $this->ensure(Permissions::PROCTOR_MONITOR);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(FlagCatalog::types())],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'source' => ['sometimes', Rule::in(['client', 'edge', 'server_inference'])],
        ]);
        $session = ProctoringSession::findOrFail($sessionId);

        $flag = $this->proctoring->recordFlag($session, $data['type'], (float) $data['confidence'], $data['source'] ?? 'client');

        return response()->json(['id' => $flag->id, 'type' => $flag->type], 201);
    }

    public function assess(string $sessionId): JsonResponse
    {
        $this->ensure(Permissions::PROCTOR_MONITOR);
        $session = ProctoringSession::findOrFail($sessionId);

        return response()->json($this->proctoring->assess($session), 201);
    }

    public function show(string $sessionId): JsonResponse
    {
        $this->ensure(Permissions::PROCTOR_MONITOR);
        $session = ProctoringSession::with(['flags', 'riskAssessment'])->findOrFail($sessionId);

        return response()->json($session);
    }

    /** QA queue: assessed sessions whose risk crosses the review threshold, awaiting a human. */
    public function reviewQueue(Request $request): JsonResponse
    {
        $this->ensure(Permissions::PROCTOR_REVIEW);
        $threshold = (float) $request->query('threshold', 0.6);

        $queue = RiskAssessment::whereHas('session')
            ->with('session.sitting.candidate:id,full_name')
            ->where('status', 'auto')
            ->where('cheating_probability', '>=', $threshold)
            ->orderByDesc('cheating_probability')
            ->paginate(25);

        return response()->json($queue);
    }

    public function review(Request $request, string $riskId): JsonResponse
    {
        $this->ensure(Permissions::PROCTOR_REVIEW);
        $data = $request->validate(['decision' => ['required', Rule::in(['cleared', 'upheld'])]]);
        $risk = RiskAssessment::whereHas('session')->findOrFail($riskId);

        $this->proctoring->review($risk, $data['decision']);

        return response()->json(['status' => $risk->status]);
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
