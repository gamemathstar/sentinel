<?php

namespace App\Modules\QuestionBank\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\ItemVersion;

/**
 * Authorization gate for the Item aggregate (docs/04 §5), backed by the IAM permission
 * resolver. A platform super admin bypasses these via Gate::before. The author-cannot-
 * approve rule is both a permission concern and a domain invariant — it is ALSO enforced
 * in ReviewService so it holds even if a caller reaches the service directly.
 */
class ItemPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, Permissions::QB_ITEM_READ);
    }

    public function view(User $user, Item $item): bool
    {
        return $this->permissions->can($user, Permissions::QB_ITEM_READ);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, Permissions::QB_ITEM_CREATE);
    }

    public function update(User $user, Item $item): bool
    {
        return $this->permissions->can($user, Permissions::QB_ITEM_UPDATE);
    }

    public function import(User $user): bool
    {
        return $this->permissions->can($user, Permissions::QB_ITEM_IMPORT);
    }

    /** May this subject act as reviewer/approver on this version? */
    public function review(User $user, ItemVersion $version): bool
    {
        if (! $this->permissions->can($user, Permissions::QB_ITEM_REVIEW)) {
            return false;
        }

        // Separation of duties: never your own authored version.
        return $version->author_id === null || $version->author_id !== $user->id;
    }
}
