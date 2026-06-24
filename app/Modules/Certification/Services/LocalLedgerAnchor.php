<?php

namespace App\Modules\Certification\Services;

use App\Modules\Certification\Contracts\CertificateAnchor;

/**
 * PLACEHOLDER anchor (docs/04 §11): returns a deterministic pseudo-transaction id derived
 * from the hash so the workflow and verification can be exercised today. A production
 * deployment binds a real blockchain/notary client implementing CertificateAnchor.
 */
class LocalLedgerAnchor implements CertificateAnchor
{
    public function anchor(string $contentHash): string
    {
        return 'localledger:'.substr($contentHash, 0, 32);
    }
}
