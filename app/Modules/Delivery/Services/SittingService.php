<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Events\SittingStarted;
use App\Modules\Delivery\Exceptions\DeliveryError;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Models\VariantManifest;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assigns and starts sittings (docs/01 §4.5). Assignment pre-assembles the candidate's
 * variant (docs/02 §3); starting sets a SERVER-authoritative deadline — the client clock
 * never decides when time is up (docs/04 §8).
 */
class SittingService
{
    public function __construct(private readonly VariantAssembler $assembler) {}

    public function assign(Assessment $assessment, User $candidate): Sitting
    {
        if (! in_array($assessment->status, ['published', 'live'], true)) {
            throw new DeliveryError("Assessment is not open for sittings (status {$assessment->status}).");
        }

        // One sitting per candidate per assessment (also enforced by a DB unique index).
        if (Sitting::where('assessment_id', $assessment->id)->where('candidate_id', $candidate->id)->exists()) {
            throw new DeliveryError('This candidate already has a sitting for this assessment.');
        }

        return DB::transaction(function () use ($assessment, $candidate) {
            $manifestData = $this->assembler->assemble($assessment);

            $sitting = Sitting::create([
                'assessment_id' => $assessment->id,
                'candidate_id' => $candidate->id,
                'status' => 'assigned',
            ]);

            $manifest = VariantManifest::create([
                'sitting_id' => $sitting->id,
                'manifest' => $manifestData,
            ]);

            $sitting->variant_token = $manifest->id;
            $sitting->save();

            return $sitting;
        });
    }

    /** Begin the attempt; idempotent if already in progress (resume). */
    public function start(Sitting $sitting): Sitting
    {
        if ($sitting->status === 'in_progress') {
            return $sitting; // resume — do not reset the deadline
        }
        if ($sitting->status !== 'assigned') {
            throw new DeliveryError("Sitting cannot be started from status {$sitting->status}.");
        }

        $duration = $sitting->assessment->duration_seconds;
        $now = Carbon::now();

        $sitting->forceFill([
            'status' => 'in_progress',
            'started_at' => $now,
            'server_deadline_epoch' => $duration ? $now->getTimestamp() + $duration : null,
        ])->save();

        SittingStarted::dispatch($sitting->id);

        return $sitting;
    }

    /**
     * Restore a sitting after a disconnect / power failure (docs/02 §7). Answers are
     * already durable (each response is an append-only row written as the candidate goes),
     * and the deadline is server-authoritative — so resuming preserves both the work and
     * the remaining time. Records the reconnection for audit.
     */
    public function resume(Sitting $sitting): Sitting
    {
        if ($sitting->status === 'assigned') {
            return $this->start($sitting); // never started — begin now
        }
        if (! $sitting->isInProgress()) {
            throw new DeliveryError("Sitting is {$sitting->status}; it cannot be resumed.");
        }

        $meta = $sitting->sync_meta ?? [];
        $meta['resumed_count'] = ($meta['resumed_count'] ?? 0) + 1;
        $meta['last_resumed_epoch'] = Carbon::now()->getTimestamp();
        $sitting->forceFill(['sync_meta' => $meta])->save();

        return $sitting;
    }

    /**
     * Grant additional time to a candidate — an accommodation, or to compensate for a
     * power/internet outage. Extends the server-authoritative deadline (reopening it if it
     * had already lapsed) and records the grant for audit. Cannot extend a finished sitting.
     */
    public function grantExtraTime(Sitting $sitting, int $seconds, ?string $reason = null, ?string $grantedBy = null): Sitting
    {
        if (! in_array($sitting->status, ['assigned', 'in_progress'], true)) {
            throw new DeliveryError("Cannot extend a {$sitting->status} sitting.");
        }
        if ($seconds <= 0) {
            throw new DeliveryError('Extra time must be positive.');
        }

        $now = Carbon::now()->getTimestamp();
        // Add to the live deadline, or from now if it was untimed / already lapsed.
        $from = max((int) ($sitting->server_deadline_epoch ?? $now), $now);

        $meta = $sitting->sync_meta ?? [];
        $meta['extensions'][] = ['seconds' => $seconds, 'reason' => $reason, 'by' => $grantedBy, 'at' => $now];

        $sitting->forceFill([
            'server_deadline_epoch' => $from + $seconds,
            'sync_meta' => $meta,
        ])->save();

        return $sitting;
    }
}
