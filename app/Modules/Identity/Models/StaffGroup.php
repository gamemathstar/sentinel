<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named, reusable group of staff (e.g. "Physics examiners") used to share question
 * banks to many people at once (docs/18).
 */
class StaffGroup extends Model
{
    use BelongsToTenant;

    protected $table = 'staff_groups';

    protected $fillable = ['institution_id', 'created_by', 'name'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_group_members');
    }
}
