<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Scheduling\Exceptions\SchedulingError;
use App\Modules\Scheduling\Models\CandidateSchedule;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Services\AutoMapper;
use App\Modules\Scheduling\Services\SchedulingService;
use App\Modules\Scheduling\Services\SelectionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Scheduling surface: preview a selection, lay out sessions (manual or auto), assign
 * candidates + invigilators, and release schedules into live sittings.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly AutoMapper $autoMapper,
        private readonly SelectionResolver $selection,
        private readonly PermissionResolver $permissions,
    ) {}

    /** Counts grouped by programme + level for a selection — shown before mapping. */
    public function previewSelection(Request $request): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_READ);

        return response()->json($this->selection->summary($this->validateSelection($request)));
    }

    /** The resolved student list (grouped order) for a selection. */
    public function students(Request $request): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_READ);

        $rows = $this->selection->resolve($this->validateSelection($request))->map(fn ($e) => [
            'candidate_id' => $e->user_id,
            'name' => $e->student?->full_name,
            'email' => $e->student?->email,
            'programme' => $e->programme?->name,
            'level' => $e->level,
        ]);

        return response()->json(['data' => $rows->values()]);
    }

    /** All sessions of an assessment with seating + invigilator counts. */
    public function sessions(string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_READ);
        $assessment = Assessment::findOrFail($assessmentId);

        $sessions = ExamSession::where('assessment_id', $assessment->id)
            ->with(['venue:id,name,code,location', 'invigilators:id,full_name'])
            ->withCount('schedules')
            ->orderBy('starts_at')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'venue' => $s->venue?->name,
                'starts_at' => $s->starts_at,
                'ends_at' => $s->ends_at,
                'capacity' => $s->capacity,
                'seated' => $s->schedules_count,
                'status' => $s->status,
                'invigilators' => $s->invigilators->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->full_name, 'role' => $i->pivot->role,
                ]),
            ]);

        return response()->json([
            'assessment' => $assessment->only(['id', 'title', 'status']),
            'sessions' => $sessions,
        ]);
    }

    public function createSession(Request $request, string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_MANAGE);
        $assessment = Assessment::findOrFail($assessmentId);

        $data = $request->validate([
            'venue_id' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->guard(fn () => response()->json($this->scheduling->createSession($assessment, $data), 201));
    }

    /** Auto-map a selection across venues × start-times into sessions + per-student records. */
    public function autoMap(Request $request, string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_MANAGE);
        $assessment = Assessment::findOrFail($assessmentId);

        $request->validate([
            'selection' => ['required', 'array'],
            'venues' => ['required', 'array', 'min:1'],
            'venues.*.venue_id' => ['required', 'uuid'],
            'venues.*.capacity' => ['nullable', 'integer', 'min:1'],
            'start_times' => ['required', 'array', 'min:1'],
            'start_times.*' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
        ]);

        return $this->guard(fn () => response()->json($this->autoMapper->map($assessment, $request->all()), 201));
    }

    public function assignCandidates(Request $request, string $sessionId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_MANAGE);
        $session = ExamSession::findOrFail($sessionId);

        $data = $request->validate([
            'candidate_ids' => ['required', 'array', 'min:1'],
            'candidate_ids.*' => ['uuid'],
        ]);

        return $this->guard(function () use ($session, $data) {
            $n = $this->scheduling->assignCandidates($session, $data['candidate_ids']);

            return response()->json(['scheduled' => $n], 201);
        });
    }

    public function assignInvigilators(Request $request, string $sessionId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_MANAGE);
        $session = ExamSession::findOrFail($sessionId);

        $data = $request->validate([
            'invigilators' => ['required', 'array', 'min:1'],
            'invigilators.*.user_id' => ['required', 'uuid'],
            'invigilators.*.role' => ['nullable', 'in:chief,assistant'],
        ]);

        $this->scheduling->assignInvigilators($session, $data['invigilators']);

        return response()->json(['ok' => true]);
    }

    /** All candidate schedules for an assessment, grouped by session. */
    public function roster(string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_READ);
        $assessment = Assessment::findOrFail($assessmentId);

        $rows = CandidateSchedule::where('assessment_id', $assessment->id)
            ->with(['candidate:id,full_name,email', 'session:id,name,starts_at'])
            ->orderBy('exam_session_id')->orderBy('seat_no')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'candidate' => $c->candidate?->full_name,
                'email' => $c->candidate?->email,
                'session' => $c->session?->name,
                'session_id' => $c->exam_session_id,
                'seat_no' => $c->seat_no,
                'source' => $c->source,
                'status' => $c->status,
            ]);

        return response()->json(['data' => $rows]);
    }

    /** Release scheduled candidates into live Delivery sittings. */
    public function release(string $assessmentId): JsonResponse
    {
        $this->ensure(Permissions::SCHEDULE_MANAGE);
        $assessment = Assessment::findOrFail($assessmentId);

        return $this->guard(fn () => response()->json(['released' => $this->scheduling->release($assessment)]));
    }

    private function validateSelection(Request $request): array
    {
        $request->validate([
            'scope' => ['required', 'in:all,nodes'],
            'org_node_ids' => ['array'],
            'org_node_ids.*' => ['uuid'],
            'levels' => ['array'],
            'levels.*' => ['string'],
        ]);

        return $request->only(['scope', 'org_node_ids', 'levels']);
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (SchedulingError $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function ensure(string $permission): void
    {
        abort_unless($this->permissions->can(Auth::user(), $permission), 403);
    }
}
