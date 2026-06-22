<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A named, grantable capability (docs/01 §4.1). Global, not tenant-scoped. */
class Permission extends Model
{
    use HasUuidv7;

    protected $table = 'permissions';

    protected $fillable = ['key', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}
