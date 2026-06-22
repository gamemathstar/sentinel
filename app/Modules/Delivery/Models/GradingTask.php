<?php

namespace App\Modules\Delivery\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual/AI grading unit for an open-ended response (docs/01 §4.6, docs/03 §5).
 * Objective items are auto-scored against the vault; open-ended items (essay, code,
 * short answer) instead spawn a grading task to be marked later. Its presence is why a
 * score may be 'under_review' rather than 'final'.
 */
class GradingTask extends Model
{
    use HasUuidv7;

    public const STATUSES = ['pending', 'in_progress', 'double_marking', 'reconciled'];

    protected $table = 'grading_tasks';

    protected $fillable = ['sitting_id', 'response_id', 'type', 'status', 'ai_suggestion_id'];

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(Sitting::class);
    }
}
