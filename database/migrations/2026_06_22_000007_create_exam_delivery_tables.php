<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam Delivery context (docs/01 §4.5, docs/03 §4) — the hot path.
 *
 * `responses` is APPEND-ONLY and PARTITIONED BY RANGE on answered_at (monthly) so the
 * live partition stays small at national scale (docs/02 §3). A "correction" is a new
 * row with a higher sequence; the latest sequence per (sitting, item) wins, which makes
 * offline conflict resolution trivial (docs/02 §7). The partitioned table and its
 * partitions are created with raw SQL because Schema builder can't express partitioning.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('variant_manifests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // sitting_id FK added after sittings exists (circular reference resolved below).
            $table->uuid('sitting_id');
            // ordered item_version_ids + per-option order + numeric seeds; large ones go to S3.
            $table->jsonb('manifest')->nullable();
            $table->string('s3_key')->nullable();
            $table->timestamps();
        });

        Schema::create('sittings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->foreignUuid('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('assigned'); // assigned|in_progress|submitted|graded|voided
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            // Authoritative server deadline; the client clock is never trusted (docs/04 §8).
            $table->bigInteger('server_deadline_epoch')->nullable();
            $table->string('variant_token')->nullable();
            $table->jsonb('sync_meta')->default(DB::raw("'{}'::jsonb")); // offline checkpoint/sequence
            $table->timestamps();

            $table->index(['assessment_id', 'status']);
            $table->index('candidate_id');
            $table->index('institution_id');
            $table->unique(['assessment_id', 'candidate_id']); // one sitting per candidate per assessment
        });
        DB::statement("ALTER TABLE sittings ADD CONSTRAINT sittings_status_chk CHECK (status IN ('assigned','in_progress','submitted','graded','voided'))");

        // Now that sittings exists, link variant_manifests.sitting_id.
        Schema::table('variant_manifests', function (Blueprint $table) {
            $table->foreign('sitting_id')->references('id')->on('sittings')->cascadeOnDelete();
            $table->unique('sitting_id');
        });

        // Partitioned, append-only responses table (raw SQL: Schema builder lacks partitioning).
        DB::statement(<<<'SQL'
            CREATE TABLE responses (
                id              uuid NOT NULL DEFAULT gen_random_uuid(),
                sitting_id      uuid NOT NULL,
                item_version_id uuid NOT NULL,
                sequence        integer NOT NULL,                 -- monotonic per sitting; append-only
                answer          jsonb NOT NULL,                   -- candidate selection/text/etc
                confidence      numeric(5,4),                     -- for confidence-based scoring
                time_spent_ms   integer,
                answered_at     timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, answered_at)                     -- PK must include the partition key
            ) PARTITION BY RANGE (answered_at)
        SQL);

        // Latest-wins lookups per (sitting,item) and time-range scans.
        DB::statement('CREATE INDEX responses_sitting_item_seq_idx ON responses (sitting_id, item_version_id, sequence DESC)');
        DB::statement('CREATE INDEX responses_answered_at_idx ON responses (answered_at)');

        // Seed an initial monthly partition; a scheduled job rolls new ones forward.
        DB::statement("CREATE TABLE IF NOT EXISTS responses_2026_06 PARTITION OF responses FOR VALUES FROM ('2026-06-01') TO ('2026-07-01')");
        DB::statement("CREATE TABLE IF NOT EXISTS responses_2026_07 PARTITION OF responses FOR VALUES FROM ('2026-07-01') TO ('2026-08-01')");
        // A default partition catches anything outside provisioned ranges (safety net).
        DB::statement('CREATE TABLE IF NOT EXISTS responses_default PARTITION OF responses DEFAULT');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS responses CASCADE');
        Schema::dropIfExists('variant_manifests');
        Schema::dropIfExists('sittings');
    }
};
