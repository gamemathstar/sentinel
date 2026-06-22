<?php

namespace App\Modules\QuestionBank\Services;

use App\Modules\QuestionBank\Exceptions\WorkflowViolation;
use App\Modules\QuestionBank\Models\ItemReview;
use App\Modules\QuestionBank\Models\ItemVersion;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The item moderation workflow with separation of duties (docs/01 §4.3, docs/04 §5).
 *
 * Approval pipeline: draft -> reviewed -> moderated -> approved. A reject/revise at any
 * stage returns the version to draft for rework. Separation of duties is STRUCTURAL:
 *   - the author may never advance their own version;
 *   - no single subject may perform two consecutive approval stages (true 4-eyes).
 * These are enforced here as invariants, not as UI hints.
 */
class ReviewService
{
    private const DECISIONS = ['approve', 'reject', 'revise'];

    /** state on approval -> next state */
    private const NEXT_STATE = [
        'draft' => 'reviewed',
        'reviewed' => 'moderated',
        'moderated' => 'approved',
    ];

    public function submitReview(ItemVersion $version, string $reviewerId, string $decision, ?string $comment = null): ItemReview
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException("Invalid review decision: {$decision}");
        }

        return DB::transaction(function () use ($version, $reviewerId, $decision, $comment) {
            // For an approval, enforce separation of duties BEFORE recording anything.
            if ($decision === 'approve') {
                $this->assertMayAdvance($version, $reviewerId);
            }

            $review = ItemReview::create([
                'item_version_id' => $version->id,
                'reviewer_id' => $reviewerId,
                'decision' => $decision,
                'comment' => $comment,
            ]);

            if ($decision === 'approve') {
                $version->state = self::NEXT_STATE[$version->state];
                $version->save();
                // Approving the final stage activates the item for use in assessments.
                if ($version->state === 'approved') {
                    $version->item()->update(['status' => 'active']);
                }
            } else {
                // reject/revise -> back to draft for rework
                $version->state = 'draft';
                $version->save();
            }

            return $review;
        });
    }

    private function assertMayAdvance(ItemVersion $version, string $reviewerId): void
    {
        if (! array_key_exists($version->state, self::NEXT_STATE)) {
            throw new WorkflowViolation("Version in state '{$version->state}' cannot be advanced.");
        }

        // SoD #1: author may never advance their own version.
        if ($version->author_id !== null && $version->author_id === $reviewerId) {
            throw new WorkflowViolation('Separation of duties: the author cannot review or approve their own item.');
        }

        // SoD #2: no subject performs two consecutive approval stages (true 4-eyes).
        $previousApprover = ItemReview::where('item_version_id', $version->id)
            ->where('decision', 'approve')
            ->orderByDesc('created_at')
            ->value('reviewer_id');
        if ($previousApprover !== null && $previousApprover === $reviewerId) {
            throw new WorkflowViolation('Separation of duties: the same subject cannot perform consecutive approval stages.');
        }
    }
}
