<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scoring & Grading context (docs/01 §4.6, docs/03 §5).
 *
 * A score always records which scoring-rule version produced it, so a result is
 * reproducible from (responses, scoring_rule@version). Open-ended items become
 * grading_tasks routed for double-marking + reconciliation; AI is just another marker
 * (is_ai=true) whose mark is advisory until a human reconciles (docs/01 §13 invariant).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sitting_id')->constrained('sittings')->cascadeOnDelete();
            $table->foreignUuid('scoring_rule_id')->nullable()->constrained('scoring_rules')->nullOnDelete();
            $table->integer('scoring_rule_version')->nullable(); // pinned for reproducibility
            $table->decimal('raw_score', 12, 4)->nullable();
            $table->decimal('scaled_score', 12, 4)->nullable();
            $table->jsonb('section_breakdown')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('competency_breakdown')->default(DB::raw("'{}'::jsonb"));
            $table->string('status')->default('provisional'); // provisional|final|under_review
            $table->timestamps();

            $table->unique('sitting_id');
        });
        DB::statement("ALTER TABLE scores ADD CONSTRAINT scores_status_chk CHECK (status IN ('provisional','final','under_review'))");

        Schema::create('grading_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sitting_id')->constrained('sittings')->cascadeOnDelete();
            // response_id is logical (responses is partitioned, so no hard FK); stored for routing.
            $table->uuid('response_id');
            $table->string('type'); // essay|short_answer|code
            $table->string('status')->default('pending'); // pending|in_progress|double_marking|reconciled
            $table->uuid('ai_suggestion_id')->nullable();
            $table->timestamps();

            $table->index(['sitting_id', 'status']);
        });
        DB::statement("ALTER TABLE grading_tasks ADD CONSTRAINT grading_tasks_status_chk CHECK (status IN ('pending','in_progress','double_marking','reconciled'))");

        Schema::create('grading_marks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grading_task_id')->constrained('grading_tasks')->cascadeOnDelete();
            $table->foreignUuid('grader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('mark', 12, 4);
            $table->jsonb('rubric_breakdown')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('is_ai')->default(false); // AI marks are advisory until reconciled
            $table->timestamps();

            $table->index('grading_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_marks');
        Schema::dropIfExists('grading_tasks');
        Schema::dropIfExists('scores');
    }
};
