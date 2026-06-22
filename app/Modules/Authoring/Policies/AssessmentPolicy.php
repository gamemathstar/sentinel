<?php

namespace App\Modules\Authoring\Policies;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;

/** Authorization for the Assessment aggregate, backed by the IAM resolver (docs/04 §5). */
class AssessmentPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, Permissions::ASSESSMENT_READ);
    }

    public function view(User $user, Assessment $assessment): bool
    {
        return $this->permissions->can($user, Permissions::ASSESSMENT_READ);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, Permissions::ASSESSMENT_CREATE);
    }

    public function update(User $user, Assessment $assessment): bool
    {
        return $this->permissions->can($user, Permissions::ASSESSMENT_UPDATE);
    }

    public function publish(User $user, Assessment $assessment): bool
    {
        return $this->permissions->can($user, Permissions::ASSESSMENT_PUBLISH);
    }
}
