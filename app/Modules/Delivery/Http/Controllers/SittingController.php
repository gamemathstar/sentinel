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
            'questions' => $this->assembler->presentation($sitting->manifest->manifest),
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
