<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Question Bank context (docs/01 §4.3, docs/03 §2).
 *
 * SECURITY-CRITICAL: item_versions.content holds the stem and option *texts* only,
 * with NO flag marking the correct option. The scoring truth lives in vault.answer_keys
 * (separate migration), keyed by an opaque token, never by item_version_id. See
 * docs/04-security-architecture.md §2. Adding a new question type is a data concern
 * (a new `type` value + content shape), not a migration.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stimuli', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('kind'); // passage|image|audio|video|casestudy
            $table->string('s3_key')->nullable();
            $table->jsonb('meta')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();

            $table->index(['institution_id', 'kind']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('type'); // single|multiple|matching|ordering|hotspot|essay|code|sql|numerical|...
            $table->uuid('current_version_id')->nullable(); // FK added after item_versions exists
            $table->string('status')->default('draft'); // draft|active|retired
            // Psychometric metadata, refreshed by the analytics context from finalized data.
            $table->decimal('difficulty', 5, 4)->nullable();      // facility index 0..1
            $table->decimal('discrimination', 6, 4)->nullable();  // point-biserial
            $table->smallInteger('bloom_level')->nullable();      // 1..6
            $table->integer('expected_seconds')->nullable();
            $table->decimal('default_weight', 8, 3)->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'type', 'status']);
        });
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_status_chk CHECK (status IN ('draft','active','retired'))");
        DB::statement('ALTER TABLE items ADD CONSTRAINT items_bloom_chk CHECK (bloom_level IS NULL OR bloom_level BETWEEN 1 AND 6)');

        Schema::create('item_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->integer('version_no');
            $table->foreignUuid('stimulus_id')->nullable()->constrained('stimuli')->nullOnDelete();
            // Stem + option texts/pairs/blanks. NO correctness markers live here.
            $table->jsonb('content');
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state')->default('draft'); // draft|reviewed|moderated|approved|retired
            $table->string('content_hash')->index(); // duplicate detection
            $table->timestamps();

            $table->unique(['item_id', 'version_no']);
        });
        DB::statement("ALTER TABLE item_versions ADD CONSTRAINT item_versions_state_chk CHECK (state IN ('draft','reviewed','moderated','approved','retired'))");

        // Wire items.current_version_id now that the target table exists.
        Schema::table('items', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('item_versions')->nullOnDelete();
        });

        // Many-to-many tagging of items to org nodes (topics, learning outcomes, competencies).
        Schema::create('item_org_node', function (Blueprint $table) {
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('org_node_id')->constrained('org_nodes')->cascadeOnDelete();
            $table->primary(['item_id', 'org_node_id']);
        });

        Schema::create('item_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_version_id')->constrained('item_versions')->cascadeOnDelete();
            $table->foreignUuid('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision'); // approve|reject|revise
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('item_version_id');
        });
        DB::statement("ALTER TABLE item_reviews ADD CONSTRAINT item_reviews_decision_chk CHECK (decision IN ('approve','reject','revise'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('item_reviews');
        Schema::dropIfExists('item_org_node');
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('item_versions');
        Schema::dropIfExists('items');
        Schema::dropIfExists('stimuli');
    }
};
