<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Services\PermissionResolver;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Tenancy\Services\OrgNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Scoped RBAC resolution: institution-wide vs. org-subtree grants (docs/04 §5). */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_institution_wide_grant_applies_everywhere(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst);
        $this->grantRole($user, 'question_author'); // null scope = institution-wide

        $resolver = app(PermissionResolver::class);
        $this->assertTrue($resolver->can($user, Permissions::QB_ITEM_CREATE));
        $this->assertFalse($resolver->can($user, Permissions::QB_ITEM_REVIEW)); // author can't review
    }

    public function test_node_scoped_grant_only_applies_within_its_subtree(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst);
        $org = app(OrgNodeService::class);

        $faculty = $org->create($inst->id, 'faculty', 'Science');
        $deptA = $org->create($inst->id, 'department', 'Physics', $faculty);
        $deptB = $org->create($inst->id, 'department', 'Chemistry', $faculty);
        $topicInA = $org->create($inst->id, 'topic', 'Mechanics', $deptA);

        // Grant author scoped to department A only.
        $this->grantRole($user, 'question_author', $deptA->id);

        $resolver = app(PermissionResolver::class);

        // Within the subtree of A (the dept itself and its descendants): granted.
        $this->assertTrue($resolver->can($user, Permissions::QB_ITEM_CREATE, $deptA->id));
        $this->assertTrue($resolver->can($user, Permissions::QB_ITEM_CREATE, $topicInA->id));

        // In a sibling department or with no node context: not granted.
        $this->assertFalse($resolver->can($user, Permissions::QB_ITEM_CREATE, $deptB->id));
        $this->assertFalse($resolver->can($user, Permissions::QB_ITEM_CREATE));
    }

    public function test_platform_super_admin_bypasses_everything(): void
    {
        $inst = $this->makeTenant();
        $user = $this->makeUser($inst);
        $this->grantRole($user, Permissions::ROLE_PLATFORM_SUPER_ADMIN);

        $resolver = app(PermissionResolver::class);
        $this->assertTrue($resolver->isPlatformSuperAdmin($user));
        $this->assertTrue($resolver->can($user, Permissions::QB_ITEM_REVIEW));
        $this->assertTrue($resolver->can($user, Permissions::IAM_ROLE_MANAGE));
    }

    public function test_permissions_are_isolated_per_user(): void
    {
        $inst = $this->makeTenant();
        $author = $this->makeUser($inst);
        $reviewer = $this->makeUser($inst);
        $this->grantRole($author, 'question_author');
        $this->grantRole($reviewer, 'question_reviewer');

        $resolver = app(PermissionResolver::class);
        $this->assertTrue($resolver->can($author, Permissions::QB_ITEM_CREATE));
        $this->assertFalse($resolver->can($reviewer, Permissions::QB_ITEM_CREATE));
        $this->assertTrue($resolver->can($reviewer, Permissions::QB_ITEM_REVIEW));
    }
}
