<?php

namespace Tests\Feature\Delivery;

use App\Modules\Authoring\Services\AssessmentService;
use App\Modules\Authoring\Services\ScoringRuleService;
use App\Modules\Delivery\Models\GradingTask;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\QuestionBank\Services\ItemService;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP grading flow: two graders mark, score finalizes; permission enforcement. */
class GradingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    private function scenario(): array
    {
        $inst = Institution::create(['name' => 'Gr U', 'slug' => 'gr-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);

        $essay = app(ItemService::class)->createItem(['type' => 'essay', 'content' => ['stem' => 'Discuss.']]);
        $essay->forceFill(['status' => 'active'])->save();

        $rule = app(ScoringRuleService::class)->create('R', ['correct' => 1]);
        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'E', 'kind' => 'final', 'scoring_rule_id' => $rule->id]);
        $section = $svc->addSection($assessment, 'S');
        $svc->pinItemVersions($section, [$essay->fresh()->current_version_id]);
        $svc->publish($assessment->fresh());

        $candidate = $this->makeUser($inst);
        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment->fresh(), $candidate);
        $sittings->start($sitting);
        app(ResponseRecorder::class)->record($sitting, $essay->fresh()->current_version_id, ['text' => 'My essay answer.']);
        app(ScoringService::class)->submit($sitting->fresh());

        $task = GradingTask::where('sitting_id', $sitting->id)->first();

        return [$inst, $sitting, $task];
    }

    public function test_two_graders_mark_via_api_and_score_finalizes(): void
    {
        [$inst, $sitting, $task] = $this->scenario();
        $g1 = $this->makeUser($inst);
        $this->grantRole($g1, 'grader');
        $g2 = $this->makeUser($inst);
        $this->grantRole($g2, 'grader');

        $this->postJson("/api/delivery/grading/tasks/{$task->id}/marks", ['mark' => 7], $this->authHeaders($g1))
            ->assertCreated()->assertJsonPath('status', 'double_marking');

        $this->postJson("/api/delivery/grading/tasks/{$task->id}/marks", ['mark' => 7], $this->authHeaders($g2))
            ->assertCreated()->assertJsonPath('status', 'reconciled');

        $this->assertSame('final', Score::where('sitting_id', $sitting->id)->value('status'));
    }

    public function test_ai_suggest_endpoint_is_advisory(): void
    {
        [$inst, , $task] = $this->scenario();
        $g1 = $this->makeUser($inst);
        $this->grantRole($g1, 'grader');

        $this->postJson("/api/delivery/grading/tasks/{$task->id}/ai-suggest", ['max_mark' => 10], $this->authHeaders($g1))
            ->assertCreated()
            ->assertJsonPath('advisory', true);

        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_student_cannot_access_the_grading_queue(): void
    {
        [$inst] = $this->scenario();
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->getJson('/api/delivery/grading/tasks', $this->authHeaders($student))->assertStatus(403);
    }

    public function test_a_grader_cannot_reconcile_only_a_senior_can(): void
    {
        [$inst, , $task] = $this->scenario();
        $g1 = $this->makeUser($inst);
        $this->grantRole($g1, 'grader');
        $g2 = $this->makeUser($inst);
        $this->grantRole($g2, 'grader');
        $this->postJson("/api/delivery/grading/tasks/{$task->id}/marks", ['mark' => 2], $this->authHeaders($g1));
        $this->postJson("/api/delivery/grading/tasks/{$task->id}/marks", ['mark' => 9], $this->authHeaders($g2));

        // A grader lacks grading.reconcile.
        $this->postJson("/api/delivery/grading/tasks/{$task->id}/reconcile", ['final_mark' => 5], $this->authHeaders($g1))
            ->assertStatus(403);

        // A senior (exam_officer) can.
        $senior = $this->makeUser($inst);
        $this->grantRole($senior, 'exam_officer');
        $this->postJson("/api/delivery/grading/tasks/{$task->id}/reconcile", ['final_mark' => 5], $this->authHeaders($senior))
            ->assertOk()->assertJsonPath('status', 'reconciled');
    }
}
