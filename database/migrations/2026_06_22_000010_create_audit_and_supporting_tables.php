<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit & Compliance, Certification, Analytics read-models, Notifications
 * (docs/01 §4.8, docs/03 §7, docs/04 §7).
 *
 * audit_entries is APPEND-ONLY and HASH-CHAINED: entry_hash = SHA-256(prev_hash ||
 * canonical(payload)). The application DB role is later granted INSERT only (UPDATE/
 * DELETE revoked) so historical rows cannot be silently altered; the chain head is
 * periodically anchored externally. It is range-partitioned by occurred_at.
 */
return new class extends Migration {
    public function up(): void
    {
        // Append-only, partitioned audit log (raw SQL for partitioning + hash chain).
        DB::statement(<<<'SQL'
            CREATE TABLE audit_entries (
                id           uuid NOT NULL DEFAULT gen_random_uuid(),
                institution_id uuid,                        -- nullable for platform-level actions
                actor_id     uuid,
                action       text NOT NULL,                 -- item.create | exam.access | score.publish | ...
                subject_type text,
                subject_id   uuid,
                metadata     jsonb NOT NULL DEFAULT '{}'::jsonb,
                correlation_id uuid,
                prev_hash    text,                          -- previous entry's hash (per institution chain)
                entry_hash   text NOT NULL,                 -- = SHA-256(prev_hash || canonical(payload))
                occurred_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, occurred_at)
            ) PARTITION BY RANGE (occurred_at)
        SQL);
        DB::statement('CREATE INDEX audit_entries_inst_time_idx ON audit_entries (institution_id, occurred_at)');
        DB::statement('CREATE INDEX audit_entries_subject_idx ON audit_entries (subject_type, subject_id)');
        DB::statement("CREATE TABLE IF NOT EXISTS audit_entries_2026_06 PARTITION OF audit_entries FOR VALUES FROM ('2026-06-01') TO ('2026-07-01')");
        DB::statement("CREATE TABLE IF NOT EXISTS audit_entries_2026_07 PARTITION OF audit_entries FOR VALUES FROM ('2026-07-01') TO ('2026-08-01')");
        DB::statement('CREATE TABLE IF NOT EXISTS audit_entries_default PARTITION OF audit_entries DEFAULT');

        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('candidate_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('serial')->unique();
            $table->string('verification_token')->unique(); // public-portal verification, no DB trust needed
            $table->string('anchor_txid')->nullable();       // optional blockchain anchor of cert hash
            $table->string('s3_key')->nullable();            // rendered PDF / badge
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'candidate_id']);
        });

        Schema::create('item_statistics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->integer('sample_n')->default(0);
            $table->decimal('facility_index', 5, 4)->nullable();
            $table->decimal('discrimination_index', 6, 4)->nullable();
            $table->jsonb('distractor_analysis')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('irt_params')->default(DB::raw("'{}'::jsonb")); // a, b, c
            $table->timestamps();

            $table->unique('item_id');
        });

        Schema::create('assessment_reliability', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->decimal('kr20', 6, 4)->nullable();
            $table->decimal('cronbach_alpha', 6, 4)->nullable();
            $table->decimal('sem', 10, 4)->nullable(); // standard error of measurement
            $table->timestamps();

            $table->unique('assessment_id');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel'); // email|sms|push|whatsapp
            $table->string('event_key');
            $table->string('status')->default('queued'); // queued|sent|failed
            $table->string('dedupe_key')->unique(); // idempotency per (recipient,event)
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'status']);
        });
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_chk CHECK (channel IN ('email','sms','push','whatsapp'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('assessment_reliability');
        Schema::dropIfExists('item_statistics');
        Schema::dropIfExists('certificates');
        DB::statement('DROP TABLE IF EXISTS audit_entries CASCADE');
    }
};
