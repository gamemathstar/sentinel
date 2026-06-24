<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Question Banks as first-class containers (docs/18).
 *
 * A Question Bank is owned by an org node (department / programme / course) and carries
 * the VISIBILITY policy; a question belongs to exactly one bank and additionally carries
 * its own course, specialization, and tags so questions can be filtered/sorted across
 * banks when assembling an assessment. Visibility is one of:
 *   - org_subtree: staff scoped to the owning org node (and below)
 *   - restricted:  only the owner + explicitly shared users / groups
 * User and group shares are additive on top of either base.
 */
return new class extends Migration {
    public function up(): void
    {
        // 'specialization' becomes a first-class org-hierarchy node type.
        DB::statement('ALTER TABLE org_nodes DROP CONSTRAINT IF EXISTS org_nodes_type_chk');
        DB::statement("ALTER TABLE org_nodes ADD CONSTRAINT org_nodes_type_chk CHECK (type IN ('faculty','department','programme','specialization','course','topic','learning_outcome'))");

        Schema::create('question_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('owner_org_node_id')->nullable()->constrained('org_nodes')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('visibility')->default('restricted'); // org_subtree | restricted
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));
            $table->timestamps();
            $table->softDeletes();

            $table->index(['institution_id', 'owner_org_node_id']);
        });
        DB::statement("ALTER TABLE question_banks ADD CONSTRAINT question_banks_visibility_chk CHECK (visibility IN ('org_subtree','restricted'))");

        Schema::create('question_bank_user_shares', function (Blueprint $table) {
            $table->foreignUuid('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->primary(['question_bank_id', 'user_id']);
        });

        Schema::create('staff_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['institution_id', 'name']);
        });

        Schema::create('staff_group_members', function (Blueprint $table) {
            $table->foreignUuid('staff_group_id')->constrained('staff_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['staff_group_id', 'user_id']);
        });

        Schema::create('question_bank_group_shares', function (Blueprint $table) {
            $table->foreignUuid('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->foreignUuid('staff_group_id')->constrained('staff_groups')->cascadeOnDelete();
            $table->boolean('can_edit')->default(false);
            $table->primary(['question_bank_id', 'staff_group_id']);
        });

        // Questions belong to a bank and carry their own course / specialization / tags.
        Schema::table('items', function (Blueprint $table) {
            $table->foreignUuid('question_bank_id')->nullable()->after('institution_id')->constrained('question_banks')->nullOnDelete();
            $table->foreignUuid('course_org_node_id')->nullable()->after('question_bank_id')->constrained('org_nodes')->nullOnDelete();
            $table->foreignUuid('specialization_org_node_id')->nullable()->after('course_org_node_id')->constrained('org_nodes')->nullOnDelete();
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"))->after('specialization_org_node_id');

            $table->index('question_bank_id');
            $table->index('course_org_node_id');
            $table->index('specialization_org_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('question_bank_id');
            $table->dropConstrainedForeignId('course_org_node_id');
            $table->dropConstrainedForeignId('specialization_org_node_id');
            $table->dropColumn('tags');
        });
        Schema::dropIfExists('question_bank_group_shares');
        Schema::dropIfExists('staff_group_members');
        Schema::dropIfExists('staff_groups');
        Schema::dropIfExists('question_bank_user_shares');
        Schema::dropIfExists('question_banks');
        // org_nodes_type_chk intentionally left with the extended set.
    }
};
