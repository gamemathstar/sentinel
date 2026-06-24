<?php

namespace App\Modules\Proctoring\Exceptions;

use RuntimeException;

/** A proctoring-rule violation (unknown flag type, bad review decision). Mapped to 422. */
class ProctoringError extends RuntimeException {}
