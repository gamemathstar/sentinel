<?php

namespace App\Modules\Delivery\Exceptions;

use RuntimeException;

/** A delivery-rule violation (wrong state, past deadline, duplicate sitting). Mapped to 422. */
class DeliveryError extends RuntimeException {}
