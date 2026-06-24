<?php

namespace App\Modules\QuestionBank\Services;

use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\Tenancy\Services\OrgNodeService;
use Illuminate\Support\Facades\DB;

/**
 * Decides which question banks a subject may read or edit (docs/18). A bank is readable if
 * the subject owns it, has the manage-all permission, is in the bank's owning org subtree
 * (when visibility = org_subtree), or has been shared the bank directly or via a group.
 * Visibility lives on the BANK, not the individual question.
 */
class BankVisibilityResolver
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function canManageAll(User $user): bool
    {
        return $this->permissions->can($user, Permissions::QB_BANK_MANAGE_ALL);
    }

    public function canRead(User $user, QuestionBank $bank): bool
    {
        if ($this->canManageAll($user) || $bank->created_by === $user->id) {
            return true;
        }
        if ($this->userShare($bank->id, $user->id) !== null) {
            return true;
        }
        if ($this->sharedViaGroup($bank->id, $user->id)) {
            return true;
        }

        return $bank->visibility === 'org_subtree' && $this->orgCovered($user, $bank);
    }

    public function canEdit(User $user, QuestionBank $bank): bool
    {
        if ($this->canManageAll($user) || $bank->created_by === $user->id) {
            return true;
        }
        $share = $this->userShare($bank->id, $user->id);
        if ($share && $share->can_edit) {
            return true;
        }

        return $this->sharedViaGroup($bank->id, $user->id, requireEdit: true);
    }

    /** @return string[] ids of every bank the subject may read (tenant-scoped). */
    public function readableBankIds(User $user): array
    {
        if ($this->canManageAll($user)) {
            return QuestionBank::query()->pluck('id')->all();
        }

        $ids = QuestionBank::where('created_by', $user->id)->pluck('id')->all();
        $ids = array_merge($ids, DB::table('question_bank_user_shares')->where('user_id', $user->id)->pluck('question_bank_id')->all());

        $groupIds = DB::table('staff_group_members')->where('user_id', $user->id)->pluck('staff_group_id');
        if ($groupIds->isNotEmpty()) {
            $ids = array_merge($ids, DB::table('question_bank_group_shares')->whereIn('staff_group_id', $groupIds)->pluck('question_bank_id')->all());
        }

        foreach (QuestionBank::where('visibility', 'org_subtree')->with('ownerOrgNode')->get() as $bank) {
            if ($this->orgCovered($user, $bank)) {
                $ids[] = $bank->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function orgCovered(User $user, QuestionBank $bank): bool
    {
        $scopes = $this->scopePaths($user);
        if (in_array('*', $scopes, true)) {
            return true; // an institution-wide (null-scope) assignment covers every org node
        }
        $ownerPath = $bank->ownerOrgNode?->path;
        if ($ownerPath === null) {
            return false; // institution-level bank: only all-scope users (handled above)
        }
        foreach ($scopes as $scopePath) {
            if (OrgNodeService::pathCovers($scopePath, $ownerPath)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] the user's assignment scope paths, or ['*'] if any is institution-wide */
    private function scopePaths(User $user): array
    {
        $assignments = RoleAssignment::where('user_id', $user->id)->with('scope')->get();
        $paths = [];
        foreach ($assignments as $a) {
            if ($a->scope_org_node_id === null) {
                return ['*'];
            }
            if ($a->scope) {
                $paths[] = $a->scope->path;
            }
        }

        return $paths;
    }

    private function userShare(string $bankId, string $userId): ?object
    {
        return DB::table('question_bank_user_shares')
            ->where('question_bank_id', $bankId)->where('user_id', $userId)->first();
    }

    private function sharedViaGroup(string $bankId, string $userId, bool $requireEdit = false): bool
    {
        $groupIds = DB::table('staff_group_members')->where('user_id', $userId)->pluck('staff_group_id');
        if ($groupIds->isEmpty()) {
            return false;
        }

        return DB::table('question_bank_group_shares')
            ->where('question_bank_id', $bankId)
            ->whereIn('staff_group_id', $groupIds)
            ->when($requireEdit, fn ($q) => $q->where('can_edit', true))
            ->exists();
    }
}
