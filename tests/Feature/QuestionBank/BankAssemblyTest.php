<?php

namespace Tests\Feature\Authoring;

use App\Modules\Authoring\Services\AssessmentService;
use App\Modules\Authoring\Services\BlueprintService;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\Tenancy\Services\OrgNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Assembly only draws from banks the assembling author may read (docs/18). */
class BankAssemblyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    public function test_assembly_is_restricted_to_the_authors_readable_banks(): void
    {
        $inst = $this->makeTenant();
        $org = app(OrgNodeService::class);
        $faculty = $org->create($inst->id, 'faculty', 'Science');
        $deptA = $org->create($inst->id, 'department', 'Physics', $faculty);
        $deptB = $org->create($inst->id, 'department', 'Chemistry', $faculty);

        // Author scoped to department A; a different owner for department B.
        $author = $this->makeUser($inst);
        $this->grantRole($author, 'question_author', $deptA->id);
        $other = $this->makeUser($inst);
        $this->grantRole($other, 'question_author', $deptB->id);

        // Bank B is owned by `other` in a sibling dept — the author cannot read it.
        $this->actingForTenant($inst, $other);
        $bankB = $this->makeBank('org_subtree', $deptB->id);
        // Bank A is owned by the author in department A.
        $this->actingForTenant($inst, $author);
        $bankA = $this->makeBank('org_subtree', $deptA->id);

        $aIds = [];
        foreach (range(1, 3) as $i) {
            $aIds[] = $this->makeApprovedItem(0.5, 'single', $bankA->id)->current_version_id;
            $this->makeApprovedItem(0.5, 'single', $bankB->id); // should never be drawn
        }

        // Assemble as the author.
        $blueprint = app(BlueprintService::class)->create('BP', ['total' => 3, 'types' => ['single']]);
        $svc = app(AssessmentService::class);
        $assessment = $svc->create(['title' => 'A', 'kind' => 'final']);
        $section = $svc->addSection($assessment, 'S');
        $drawn = $svc->assembleSectionFromBlueprint($section, $blueprint);

        $this->assertCount(3, $drawn);
        // Every drawn version belongs to an item in Bank A.
        $bankIds = ItemVersion::whereIn('id', $drawn)->with('item')->get()->pluck('item.question_bank_id')->unique()->all();
        $this->assertSame([$bankA->id], $bankIds);
        $this->assertEqualsCanonicalizing($aIds, $drawn);
    }
}
