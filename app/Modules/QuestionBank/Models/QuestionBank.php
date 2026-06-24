<?php

namespace App\Modules\QuestionBank\Models;

use App\Modules\Identity\Models\StaffGroup;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A container of questions, owned by an org node and carrying the visibility policy
 * (docs/18). Questions belong to exactly one bank; privacy is enforced at the bank level
 * by BankVisibilityResolver.
 */
class QuestionBank extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const VISIBILITIES = ['org_subtree', 'restricted'];

    protected $table = 'question_banks';

    protected $fillable = ['institution_id', 'owner_org_node_id', 'created_by', 'name', 'visibility', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function ownerOrgNode(): BelongsTo
    {
        return $this->belongsTo(OrgNode::class, 'owner_org_node_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function sharedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'question_bank_user_shares')->withPivot('can_edit');
    }

    public function sharedGroups(): BelongsToMany
    {
        return $this->belongsToMany(StaffGroup::class, 'question_bank_group_shares', 'question_bank_id', 'staff_group_id')
            ->withPivot('can_edit');
    }
}
