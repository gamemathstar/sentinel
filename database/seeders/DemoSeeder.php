<?php

namespace Database\Seeders;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Authoring\Models\Assessment;
use App\Modules\Authoring\Models\ProctoringPolicy;
use App\Modules\Authoring\Services\AssessmentService;
use App\Modules\Authoring\Services\BlueprintService;
use App\Modules\Authoring\Services\ScoringRuleService;
use App\Modules\Certification\Models\Certificate;
use App\Modules\Delivery\Models\GradingTask;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Delivery\Services\GradingService;
use App\Modules\Delivery\Services\ResponseRecorder;
use App\Modules\Delivery\Services\ScoringService;
use App\Modules\Delivery\Services\SittingService;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\RoleAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RbacProvisioner;
use App\Modules\Proctoring\Models\ProctoringSession;
use App\Modules\Proctoring\Services\ProctoringService;
use App\Modules\QuestionBank\Import\ImportManager;
use App\Modules\QuestionBank\Models\Item;
use App\Modules\QuestionBank\Services\AnswerKeyVault;
use App\Modules\QuestionBank\Services\ItemService;
use App\Modules\QuestionBank\Services\QuestionBankService;
use App\Modules\QuestionBank\Services\ReviewService;
use App\Modules\Scheduling\Models\StudentEnrollment;
use App\Modules\Scheduling\Models\Venue;
use App\Modules\Scheduling\Services\AutoMapper;
use App\Modules\Tenancy\Models\Institution;
use App\Modules\Tenancy\Services\OrgNodeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one institution through a complete assessment lifecycle so the whole platform is
 * explorable with a single command. It drives the REAL domain services (not raw inserts),
 * so events fire and proctoring sessions, scores, certificates, and analytics are produced
 * exactly as in production.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const ADMIN_EMAIL = 'admin@demo.legion.test';

    public function run(): void
    {
        if (User::where('email', self::ADMIN_EMAIL)->exists()) {
            $this->command->warn('Demo data already present — skipping. Run `php artisan migrate:fresh --seed` to rebuild.');

            return;
        }

        app(RbacProvisioner::class)->provision();

        $institution = Institution::create(['name' => 'Demo University', 'slug' => 'demo-university', 'status' => 'active']);
        $users = $this->seedUsers($institution);

        // Act as the institution within the author's identity (stamps item authorship).
        app(TenantContext::class)->set($institution->id, $users['author']->id);

        $org = $this->seedOrg($institution);
        $items = $this->seedQuestionBank($users, $org);
        $assessment = $this->seedAssessment($institution, $items);

        $this->seedScheduling($institution, $assessment, $org, $users);

        $sittings = $this->seedSittings($assessment, $users['candidates']);
        $this->seedProctoring($sittings[0]);
        $this->seedGrading($sittings, $users['graders']);
        app(AnalyticsService::class)->compileAssessment($assessment->fresh());

        $this->printSummary($institution, $users, $assessment, $sittings);
    }

    private function seedUsers(Institution $institution): array
    {
        $make = function (string $email, string $name, string $role) use ($institution) {
            $user = User::create([
                'institution_id' => $institution->id,
                'email' => $email,
                'full_name' => $name,
                'password_hash' => Hash::make(self::PASSWORD),
                'status' => 'active',
            ]);
            RoleAssignment::create([
                'user_id' => $user->id,
                'role_id' => Role::whereNull('institution_id')->where('name', $role)->value('id'),
                'institution_id' => $institution->id,
            ]);

            return $user;
        };

        $candidates = [];
        foreach (['ada@demo.legion.test', 'bode@demo.legion.test', 'chidi@demo.legion.test', 'dela@demo.legion.test'] as $i => $email) {
            $candidates[] = $make($email, 'Candidate '.($i + 1), 'student');
        }

        return [
            'admin' => $make(self::ADMIN_EMAIL, 'Institution Admin', 'institution_admin'),
            'officer' => $make('officer@demo.legion.test', 'Exam Officer', 'exam_officer'),
            'author' => $make('author@demo.legion.test', 'Question Author', 'question_author'),
            'reviewer' => $make('reviewer@demo.legion.test', 'Question Reviewer', 'question_reviewer'),
            'moderator' => $make('moderator@demo.legion.test', 'Question Moderator', 'question_reviewer'),
            'approver' => $make('approver@demo.legion.test', 'Question Approver', 'question_approver'),
            'graders' => [
                $make('grader1@demo.legion.test', 'Grader One', 'grader'),
                $make('grader2@demo.legion.test', 'Grader Two', 'grader'),
            ],
            'proctor' => $make('proctor@demo.legion.test', 'Proctor', 'proctor'),
            'candidates' => $candidates,
        ];
    }

    private function seedOrg(Institution $institution): array
    {
        $svc = app(OrgNodeService::class);
        $faculty = $svc->create($institution->id, 'faculty', 'Faculty of Science');
        $dept = $svc->create($institution->id, 'department', 'Computer Science', $faculty);
        $programme = $svc->create($institution->id, 'programme', 'B.Sc. Computer Science', $dept);
        $specialization = $svc->create($institution->id, 'specialization', 'Systems & Architecture', $dept);
        $course = $svc->create($institution->id, 'course', 'CSC101 — Intro to Computing', $dept);

        return compact('faculty', 'dept', 'programme', 'specialization', 'course');
    }

    /**
     * Enroll the demo candidates into the programme, create venues, and auto-map them into
     * timed/venued sessions for the assessment — so the Scheduling screens show live data.
     * Sittings are seeded separately, so this leaves schedules unreleased (sitting_id null).
     */
    private function seedScheduling(Institution $institution, $assessment, array $org, array $users): void
    {
        foreach ($users['candidates'] as $i => $candidate) {
            StudentEnrollment::create([
                'user_id' => $candidate->id,
                'programme_org_node_id' => $org['programme']->id,
                'level' => $i < 2 ? '100' : '200',
                'entry_year' => '2025',
                'status' => 'active',
            ]);
        }

        $hall = Venue::create(['name' => 'Main Hall A', 'code' => 'MHA', 'location' => 'Block A', 'capacity' => 3]);
        Venue::create(['name' => 'Computer Lab B', 'code' => 'CLB', 'location' => 'ICT Wing', 'capacity' => 80]);

        // department subtree → both levels, 2 start-times in one venue of capacity 3 = 6 seats for 4 students.
        app(AutoMapper::class)->map($assessment, [
            'selection' => ['scope' => 'nodes', 'org_node_ids' => [$org['dept']->id]],
            'venues' => [['venue_id' => $hall->id, 'capacity' => 3]],
            'start_times' => ['2026-07-01T09:00:00Z', '2026-07-01T11:00:00Z'],
            'duration_minutes' => 60,
        ]);
    }

    /** Create a department-visible bank, fill it with approved items, and demo a shared group. */
    private function seedQuestionBank(array $users, array $org): array
    {
        $items = app(ItemService::class);
        $bankSvc = app(QuestionBankService::class);
        $created = [];

        // A department-owned bank, visible to staff scoped within Computer Science.
        $bank = $bankSvc->create('CSC101 Question Bank', $org['course']->id, 'org_subtree');

        // Demonstrate a staff group + a read-only share to an external reviewer.
        $group = $bankSvc->createGroup('CSC Examiners');
        $bankSvc->addGroupMember($group, $users['reviewer']->id);
        $bankSvc->shareWithUser($bank, $users['officer']->id, canEdit: true);

        $questions = [
            ['What does CPU stand for?', ['a' => 'Central Process Unit', 'b' => 'Central Processing Unit', 'c' => 'Computer Personal Unit', 'd' => 'Central Processor Underflow']],
            ['Which is a programming language?', ['a' => 'HTTP', 'b' => 'Python', 'c' => 'HTML5', 'd' => 'TCP']],
            ['What is 2 + 2 × 3?', ['a' => '12', 'b' => '8', 'c' => '10', 'd' => '6']],
            ['Binary of decimal 5?', ['a' => '100', 'b' => '101', 'c' => '110', 'd' => '111']],
            ['Which sorts fastest on average?', ['a' => 'Bubble sort', 'b' => 'Quicksort', 'c' => 'Insertion sort', 'd' => 'Selection sort']],
            ['SQL keyword to retrieve data?', ['a' => 'GET', 'b' => 'SELECT', 'c' => 'FETCH', 'd' => 'PULL']],
            ['A byte is how many bits?', ['a' => '4', 'b' => '8', 'c' => '16', 'd' => '32']],
            ['Which is volatile memory?', ['a' => 'SSD', 'b' => 'RAM', 'c' => 'HDD', 'd' => 'ROM']],
        ];

        foreach ($questions as $q) {
            $item = $items->createItem([
                'type' => 'single',
                'question_bank_id' => $bank->id,
                'course_org_node_id' => $org['course']->id,
                'specialization_org_node_id' => $org['specialization']->id,
                'tags' => ['fundamentals'],
                'content' => ['stem' => $q[0], 'options' => $q[1]],
                'answer' => ['correct' => ['b']], // 'b' is the correct option in each above
                'metadata' => ['bloom_level' => 2, 'expected_seconds' => 45],
                'org_node_ids' => [$org['course']->id],
            ]);
            $this->approve($item, $users);
            $created['single'][] = $item->fresh();
        }

        $essay = $items->createItem([
            'type' => 'essay',
            'question_bank_id' => $bank->id,
            'course_org_node_id' => $org['course']->id,
            'specialization_org_node_id' => $org['specialization']->id,
            'tags' => ['memory', 'concepts'],
            'content' => ['stem' => 'Explain, in your own words, the difference between RAM and ROM.'],
            'metadata' => ['bloom_level' => 4, 'expected_seconds' => 300],
            'org_node_ids' => [$org['course']->id],
        ]);
        $this->approve($essay, $users);
        $created['essay'] = $essay->fresh();

        // Demonstrate the import engine too (these go into the bank but not the demo exam).
        app(ImportManager::class)->import(
            "?? Capital of Nigeria?\n** Lagos\n** Abuja ==\n** Kano",
            'legion'
        );

        return $created;
    }

    /** Run an item version through reviewer -> moderator -> approver (distinct subjects). */
    private function approve(Item $item, array $users): void
    {
        $reviews = app(ReviewService::class);
        $version = $item->currentVersion;
        $reviews->submitReview($version, $users['reviewer']->id, 'approve');   // draft -> reviewed
        $reviews->submitReview($version->fresh(), $users['moderator']->id, 'approve'); // -> moderated
        $reviews->submitReview($version->fresh(), $users['approver']->id, 'approve');  // -> approved (item active)
    }

    private function seedAssessment(Institution $institution, array $items): Assessment
    {
        $rule = app(ScoringRuleService::class)->create('Standard +1/-0', ['correct' => 1, 'wrong' => 0, 'blank' => 0]);
        $blueprint = app(BlueprintService::class)->create('CSC101 Final', ['total' => 5, 'types' => ['single']]);

        $proctoringPolicy = ProctoringPolicy::create([
            'institution_id' => $institution->id,
            'name' => 'AI proctoring + lockdown',
            'mode' => 'ai_only',
            'lockdown_required' => true,
            'signals' => ['review_threshold' => 0.6],
        ]);

        $svc = app(AssessmentService::class);
        $assessment = $svc->create([
            'title' => 'CSC101 Final Examination',
            'kind' => 'final',
            'scoring_rule_id' => $rule->id,
            'blueprint_id' => $blueprint->id,
            'proctoring_policy_id' => $proctoringPolicy->id,
            'duration_seconds' => 3600,
        ]);

        $objectiveSection = $svc->addSection($assessment, 'Section A — Objective');
        $svc->assembleSectionFromBlueprint($objectiveSection, $blueprint);

        $essaySection = $svc->addSection($assessment, 'Section B — Essay');
        $svc->pinItemVersions($essaySection, [$items['essay']->current_version_id]);

        $svc->publish($assessment->fresh());

        return $assessment->fresh();
    }

    /** @return Sitting[] */
    private function seedSittings($assessment, array $candidates): array
    {
        // Vary how many objective items each candidate gets right, for meaningful analytics.
        $correctCounts = [5, 4, 2, 1];
        $sittings = [];
        foreach ($candidates as $i => $candidate) {
            $sittings[] = $this->sit($assessment, $candidate, $correctCounts[$i] ?? 3);
        }

        return $sittings;
    }

    private function sit($assessment, User $candidate, int $correctObjective): Sitting
    {
        $sittings = app(SittingService::class);
        $recorder = app(ResponseRecorder::class);
        $vault = app(AnswerKeyVault::class);

        $sitting = $sittings->assign($assessment, $candidate);
        $sittings->start($sitting); // SittingStarted -> proctoring session auto-opens

        $objIndex = 0;
        foreach ($sitting->manifest->manifest['items'] as $entry) {
            $iv = $entry['iv'];
            $order = $entry['options'];

            if ($order === []) { // the essay (no options)
                $recorder->record($sitting, $iv, ['text' => 'RAM is volatile working memory that loses its contents on power-off, while ROM is non-volatile and retains firmware.']);

                continue;
            }

            $correctKey = $vault->fetch($iv)['correct'][0] ?? 'b';
            $correctIdx = (int) array_search($correctKey, $order, true);
            $idx = $objIndex < $correctObjective ? $correctIdx : ($correctIdx === 0 ? 1 : 0);
            $recorder->record($sitting, $iv, ['selected' => [$idx]]);
            $objIndex++;
        }

        app(ScoringService::class)->submit($sitting->fresh()); // score -> under_review (essay pending)

        return $sitting->fresh();
    }

    private function seedProctoring(Sitting $suspect): void
    {
        $session = ProctoringSession::where('sitting_id', $suspect->id)->first();
        if (! $session) {
            return;
        }
        $proctoring = app(ProctoringService::class);
        $proctoring->recordFlag($session, 'phone_detected', 0.95, 'server_inference');
        $proctoring->recordFlag($session, 'face_absent', 0.8);
        $proctoring->recordFlag($session, 'tab_switch', 1.0, 'client');
        $proctoring->assess($session); // explainable risk -> review queue
    }

    /** Mark every essay grading task with two agreeing graders -> reconciled -> score final -> cert. */
    private function seedGrading(array $sittings, array $graders): void
    {
        $grading = app(GradingService::class);
        foreach ($sittings as $sitting) {
            $task = GradingTask::where('sitting_id', $sitting->id)->first();
            if (! $task) {
                continue;
            }
            $grading->submitMark($task, $graders[0]->id, 7);
            $grading->submitMark($task->fresh(), $graders[1]->id, 7); // agree -> reconcile -> final -> ScoreFinalized -> cert
        }
    }

    private function printSummary(Institution $institution, array $users, $assessment, array $sittings): void
    {
        $cert = Certificate::where('assessment_id', $assessment->id)->first();
        $c = $this->command;

        $c->info('');
        $c->info('========================================================');
        $c->info('  Legion CBT — demo data seeded');
        $c->info('========================================================');
        $c->info("Institution : {$institution->name} ({$institution->slug})");
        $c->info('Password for ALL demo users: '.self::PASSWORD);
        $c->info('');
        $c->table(['Role', 'Email'], [
            ['Institution Admin', $users['admin']->email],
            ['Exam Officer (assign/grade-reconcile/QA/proctor-review)', $users['officer']->email],
            ['Author / Reviewer / Approver', 'author@ / reviewer@ / approver@demo.legion.test'],
            ['Graders', 'grader1@ / grader2@demo.legion.test'],
            ['Proctor', $users['proctor']->email],
            ['Candidates', 'ada@ / bode@ / chidi@ / dela@demo.legion.test'],
        ]);
        $c->info('Items in bank: '.Item::count().'  |  Sittings: '.count($sittings).'  |  Certificates issued: '.Certificate::count());
        $c->info("Assessment id : {$assessment->id}");
        if ($cert) {
            $c->info("Sample certificate serial: {$cert->serial}");
            $c->info('');
            $c->info('Try the PUBLIC verification portal (no auth):');
            $c->info("  curl http://localhost:8000/api/certification/verify/{$cert->verification_token}");
        }
        $c->info('');
        $c->info('Log in to get a token:');
        $c->info('  curl -X POST http://localhost:8000/api/auth/login \\');
        $c->info('    -H "Content-Type: application/json" \\');
        $c->info('    -d \'{"email":"'.$users['officer']->email.'","password":"'.self::PASSWORD.'"}\'');
        $c->info('Then call e.g.  GET /api/analytics/assessments/'.$assessment->id.'/reliability  with the bearer token.');
        $c->info('========================================================');
    }
}
