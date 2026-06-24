<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Services\BankVisibilityResolver;
use App\Modules\QuestionBank\Services\QuestionBankService;
use App\Modules\Tenancy\Services\OrgNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Bank-level visibility: owner, org-subtree, shared user, staff group, manage-all (docs/18). */
class BankVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_owner_sees_their_restricted_bank_but_a_stranger_does_not(): void
    {
        $inst = $this->makeTenant();
        $owner = $this->makeUser($inst);
        $stranger = $this->makeUser($inst);
        $this->actingForTenant($inst, $owner);
        $bank = $this->makeBank('restricted');

        $resolver = app(BankVisibilityResolver::class);
        $this->assertTrue($resolver->canRead($owner, $bank));
        $this->assertFalse($resolver->canRead($stranger, $bank));
    }

    public function test_org_subtree_visibility_follows_the_org_tree(): void
    {
        $inst = $this->makeTenant();
        $org = app(OrgNodeService::class);
        $faculty = $org->create($inst->id, 'faculty', 'Science');
        $deptA = $org->create($inst->id, 'department', 'Physics', $faculty);
        $deptB = $org->create($inst->id, 'department', 'Chemistry', $faculty);
        $courseA = $org->create($inst->id, 'course', 'PHY101', $deptA);

        $owner = $this->makeUser($inst);
        $this->actingForTenant($inst, $owner);
        $bank = $this->makeBank('org_subtree', $deptA->id); // owned by Physics

        $inDept = $this->makeUser($inst);
        $this->grantRole($inDept, 'question_author', $deptA->id);
        $inCourse = $this->makeUser($inst);
        $this->grantRole($inCourse, 'question_author', $courseA->id); // below the bank's owner
        $inOther = $this->makeUser($inst);
        $this->grantRole($inOther, 'question_author', $deptB->id);
        $institutionWide = $this->makeUser($inst);
        $this->grantRole($institutionWide, 'question_author'); // null scope

        $r = app(BankVisibilityResolver::class);
        $this->assertTrue($r->canRead($inDept, $bank), 'department-scoped staff can read');
        $this->assertTrue($r->canRead($institutionWide, $bank), 'institution-wide staff can read');
        $this->assertFalse($r->canRead($inCourse, $bank), 'a course below the dept does not cover the dept bank');
        $this->assertFalse($r->canRead($inOther, $bank), 'a sibling department cannot read');
    }

    public function test_shared_user_and_group_can_read(): void
    {
        $inst = $this->makeTenant();
        $owner = $this->makeUser($inst);
        $this->actingForTenant($inst, $owner);
        $banks = app(QuestionBankService::class);
        $bank = $this->makeBank('restricted');

        $directShare = $this->makeUser($inst);
        $banks->shareWithUser($bank, $directShare->id, canEdit: false);

        $groupMember = $this->makeUser($inst);
        $group = $banks->createGroup('Examiners');
        $banks->addGroupMember($group, $groupMember->id);
        $banks->shareWithGroup($bank, $group->id, canEdit: true);

        $r = app(BankVisibilityResolver::class);
        $this->assertTrue($r->canRead($directShare, $bank));
        $this->assertTrue($r->canRead($groupMember, $bank));

        // Edit rights: direct share is read-only, group share can edit.
        $this->assertFalse($r->canEdit($directShare, $bank));
        $this->assertTrue($r->canEdit($groupMember, $bank));
        $this->assertTrue($r->canEdit($owner, $bank));
    }

    public function test_manage_all_sees_every_bank(): void
    {
        $inst = $this->makeTenant();
        $owner = $this->makeUser($inst);
        $this->actingForTenant($inst, $owner);
        $bank = $this->makeBank('restricted');

        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer'); // has questionbank.bank.manage_all

        $r = app(BankVisibilityResolver::class);
        $this->assertTrue($r->canRead($officer, $bank));
        $this->assertContains($bank->id, $r->readableBankIds($officer));
    }
}
