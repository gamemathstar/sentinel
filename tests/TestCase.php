<?php

namespace Tests;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Authoring\Services\AssessmentService;
use App\Modules\Authoring\Services\ScoringRuleService;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\RbacProvisioner;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\QuestionBank\Services\ItemService;
use App\Modules\QuestionBank\Services\QuestionBankService;
use App\Modules\Tenancy\Models\Institution;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Create an institution and make it the active tenant for the test. */
    protected function makeTenant(string $name = 'Test University'): Institution
    {
        $institution = Institution::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'status' => 'active',
        ]);

        app(TenantContext::class)->set($institution->id);

        return $institution;
    }

    /** Create a user in an institution. */
    protected function makeUser(Institution $institution, ?string $email = null): User
    {
        return User::create([
            'institution_id' => $institution->id,
            'email' => $email ?? Str::random(8).'@example.test',
            'full_name' => 'Test User',
            'password_hash' => bcrypt('secret'),
            'status' => 'active',
        ]);
    }

    /** Switch the active tenant (and optionally acting user) mid-test. */
    protected function actingForTenant(Institution $institution, ?User $user = null): void
    {
        app(TenantContext::class)->set($institution->id, $user?->id);
    }

    /** Seed the permission catalog and system roles. */
    protected function provisionRbac(): void
    {
        app(RbacProvisioner::class)->provision();
    }

    /** Grant a system role to a user, optionally scoped to an org node. */
    protected function grantRole(User $user, string $roleName, ?string $scopeOrgNodeId = null): RoleAssignment
    {
        $role = Role::whereNull('institution_id')->where('name', $roleName)->firstOrFail();

        return RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_org_node_id' => $scopeOrgNodeId,
            'institution_id' => $user->institution_id,
        ]);
    }

    /** Issue a real bearer token for a user (password defaults to the makeUser secret). */
    protected function tokenFor(User $user, string $password = 'secret'): string
    {
        return app(AuthService::class)->attempt($user->email, $password)['token'];
    }

    protected function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($user)];
    }

    /** Create a question bank in the current tenant. */
    protected function makeBank(string $visibility = 'restricted', ?string $ownerOrgNodeId = null): QuestionBank
    {
        return app(QuestionBankService::class)->create('Bank '.Str::random(5), $ownerOrgNodeId, $visibility);
    }

    /** Create an approved (status=active), banded item in the current tenant for assembly tests. */
    protected function makeApprovedItem(float $difficulty, string $type = 'single', ?string $bankId = null): Item
    {
        $item = app(ItemService::class)->createItem([
            'type' => $type,
            'question_bank_id' => $bankId,
            'content' => ['stem' => 'Q '.Str::random(6), 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
            'metadata' => ['difficulty' => $difficulty],
        ]);
        $item->forceFill(['status' => 'active'])->save();

        return $item->fresh();
    }

    /**
     * Build and publish a simple assessment: `$numItems` single-choice items (correct
     * option is canonical 'a'), one section, the given scoring policy. Tenant context
     * must already be set.
     *
     * @return array{assessment: Assessment, items: array<int, Item>}
     */
    protected function publishSimpleAssessment(int $numItems = 2, array $policy = ['correct' => 1, 'wrong' => 0, 'blank' => 0], ?int $duration = null, ?string $proctoringPolicyId = null): array
    {
        $items = [];
        for ($i = 0; $i < $numItems; $i++) {
            $items[] = $this->makeApprovedItem(0.5);
        }

        $rule = app(ScoringRuleService::class)->create('Rule '.Str::random(5), $policy);
        $svc = app(AssessmentService::class);
        $assessment = $svc->create([
            'title' => 'Exam', 'kind' => 'final', 'scoring_rule_id' => $rule->id,
            'duration_seconds' => $duration, 'proctoring_policy_id' => $proctoringPolicyId,
        ]);
        $section = $svc->addSection($assessment, 'Section A');
        $svc->pinItemVersions($section, array_map(fn (Item $i) => $i->current_version_id, $items));
        $svc->publish($assessment->fresh());

        return ['assessment' => $assessment->fresh(), 'items' => $items];
    }

    /**
     * Run a full sitting for a candidate: assign, start, answer each mapped item
     * correctly/incorrectly, and submit. Items not in $correctMap are left blank.
     *
     * @param  array<string,bool>  $correctMap  item_version_id => answer correctly?
     */
    protected function runSitting(Assessment $assessment, User $candidate, array $correctMap): Sitting
    {
        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);

        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting);

        foreach ($sitting->manifest->manifest['items'] as $entry) {
            $iv = $entry['iv'];
            if (! array_key_exists($iv, $correctMap)) {
                continue; // blank
            }
            $correctIdx = (int) array_search('a', $sitting->manifest->optionOrderFor($iv), true);
            $idx = $correctMap[$iv] ? $correctIdx : (1 - $correctIdx);
            $recorder->record($sitting, $iv, ['selected' => [$idx]]);
        }

        app(ScoringService::class)->submit($sitting->fresh());

        return $sitting->fresh();
    }
}
