<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grading tasks need to point at the specific item version being graded so a grader can
 * see the question and the candidate's answer. Added separately to keep the original
 * scoring/grading migration as an immutable record of the initial schema.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('grading_tasks', function (Blueprint $table) {
            $table->uuid('item_version_id')->nullable()->after('response_id');
            // The reconciled final mark for the item (set when the task reconciles).
            $table->decimal('final_mark', 12, 4)->nullable()->after('item_version_id');
            $table->index('item_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('grading_tasks', function (Blueprint $table) {
            $table->dropColumn(['item_version_id', 'final_mark']);
        });
    }
};
