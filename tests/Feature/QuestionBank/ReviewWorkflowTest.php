<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Exceptions\WorkflowViolation;
use App\Modules\QuestionBank\Services\ItemService;
use App\Modules\QuestionBank\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The moderation workflow and its structural separation-of-duties (docs/04 §5). */
class ReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_approval_pipeline_activates_the_item(): void
    {
        $inst = $this->makeTenant();
        $author = $this->makeUser($inst);
        $reviewer = $this->makeUser($inst);
        $moderator = $this->makeUser($inst);
        $approver = $this->makeUser($inst);

        $this->actingForTenant($inst, $author);
        $item = app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'Q?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ]);
        $version = $item->currentVersion;
        $reviews = app(ReviewService::class);

        $reviews->submitReview($version, $reviewer->id, 'approve');
        $this->assertSame('reviewed', $version->fresh()->state);

        $reviews->submitReview($version->fresh(), $moderator->id, 'approve');
        $this->assertSame('moderated', $version->fresh()->state);

        $reviews->submitReview($version->fresh(), $approver->id, 'approve');
        $this->assertSame('approved', $version->fresh()->state);
        $this->assertSame('active', $item->fresh()->status);
    }

    public function test_author_cannot_approve_their_own_item(): void
    {
        $inst = $this->makeTenant();
        $author = $this->makeUser($inst);
        $this->actingForTenant($inst, $author);

        $item = app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'Q?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ]);

        $this->expectException(WorkflowViolation::class);
        app(ReviewService::class)->submitReview($item->currentVersion, $author->id, 'approve');
    }

    public function test_same_subject_cannot_perform_consecutive_stages(): void
    {
        $inst = $this->makeTenant();
        $author = $this->makeUser($inst);
        $reviewer = $this->makeUser($inst);
        $this->actingForTenant($inst, $author);

        $item = app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'Q?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ]);
        $version = $item->currentVersion;
        $reviews = app(ReviewService::class);

        $reviews->submitReview($version, $reviewer->id, 'approve'); // draft -> reviewed

        $this->expectException(WorkflowViolation::class);
        $reviews->submitReview($version->fresh(), $reviewer->id, 'approve'); // same person again
    }

    public function test_reject_sends_version_back_to_draft(): void
    {
        $inst = $this->makeTenant();
        $author = $this->makeUser($inst);
        $reviewer = $this->makeUser($inst);
        $this->actingForTenant($inst, $author);

        $item = app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'Q?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ]);
        $version = $item->currentVersion;
        $reviews = app(ReviewService::class);

        $reviews->submitReview($version, $reviewer->id, 'approve'); // -> reviewed
        $reviews->submitReview($version->fresh(), $this->makeUser($inst)->id, 'revise'); // back to draft

        $this->assertSame('draft', $version->fresh()->state);
    }
}
