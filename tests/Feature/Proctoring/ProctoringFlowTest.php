<?php

namespace Tests\Feature\Proctoring;

use App\Modules\Authoring\Models\ProctoringPolicy;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Proctoring\Exceptions\ProctoringError;
use App\Modules\Proctoring\Models\ProctoringSession;
use App\Modules\Proctoring\Services\ProctoringService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Session lifecycle, flag ingest, explainable risk, review, and the never-auto-void rule. */
class ProctoringFlowTest extends TestCase
{
    use RefreshDatabase;

    private function proctoringPolicy(string $mode = 'ai_only', bool $lockdown = true, array $signals = []): ProctoringPolicy
    {
        return ProctoringPolicy::create([
            'institution_id' => app(TenantContext::class)->institutionId(),
            'name' => 'Policy '.$mode,
            'mode' => $mode,
            'lockdown_required' => $lockdown,
            'signals' => $signals,
        ]);
    }

    public function test_session_auto_opens_on_start_for_a_proctored_assessment(): void
    {
        $inst = $this->makeTenant();
        $policy = $this->proctoringPolicy('ai_only', true);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, proctoringPolicyId: $policy->id);

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $this->makeUser($inst));
        $sittings->start($sitting); // SittingStarted -> listener opens the session

        $session = ProctoringSession::where('sitting_id', $sitting->id)->first();
        $this->assertNotNull($session);
        $this->assertSame('ai_only', $session->mode);
        $this->assertTrue($session->lockdown_active);
    }

    public function test_no_session_for_an_unproctored_assessment(): void
    {
        $inst = $this->makeTenant();
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1); // no proctoring policy

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $this->makeUser($inst));
        $sittings->start($sitting);

        $this->assertSame(0, ProctoringSession::where('sitting_id', $sitting->id)->count());
    }

    public function test_flags_produce_an_explainable_risk_assessment_without_voiding_the_sitting(): void
    {
        $inst = $this->makeTenant();
        $policy = $this->proctoringPolicy('live', true);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, proctoringPolicyId: $policy->id);

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $this->makeUser($inst));
        $sittings->start($sitting);

        $proctoring = app(ProctoringService::class);
        $session = ProctoringSession::where('sitting_id', $sitting->id)->firstOrFail();

        $proctoring->recordFlag($session, 'phone_detected', 0.95, 'server_inference');
        $f = $proctoring->recordFlag($session, 'face_absent', 0.9);

        $risk = $proctoring->assess($session);

        $this->assertGreaterThan(0.6, $risk->cheating_probability);
        $this->assertSame('auto', $risk->status);
        // Explainable: the timeline references the contributing flags.
        $this->assertNotEmpty($risk->timeline);
        $allIds = collect($risk->timeline)->pluck('flag_ids')->flatten()->all();
        $this->assertContains($f->id, $allIds);

        // The sitting is NOT auto-voided by a high risk (docs/05 §1).
        $this->assertSame('in_progress', $sitting->fresh()->status);
    }

    public function test_review_records_a_human_decision(): void
    {
        $inst = $this->makeTenant();
        $policy = $this->proctoringPolicy();
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, proctoringPolicyId: $policy->id);
        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $this->makeUser($inst));
        $sittings->start($sitting);

        $proctoring = app(ProctoringService::class);
        $session = ProctoringSession::where('sitting_id', $sitting->id)->firstOrFail();
        $proctoring->recordFlag($session, 'vm_detected', 0.9);
        $risk = $proctoring->assess($session);

        $proctoring->review($risk, 'upheld');
        $this->assertSame('upheld', $risk->fresh()->status);
    }

    public function test_unknown_flag_type_is_rejected(): void
    {
        $inst = $this->makeTenant();
        $policy = $this->proctoringPolicy();
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, proctoringPolicyId: $policy->id);
        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $this->makeUser($inst));
        $sittings->start($sitting);
        $session = ProctoringSession::where('sitting_id', $sitting->id)->firstOrFail();

        $this->expectException(ProctoringError::class);
        app(ProctoringService::class)->recordFlag($session, 'telepathy_detected', 0.9);
    }
}
