<?php

namespace App\Modules\Scheduling\Exceptions;

use RuntimeException;

/** A scheduling rule was violated (capacity, state, or selection). */
class SchedulingError extends RuntimeException {}
