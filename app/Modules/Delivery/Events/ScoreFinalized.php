<?php

namespace App\Modules\Delivery\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Emitted when a sitting is scored (docs/01 §5). Analytics, Certification, Notifications subscribe. */
class ScoreFinalized
{
    use Dispatchable;

    public function __construct(public readonly string $sittingId, public readonly string $scoreId) {}
}
