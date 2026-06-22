<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Editing an item creates a new immutable version; the paper-pinning model (docs/01 §4.3). */
class ItemVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_creates_a_new_version_and_advances_current_pointer(): void
    {
        $this->makeTenant();
        $service = app(ItemService::class);

        $item = $service->createItem([
            'type' => 'single',
            'content' => ['stem' => 'Original?', 'options' => ['a' => 'x', 'b' => 'y']],
            'answer' => ['correct' => ['a']],
        ]);
        $v1 = $item->current_version_id;

        $v2 = $service->addVersion($item->fresh(), [
            'content' => ['stem' => 'Edited?', 'options' => ['a' => 'x', 'b' => 'y']],
            'answer' => ['correct' => ['b']],
        ]);

        $item->refresh();
        $this->assertSame(2, $item->versions()->count());
        $this->assertSame($v2->id, $item->current_version_id);
        $this->assertNotSame($v1, $item->current_version_id);
        $this->assertSame(1, $item->versions()->where('version_no', 1)->count());
        $this->assertSame(2, (int) $item->currentVersion->version_no);
    }

    public function test_identical_content_produces_identical_hash(): void
    {
        $this->makeTenant();
        $service = app(ItemService::class);

        $content = ['stem' => 'Same?', 'options' => ['a' => 'x', 'b' => 'y']];
        $h1 = $service->contentHash('single', $content);
        $h2 = $service->contentHash('single', ['options' => ['b' => 'y', 'a' => 'x'], 'stem' => 'Same?']);

        // Hash is order-independent (canonicalized), so reordering keys matches.
        $this->assertSame($h1, $h2);
        $this->assertNotSame($h1, $service->contentHash('single', ['stem' => 'Different?', 'options' => []]));
    }
}
