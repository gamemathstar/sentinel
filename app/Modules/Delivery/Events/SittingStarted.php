<?php

namespace App\Modules\Delivery\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Emitted when a candidate starts a sitting (docs/01 §5). Proctoring opens a session. */
class SittingStarted
{
    use Dispatchable;

    public function __construct(public readonly string $sittingId) {}
}
