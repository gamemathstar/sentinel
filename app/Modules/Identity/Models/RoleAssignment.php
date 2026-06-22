<?php

namespace App\Modules\Identity\Models;

use App\Modules\Tenancy\Models\OrgNode;
use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Binds a user to a role within a tenant/org scope (docs/01 §4.1). A null scope means
 * institution-wide; otherwise the grant applies to the scope org node and its subtree.
 * Deliberately NOT tenant-scoped via the global scope: it must be queryable during
 * authentication, before a tenant context exists.
 */
class RoleAssignment extends Model
{
    use HasUuidv7;

    protected $table = 'role_assignments';

    protected $fillable = ['user_id', 'role_id', 'scope_org_node_id', 'institution_id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(OrgNode::class, 'scope_org_node_id');
    }
}
