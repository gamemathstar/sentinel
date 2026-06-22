<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Identity-only user (docs/01 §4.1). Roles live in role_assignments, not here.
 * Maps to our `users` schema (password_hash/full_name), distinct from the default
 * Laravel skeleton model. Not globally tenant-scoped: platform admins have no
 * institution, and a user is looked up by id/email during authentication.
 */
class User extends Authenticatable
{
    use HasUuidv7;

    protected $table = 'users';

    protected $fillable = [
        'institution_id', 'email', 'full_name', 'password_hash', 'status', 'mfa_enabled', 'email_verified_at',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'mfa_enabled' => 'boolean',
        'email_verified_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function mfaFactors(): HasMany
    {
        return $this->hasMany(MfaFactor::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }
}
