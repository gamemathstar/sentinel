<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-candidate schedule record: one student, one assessment, one session. It carries
 * the seat and, once released, the Delivery sitting that actually runs the exam.
 */
class CandidateSchedule extends Model
{
    use BelongsToTenant;

    protected $table = 'candidate_schedules';

    protected $fillable = [
        'institution_id', 'assessment_id', 'exam_session_id', 'candidate_id',
        'sitting_id', 'seat_no', 'source', 'status',
    ];

    protected $casts = ['seat_no' => 'integer'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(Sitting::class);
    }
}
