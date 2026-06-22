<?php

namespace App\Modules\Analytics\Models;

use App\Modules\Authoring\Models\Assessment;
use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model of an assessment's reliability metrics (docs/01 §4.8, docs/03 §7):
 * KR-20, Cronbach's alpha, and the standard error of measurement.
 */
class AssessmentReliability extends Model
{
    use HasUuidv7;

    protected $table = 'assessment_reliability';

    protected $fillable = ['assessment_id', 'kr20', 'cronbach_alpha', 'sem'];

    protected $casts = [
        'kr20' => 'float',
        'cronbach_alpha' => 'float',
        'sem' => 'float',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
