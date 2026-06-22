<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proctoring context (docs/01 §4.7, docs/03 §6, docs/05).
 *
 * Every risk_assessment is EXPLAINABLE: its timeline references the specific flags
 * (and their evidence) that contributed, so a reviewer/appeals board can audit why a
 * candidate was scored risky. A high score routes to human review; it never auto-voids.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('proctoring_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sitting_id')->constrained('sittings')->cascadeOnDelete();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('mode')->default('none'); // live|record_review|ai_only|none
            $table->boolean('lockdown_active')->default(false);
            // face/voice/id match results as scores, never raw biometrics (docs/05 §5).
            $table->jsonb('identity_verification')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->unique('sitting_id');
            $table->index('institution_id');
        });
        DB::statement("ALTER TABLE proctoring_sessions ADD CONSTRAINT proctoring_sessions_mode_chk CHECK (mode IN ('live','record_review','ai_only','none'))");

        Schema::create('evidence_clips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proctoring_session_id')->constrained('proctoring_sessions')->cascadeOnDelete();
            $table->string('s3_key'); // encrypted media
            $table->string('kind'); // video|audio|screenshot|screen
            $table->timestamp('from_ts')->nullable();
            $table->timestamp('to_ts')->nullable();
            $table->timestamps();

            $table->index('proctoring_session_id');
        });

        Schema::create('proctoring_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proctoring_session_id')->constrained('proctoring_sessions')->cascadeOnDelete();
            $table->string('type'); // multiple_faces|face_absent|phone_detected|tab_switch|vm_detected|...
            $table->decimal('confidence', 5, 4)->default(0);
            $table->timestamp('occurred_at');
            $table->foreignUuid('evidence_clip_id')->nullable()->constrained('evidence_clips')->nullOnDelete();
            $table->string('source')->default('client'); // client|edge|server_inference
            $table->timestamps();

            $table->index(['proctoring_session_id', 'occurred_at']);
            $table->index('type');
        });
        DB::statement("ALTER TABLE proctoring_flags ADD CONSTRAINT proctoring_flags_source_chk CHECK (source IN ('client','edge','server_inference'))");

        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('proctoring_session_id')->constrained('proctoring_sessions')->cascadeOnDelete();
            $table->decimal('cheating_probability', 5, 4)->default(0); // 0..1, calibrated
            $table->decimal('suspicion_score', 8, 4)->default(0);
            // explainable: contributing flags + timestamps + evidence links (docs/05 §7)
            $table->jsonb('timeline')->default(DB::raw("'[]'::jsonb"));
            $table->string('status')->default('auto'); // auto|reviewed|cleared|upheld
            $table->timestamps();

            $table->unique('proctoring_session_id');
        });
        DB::statement("ALTER TABLE risk_assessments ADD CONSTRAINT risk_assessments_status_chk CHECK (status IN ('auto','reviewed','cleared','upheld'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('proctoring_flags');
        Schema::dropIfExists('evidence_clips');
        Schema::dropIfExists('proctoring_sessions');
    }
};
