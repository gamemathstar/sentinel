<?php

namespace App\Modules\Delivery\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Emitted when a candidate submits (docs/01 §5). Scoring + proctoring-finalize subscribe. */
class SittingSubmitted
{
    use Dispatchable;

    public function __construct(public readonly string $sittingId) {}
}
