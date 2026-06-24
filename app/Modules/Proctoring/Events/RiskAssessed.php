<?php

namespace App\Modules\Proctoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Emitted when a session's risk is (re)computed (docs/01 §5). Reporting + QA queue subscribe. */
class RiskAssessed
{
    use Dispatchable;

    public function __construct(
        public readonly string $sessionId,
        public readonly float $cheatingProbability,
        public readonly bool $requiresReview,
    ) {}
}
