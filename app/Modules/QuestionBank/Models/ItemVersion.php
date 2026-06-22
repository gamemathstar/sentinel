<?php

namespace App\Modules\QuestionBank\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immutable revision of an Item (docs/01 §4.3). Part of the Item aggregate (no
 * institution_id of its own — isolated transitively via its Item, docs/03 §9).
 *
 * `content` holds the stem and option *texts* ONLY. It must never contain a flag
 * marking which option is correct; that is enforced by ItemService writing correctness
 * to the vault instead. See docs/04 §2.
 */
class ItemVersion extends Model
{
    use HasUuidv7;

    /** Workflow states (docs/01 §4.3, separation of duties in docs/04 §5). */
    public const STATES = ['draft', 'reviewed', 'moderated', 'approved', 'retired'];

    protected $table = 'item_versions';

    protected $fillable = [
        'item_id', 'version_no', 'stimulus_id', 'content', 'author_id', 'state', 'content_hash',
    ];

    protected $casts = ['content' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ItemReview::class);
    }
}
