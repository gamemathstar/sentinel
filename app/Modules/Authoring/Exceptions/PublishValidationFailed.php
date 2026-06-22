<?php

namespace App\Modules\Authoring\Exceptions;

use RuntimeException;

/** Raised when an assessment fails the pre-publish validation. Carries the reasons. */
class PublishValidationFailed extends RuntimeException
{
    /** @param string[] $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Assessment cannot be published: '.implode('; ', $errors));
    }
}
