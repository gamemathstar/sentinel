<?php

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/** Thrown on a failed authentication or MFA challenge. Mapped to HTTP 401. */
class InvalidCredentials extends RuntimeException
{
    public function __construct(string $message = 'Invalid credentials.')
    {
        parent::__construct($message);
    }
}
