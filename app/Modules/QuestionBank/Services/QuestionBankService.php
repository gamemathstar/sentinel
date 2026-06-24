<?php

namespace App\Modules\QuestionBank\Services;

use App\Modules\Identity\Models\StaffGroup;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Creates and shares question banks and staff groups (docs/18). Visibility is set at
 * creation; user/group shares are additive and may grant read or edit.
 */
class QuestionBankService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function create(string $name, ?string $ownerOrgNodeId, string $visibility = 'restricted'): QuestionBank
    {
        if (! in_array($visibility, QuestionBank::VISIBILITIES, true)) {
            throw new InvalidArgumentException("Unknown visibility: {$visibility}");
        }

        return QuestionBank::create([
            'name' => $name,
            'owner_org_node_id' => $ownerOrgNodeId,
            'visibility' => $visibility,
            'created_by' => $this->tenant->userId(),
        ]);
    }

    public function shareWithUser(QuestionBank $bank, string $userId, bool $canEdit = false): void
    {
        $bank->sharedUsers()->syncWithoutDetaching([$userId => ['can_edit' => $canEdit]]);
    }

    public function shareWithGroup(QuestionBank $bank, string $groupId, bool $canEdit = false): void
    {
        $bank->sharedGroups()->syncWithoutDetaching([$groupId => ['can_edit' => $canEdit]]);
    }

    public function unshareUser(QuestionBank $bank, string $userId): void
    {
        $bank->sharedUsers()->detach($userId);
    }

    public function unshareGroup(QuestionBank $bank, string $groupId): void
    {
        $bank->sharedGroups()->detach($groupId);
    }

    public function createGroup(string $name): StaffGroup
    {
        return StaffGroup::create(['name' => $name, 'created_by' => $this->tenant->userId()]);
    }

    public function addGroupMember(StaffGroup $group, string $userId): void
    {
        $group->members()->syncWithoutDetaching([$userId]);
    }
}
