<?php

namespace App\Modules\Proctoring\Services;

use App\Modules\Authoring\Models\ProctoringPolicy;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Proctoring\Events\FlagRaised;
use App\Modules\Proctoring\Events\RiskAssessed;
use App\Modules\Proctoring\Exceptions\ProctoringError;
use App\Modules\Proctoring\Models\EvidenceClip;
use App\Modules\Proctoring\Models\ProctoringFlag;
use App\Modules\Proctoring\Models\ProctoringSession;
use App\Modules\Proctoring\Models\RiskAssessment;
use App\Modules\Proctoring\Support\FlagCatalog;
use Illuminate\Support\Carbon;

/**
 * Orchestrates proctoring (docs/05): open a session for a sitting, ingest flags and
 * evidence, compute an explainable risk assessment, and record a human review decision.
 * Risk NEVER auto-voids a sitting — a high score only routes to review (docs/05 §1).
 */
class ProctoringService
{
    public function __construct(private readonly RiskScoringEngine $engine) {}

    /** Open (idempotently) the monitoring session for a sitting, using its policy. */
    public function openSession(Sitting $sitting): ProctoringSession
    {
        $policy = $this->policyFor($sitting);

        return ProctoringSession::firstOrCreate(
            ['sitting_id' => $sitting->id],
            [
                'mode' => $policy?->mode ?? 'none',
                'lockdown_active' => (bool) ($policy?->lockdown_required ?? false),
            ]
        );
    }

    public function recordFlag(ProctoringSession $session, string $type, float $confidence, string $source = 'client', ?EvidenceClip $evidence = null, ?Carbon $occurredAt = null): ProctoringFlag
    {
        if (! FlagCatalog::isKnown($type)) {
            throw new ProctoringError("Unknown flag type: {$type}");
        }
        if (! in_array($source, ProctoringFlag::SOURCES, true)) {
            throw new ProctoringError("Unknown flag source: {$source}");
        }

        $flag = ProctoringFlag::create([
            'proctoring_session_id' => $session->id,
            'type' => $type,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'source' => $source,
            'evidence_clip_id' => $evidence?->id,
            'occurred_at' => $occurredAt ?? Carbon::now(),
        ]);

        FlagRaised::dispatch($session->id, $flag->id);

        return $flag;
    }

    public function attachEvidence(ProctoringSession $session, string $kind, string $s3Key, ?Carbon $from = null, ?Carbon $to = null): EvidenceClip
    {
        if (! in_array($kind, EvidenceClip::KINDS, true)) {
            throw new ProctoringError("Unknown evidence kind: {$kind}");
        }

        return EvidenceClip::create([
            'proctoring_session_id' => $session->id,
            's3_key' => $s3Key,
            'kind' => $kind,
            'from_ts' => $from,
            'to_ts' => $to,
        ]);
    }

    /** Recompute the explainable risk assessment from the session's flags. */
    public function assess(ProctoringSession $session): RiskAssessment
    {
        $policy = $this->policyFor($session->sitting);
        $weights = $policy?->signals['weights'] ?? [];
        $threshold = (float) ($policy?->signals['review_threshold'] ?? 0.6);

        $flags = $session->flags()->get(['id', 'type', 'confidence'])
            ->map(fn ($f) => ['id' => $f->id, 'type' => $f->type, 'confidence' => (float) $f->confidence])
            ->all();

        $result = $this->engine->assess($flags, $weights, $threshold);

        $risk = RiskAssessment::updateOrCreate(
            ['proctoring_session_id' => $session->id],
            [
                'cheating_probability' => $result['cheating_probability'],
                'suspicion_score' => $result['suspicion_score'],
                'timeline' => $result['timeline'],
                'status' => 'auto', // a human decides; this is never an automatic verdict
            ]
        );

        RiskAssessed::dispatch($session->id, $result['cheating_probability'], $result['requires_review']);

        return $risk;
    }

    /** Record a human review decision (does not itself void the sitting). */
    public function review(RiskAssessment $risk, string $decision): RiskAssessment
    {
        if (! in_array($decision, ['cleared', 'upheld'], true)) {
            throw new ProctoringError("Invalid review decision: {$decision}");
        }

        $risk->forceFill(['status' => $decision])->save();

        return $risk;
    }

    private function policyFor(Sitting $sitting): ?ProctoringPolicy
    {
        $policyId = $sitting->assessment?->proctoring_policy_id;

        return $policyId ? ProctoringPolicy::find($policyId) : null;
    }
}
