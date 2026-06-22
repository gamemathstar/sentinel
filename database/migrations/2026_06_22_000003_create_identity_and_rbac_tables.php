<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity & Access context (docs/01 §4.1, docs/03 §1, docs/04 §5).
 *
 * Roles are decoupled from users via scoped `role_assignments`: a person can hold
 * different roles in different org-node scopes. Effective permissions = union of
 * role permissions over assignments whose scope is ancestor-or-self of the resource.
 * Custom roles are simply rows with is_system=false and a non-null institution_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable: platform-level super admins are not bound to a tenant.
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->string('email')->unique();
            $table->string('full_name');
            $table->string('password_hash');
            $table->string('status')->default('active'); // active|suspended|locked
            $table->boolean('mfa_enabled')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('institution_id');
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_chk CHECK (status IN ('active','suspended','locked'))");

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null institution_id => system role available to all tenants.
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['institution_id', 'name']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique(); // e.g. "questionbank.item.create"
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->cascadeOnDelete();
            // null scope => institution-wide; otherwise scoped to an org subtree.
            $table->foreignUuid('scope_org_node_id')->nullable()->constrained('org_nodes')->nullOnDelete();
            $table->foreignUuid('institution_id')->nullable()->constrained('institutions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'scope_org_node_id']);
            $table->index(['institution_id', 'user_id']);
        });

        Schema::create('mfa_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // totp|webauthn|sms
            $table->text('secret_enc'); // field-level encrypted; never plaintext
            $table->boolean('confirmed')->default(false);
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('refresh_token_hash')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('mfa_factors');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
