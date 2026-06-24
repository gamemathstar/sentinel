<?php

namespace App\Modules\QuestionBank\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\QuestionBank\Services\BankVisibilityResolver;

/**
 * Authorization for question banks (docs/18). Reading/editing defers to the visibility
 * resolver; creating and sharing are permission-gated.
 */
class QuestionBankPolicy
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly BankVisibilityResolver $visibility,
    ) {}

    public function create(User $user): bool
    {
        return $this->permissions->can($user, Permissions::QB_BANK_CREATE);
    }

    public function view(User $user, QuestionBank $bank): bool
    {
        return $this->visibility->canRead($user, $bank);
    }

    public function update(User $user, QuestionBank $bank): bool
    {
        return $this->visibility->canEdit($user, $bank);
    }

    public function share(User $user, QuestionBank $bank): bool
    {
        return $this->permissions->can($user, Permissions::QB_BANK_SHARE) && $this->visibility->canEdit($user, $bank);
    }
}
