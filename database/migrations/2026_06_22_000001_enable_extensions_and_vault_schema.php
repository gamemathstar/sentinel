<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Foundational migration.
 *
 * - pgcrypto / uuid-ossp give us gen_random_uuid() for UUID primary keys.
 * - The `vault` schema is the physically separate home of answer keys
 *   (see docs/04-security-architecture.md §2). The application database role
 *   is granted NO access to it by default; only the scoring/vault workload
 *   identity gets a grant. Storing answer keys in their own schema is the
 *   first layer of the "DB dump does not reveal answers" guarantee.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');
        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        // Isolated schema for scoring truth. Kept separate from `public` so the
        // app role can be denied access independently of the main tables.
        DB::statement('CREATE SCHEMA IF NOT EXISTS vault');

        // In production these GRANT/REVOKE statements target named DB roles.
        // Documented here as the intent; the deploy provisioner applies them
        // against the real role names per environment.
        //   REVOKE ALL ON SCHEMA vault FROM app_role;
        //   GRANT USAGE ON SCHEMA vault TO scoring_role;
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS vault CASCADE');
        // Extensions are intentionally left installed.
    }
};
