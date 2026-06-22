<?php

namespace App\Modules\Delivery\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The computed result for a sitting (docs/01 §4.6, docs/03 §5). Pins the scoring-rule
 * version that produced it, so the result is reproducible from (responses, rule@version).
 */
class Score extends Model
{
    use HasUuidv7;

    public const STATUSES = ['provisional', 'final', 'under_review'];

    protected $table = 'scores';

    protected $fillable = [
        'sitting_id', 'scoring_rule_id', 'scoring_rule_version',
        'raw_score', 'scaled_score', 'section_breakdown', 'competency_breakdown', 'status',
    ];

    protected $casts = [
        'raw_score' => 'float',
        'scaled_score' => 'float',
        'scoring_rule_version' => 'integer',
        'section_breakdown' => 'array',
        'competency_breakdown' => 'array',
    ];

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(Sitting::class);
    }
}
