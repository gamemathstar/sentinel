<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make certificates self-verifiable and revocable (docs/03 §7, docs/04):
 *  - payload: an immutable snapshot of the result, so verification does not depend on
 *    trusting the issuer's live tables;
 *  - content_hash: SHA-256 over the snapshot + serial for tamper-evidence (the value the
 *    optional blockchain anchor commits to);
 *  - revoked_at: revocation without deletion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->jsonb('payload')->nullable()->after('verification_token');
            $table->string('content_hash')->nullable()->after('payload');
            $table->timestamp('revoked_at')->nullable()->after('issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['payload', 'content_hash', 'revoked_at']);
        });
    }
};
