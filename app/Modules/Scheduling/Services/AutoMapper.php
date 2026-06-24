<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Scheduling\Exceptions\SchedulingError;
use App\Modules\Scheduling\Models\CandidateSchedule;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Models\Venue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Automatic timetabling. The admin defines a selection, the venues to use, a set of
 * start-times, and a per-session duration; the mapper creates one session per
 * (venue × start-time) and distributes the resolved candidates across those slots up to
 * each slot's capacity — keeping programme/level cohorts contiguous so a class tends to
 * land in the same room/time.
 *
 * Example: department A & B, Venue B, 5 start-times of 60 min each, capacity 50 →
 * 5 sessions in Venue B, candidates filling 1..5 in order, 50 per room.
 *
 * Input:
 *   [
 *     'selection'        => [...],                       // see SelectionResolver
 *     'venues'           => [['venue_id'=>uuid,'capacity'=>?], ...],
 *     'start_times'      => ['2026-07-01T09:00:00Z', ...],
 *     'duration_minutes' => 60,
 *   ]
 */
class AutoMapper
{
    public function __construct(private readonly SelectionResolver $selection) {}

    public function map(Assessment $assessment, array $input): array
    {
        $candidates = $this->selection->resolve($input['selection'] ?? ['scope' => 'all']);
        if ($candidates->isEmpty()) {
            throw new SchedulingError('The selection resolved to no students.');
        }

        $startTimes = $input['start_times'] ?? [];
        $venueSpecs = $input['venues'] ?? [];
        $duration = (int) ($input['duration_minutes'] ?? 60);
        if ($startTimes === [] || $venueSpecs === []) {
            throw new SchedulingError('Provide at least one venue and one start-time.');
        }

        $venues = Venue::whereIn('id', array_column($venueSpecs, 'venue_id'))->get()->keyBy('id');
        $capacityOf = [];
        foreach ($venueSpecs as $spec) {
            $venue = $venues->get($spec['venue_id']);
            if (! $venue) {
                throw new SchedulingError('Unknown venue in mapping request.');
            }
            $capacityOf[$venue->id] = (int) ($spec['capacity'] ?? $venue->capacity);
            if ($capacityOf[$venue->id] <= 0) {
                throw new SchedulingError("Venue {$venue->name} has no usable capacity.");
            }
        }

        // Candidates already scheduled for this assessment are left untouched.
        $already = CandidateSchedule::where('assessment_id', $assessment->id)->pluck('candidate_id')->flip();
        $queue = $candidates->reject(fn ($e) => $already->has($e->user_id))->values();

        $totalCapacity = count($startTimes) * array_sum($capacityOf);

        return DB::transaction(function () use ($assessment, $startTimes, $venueSpecs, $venues, $capacityOf, $duration, $queue, $totalCapacity) {
            // Build session slots in fill order: start-time outer, venue inner.
            $slots = [];
            foreach ($startTimes as $start) {
                $startsAt = Carbon::parse($start);
                foreach ($venueSpecs as $spec) {
                    $venue = $venues->get($spec['venue_id']);
                    $session = ExamSession::create([
                        'assessment_id' => $assessment->id,
                        'venue_id' => $venue->id,
                        'name' => $venue->name.' · '.$startsAt->format('H:i'),
                        'starts_at' => $startsAt,
                        'ends_at' => (clone $startsAt)->addMinutes($duration),
                        'capacity' => $capacityOf[$venue->id],
                        'status' => 'scheduled',
                    ]);
                    $slots[] = ['session' => $session, 'free' => $capacityOf[$venue->id], 'seat' => 0];
                }
            }

            // Greedy fill: pour the (cohort-ordered) queue into slots in order.
            $scheduled = 0;
            $slotIndex = 0;
            $perSession = [];
            foreach ($queue as $enrollment) {
                while ($slotIndex < count($slots) && $slots[$slotIndex]['free'] === 0) {
                    $slotIndex++;
                }
                if ($slotIndex >= count($slots)) {
                    break; // out of capacity; remainder reported as unscheduled
                }
                $slot = &$slots[$slotIndex];
                CandidateSchedule::create([
                    'assessment_id' => $assessment->id,
                    'exam_session_id' => $slot['session']->id,
                    'candidate_id' => $enrollment->user_id,
                    'seat_no' => ++$slot['seat'],
                    'source' => 'auto',
                    'status' => 'scheduled',
                ]);
                $slot['free']--;
                $perSession[$slot['session']->id] = ($perSession[$slot['session']->id] ?? 0) + 1;
                $scheduled++;
                unset($slot);
            }

            return [
                'sessions_created' => count($slots),
                'candidates_total' => $queue->count(),
                'scheduled' => $scheduled,
                'unscheduled' => max(0, $queue->count() - $scheduled),
                'total_capacity' => $totalCapacity,
                'per_session' => collect($slots)->map(fn ($s) => [
                    'session_id' => $s['session']->id,
                    'name' => $s['session']->name,
                    'seated' => $perSession[$s['session']->id] ?? 0,
                    'capacity' => $s['session']->capacity,
                ])->all(),
            ];
        });
    }
}
