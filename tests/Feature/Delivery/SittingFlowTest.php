<?php

namespace Tests\Feature\Delivery;

use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Delivery\Models\Response;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Models\VariantManifest;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Sitting lifecycle, append-only responses, deadline guard, and JIT vault scoring. */
class SittingFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Find the display index that maps to the canonical correct option ('a'). */
    private function correctIndex(VariantManifest $manifest, string $iv, string $canonical = 'a'): int
    {
        return (int) array_search($canonical, $manifest->optionOrderFor($iv), true);
    }

    public function test_assign_builds_a_variant_and_start_sets_a_server_deadline(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(2, duration: 3600);

        $sitting = app(SittingService::class)->assign($assessment, $candidate);
        $this->assertSame('assigned', $sitting->status);
        $this->assertNotNull($sitting->manifest);
        $this->assertCount(2, $sitting->manifest->manifest['items']);

        app(SittingService::class)->start($sitting);
        $this->assertSame('in_progress', $sitting->status);
        $this->assertGreaterThan(Carbon::now()->getTimestamp(), $sitting->server_deadline_epoch);
    }

    public function test_one_sitting_per_candidate_per_assessment(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        app(SittingService::class)->assign($assessment, $candidate);

        $this->expectException(DeliveryError::class);
        app(SittingService::class)->assign($assessment, $candidate);
    }

    public function test_responses_are_append_only_and_latest_sequence_wins(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(1);

        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);

        $iv = $items[0]->current_version_id;
        $manifest = $sitting->manifest;
        $wrongIndex = 1 - $this->correctIndex($manifest, $iv); // the other option
        $correctIndex = $this->correctIndex($manifest, $iv);

        // First answer wrong, then correct it — both rows are kept (append-only).
        $r1 = $recorder->record($sitting, $iv, ['selected' => [$wrongIndex]]);
        $r2 = $recorder->record($sitting, $iv, ['selected' => [$correctIndex]]);
        $this->assertSame(2, $r2->sequence);
        $this->assertSame(2, Response::where('sitting_id', $sitting->id)->count());

        // The latest sequence is what scoring uses.
        $latest = $recorder->latestAnswers($sitting);
        $this->assertSame([$correctIndex], $latest[$iv]['selected']);
    }

    public function test_responses_are_rejected_after_the_deadline(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(1, duration: 3600);

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);

        // Force the server-authoritative deadline into the past.
        $sitting->forceFill(['server_deadline_epoch' => Carbon::now()->getTimestamp() - 10])->save();

        $this->expectException(DeliveryError::class);
        app(ResponseRecorder::class)->record($sitting->fresh(), $items[0]->current_version_id, ['selected' => [0]]);
    }

    public function test_submit_scores_objective_answers_through_the_vault(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2, ['correct' => 1, 'wrong' => 0, 'blank' => 0]);

        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);
        $manifest = $sitting->manifest;

        // Answer item 0 correctly, item 1 wrongly.
        $iv0 = $items[0]->current_version_id;
        $iv1 = $items[1]->current_version_id;
        $recorder->record($sitting, $iv0, ['selected' => [$this->correctIndex($manifest, $iv0)]]);
        $recorder->record($sitting, $iv1, ['selected' => [1 - $this->correctIndex($manifest, $iv1)]]);

        $score = app(ScoringService::class)->submit($sitting->fresh());

        $this->assertSame(1.0, $score->raw_score);   // 1 correct, 1 wrong
        $this->assertEquals(2.0, $score->section_breakdown['objective_max']); // jsonb may return int
        $this->assertSame('final', $score->status);
        $this->assertSame('graded', $sitting->fresh()->status);
        // The score pins the scoring-rule version for reproducibility.
        $this->assertSame(1, $score->scoring_rule_version);
    }

    public function test_negative_marking_applies(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment, 'items' => $items] = $this->publishSimpleAssessment(2, ['correct' => 4, 'wrong' => -1, 'blank' => 0]);

        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);
        $manifest = $sitting->manifest;

        $iv0 = $items[0]->current_version_id;
        $iv1 = $items[1]->current_version_id;
        $recorder->record($sitting, $iv0, ['selected' => [$this->correctIndex($manifest, $iv0)]]); // +4
        $recorder->record($sitting, $iv1, ['selected' => [1 - $this->correctIndex($manifest, $iv1)]]); // -1

        $score = app(ScoringService::class)->submit($sitting->fresh());
        $this->assertSame(3.0, $score->raw_score); // 4 - 1
    }

    public function test_cannot_record_a_question_outside_the_variant(): void
    {
        $inst = $this->makeTenant();
        $candidate = $this->makeUser($inst);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);
        $foreign = $this->makeApprovedItem(0.5); // not pinned into this assessment

        $sittings = app(SittingService::class);
        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);

        $this->expectException(DeliveryError::class);
        app(ResponseRecorder::class)->record($sitting, $foreign->current_version_id, ['selected' => [0]]);
    }
}
