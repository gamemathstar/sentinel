<?php

namespace App\Modules\Authoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted when an assessment is published (docs/01 §5). Delivery will subscribe to open
 * scheduling/assignment, and Notifications to alert candidates. No listeners yet — the
 * event is the seam along which those contexts integrate later.
 */
class AssessmentPublished
{
    use Dispatchable;

    public function __construct(public readonly string $assessmentId) {}
}
