<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Exceptions\SchedulingError;
use App\Modules\Scheduling\Models\CandidateSchedule;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Models\Venue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The scheduling control surface: venues, sessions, manual candidate assignment,
 * invigilator assignment, and releasing schedules into live Delivery sittings.
 *
 * Auto-mapping a whole selection lives in {@see AutoMapper}; this service is the
 * single-entity / manual side plus the bridge into the Delivery context.
 */
class SchedulingService
{
    public function __construct(private readonly SittingService $sittings) {}

    public function createVenue(array $data): Venue
    {
        return Venue::create($data);
    }

    /** Create one session of an assessment at a venue/time. Capacity defaults to the venue's. */
    public function createSession(Assessment $assessment, array $data): ExamSession
    {
        $venue = isset($data['venue_id']) ? Venue::findOrFail($data['venue_id']) : null;
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = isset($data['ends_at'])
            ? Carbon::parse($data['ends_at'])
            : (clone $startsAt)->addMinutes((int) ($data['duration_minutes'] ?? 60));

        $capacity = $data['capacity'] ?? $venue?->capacity ?? 0;
        if ($capacity <= 0) {
            throw new SchedulingError('A session needs a positive capacity (set one or pick a venue with capacity).');
        }

        return ExamSession::create([
            'assessment_id' => $assessment->id,
            'venue_id' => $venue?->id,
            'name' => $data['name'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'capacity' => $capacity,
            'status' => 'scheduled',
        ]);
    }

    /**
     * Manually place candidates into a session, respecting its remaining capacity. Skips
     * candidates already scheduled for the assessment (the unique pair guards it too).
     *
     * @param  string[]  $candidateIds
     * @return int number actually scheduled
     */
    public function assignCandidates(ExamSession $session, array $candidateIds, string $source = 'manual'): int
    {
        $already = CandidateSchedule::where('assessment_id', $session->assessment_id)
            ->pluck('candidate_id')->all();
        $pending = array_values(array_diff(array_unique($candidateIds), $already));

        $free = $session->remainingCapacity();
        if (count($pending) > $free) {
            throw new SchedulingError("Session has only {$free} seat(s) left for ".count($pending).' candidate(s).');
        }

        $seat = (int) CandidateSchedule::where('exam_session_id', $session->id)->max('seat_no');

        return DB::transaction(function () use ($session, $pending, $source, &$seat) {
            foreach ($pending as $candidateId) {
                CandidateSchedule::create([
                    'assessment_id' => $session->assessment_id,
                    'exam_session_id' => $session->id,
                    'candidate_id' => $candidateId,
                    'seat_no' => ++$seat,
                    'source' => $source,
                    'status' => 'scheduled',
                ]);
            }

            return count($pending);
        });
    }

    /** Assign (or re-assign) invigilators to a session. Idempotent on (session,user). */
    public function assignInvigilators(ExamSession $session, array $assignments): void
    {
        $sync = [];
        foreach ($assignments as $a) {
            $sync[$a['user_id']] = ['role' => $a['role'] ?? 'assistant'];
        }
        $session->invigilators()->syncWithoutDetaching($sync);
    }

    /**
     * Release scheduled candidates into live Delivery sittings (assessment must be
     * published/live). Each schedule gets its sitting_id wired and status -> released.
     *
     * @return int number released
     */
    public function release(Assessment $assessment): int
    {
        $schedules = CandidateSchedule::where('assessment_id', $assessment->id)
            ->where('status', 'scheduled')
            ->with('candidate')
            ->get();

        $released = 0;
        foreach ($schedules as $schedule) {
            if (! $schedule->candidate) {
                continue;
            }
            $sitting = $this->sittings->assign($assessment, $schedule->candidate);
            $schedule->update(['sitting_id' => $sitting->id, 'status' => 'released']);
            $released++;
        }

        return $released;
    }
}
