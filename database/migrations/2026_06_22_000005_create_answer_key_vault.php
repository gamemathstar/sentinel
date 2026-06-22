<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Answer-Key Vault (docs/04-security-architecture.md §2) — the crown-jewel separation.
 *
 * This table lives in the `vault` schema, NOT `public`, so the application DB role can
 * be denied access to it independently. It is keyed by an OPAQUE version_token, never
 * by item_version_id — the mapping (item_version_id -> version_token) is HMAC(K_map, id)
 * with K_map held in the HSM/KMS, so reading both this table and the question bank still
 * does not let an attacker align answers to questions. key_blob_enc is the scoring truth
 * encrypted with a split (Shamir/dual-control) data key.
 *
 * Schema builder can't target a non-default schema cleanly, so this uses raw SQL.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS vault.answer_keys (
                id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                version_token uuid NOT NULL UNIQUE,        -- opaque; = HMAC(K_map, item_version_id)
                key_blob_enc  bytea NOT NULL,              -- AES-256-GCM scoring truth, split-key DEK
                key_version   integer NOT NULL DEFAULT 1,  -- which DEK version encrypted this (rotation)
                algo          text NOT NULL,               -- scoring algorithm id
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now()
            )
        SQL);

        // The vault is write-rarely, read-only-at-scoring. No FK to public on purpose.
        DB::statement('CREATE INDEX IF NOT EXISTS answer_keys_version_token_idx ON vault.answer_keys (version_token)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS vault.answer_keys');
    }
};
