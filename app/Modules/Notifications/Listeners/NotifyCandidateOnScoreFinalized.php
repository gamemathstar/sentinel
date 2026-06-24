<?php

namespace App\Modules\Notifications\Listeners;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Events\ScoreFinalized;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Notifications\Services\NotificationService;

/**
 * Notifies a candidate that their result is ready (docs/01 §5). Like certification, this
 * acts only on truly-final scores (ScoreFinalized also fires for under_review scores) and
 * is idempotent per sitting.
 */
class NotifyCandidateOnScoreFinalized
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(ScoreFinalized $event): void
    {
        $sitting = Sitting::find($event->sittingId);
        if (! $sitting) {
            return;
        }
        $score = Score::where('sitting_id', $sitting->id)->first();
        if (! $score || $score->status !== 'final') {
            return;
        }

        $assessment = Assessment::find($sitting->assessment_id);

        $this->notifications->send(
            recipientId: $sitting->candidate_id,
            channel: 'email',
            eventKey: 'result_ready',
            context: [
                'assessment' => $assessment?->title,
                'raw_score' => (float) $score->raw_score,
                'ref' => $sitting->id,
            ],
            dedupeKey: 'result_ready:'.$sitting->id,
        );
    }
}
