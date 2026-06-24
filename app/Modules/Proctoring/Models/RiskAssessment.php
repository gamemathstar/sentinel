<?php

namespace App\Modules\Proctoring\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The computed, EXPLAINABLE risk summary for a session (docs/01 §4.7, docs/05 §7). The
 * `timeline` references the specific flags that contributed, so a reviewer and an appeals
 * process can audit *why* a candidate was scored risky. A high score routes to human
 * review; it NEVER auto-voids a result (docs/05 §1).
 */
class RiskAssessment extends Model
{
    use HasUuidv7;

    public const STATUSES = ['auto', 'reviewed', 'cleared', 'upheld'];

    protected $table = 'risk_assessments';

    protected $fillable = [
        'proctoring_session_id', 'cheating_probability', 'suspicion_score', 'timeline', 'status',
    ];

    protected $casts = [
        'cheating_probability' => 'float',
        'suspicion_score' => 'float',
        'timeline' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProctoringSession::class, 'proctoring_session_id');
    }
}
