<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Delivery\Contracts\AiGrader;
use App\Modules\Delivery\Events\ScoreFinalized;
use App\Modules\Delivery\Exceptions\GradingError;
use App\Modules\Delivery\Models\GradingMark;
use App\Modules\Delivery\Models\GradingTask;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\QuestionBank\Models\ItemVersion;
use Illuminate\Support\Facades\DB;

/**
 * Manual + AI grading of open-ended items (docs/01 §4.6, docs/03 §5).
 *
 * Workflow: two INDEPENDENT human marks (separation of duties — different graders); if
 * they agree within tolerance the task auto-reconciles to their average, otherwise a
 * senior reconciles. AI is just another marker (advisory, never finalizing). When every
 * grading task for a sitting is reconciled, the manual marks are folded into the Score
 * and it goes from under_review to final.
 */
class GradingService
{
    private const REQUIRED_HUMAN_MARKS = 2;

    private const AGREEMENT_TOLERANCE = 1.0;

    public function __construct(private readonly AiGrader $ai) {}

    /** Question stem + the candidate's answer text + marks recorded so far. */
    public function detail(GradingTask $task): array
    {
        $version = ItemVersion::whereHas('item')->find($task->item_version_id);
        $answer = $this->latestAnswer($task);

        return [
            'task' => $task->only(['id', 'type', 'status', 'final_mark']),
            'question' => $version?->content['stem'] ?? null,
            'answer' => $answer['text'] ?? ($answer['value'] ?? ''),
            'marks' => $task->marks()->get(['grader_id', 'mark', 'is_ai']),
        ];
    }

    /** Produce an ADVISORY AI mark; stored as a mark flagged is_ai, never finalizes. */
    public function aiSuggest(GradingTask $task, float $maxMark, ?array $rubric = null): GradingMark
    {
        $version = ItemVersion::whereHas('item')->find($task->item_version_id);
        $answer = $this->latestAnswer($task);
        $text = $answer['text'] ?? ($answer['value'] ?? '');

        $suggestion = $this->ai->suggest($version?->content['stem'] ?? '', $text, $maxMark, $rubric);

        $mark = GradingMark::create([
            'grading_task_id' => $task->id,
            'grader_id' => null,
            'mark' => $suggestion['mark'],
            'rubric_breakdown' => ['rationale' => $suggestion['rationale']],
            'is_ai' => true,
        ]);
        $task->forceFill(['ai_suggestion_id' => $mark->id])->save();

        return $mark;
    }

    /** Record one human mark; auto-reconciles when two independent marks agree. */
    public function submitMark(GradingTask $task, string $graderId, float $mark, ?array $rubric = null): GradingTask
    {
        if ($task->status === 'reconciled') {
            throw new GradingError('Task is already reconciled.');
        }
        $humans = $task->humanMarks()->get();
        if ($humans->count() >= self::REQUIRED_HUMAN_MARKS) {
            throw new GradingError('Both human marks are in; a senior must reconcile.');
        }
        if ($humans->contains('grader_id', $graderId)) {
            throw new GradingError('Separation of duties: a grader cannot mark the same task twice.');
        }

        return DB::transaction(function () use ($task, $graderId, $mark, $rubric, $humans) {
            GradingMark::create([
                'grading_task_id' => $task->id,
                'grader_id' => $graderId,
                'mark' => $mark,
                'rubric_breakdown' => $rubric ?? [],
                'is_ai' => false,
            ]);

            // Still waiting on the second independent mark.
            if ($humans->count() + 1 < self::REQUIRED_HUMAN_MARKS) {
                $task->forceFill(['status' => 'double_marking'])->save();

                return $task->fresh();
            }

            // Two marks in: agree -> auto-reconcile to the average; else await a senior.
            $marks = $task->humanMarks()->pluck('mark')->all();
            if (abs($marks[0] - $marks[1]) <= self::AGREEMENT_TOLERANCE) {
                $this->finalize($task, array_sum($marks) / 2);
            } else {
                $task->forceFill(['status' => 'double_marking'])->save();
            }

            return $task->fresh();
        });
    }

    /** Senior reconciliation when the two independent marks diverge. */
    public function reconcile(GradingTask $task, string $reconcilerId, float $finalMark): GradingTask
    {
        if ($task->status === 'reconciled') {
            throw new GradingError('Task is already reconciled.');
        }
        if ($task->humanMarks()->count() < self::REQUIRED_HUMAN_MARKS) {
            throw new GradingError('Reconciliation requires two independent marks first.');
        }
        // SoD: the reconciler must not be one of the two markers.
        if ($task->humanMarks()->where('grader_id', $reconcilerId)->exists()) {
            throw new GradingError('Separation of duties: a marker cannot reconcile their own task.');
        }

        return DB::transaction(function () use ($task, $reconcilerId, $finalMark) {
            GradingMark::create([
                'grading_task_id' => $task->id,
                'grader_id' => $reconcilerId,
                'mark' => $finalMark,
                'rubric_breakdown' => ['reconciliation' => true],
                'is_ai' => false,
            ]);
            $this->finalize($task, $finalMark);

            return $task->fresh();
        });
    }

    private function finalize(GradingTask $task, float $finalMark): void
    {
        $task->forceFill(['final_mark' => $finalMark, 'status' => 'reconciled'])->save();
        $this->applyToScoreIfComplete($task->sitting);
    }

    /** Once every grading task for the sitting is reconciled, fold the marks into the Score. */
    private function applyToScoreIfComplete(Sitting $sitting): void
    {
        $tasks = GradingTask::where('sitting_id', $sitting->id)->get();
        if ($tasks->isEmpty() || $tasks->contains(fn ($t) => $t->status !== 'reconciled')) {
            return;
        }

        $manualTotal = (float) $tasks->sum(fn ($t) => (float) $t->final_mark);
        $score = Score::where('sitting_id', $sitting->id)->first();
        if (! $score) {
            return;
        }

        $objectiveRaw = (float) ($score->section_breakdown['objective_raw'] ?? $score->raw_score);
        $breakdown = $score->section_breakdown;
        $breakdown['manual_total'] = $manualTotal;

        $score->forceFill([
            'raw_score' => $objectiveRaw + $manualTotal,
            'section_breakdown' => $breakdown,
            'status' => 'final',
        ])->save();

        ScoreFinalized::dispatch($sitting->id, $score->id);
    }

    private function latestAnswer(GradingTask $task): array
    {
        $json = DB::table('responses')
            ->where('sitting_id', $task->sitting_id)
            ->where('item_version_id', $task->item_version_id)
            ->orderByDesc('sequence')
            ->value('answer');

        return $json ? (json_decode($json, true) ?? []) : [];
    }
}
