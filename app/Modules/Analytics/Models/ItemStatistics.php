<?php

namespace App\Modules\Analytics\Models;

use App\Modules\QuestionBank\Models\Item;
use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read model of an item's psychometric statistics (docs/01 §4.8, docs/03 §7). Recomputed
 * by analytics workers from finalized scoring data — never written on the exam hot path.
 */
class ItemStatistics extends Model
{
    use HasUuidv7;

    protected $table = 'item_statistics';

    protected $fillable = [
        'item_id', 'sample_n', 'facility_index', 'discrimination_index', 'distractor_analysis', 'irt_params',
    ];

    protected $casts = [
        'sample_n' => 'integer',
        'facility_index' => 'float',
        'discrimination_index' => 'float',
        'distractor_analysis' => 'array',
        'irt_params' => 'array',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
