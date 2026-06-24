<?php

namespace Tests\Feature\QuestionBank;

use App\Modules\QuestionBank\Models\QuestionBank;
use App\Modules\Tenancy\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** HTTP: bank create/list visibility + that authoring into an unwritable bank is denied. */
class BankApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    private function tenant(): Institution
    {
        $inst = Institution::create(['name' => 'B U', 'slug' => 'b-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);

        return $inst;
    }

    public function test_officer_creates_and_lists_banks(): void
    {
        $inst = $this->tenant();
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');
        $headers = $this->authHeaders($officer);

        $this->postJson('/api/question-bank/banks', [
            'name' => 'Physics Bank', 'visibility' => 'restricted',
        ], $headers)->assertCreated();

        $this->getJson('/api/question-bank/banks', $headers)
            ->assertOk()
            ->assertJsonFragment(['name' => 'Physics Bank']);
    }

    public function test_student_cannot_create_a_bank(): void
    {
        $inst = $this->tenant();
        $student = $this->makeUser($inst);
        $this->grantRole($student, 'student');

        $this->postJson('/api/question-bank/banks', [
            'name' => 'Nope', 'visibility' => 'restricted',
        ], $this->authHeaders($student))->assertStatus(403);
    }

    public function test_cannot_author_into_a_bank_you_cannot_edit(): void
    {
        $inst = $this->tenant();
        // A restricted bank owned by someone else.
        $owner = $this->makeUser($inst);
        $this->actingForTenant($inst, $owner);
        $bank = $this->makeBank('restricted');

        // An author with no access to that bank.
        $author = $this->makeUser($inst);
        $this->grantRole($author, 'question_author');

        $this->postJson('/api/question-bank/items', [
            'type' => 'single',
            'question_bank_id' => $bank->id,
            'content' => ['stem' => 'x', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ], $this->authHeaders($author))->assertStatus(403);
    }

    public function test_author_can_create_an_item_in_their_own_bank(): void
    {
        $inst = $this->tenant();
        $author = $this->makeUser($inst);
        $this->grantRole($author, 'question_author');
        $this->actingForTenant($inst, $author);
        $bank = $this->makeBank('restricted'); // created_by = author

        $this->postJson('/api/question-bank/items', [
            'type' => 'single',
            'question_bank_id' => $bank->id,
            'content' => ['stem' => 'Owned?', 'options' => ['a' => '1', 'b' => '2']],
            'answer' => ['correct' => ['a']],
        ], $this->authHeaders($author))->assertCreated();

        $this->assertSame(1, QuestionBank::find($bank->id)->items()->count());
    }
}
