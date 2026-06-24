# 13 — Certification Module

Turns a finalized score into a **verifiable credential**. Certificates are issued
automatically when a score becomes final (subscribing to `ScoreFinalized`) and verified
through a **public portal that trusts only the token, not the issuer's live database**.

Covered by **11 certification tests** (whole suite: **94 passing**).

## Code layout

```
app/Modules/Certification/
  Models/Certificate.php
  Contracts/CertificateAnchor.php          anti-corruption interface to a ledger/notary
  Services/
    CertificationService.php               issue (idempotent) / verify / revoke
    LocalLedgerAnchor.php                  PLACEHOLDER anchor implementation
  Listeners/IssueCertificateOnScoreFinalized.php
  Exceptions/CertificationError.php
  Http/Controllers/CertificateController.php
database/migrations/...add_verification_fields_to_certificates.php
```

## How verification stays trustworthy

At issue, the result is **snapshotted** into the certificate's `payload` and a
`content_hash = SHA-256(serial ‖ payload)` is stored. Verification:

1. looks the certificate up by its unguessable `verification_token`,
2. checks it isn't revoked,
3. **recomputes the hash from the stored snapshot** and compares — so a tampered payload
   is detected (verified by test: mutating `raw_score` ⇒ `tampered`),

and returns the snapshot. Because everything needed is in the certificate row, a third
party can verify **without trusting the issuer's live tables** (docs/04 §9).

A subtle correctness point the tests caught: the hash is computed from the *persisted*
payload (after the JSONB round-trip normalizes key order and numeric types), so the value
verification recomputes always matches.

## Issuance

- **Automatic** via `IssueCertificateOnScoreFinalized` — but only when the score is truly
  `final`. `ScoreFinalized` also fires for `under_review` scores (objective done, grading
  pending); those are skipped, and the certificate is issued once grading reconciles.
- **Idempotent** — one credential per candidate per assessment; a re-fired event or a
  manual issue returns the existing certificate. Issuing for a non-final score is refused
  (`CertificationError` ⇒ 422).
- **Optional anchoring** — the content hash can be committed to an external ledger via the
  `CertificateAnchor` contract (placeholder `LocalLedgerAnchor` today); an existing
  unanchored certificate can be anchored later.

## API

| Method & path | Auth | Purpose |
|---------------|------|---------|
| `GET /api/certification/verify/{token}` | **public** | verify authenticity (200 valid / 404 invalid+reason) |
| `POST /api/certification/sittings/{id}/issue` | `certification.issue` | issue (idempotent); `?anchor=1` to anchor |
| `GET /api/certification/certificates` | `certification.read` | list issued credentials |
| `POST /api/certification/certificates/{id}/revoke` | `certification.revoke` | revoke |

The verification route sits **outside** the auth middleware (tested with no
`Authorization` header). `exam_officer` holds issue/read/revoke; a `student` is forbidden
(403, tested). After revocation the portal reports the credential invalid.

## Notes / future work

- PDF / digital-badge rendering (`certificates.s3_key`) and QR-code generation around the
  verification URL are presentation concerns layered on top of this data + the public
  endpoint.
- The real blockchain/notary anchor implements `CertificateAnchor`; `LocalLedgerAnchor`
  is a clearly-labelled stand-in.
- Transcript entries aggregate many certificates per candidate — a straightforward read
  model over this table.
