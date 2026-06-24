<?php

namespace App\Modules\Certification\Exceptions;

use RuntimeException;

/** A certification-rule violation (e.g. issuing for a non-final score). Mapped to 422. */
class CertificationError extends RuntimeException {}
