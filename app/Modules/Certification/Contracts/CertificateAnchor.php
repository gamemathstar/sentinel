<?php

namespace App\Modules\Certification\Contracts;

/**
 * Anti-corruption interface for anchoring a certificate's content hash to an external,
 * append-only ledger (docs/04 §7, docs spec "blockchain verification option"). Returns a
 * transaction id. Swap the binding for a real chain/notary without touching issuance.
 */
interface CertificateAnchor
{
    /** Commit the hash to the ledger; return an opaque transaction id. */
    public function anchor(string $contentHash): string;
}
