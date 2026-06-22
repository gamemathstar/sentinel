<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Import\ImportManager;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Services\AnswerKeyVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The import engine: native Legion format, Aiken, GIFT, and duplicate detection. */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_legion_native_format_imports_with_answers_in_the_vault(): void
    {
        $this->makeTenant();

        $raw = <<<'TXT'
        ?? What is the capital of Nigeria? {Easy}
        ** Lagos
        ** Abuja ==
        ** Kano

        ?? Select the prime numbers {Hard}
        ** 2 ==
        ** 4
        ** 5 ==
        TXT;

        $summary = app(ImportManager::class)->import($raw, 'legion');

        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['errors']);

        $single = Item::where('type', 'single')->firstOrFail();
        $multiple = Item::where('type', 'multiple')->firstOrFail();
        $vault = app(AnswerKeyVault::class);

        // Answers landed in the vault, not in content.
        $this->assertSame(['correct' => ['b']], $vault->fetch($single->current_version_id));
        $this->assertArrayNotHasKey('correct', $single->currentVersion->content);
        $this->assertSame(['correct' => ['a', 'c']], $vault->fetch($multiple->current_version_id));
    }

    public function test_aiken_format(): void
    {
        $this->makeTenant();

        $raw = <<<'TXT'
        What is the capital of France?
        A. London
        B. Paris
        C. Berlin
        ANSWER: B
        TXT;

        $summary = app(ImportManager::class)->import($raw, 'aiken');
        $this->assertSame(1, $summary['created']);

        $item = Item::firstOrFail();
        $this->assertSame('single', $item->type);
        $this->assertSame(['correct' => ['b']], app(AnswerKeyVault::class)->fetch($item->current_version_id));
    }

    public function test_gift_format_handles_mc_truefalse_and_shortanswer(): void
    {
        $this->makeTenant();

        $raw = <<<'TXT'
        ::Capital:: Who is buried in Grant's tomb? {~no one =Ulysses S. Grant ~Napoleon}

        The earth is flat. {FALSE}

        What gas do plants absorb? {=carbon dioxide =CO2}
        TXT;

        $summary = app(ImportManager::class)->import($raw, 'gift');
        $this->assertSame(3, $summary['created'], json_encode($summary));

        $this->assertSame(1, Item::where('type', 'single')->count());
        $this->assertSame(1, Item::where('type', 'true_false')->count());
        $this->assertSame(1, Item::where('type', 'fill_blank')->count());

        $tf = Item::where('type', 'true_false')->firstOrFail();
        $this->assertSame(['correct' => ['false']], app(AnswerKeyVault::class)->fetch($tf->current_version_id));

        $sa = Item::where('type', 'fill_blank')->firstOrFail();
        $this->assertSame(['accept' => ['carbon dioxide', 'CO2']], app(AnswerKeyVault::class)->fetch($sa->current_version_id));
    }

    public function test_duplicate_detection_within_batch_and_against_bank(): void
    {
        $this->makeTenant();
        $manager = app(ImportManager::class);

        $raw = <<<'TXT'
        ?? Repeated question?
        ** A ==
        ** B

        ?? Repeated question?
        ** A ==
        ** B
        TXT;

        // Same question twice in one batch -> imported once.
        $first = $manager->import($raw, 'legion');
        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['duplicates']);

        // Re-importing against the existing bank -> all duplicates.
        $second = app(ImportManager::class)->import($raw, 'legion');
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['duplicates']);
    }

    public function test_malformed_legion_question_is_reported_as_error_not_fatal(): void
    {
        $this->makeTenant();

        // Second question has no correct option marked.
        $raw = <<<'TXT'
        ?? Good one?
        ** A ==
        ** B

        ?? Bad one?
        ** A
        ** B
        TXT;

        $summary = app(ImportManager::class)->import($raw, 'legion');
        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['errors']);
    }
}
