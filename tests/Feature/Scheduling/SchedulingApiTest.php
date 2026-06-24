<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Models\CandidateSchedule;
use App\Modules\Scheduling\Models\ExamSession;
use App\Modules\Scheduling\Models\StudentEnrollment;
use App\Modules\Scheduling\Models\Venue;
use App\Modules\Tenancy\Models\Institution;
use App\Modules\Tenancy\Services\OrgNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Student scheduling: selection, auto-mapping across venues × times, manual assign, release. */
class SchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionRbac();
    }

    /** @return array{inst: Institution, officer: User, programme: object, students: array<int, User>} */
    private function seedCohort(int $count, string $level = '100'): array
    {
        $inst = Institution::create(['name' => 'Sch U', 'slug' => 'sch-u-'.Str::random(5), 'status' => 'active']);
        $this->actingForTenant($inst);
        $officer = $this->makeUser($inst);
        $this->grantRole($officer, 'exam_officer');

        $org = app(OrgNodeService::class);
        $faculty = $org->create($inst->id, 'faculty', 'Engineering');
        $dept = $org->create($inst->id, 'department', 'Computing', $faculty);
        $programme = $org->create($inst->id, 'programme', 'Computer Science', $dept);

        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $student = $this->makeUser($inst);
            $this->grantRole($student, 'student');
            StudentEnrollment::create([
                'user_id' => $student->id,
                'programme_org_node_id' => $programme->id,
                'level' => $level,
                'status' => 'active',
            ]);
            $students[] = $student;
        }

        return ['inst' => $inst, 'officer' => $officer, 'faculty' => $faculty, 'programme' => $programme, 'students' => $students];
    }

    public function test_selection_resolves_students_by_faculty_subtree(): void
    {
        ['officer' => $officer, 'faculty' => $faculty] = $this->seedCohort(4);

        $this->postJson('/api/scheduling/selection/preview', [
            'scope' => 'nodes', 'org_node_ids' => [$faculty->id],
        ], $this->authHeaders($officer))
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('groups.0.programme', 'Computer Science')
            ->assertJsonPath('groups.0.count', 4);
    }

    public function test_auto_map_distributes_across_sessions_then_releases_sittings(): void
    {
        ['inst' => $inst, 'officer' => $officer, 'faculty' => $faculty] = $this->seedCohort(5);
        $headers = $this->authHeaders($officer);

        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        $venue = Venue::create(['name' => 'Hall B', 'capacity' => 2]);

        // 5 students, capacity 2 per session, 2 start-times in one venue = 2 sessions, 4 seats.
        $res = $this->postJson("/api/scheduling/assessments/{$assessment->id}/auto-map", [
            'selection' => ['scope' => 'nodes', 'org_node_ids' => [$faculty->id]],
            'venues' => [['venue_id' => $venue->id, 'capacity' => 2]],
            'start_times' => ['2026-07-01T09:00:00Z', '2026-07-01T11:00:00Z'],
            'duration_minutes' => 60,
        ], $headers)->assertCreated()->json();

        $this->assertSame(2, $res['sessions_created']);
        $this->assertSame(4, $res['scheduled']);
        $this->assertSame(1, $res['unscheduled']); // 5 students, only 4 seats

        $this->assertSame(2, ExamSession::where('assessment_id', $assessment->id)->count());
        $this->assertSame(4, CandidateSchedule::where('assessment_id', $assessment->id)->count());

        // Release turns scheduled records into live Delivery sittings.
        $this->postJson("/api/scheduling/assessments/{$assessment->id}/release", [], $headers)
            ->assertOk()->assertJsonPath('released', 4);

        $this->assertSame(4, CandidateSchedule::where('assessment_id', $assessment->id)
            ->whereNotNull('sitting_id')->where('status', 'released')->count());
    }

    public function test_manual_session_assign_respects_capacity(): void
    {
        ['officer' => $officer, 'students' => $students] = $this->seedCohort(3);
        $headers = $this->authHeaders($officer);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        $venue = Venue::create(['name' => 'Room 1', 'capacity' => 2]);
        $session = $this->postJson("/api/scheduling/assessments/{$assessment->id}/sessions", [
            'venue_id' => $venue->id, 'starts_at' => '2026-07-01T09:00:00Z', 'duration_minutes' => 90,
        ], $headers)->assertCreated()->json();

        // Two fit.
        $this->postJson("/api/scheduling/sessions/{$session['id']}/candidates", [
            'candidate_ids' => [$students[0]->id, $students[1]->id],
        ], $headers)->assertCreated()->assertJsonPath('scheduled', 2);

        // The third overflows capacity → 422.
        $this->postJson("/api/scheduling/sessions/{$session['id']}/candidates", [
            'candidate_ids' => [$students[2]->id],
        ], $headers)->assertStatus(422);
    }

    public function test_student_cannot_manage_schedule(): void
    {
        ['students' => $students] = $this->seedCohort(1);
        ['assessment' => $assessment] = $this->publishSimpleAssessment(1);

        $this->postJson("/api/scheduling/assessments/{$assessment->id}/auto-map", [
            'selection' => ['scope' => 'all'], 'venues' => [['venue_id' => Str::uuid()->toString()]],
            'start_times' => ['2026-07-01T09:00:00Z'], 'duration_minutes' => 60,
        ], $this->authHeaders($students[0]))->assertStatus(403);
    }
}
