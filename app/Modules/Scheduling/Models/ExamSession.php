<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A timed, venued session of an assessment. Many sessions can run the same paper in
 * parallel across venues/start-times; candidates fill each up to its capacity.
 */
class ExamSession extends Model
{
    use BelongsToTenant;

    protected $table = 'exam_sessions';

    protected $fillable = [
        'institution_id', 'assessment_id', 'venue_id', 'name',
        'starts_at', 'ends_at', 'capacity', 'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(CandidateSchedule::class);
    }

    public function invigilators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'exam_session_invigilator')
            ->withPivot('role')->withTimestamps();
    }

    /** Seats still available in this session. */
    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->schedules()->count());
    }
}
