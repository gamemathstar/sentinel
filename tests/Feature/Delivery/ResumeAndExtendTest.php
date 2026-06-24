<?php

namespace Tests\Feature\Delivery;

use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Session restore after failure, and granting extra time (docs/02 §7). */
class ResumeAndExtendTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_preserves_saved_answers_and_remaining_time(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(1, duration: 3600);

        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);

        // Candidate answers, then "loses power".
        $iv = $items[0]->current_version_id;
        $idx = (int) array_search('a', $sitting->manifest->optionOrderFor($iv), true);
        $recorder->record($sitting, $iv, ['selected' => [$idx]]);
        $deadlineBefore = $sitting->server_deadline_epoch;

        // Reconnect → resume.
        $fresh = Sitting::find($sitting->id);
        $resumed = $sittings->resume($fresh);

        // Answer survived (append-only) and the deadline is unchanged (time preserved).
        $this->assertArrayHasKey($iv, $recorder->latestAnswers($resumed));
        $this->assertSame($deadlineBefore, $resumed->server_deadline_epoch);
        $this->assertSame(1, $resumed->sync_meta['resumed_count']);
        $this->assertGreaterThan(0, $resumed->remainingSeconds());
        // Can still record after resuming.
        $recorder->record($resumed, $iv, ['selected' => [$idx]]);
        $this->assertSame('in_progress', $resumed->fresh()->status);
    }

    public function test_grant_extra_time_extends_the_deadline(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, duration: 1800);

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);
        $before = $sitting->server_deadline_epoch;

        $sittings->grantExtraTime($sitting, 600, 'power outage', $this->makeUser($inst)->id);

        $this->assertSame($before + 600, $sitting->server_deadline_epoch);
        $this->assertSame(600, $sitting->sync_meta['extensions'][0]['seconds']);
        $this->assertSame('power outage', $sitting->sync_meta['extensions'][0]['reason']);
    }

    public function test_extra_time_reopens_a_lapsed_deadline_from_now(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1, duration: 1800);

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);
        // Deadline already lapsed (e.g. a long outage).
        $sitting->forceFill(['server_deadline_epoch' => time() - 100])->save();

        $sittings->grantExtraTime($sitting->fresh(), 300);

        // Reopened from "now", so the candidate gets the full granted window.
        $this->assertGreaterThan(280, $sitting->fresh()->remainingSeconds());
    }

    public function test_cannot_extend_a_submitted_sitting(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(1, duration: 1800);

        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);
        $iv = $items[0]->current_version_id;
        $recorder->record($sitting, $iv, ['selected' => [0]]);
        app(ScoringService::class)->submit($sitting->fresh());

        $this->expectException(DeliveryError::class);
        $sittings->grantExtraTime($sitting->fresh(), 300);
    }
}
