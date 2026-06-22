<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy & Org Structure context (docs/01 §4.2, docs/03 §1).
 *
 * `institutions` is the tenant boundary. `org_nodes` is a single self-referential
 * table modelling the whole Faculty -> Department -> Programme -> Course -> Topic ->
 * Learning Outcome hierarchy, so the depth/shape can vary per institution without
 * a table per level. A materialized `path` makes subtree queries cheap.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active'); // active|suspended|archived
            $table->string('encryption_key_ref')->nullable(); // KMS key id for per-tenant field encryption
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE institutions ADD CONSTRAINT institutions_status_chk CHECK (status IN ('active','suspended','archived'))");

        Schema::create('org_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            // Self-reference; the FK is added in a follow-up statement below because a
            // self-referential FK declared inside the same CREATE cannot see the
            // not-yet-committed primary key on Postgres.
            $table->uuid('parent_id')->nullable();
            $table->string('type'); // faculty|department|programme|course|topic|learning_outcome
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('depth')->default(0);
            $table->string('path')->index(); // e.g. "/faculty-uuid/dept-uuid/..."
            $table->timestamps();

            $table->index(['institution_id', 'type']);
            $table->index(['institution_id', 'parent_id']);
        });
        Schema::table('org_nodes', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('org_nodes')->nullOnDelete();
        });
        DB::statement("ALTER TABLE org_nodes ADD CONSTRAINT org_nodes_type_chk CHECK (type IN ('faculty','department','programme','course','topic','learning_outcome'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('org_nodes');
        Schema::dropIfExists('institutions');
    }
};
