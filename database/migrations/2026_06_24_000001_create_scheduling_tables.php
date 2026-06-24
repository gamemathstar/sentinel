<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling & Timetabling context.
 *
 * Students are scheduled into timed, venued sessions of an assessment. A selection
 * (faculty / department / programme subtree + optional level filter, resolved against
 * `student_enrollments`) drives both manual assignment and automatic mapping. One
 * assessment can have MANY sessions — e.g. 09:00 Venue A and 09:00 Venue B run the same
 * paper in parallel — so candidates are distributed across (venue × start-time) slots up
 * to each slot's capacity. A candidate_schedule is the per-student record; it materializes
 * into a Delivery sitting at release time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Physical exam locations with a seating capacity.
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('location')->nullable();
            $table->integer('capacity')->default(0);
            $table->string('status')->default('active'); // active|inactive
            $table->timestamps();

            $table->index(['institution_id', 'status']);
        });

        // A student's academic placement — the basis for selection. Faculty/department are
        // derived from the programme node's materialized path; level is an explicit attribute.
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('programme_org_node_id')->constrained('org_nodes')->cascadeOnDelete();
            $table->string('level'); // e.g. "100","200","ND1" — institution-defined
            $table->string('entry_year')->nullable();
            $table->string('status')->default('active'); // active|graduated|withdrawn
            $table->timestamps();

            $table->unique(['user_id', 'programme_org_node_id']);
            $table->index(['institution_id', 'programme_org_node_id', 'level']);
        });

        // A timed, venued session of an assessment. Many per assessment.
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignUuid('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->string('name')->nullable(); // optional label, e.g. "Morning A"
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->integer('capacity'); // effective seats for this session
            $table->string('status')->default('scheduled'); // scheduled|active|closed|cancelled
            $table->timestamps();

            $table->index(['institution_id', 'assessment_id']);
            $table->index(['institution_id', 'starts_at']);
        });

        // Invigilators assigned to a session — assignable now or later.
        Schema::create('exam_session_invigilator', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('assistant'); // chief|assistant
            $table->timestamps();

            $table->unique(['exam_session_id', 'user_id']);
        });

        // The per-candidate schedule record. One per (assessment, candidate). It links to a
        // session and, once released, to the Delivery sitting that runs the exam.
        Schema::create('candidate_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignUuid('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignUuid('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('sitting_id')->nullable()->constrained('sittings')->nullOnDelete();
            $table->integer('seat_no')->nullable();
            $table->string('source')->default('manual'); // manual|auto
            $table->string('status')->default('scheduled'); // scheduled|released|seated|cancelled
            $table->timestamps();

            $table->unique(['assessment_id', 'candidate_id']);
            $table->index(['institution_id', 'exam_session_id']);
        });

        DB::statement("ALTER TABLE exam_sessions ADD CONSTRAINT exam_sessions_status_chk CHECK (status IN ('scheduled','active','closed','cancelled'))");
        DB::statement("ALTER TABLE candidate_schedules ADD CONSTRAINT candidate_schedules_status_chk CHECK (status IN ('scheduled','released','seated','cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_schedules');
        Schema::dropIfExists('exam_session_invigilator');
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('venues');
    }
};
