<?php

namespace Tests\Feature\Authoring;

use App\Modules\Authoring\Exceptions\AssemblyShortfall;
use App\Modules\Authoring\Models\Blueprint;
use App\Modules\Authoring\Services\PaperAssembler;
use App\Modules\Authoring\Support\DifficultyBand;
use App\Modules\QuestionBank\Models\ItemVersion;
use App\Modules\QuestionBank\Services\ItemService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Blueprint-driven balanced paper assembly from the bank (docs spec). */
class BlueprintAssemblyTest extends TestCase
{
    use RefreshDatabase;

    private function seedBank(): void
    {
        // 5 easy (facility 0.8), 5 medium (0.5), 5 hard (0.2).
        foreach (range(1, 5) as $i) {
            $this->makeApprovedItem(0.8);
            $this->makeApprovedItem(0.5);
            $this->makeApprovedItem(0.2);
        }
    }

    public function test_assembles_a_balanced_paper_matching_the_difficulty_distribution(): void
    {
        $this->makeTenant();
        $this->seedBank();

        $blueprint = new Blueprint(['name' => 'Final', 'constraints' => [
            'total' => 10,
            'difficulty' => ['easy' => 0.4, 'medium' => 0.4, 'hard' => 0.2],
        ]]);
        $blueprint->institution_id = app(TenantContext::class)->institutionId();
        $blueprint->save();

        $versionIds = app(PaperAssembler::class)->assemble($blueprint);
        $this->assertCount(10, $versionIds);

        // Map the drawn versions back to difficulty bands and check the distribution.
        $bands = ItemVersion::whereIn('id', $versionIds)->with('item')->get()
            ->groupBy(fn ($v) => DifficultyBand::fromFacility((float) $v->item->difficulty))
            ->map->count();

        $this->assertSame(4, $bands[DifficultyBand::EASY]);
        $this->assertSame(4, $bands[DifficultyBand::MEDIUM]);
        $this->assertSame(2, $bands[DifficultyBand::HARD]);
    }

    public function test_reports_a_shortfall_when_the_bank_lacks_items(): void
    {
        $this->makeTenant();
        // Only 1 hard item, but the blueprint needs 2.
        $this->makeApprovedItem(0.8);
        $this->makeApprovedItem(0.8);
        $this->makeApprovedItem(0.8);
        $this->makeApprovedItem(0.2);

        $blueprint = new Blueprint(['name' => 'Hard paper', 'constraints' => [
            'total' => 5,
            'difficulty' => ['easy' => 0.2, 'medium' => 0.0, 'hard' => 0.8], // needs 4 hard
        ]]);
        $blueprint->institution_id = app(TenantContext::class)->institutionId();
        $blueprint->save();

        try {
            app(PaperAssembler::class)->assemble($blueprint);
            $this->fail('Expected AssemblyShortfall.');
        } catch (AssemblyShortfall $e) {
            $this->assertArrayHasKey('hard', $e->shortfall);
            $this->assertSame(4, $e->shortfall['hard']['needed']);
            $this->assertSame(1, $e->shortfall['hard']['available']);
        }
    }

    public function test_assembles_without_difficulty_constraint(): void
    {
        $this->makeTenant();
        $this->seedBank();

        $blueprint = new Blueprint(['name' => 'Any 7', 'constraints' => ['total' => 7]]);
        $blueprint->institution_id = app(TenantContext::class)->institutionId();
        $blueprint->save();

        $this->assertCount(7, app(PaperAssembler::class)->assemble($blueprint));
    }

    public function test_only_approved_items_are_eligible(): void
    {
        $this->makeTenant();
        // A draft item (not approved) must be ignored.
        app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'draft', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
            'metadata' => ['difficulty' => 0.8],
        ]); // stays draft

        $blueprint = new Blueprint(['name' => 'Need 1', 'constraints' => ['total' => 1]]);
        $blueprint->institution_id = app(TenantContext::class)->institutionId();
        $blueprint->save();

        $this->expectException(AssemblyShortfall::class);
        app(PaperAssembler::class)->assemble($blueprint);
    }
}
