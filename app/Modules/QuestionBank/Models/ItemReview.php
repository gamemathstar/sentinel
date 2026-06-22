<?php

namespace App\Modules\QuestionBank\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A moderation-workflow record on an item version (docs/01 §4.3).
 */
class ItemReview extends Model
{
    use HasUuidv7;

    protected $table = 'item_reviews';

    protected $fillable = ['item_version_id', 'reviewer_id', 'decision', 'comment'];

    public function itemVersion(): BelongsTo
    {
        return $this->belongsTo(ItemVersion::class);
    }
}
