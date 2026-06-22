<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Authoring\Models\Assessment;
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

        return $sitting;
    }
}
