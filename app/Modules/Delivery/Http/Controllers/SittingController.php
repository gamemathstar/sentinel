<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Delivery\Services\VariantAssembler;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Exam delivery REST surface. The candidate-facing endpoints never return answer keys —
 * only the question presentation in the candidate's display order (docs/04 §2, §8).
 */
class SittingController extends Controller
{
    public function __construct(
        private readonly SittingService $sittings,
        private readonly ResponseRecorder $responses,
        private readonly ScoringService $scoring,
        private readonly VariantAssembler $assembler,
    ) {}

    /** Staff assigns a candidate to a published assessment. */
    public function assign(Request $request, string $assessmentId): JsonResponse
    {
        $this->authorize('assign', Sitting::class);

        $data = $request->validate(['candidate_id' => ['required', 'uuid']]);
        $assessment = Assessment::findOrFail($assessmentId);
        $candidate = User::where('id', $data['candidate_id'])->firstOrFail();

        $sitting = $this->sittings->assign($assessment, $candidate);

        return response()->json($sitting, 201);
    }

    /** Candidate starts their attempt (sets the server-authoritative deadline). */
    public function start(string $id): JsonResponse
    {
        $sitting = Sitting::findOrFail($id);
        $this->authorize('take', $sitting);

        $this->sittings->start($sitting);

        return response()->json([
            'status' => $sitting->status,
            'server_deadline_epoch' => $sitting->server_deadline_epoch,
        ]);
    }

    /** Candidate fetches their paper — questions in display order, no answers. */
    public function show(string $id): JsonResponse
    {
        $sitting = Sitting::with('manifest')->findOrFail($id);
        $this->authorize('take', $sitting);

        return response()->json([
            'sitting' => $sitting->only(['id', 'status', 'server_deadline_epoch']),
            'remaining_seconds' => $sitting->remainingSeconds(),
            'questions' => $this->assembler->presentation($sitting->manifest->manifest),
        ]);
    }

    /**
     * Resume a sitting after a disconnect / power failure. Returns the paper, the
     * candidate's saved answers (append-only, so nothing is lost), and the remaining
     * server-authoritative time — so the student picks up exactly where they left off.
     */
    public function resume(string $id): JsonResponse
    {
        $sitting = Sitting::with('manifest')->findOrFail($id);
        $this->authorize('take', $sitting);

        $this->sittings->resume($sitting);

        return response()->json([
            'sitting' => $sitting->only(['id', 'status', 'server_deadline_epoch']),
            'remaining_seconds' => $sitting->remainingSeconds(),
            'answers' => $this->responses->latestAnswers($sitting),
            'questions' => $this->assembler->presentation($sitting->manifest->manifest),
        ]);
    }

    /**
     * Live invigilation view for an assessment: every sitting with its progress
     * (answered/total), remaining time, and proctoring risk — for monitoring who is
     * writing and flagging malpractice in real time.
     */
    public function monitor(string $assessmentId): JsonResponse
    {
        $this->authorize('assign', Sitting::class);
        $assessment = Assessment::findOrFail($assessmentId);

        $sittings = Sitting::where('assessment_id', $assessment->id)
            ->with(['candidate:id,full_name', 'manifest'])
            ->get();
        $ids = $sittings->pluck('id');

        // Answered = distinct items with at least one response.
        $answered = DB::table('responses')->whereIn('sitting_id', $ids)
            ->select('sitting_id', DB::raw('count(distinct item_version_id) as n'))
            ->groupBy('sitting_id')->pluck('n', 'sitting_id');

        // Proctoring risk per sitting (raw join to avoid coupling to the Proctoring module).
        $risk = DB::table('proctoring_sessions as ps')
            ->join('risk_assessments as ra', 'ra.proctoring_session_id', '=', 'ps.id')
            ->whereIn('ps.sitting_id', $ids)
            ->select('ps.sitting_id', 'ps.id as session_id', 'ra.id as risk_id', 'ra.cheating_probability', 'ra.status')
            ->get()->keyBy('sitting_id');

        $rows = $sittings->map(function ($s) use ($answered, $risk) {
            $r = $risk->get($s->id);

            return [
                'id' => $s->id,
                'candidate' => $s->candidate?->full_name,
                'status' => $s->status,
                'server_deadline_epoch' => $s->server_deadline_epoch,
                'remaining_seconds' => $s->remainingSeconds(),
                'answered' => (int) ($answered[$s->id] ?? 0),
                'total' => count($s->manifest?->manifest['items'] ?? []),
                'risk' => $r ? [
                    'id' => $r->risk_id, 'session_id' => $r->session_id,
                    'cheating_probability' => (float) $r->cheating_probability, 'status' => $r->status,
                ] : null,
            ];
        });

        return response()->json([
            'assessment' => $assessment->only(['id', 'title', 'status', 'kind']),
            'sittings' => $rows->values(),
        ]);
    }

