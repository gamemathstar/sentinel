<?php

namespace App\Modules\Delivery\Exceptions;

use RuntimeException;

/** A grading-workflow violation (wrong state, separation of duties). Mapped to 422. */
class GradingError extends RuntimeException {}
