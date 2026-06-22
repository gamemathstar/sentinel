<?php

namespace App\Modules\QuestionBank\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * The answer-key vault (docs/04 §2) — the crown-jewel separation, made real.
 *
 * Correct answers are NEVER stored beside questions. They go to the isolated
 * `vault.answer_keys` table, keyed by an opaque token derived as
 * version_token = HMAC(K_map, item_version_id). The mapping is therefore not stored
 * anywhere; it is recomputed at scoring time from a secret held outside the DB. The
 * payload is encrypted before it touches the row.
 *
 * NOTE ON CRYPTO: this uses Laravel's app-key encryption as a working stand-in for the
 * production split-key DEK (KMS + HSM, Shamir 2-of-3, JIT release). The *separation and
 * opaque-token* properties are fully real here; the key-custody hardening is the
 * Scoring/Vault module's later concern (docs/04 §11).
 */
class AnswerKeyVault
{
    private const TABLE = 'vault.answer_keys';

    private function connection(): Connection
    {
        return DB::connection(config('legion.vault_connection'));
    }

    /** Deterministically derive the opaque token; never stored, always recomputed. */
    public function deriveToken(string $itemVersionId): string
    {
        $secret = (string) config('legion.answer_key_map_secret');
        $mac = hash_hmac('sha256', $itemVersionId, $secret, true);
        $b = substr($mac, 0, 16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x70); // version nibble (cosmetic, keeps it uuid-shaped)
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80); // variant bits
        $h = bin2hex($b);

        return sprintf('%s-%s-%s-%s-%s',
            substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    /** Store (or replace) the scoring truth for an item version. */
    public function store(string $itemVersionId, array $truth, string $algo = 'objective.v1'): void
    {
        $token = $this->deriveToken($itemVersionId);
        $cipher = Crypt::encryptString(json_encode($truth));

        $this->connection()->statement(
            'INSERT INTO '.self::TABLE.' (id, version_token, key_blob_enc, key_version, algo, created_at, updated_at) '
            ."VALUES (gen_random_uuid(), ?, decode(?, 'base64'), 1, ?, now(), now()) "
            .'ON CONFLICT (version_token) DO UPDATE SET key_blob_enc = excluded.key_blob_enc, algo = excluded.algo, updated_at = now()',
            [$token, base64_encode($cipher), $algo]
        );
    }

    /** Retrieve and decrypt the scoring truth, or null if none stored. */
    public function fetch(string $itemVersionId): ?array
    {
        $token = $this->deriveToken($itemVersionId);
        $row = $this->connection()->selectOne(
            "SELECT encode(key_blob_enc, 'base64') AS blob FROM ".self::TABLE.' WHERE version_token = ?',
            [$token]
        );

        if (! $row) {
            return null;
        }

        return json_decode(Crypt::decryptString(base64_decode($row->blob)), true);
    }

    public function forget(string $itemVersionId): void
    {
        $this->connection()->statement(
            'DELETE FROM '.self::TABLE.' WHERE version_token = ?',
            [$this->deriveToken($itemVersionId)]
        );
    }

    public function has(string $itemVersionId): bool
    {
        return $this->fetch($itemVersionId) !== null;
    }

    /**
     * Score an objective answer against the vault truth. Returns a fraction in [0,1].
     * This is the minimal proof that the answer round-trips correctly through the vault;
     * the full scoring engine (partial/negative/IRT/formula) is the Scoring module.
     *
     * @param  mixed  $candidate  the candidate's submitted answer (option key, set, value, …)
     */
    public function scoreObjective(string $itemVersionId, string $type, mixed $candidate): float
    {
        $truth = $this->fetch($itemVersionId);
        if ($truth === null) {
            return 0.0;
        }

        return match ($type) {
            'single', 'true_false', 'yes_no' => $this->norm($candidate) === $this->norm($truth['correct'][0] ?? null) ? 1.0 : 0.0,
            'multiple' => $this->scoreSet((array) $candidate, $truth['correct'] ?? []),
            'fill_blank' => in_array($this->norm($candidate), array_map([$this, 'norm'], $truth['accept'] ?? []), true) ? 1.0 : 0.0,
            'numerical' => $this->scoreNumeric($candidate, $truth),
            'ordering' => $this->scoreOrdering((array) $candidate, $truth['order'] ?? []),
            'matching' => $this->scoreMatching((array) $candidate, $truth['pairs'] ?? []),
            default => 0.0,
        };
    }

    private function norm(mixed $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    /** All-or-nothing for multiple-correct (partial credit lives in the scoring rule). */
    private function scoreSet(array $candidate, array $correct): float
    {
        $c = array_map([$this, 'norm'], $candidate);
        $k = array_map([$this, 'norm'], $correct);
        sort($c);
        sort($k);

        return $c === $k ? 1.0 : 0.0;
    }

    private function scoreNumeric(mixed $candidate, array $truth): float
    {
        $tol = (float) ($truth['tolerance'] ?? 0);
        $target = (float) ($truth['value'] ?? 0);

        return abs((float) $candidate - $target) <= $tol ? 1.0 : 0.0;
    }

    private function scoreOrdering(array $candidate, array $order): float
    {
        return array_map([$this, 'norm'], $candidate) === array_map([$this, 'norm'], $order) ? 1.0 : 0.0;
    }

    private function scoreMatching(array $candidate, array $pairs): float
    {
        foreach ($pairs as $left => $right) {
            if (! isset($candidate[$left]) || $this->norm($candidate[$left]) !== $this->norm($right)) {
                return 0.0;
            }
        }

        return count($candidate) === count($pairs) ? 1.0 : 0.0;
    }
}
