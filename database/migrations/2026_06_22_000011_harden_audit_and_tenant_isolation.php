<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defense-in-depth hardening (docs/03 §9, docs/04 §7).
 *
 * 1. AUDIT IMMUTABILITY: a trigger rejects UPDATE/DELETE on audit_entries so the
 *    hash chain cannot be silently rewritten even by code with table access. In
 *    production, REVOKE UPDATE,DELETE from the app role backs this at the privilege
 *    level too; the trigger guarantees it regardless of role.
 *
 * 2. TENANT ISOLATION via Row-Level Security: policies key reads/writes to a
 *    `app.current_institution` session GUC set by middleware on each request, so even
 *    raw SQL from the app role cannot cross tenants. Platform admins set the GUC to a
 *    sentinel that the policy treats as "all tenants".
 *
 * Both are additive to the application-layer global scope (docs/03 §9 layer 1).
 */
return new class extends Migration {
    /**
     * Tables that carry institution_id directly and get an RLS policy. Child tables
     * (item_versions, assessment_sections, scores, grading_tasks, …) deliberately omit
     * institution_id and are isolated transitively: they are only reachable through a
     * parent aggregate that is itself RLS-scoped, so a separate policy would be redundant.
     */
    private array $tenantTables = [
        'org_nodes', 'items', 'stimuli', 'assessments', 'blueprints',
        'scoring_rules', 'proctoring_policies', 'sittings', 'proctoring_sessions',
        'certificates', 'notifications',
    ];

    public function up(): void
    {
        // --- 1. Audit immutability trigger ---
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_audit_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_entries is append-only; % rejected', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_entries_immutable
            BEFORE UPDATE OR DELETE ON audit_entries
            FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();
        SQL);

        // --- 2. Row-Level Security for tenant isolation ---
        foreach ($this->tenantTables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            // 'all-tenants' sentinel lets platform-scope work bypass; otherwise match GUC.
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    current_setting('app.current_institution', true) = 'all-tenants'
                    OR institution_id::text = current_setting('app.current_institution', true)
                )
                WITH CHECK (
                    current_setting('app.current_institution', true) = 'all-tenants'
                    OR institution_id::text = current_setting('app.current_institution', true)
                )
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tenantTables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
        DB::statement('DROP TRIGGER IF EXISTS audit_entries_immutable ON audit_entries');
        DB::statement('DROP FUNCTION IF EXISTS reject_audit_mutation()');
    }
};
