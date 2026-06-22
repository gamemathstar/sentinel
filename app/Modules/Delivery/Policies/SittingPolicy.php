<?php

namespace App\Modules\Delivery\Policies;

use App\Modules\Delivery\Models\Sitting;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;

/**
 * Authorization for sittings (docs/04 §5). A candidate may only act on their OWN sitting
 * (ownership), while staff with the assign permission may assign and manage others'.
 */
class SittingPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function assign(User $user): bool
    {
        return $this->permissions->can($user, Permissions::SITTING_ASSIGN);
    }

    /** Take/answer/submit: the owning candidate with take permission, or an assigner. */
    public function take(User $user, Sitting $sitting): bool
    {
        if ($sitting->candidate_id === $user->id && $this->permissions->can($user, Permissions::SITTING_TAKE)) {
            return true;
        }

        return $this->permissions->can($user, Permissions::SITTING_ASSIGN);
    }

    public function viewScore(User $user, Sitting $sitting): bool
    {
        if (! $this->permissions->can($user, Permissions::SCORE_READ)) {
            return false;
        }

        // A candidate sees only their own score; staff with assign rights see any.
        return $sitting->candidate_id === $user->id || $this->permissions->can($user, Permissions::SITTING_ASSIGN);
    }
}
