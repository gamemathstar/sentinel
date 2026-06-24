<?php

namespace App\Modules\Proctoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Emitted when a proctoring flag is raised (docs/01 §5). Audit + invigilator notify subscribe. */
class FlagRaised
{
    use Dispatchable;

    public function __construct(public readonly string $sessionId, public readonly string $flagId) {}
}
