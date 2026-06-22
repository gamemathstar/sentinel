<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Tenancy\Models\OrgNode;
use App\Modules\Tenancy\Services\OrgNodeService;

/**
 * Resolves a subject's effective permissions (docs/04 §5):
 *
 *   effective(user, resource) = ⋃ { role.permissions
 *       | assignment ∈ user.assignments
 *       ∧ assignment.scope is ancestor-or-self of resource.org_node (or institution-wide) }
 *
 * A null assignment scope is institution-wide. A node-scoped assignment grants only on
 * that node and its subtree, tested via the materialized path. Resolution is cached per
 * (user, orgNode) for the lifetime of the request.
 */
class PermissionResolver
{
    /** @var array<string, string[]> */
    private array $cache = [];

    /** Is this user the platform super admin (bypasses all checks via Gate::before)? */
    public function isPlatformSuperAdmin(User $user): bool
    {
        return RoleAssignment::where('user_id', $user->id)
            ->whereHas('role', fn ($q) => $q->where('name', Permissions::ROLE_PLATFORM_SUPER_ADMIN))
            ->exists();
    }

    /** @return string[] permission keys granted to the user (optionally for a resource node) */
    public function permissionKeys(User $user, ?string $orgNodeId = null): array
    {
        $key = $user->id.'|'.($orgNodeId ?? '∅');
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $resourcePath = $orgNodeId ? OrgNode::query()->whereKey($orgNodeId)->value('path') : null;

        $assignments = RoleAssignment::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($user) {
                $q->whereNull('institution_id')->orWhere('institution_id', $user->institution_id);
            })
            ->with(['role.permissions', 'scope'])
            ->get();

        $keys = [];
        foreach ($assignments as $assignment) {
            if (! $this->scopeApplies($assignment, $resourcePath)) {
                continue;
            }
            foreach ($assignment->role->permissions as $permission) {
                $keys[$permission->key] = true;
            }
        }

        return $this->cache[$key] = array_keys($keys);
    }

    public function can(User $user, string $permissionKey, ?string $orgNodeId = null): bool
    {
        if ($this->isPlatformSuperAdmin($user)) {
            return true;
        }

        return in_array($permissionKey, $this->permissionKeys($user, $orgNodeId), true);
    }

    private function scopeApplies(RoleAssignment $assignment, ?string $resourcePath): bool
    {
        // Institution-wide assignment always applies within the tenant.
        if ($assignment->scope_org_node_id === null) {
            return true;
        }

        // Node-scoped assignment only applies to a specific resource node in its subtree.
        if ($resourcePath === null || $assignment->scope === null) {
            return false;
        }

        return OrgNodeService::pathCovers($assignment->scope->path, $resourcePath);
    }
}
