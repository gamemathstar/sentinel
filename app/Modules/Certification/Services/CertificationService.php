<?php

namespace App\Modules\Certification\Services;

use App\Modules\Authoring\Models\Assessment;
use App\Modules\Certification\Contracts\CertificateAnchor;
use App\Modules\Certification\Exceptions\CertificationError;
use App\Modules\Certification\Models\Certificate;
use App\Modules\Delivery\Models\Score;
use App\Modules\Delivery\Models\Sitting;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and verifies certificates from finalized scores (docs/01 §4.8, docs/03 §7).
 *
 * Issuance snapshots the result into the certificate `payload` and hashes it, so the
 * public verification portal can confirm authenticity WITHOUT trusting the issuer's live
 * tables (docs/04 §9). Issuance is idempotent — one credential per candidate per
 * assessment. Anchoring the hash to an external ledger is optional.
 */
class CertificationService
{
    public function __construct(private readonly CertificateAnchor $anchor) {}

    /** Issue a certificate for a sitting whose score is final. Idempotent. */
    public function issueForSitting(Sitting $sitting, bool $anchor = false): Certificate
    {
        $score = Score::where('sitting_id', $sitting->id)->first();
        if (! $score || $score->status !== 'final') {
            throw new CertificationError('A certificate can only be issued for a finalized score.');
        }

        $existing = Certificate::where('assessment_id', $sitting->assessment_id)
            ->where('candidate_id', $sitting->candidate_id)
            ->first();
        if ($existing) {
            // Idempotent — but allow anchoring a previously-unanchored credential.
            if ($anchor && ! $existing->isAnchored()) {
                $existing->forceFill(['anchor_txid' => $this->anchor->anchor($existing->content_hash)])->save();
            }

            return $existing;
        }

        return DB::transaction(function () use ($sitting, $score, $anchor) {
            $candidate = User::find($sitting->candidate_id);
            $assessment = Assessment::find($sitting->assessment_id);
            $issuedAt = Carbon::now();
            $serial = $this->generateSerial($assessment);

            $certificate = Certificate::create([
                'candidate_id' => $sitting->candidate_id,
                'assessment_id' => $sitting->assessment_id,
                'serial' => $serial,
                'verification_token' => $this->generateToken(),
                'payload' => [
                    'serial' => $serial,
                    'institution_id' => $sitting->institution_id,
                    'candidate' => ['id' => $candidate?->id, 'name' => $candidate?->full_name],
                    'assessment' => ['id' => $assessment?->id, 'title' => $assessment?->title, 'kind' => $assessment?->kind],
                    'result' => [
                        'raw_score' => (float) $score->raw_score,
                        'scaled_score' => $score->scaled_score !== null ? (float) $score->scaled_score : null,
                    ],
                    'issued_at' => $issuedAt->toIso8601String(),
                ],
                'issued_at' => $issuedAt,
            ]);

            // Hash the PERSISTED payload (jsonb normalizes key order / numeric types), so
            // verification — which reads the stored payload — recomputes the same hash.
            $certificate->refresh();
            $contentHash = $this->hashPayload($certificate->serial, $certificate->payload);
            $certificate->forceFill([
                'content_hash' => $contentHash,
                'anchor_txid' => $anchor ? $this->anchor->anchor($contentHash) : null,
            ])->save();

            return $certificate;
        });
    }

    /**
     * Public verification by token. Self-contained: recomputes the hash from the stored
     * snapshot, so a mutated payload is detected even without re-reading source data.
     *
     * @return array{valid:bool, reason?:string, ...}
     */
    public function verify(string $token): array
    {
        // No tenant context on the public portal -> the global scope adds no filter.
        $certificate = Certificate::where('verification_token', $token)->first();
        if (! $certificate) {
            return ['valid' => false, 'reason' => 'not_found'];
        }
        if ($certificate->isRevoked()) {
            return ['valid' => false, 'reason' => 'revoked', 'serial' => $certificate->serial];
        }
        if (! hash_equals((string) $certificate->content_hash, $this->hashPayload($certificate->serial, $certificate->payload))) {
            return ['valid' => false, 'reason' => 'tampered', 'serial' => $certificate->serial];
        }

        return [
            'valid' => true,
            'serial' => $certificate->serial,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'payload' => $certificate->payload,
            'anchored' => $certificate->isAnchored(),
            'anchor_txid' => $certificate->anchor_txid,
        ];
    }

    public function revoke(Certificate $certificate): Certificate
    {
        $certificate->forceFill(['revoked_at' => Carbon::now()])->save();

        return $certificate;
    }

    private function hashPayload(string $serial, ?array $payload): string
    {
        return hash('sha256', $serial.'|'.json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function generateSerial(?Assessment $assessment): string
    {
        $prefix = strtoupper(substr($assessment?->kind ?? 'CERT', 0, 4));

        return $prefix.'-'.now()->format('Y').'-'.strtoupper(Str::random(10));
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(20)); // 40 hex chars, unguessable
    }
}
