<?php

namespace App\Modules\Delivery\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's answer to one delivered question (docs/01 §4.5, docs/03 §4).
 *
 * APPEND-ONLY: a correction is a NEW row with a higher `sequence`; the latest sequence
 * per (sitting, item) wins. The table is range-partitioned by answered_at and has no
 * updated_at — these rows are never mutated, which is what makes offline conflict
 * resolution trivial (docs/02 §7).
 */
class Response extends Model
{
    use HasUuidv7;

    public $timestamps = false;

    protected $table = 'responses';

    protected $fillable = [
        'sitting_id', 'item_version_id', 'sequence', 'answer', 'confidence', 'time_spent_ms', 'answered_at',
    ];

    protected $casts = [
        'answer' => 'array',
        'confidence' => 'float',
        'sequence' => 'integer',
        'time_spent_ms' => 'integer',
        'answered_at' => 'datetime',
    ];

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(Sitting::class);
    }
}
