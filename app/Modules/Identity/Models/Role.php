<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named permission set (docs/01 §4.1). A system role has institution_id = null and is
 * shared across tenants; a custom role belongs to one institution (is_system = false).
 */
class Role extends Model
{
    use HasUuidv7;

    protected $table = 'roles';

    protected $fillable = ['institution_id', 'name', 'description', 'is_system'];

    protected $casts = ['is_system' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function syncPermissionKeys(array $keys): void
    {
        $ids = Permission::whereIn('key', $keys)->pluck('id');
        $this->permissions()->sync($ids);
    }
}
