<?php

namespace App\Modules\Proctoring\Listeners;

use App\Modules\Delivery\Events\SittingStarted;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Proctoring\Services\ProctoringService;

/**
 * Opens a proctoring session when a sitting starts (docs/01 §5) — but only if the
 * assessment's proctoring policy actually monitors (mode != none). Unproctored exams
 * start no session.
 */
class OpenProctoringSessionOnSittingStarted
{
    public function __construct(private readonly ProctoringService $proctoring) {}

    public function handle(SittingStarted $event): void
    {
        $sitting = Sitting::with('assessment')->find($event->sittingId);
        if (! $sitting) {
            return;
        }

        $session = $this->proctoring->openSession($sitting);
        if ($session->mode === 'none') {
            // No monitoring configured: drop the placeholder session to keep things clean.
            $session->delete();
        }
    }
}
