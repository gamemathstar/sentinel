<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Services\AnswerKeyVault;
use App\Modules\QuestionBank\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the crown-jewel security property (docs/04 §2): the question bank stores the
 * question without revealing the correct answer, and the answer is only recoverable
 * through the vault.
 */
class AnswerKeySeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_correct_answer_is_not_stored_in_the_question_content(): void
    {
        $this->makeTenant();
        $service = app(ItemService::class);

        $item = $service->createItem([
            'type' => 'single',
            'content' => ['stem' => 'What is 2 + 2?', 'options' => ['a' => '3', 'b' => '4', 'c' => '5']],
            'answer' => ['correct' => ['b']],
        ]);

        $version = $item->currentVersion;

        // The version content has the stem and option texts but NO correctness marker.
        $this->assertSame('What is 2 + 2?', $version->content['stem']);
        $this->assertArrayHasKey('options', $version->content);
        $this->assertArrayNotHasKey('correct', $version->content);

        // Even the raw JSON column must not contain a correctness key.
        $rawJson = DB::table('item_versions')->where('id', $version->id)->value('content');
        $this->assertStringNotContainsString('correct', $rawJson);
    }

    public function test_answer_lives_in_the_vault_under_an_opaque_token(): void
    {
        $this->makeTenant();
        $vault = app(AnswerKeyVault::class);

        $item = app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'Capital of France?', 'options' => ['a' => 'Paris', 'b' => 'Rome']],
            'answer' => ['correct' => ['a']],
        ]);
        $versionId = $item->current_version_id;

        // The vault row is keyed by HMAC-derived token, not the item_version_id.
        $token = $vault->deriveToken($versionId);
        $this->assertNotSame($versionId, $token);
        $this->assertDatabaseHas('vault.answer_keys', ['version_token' => $token]);

        // No row is keyed by the plain item_version_id.
        $this->assertDatabaseMissing('vault.answer_keys', ['version_token' => $versionId]);

        // The answer round-trips only through the vault.
        $this->assertSame(['correct' => ['a']], $vault->fetch($versionId));
    }

    public function test_objective_scoring_through_the_vault(): void
    {
        $this->makeTenant();
        $vault = app(AnswerKeyVault::class);

        $item = app(ItemService::class)->createItem([
            'type' => 'multiple',
            'content' => ['stem' => 'Pick the primes', 'options' => ['a' => '2', 'b' => '3', 'c' => '4', 'd' => '6']],
            'answer' => ['correct' => ['a', 'b']],
        ]);
        $vid = $item->current_version_id;

        $this->assertSame(1.0, $vault->scoreObjective($vid, 'multiple', ['b', 'a']));
        $this->assertSame(0.0, $vault->scoreObjective($vid, 'multiple', ['a']));
        $this->assertSame(0.0, $vault->scoreObjective($vid, 'multiple', ['a', 'c']));
    }

    public function test_content_rejects_smuggled_correctness_keys(): void
    {
        $this->makeTenant();

        $this->expectException(\InvalidArgumentException::class);

        app(ItemService::class)->createItem([
            'type' => 'single',
            'content' => ['stem' => 'x', 'options' => ['a' => '1'], 'correct' => ['a']], // illegal
            'answer' => ['correct' => ['a']],
        ]);
    }
}