    /** Per-candidate invigilation detail: progress + proctoring flags + explainable risk. */
    public function detail(string $id): JsonResponse
    {
        $this->authorize('assign', Sitting::class);
        $s = Sitting::with(['candidate:id,full_name', 'manifest'])->findOrFail($id);

        $session = DB::table('proctoring_sessions')->where('sitting_id', $s->id)->first();
        $flags = [];
        $risk = null;
        if ($session) {
            $flags = DB::table('proctoring_flags')->where('proctoring_session_id', $session->id)
                ->orderByDesc('occurred_at')->get(['type', 'confidence', 'occurred_at', 'source']);
            $r = DB::table('risk_assessments')->where('proctoring_session_id', $session->id)->first();
            if ($r) {
                $risk = [
                    'id' => $r->id, 'cheating_probability' => (float) $r->cheating_probability,
                    'suspicion_score' => (float) $r->suspicion_score, 'status' => $r->status,
                    'timeline' => json_decode($r->timeline, true),
                ];
            }
        }

        return response()->json([
            'id' => $s->id,
            'candidate' => $s->candidate?->full_name,
            'status' => $s->status,
            'answered' => (int) DB::table('responses')->where('sitting_id', $s->id)->distinct()->count('item_version_id'),
            'total' => count($s->manifest?->manifest['items'] ?? []),
            'remaining_seconds' => $s->remainingSeconds(),
            'session' => $session ? ['mode' => $session->mode, 'lockdown_active' => (bool) $session->lockdown_active] : null,
            'flags' => $flags,
            'risk' => $risk,
        ]);
    }

    /** Staff grants extra time (accommodation or outage compensation). Extends the deadline. */
    public function extend(Request $request, string $id): JsonResponse
    {
        $this->authorize('assign', Sitting::class);
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string'],
        ]);
        $sitting = Sitting::findOrFail($id);

        $this->sittings->grantExtraTime($sitting, $data['minutes'] * 60, $data['reason'] ?? null, Auth::id());

        return response()->json([
            'server_deadline_epoch' => $sitting->server_deadline_epoch,
            'remaining_seconds' => $sitting->remainingSeconds(),
        ]);
    }

    /** Candidate records (or corrects) an answer — append-only. */
    public function respond(Request $request, string $id): JsonResponse
    {
        $sitting = Sitting::findOrFail($id);
        $this->authorize('take', $sitting);

        $data = $request->validate([
            'item_version_id' => ['required', 'uuid'],
            'answer' => ['required', 'array'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'time_spent_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $response = $this->responses->record(
            $sitting, $data['item_version_id'], $data['answer'],
            $data['confidence'] ?? null, $data['time_spent_ms'] ?? null,
        );

        return response()->json(['sequence' => $response->sequence], 201);
    }

    /** Candidate submits; scoring runs and a score is produced. */
    public function submit(string $id): JsonResponse
    {
        $sitting = Sitting::findOrFail($id);
        $this->authorize('take', $sitting);

        $score = $this->scoring->submit($sitting);

        return response()->json([
            'status' => $sitting->fresh()->status,
            'score' => $score->only(['raw_score', 'scaled_score', 'status']),
        ]);
    }

    public function score(string $id): JsonResponse
    {
        $sitting = Sitting::with('score')->findOrFail($id);
        $this->authorize('viewScore', $sitting);

        return response()->json($sitting->score);
    }
}
