<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Support\Facades\DB;

/**
 * Idempotently seeds the permission catalog and the system roles (docs/04 §5).
 * Safe to run on every deploy; existing rows are reused, new ones added.
 */
class RbacProvisioner
{
    public function provision(): void
    {
        DB::transaction(function () {
            foreach (Permissions::all() as $key) {
                Permission::firstOrCreate(['key' => $key]);
            }

            foreach (Permissions::systemRoles() as $name => $keys) {
                $role = Role::firstOrCreate(
                    ['institution_id' => null, 'name' => $name],
                    ['is_system' => true],
                );
                $resolved = $keys === '*' ? Permissions::all() : $keys;
                $role->syncPermissionKeys($resolved);
            }
        });
    }
}
