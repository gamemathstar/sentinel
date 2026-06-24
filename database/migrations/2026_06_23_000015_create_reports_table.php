<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting context (docs/01 §4.8, docs deliverables "Reporting Engine"). A `reports` row
 * is the record of a generated artifact: its type, format, the parameters it was run with,
 * status, and where the rendered file lives. Generation reads from analytics/result read
 * models and never blocks the transactional write path (docs/02 §5).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');   // results | item_quality | assessment_summary | risk
            $table->string('format'); // csv | xlsx | pdf
            $table->string('status')->default('pending'); // pending|completed|failed
            $table->jsonb('params')->default(DB::raw("'{}'::jsonb"));
            $table->string('title')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();        // artifact location on the disk
            $table->integer('rows')->nullable();        // row count, for quick display
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['institution_id', 'type']);
        });
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_chk CHECK (status IN ('pending','completed','failed'))");
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_format_chk CHECK (format IN ('csv','xlsx','pdf'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
