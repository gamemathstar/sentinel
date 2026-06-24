<?php

namespace App\Modules\Reporting\Exceptions;

use RuntimeException;

/** A reporting request violation (unknown type/format). Mapped to 422. */
class ReportingError extends RuntimeException {}
