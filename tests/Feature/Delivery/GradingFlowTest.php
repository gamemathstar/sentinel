<?php

namespace Tests\Feature\Delivery;

use App\Modules\Authoring\Services\AssessmentService;
use App\Modules\Authoring\Services\ScoringRuleService;
use App\Modules\Delivery\Exceptions\GradingError;
use App\Modules\Delivery\Models\GradingTask;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Services\GradingService;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\QuestionBank\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Manual + AI grading: task creation, double-marking, SoD, reconciliation, score finalize. */
class GradingFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Build a sitting with one objective item (answered correctly) + one essay, submitted. */
    private function essayScenario(): array
    {
        $inst = $this->makeTenant();
        $single = $this->makeApprovedItem(0.5);
        $essay = app(ItemService::class)->createItem(['type' => 'essay', 'content' => ['stem' => 'Discuss the topic.']]);
        $essay->forceFill(['status' => 'active'])->save();
        $essay = $essay->fresh();

        $rule = app(ScoringRuleService::class)->create('R', ['correct' => 1, 'wrong' => 0, 'blank' => 0]);
        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'Mixed', 'kind' => 'final', 'scoring_rule_id' => $rule->id]);
        $section = $svc->addSection($assessment, 'S');
        $svc->pinItemVersions($section, [$single->current_version_id, $essay->current_version_id]);
        $svc->publish($assessment->fresh());

        $candidate = $this->makeUser($inst);
        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $sitting = $sittings->assign($assessment->fresh(), $candidate);
        $sittings->start($sitting);

        $iv = $single->current_version_id;
        $idx = (int) array_search('a', $sitting->manifest->optionOrderFor($iv), true);
        $recorder->record($sitting, $iv, ['selected' => [$idx]]);
        $recorder->record($sitting, $essay->current_version_id, ['text' => 'A thoughtful answer with sufficient content.']);

        $score = app(ScoringService::class)->submit($sitting->fresh());
        $task = GradingTask::where('sitting_id', $sitting->id)->where('item_version_id', $essay->current_version_id)->first();

        return compact('inst', 'sitting', 'task', 'score');
    }

    public function test_submission_with_an_essay_creates_a_pending_task_and_leaves_score_under_review(): void
    {
        ['score' => $score, 'task' => $task] = $this->essayScenario();

        $this->assertSame('under_review', $score->status);
        $this->assertSame(1.0, $score->raw_score); // objective portion only so far
        $this->assertNotNull($task);
        $this->assertSame('essay', $task->type);
        $this->assertSame('pending', $task->status);
    }

    public function test_two_agreeing_marks_reconcile_and_finalize_the_score(): void
    {
        ['inst' => $inst, 'sitting' => $sitting, 'task' => $task] = $this->essayScenario();
        $g1 = $this->makeUser($inst);
        $g2 = $this->makeUser($inst);
        $grading = app(GradingService::class);

        $grading->submitMark($task, $g1->id, 8);
        $this->assertSame('double_marking', $task->fresh()->status);

        $grading->submitMark($task->fresh(), $g2->id, 8); // agree -> reconcile (avg 8)
        $task->refresh();
        $this->assertSame('reconciled', $task->status);
        $this->assertSame(8.0, $task->final_mark);

        // Score folds in the manual mark and becomes final: objective 1 + manual 8 = 9.
        $score = Score::where('sitting_id', $sitting->id)->first();
        $this->assertSame('final', $score->status);
        $this->assertSame(9.0, $score->raw_score);
        $this->assertEquals(8.0, $score->section_breakdown['manual_total']); // jsonb may return int
    }

    public function test_diverging_marks_require_senior_reconciliation(): void
    {
        ['inst' => $inst, 'sitting' => $sitting, 'task' => $task] = $this->essayScenario();
        $g1 = $this->makeUser($inst);
        $g2 = $this->makeUser($inst);
        $senior = $this->makeUser($inst);
        $grading = app(GradingService::class);

        $grading->submitMark($task, $g1->id, 3);
        $grading->submitMark($task->fresh(), $g2->id, 9); // diverge (>1 apart)
        $this->assertSame('double_marking', $task->fresh()->status);
        $this->assertSame('under_review', Score::where('sitting_id', $sitting->id)->value('status'));

        $grading->reconcile($task->fresh(), $senior->id, 6);
        $task->refresh();
        $this->assertSame('reconciled', $task->status);
        $this->assertSame(6.0, $task->final_mark);
        $this->assertSame(7.0, Score::where('sitting_id', $sitting->id)->value('raw_score')); // 1 + 6
    }

    public function test_separation_of_duties(): void
    {
        ['inst' => $inst, 'task' => $task] = $this->essayScenario();
        $g1 = $this->makeUser($inst);
        $grading = app(GradingService::class);
        $grading->submitMark($task, $g1->id, 5);

        // Same grader cannot mark the same task twice.
        $this->expectException(GradingError::class);
        $grading->submitMark($task->fresh(), $g1->id, 6);
    }

    public function test_marker_cannot_reconcile_their_own_task(): void
    {
        ['inst' => $inst, 'task' => $task] = $this->essayScenario();
        $g1 = $this->makeUser($inst);
        $g2 = $this->makeUser($inst);
        $grading = app(GradingService::class);
        $grading->submitMark($task, $g1->id, 2);
        $grading->submitMark($task->fresh(), $g2->id, 9); // diverge

        $this->expectException(GradingError::class);
        $grading->reconcile($task->fresh(), $g1->id, 5); // g1 was a marker
    }

    public function test_ai_suggestion_is_advisory_and_does_not_finalize(): void
    {
        ['task' => $task, 'score' => $score] = $this->essayScenario();
        $grading = app(GradingService::class);

        $mark = $grading->aiSuggest($task, 10.0, ['keywords' => ['thoughtful', 'content']]);

        $this->assertTrue($mark->is_ai);
        $this->assertSame($mark->id, $task->fresh()->ai_suggestion_id);
        // No human marks, task still pending, score still under_review.
        $this->assertSame(0, $task->fresh()->humanMarks()->count());
        $this->assertSame('pending', $task->fresh()->status);
        $this->assertSame('under_review', $score->fresh()->status);
    }
}
