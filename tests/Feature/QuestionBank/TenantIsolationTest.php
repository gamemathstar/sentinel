<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Services\ItemService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Application-layer tenant isolation via the global scope (docs/03 §9 layer 1). */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_are_invisible_across_tenants(): void
    {
        $a = $this->makeTenant('Alpha University');
        $service = app(ItemService::class);
        $service->createItem([
            'type' => 'single',
            'content' => ['stem' => 'A-only?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ]);

        $this->assertSame(1, Item::count());

        // Switch to a second tenant: A's item is not visible.
        $b = $this->makeTenant('Beta Polytechnic');
        $this->assertSame(0, Item::count());

        $service->createItem([
            'type' => 'single',
            'content' => ['stem' => 'B-only?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['b']],
        ]);
        $this->assertSame(1, Item::count());

        // Back to A: only A's item.
        $this->actingForTenant($a);
        $this->assertSame(1, Item::count());
        $this->assertSame('A-only?', Item::first()->currentVersion->content['stem']);

        // Platform scope sees both.
        app(TenantContext::class)->actAsPlatform();
        $this->assertSame(2, Item::count());
    }

    public function test_created_item_is_stamped_with_current_tenant(): void
    {
        $inst = $this->makeTenant();
        $item = app(ItemService::class)->createItem([
            'type' => 'essay',
            'content' => ['stem' => 'Discuss.'],
        ]);

        $this->assertSame($inst->id, $item->institution_id);
    }
}
