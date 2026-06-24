<?php

namespace App\Modules\Delivery\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One marker's mark on a grading task (docs/01 §4.6, docs/03 §5). Double-marking is
 * modelled as multiple human marks; AI is just another marker flagged is_ai=true, whose
 * mark is ADVISORY until a human reconciles (the AI-is-a-suggestion invariant, docs/01 §13).
 */
class GradingMark extends Model
{
    use HasUuidv7;

    protected $table = 'grading_marks';

    protected $fillable = ['grading_task_id', 'grader_id', 'mark', 'rubric_breakdown', 'is_ai'];

    protected $casts = [
        'mark' => 'float',
        'rubric_breakdown' => 'array',
        'is_ai' => 'boolean',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(GradingTask::class, 'grading_task_id');
    }
}
