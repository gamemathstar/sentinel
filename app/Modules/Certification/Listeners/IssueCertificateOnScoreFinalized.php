<?php

namespace App\Modules\Certification\Listeners;

use App\Modules\Certification\Services\CertificationService;
use App\Modules\Delivery\Events\ScoreFinalized;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Models\Sitting;

/**
 * Auto-issues a certificate when a score becomes final (docs/01 §5: Certification
 * subscribes to ScoreFinalized). ScoreFinalized also fires for under_review scores
 * (objective part done, grading pending) — those are skipped here and a certificate is
 * issued only once grading reconciles and the score is truly final. Issuance is
 * idempotent, so a re-fired event is harmless.
 */
class IssueCertificateOnScoreFinalized
{
    public function __construct(private readonly CertificationService $certificates) {}

    public function handle(ScoreFinalized $event): void
    {
        $sitting = Sitting::find($event->sittingId);
        if (! $sitting) {
            return;
        }
        $score = Score::where('sitting_id', $sitting->id)->first();
        if (! $score || $score->status !== 'final') {
            return; // not yet final (e.g. open-ended items still under review)
        }

        $this->certificates->issueForSitting($sitting);
    }
}
