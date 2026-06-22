<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment Authoring context (docs/01 §4.4, docs/03 §3).
 *
 * Sections reference PINNED item_version_id values, never item_id, so a published paper
 * is reproducible forever even after the bank changes. Blueprints, scoring rules, and
 * proctoring policies are reusable, versioned, JSONB-driven so new rules are data.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('blueprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            // e.g. {"difficulty":{"easy":0.4,"medium":0.4,"hard":0.2},"topics":{...},"bloom":{...}}
            $table->jsonb('constraints');
            $table->timestamps();
        });

        Schema::create('scoring_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            $table->integer('version')->default(1);
            // correct/wrong/blank weights, partial, negative, confidence, custom formula
            $table->jsonb('policy');
            $table->timestamps();

            $table->unique(['institution_id', 'name', 'version']);
        });

        Schema::create('proctoring_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            // which detectors enabled + thresholds + weights (docs/05 §7)
            $table->jsonb('signals')->default(DB::raw("'{}'::jsonb"));
            $table->string('mode')->default('none'); // live|record_review|ai_only|none
            $table->boolean('lockdown_required')->default(false);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE proctoring_policies ADD CONSTRAINT proctoring_policies_mode_chk CHECK (mode IN ('live','record_review','ai_only','none'))");

        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('org_node_id')->nullable()->constrained('org_nodes')->nullOnDelete();
            $table->string('title');
            $table->string('kind'); // practice|ca|midterm|final|postutme|recruitment|certification|licensing|mock
            $table->string('status')->default('draft'); // draft|published|live|closed|archived
            $table->timestamp('window_opens_at')->nullable();
            $table->timestamp('window_closes_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->boolean('is_adaptive')->default(false);
            $table->foreignUuid('blueprint_id')->nullable()->constrained('blueprints')->nullOnDelete();
            $table->foreignUuid('scoring_rule_id')->nullable()->constrained('scoring_rules')->nullOnDelete();
            $table->foreignUuid('proctoring_policy_id')->nullable()->constrained('proctoring_policies')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'status']);
            $table->index(['institution_id', 'kind']);
        });
        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_status_chk CHECK (status IN ('draft','published','live','closed','archived'))");

        Schema::create('assessment_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('title');
            $table->integer('position')->default(0);
            // fixed item set OR a blueprint-driven random draw spec
            $table->jsonb('selection')->default(DB::raw("'{}'::jsonb"));
            $table->foreignUuid('scoring_rule_id')->nullable()->constrained('scoring_rules')->nullOnDelete();
            $table->timestamps();

            $table->index('assessment_id');
        });

        Schema::create('section_item', function (Blueprint $table) {
            $table->foreignUuid('section_id')->constrained('assessment_sections')->cascadeOnDelete();
            // PINNED version for reproducibility (docs/03 §3).
            $table->foreignUuid('item_version_id')->constrained('item_versions')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->primary(['section_id', 'item_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_item');
        Schema::dropIfExists('assessment_sections');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('proctoring_policies');
        Schema::dropIfExists('scoring_rules');
        Schema::dropIfExists('blueprints');
    }
};
