<?php

namespace App\Modules\QuestionBank\Exceptions;

use RuntimeException;

/** Raised when a review/approval transition violates the workflow or separation of duties. */
class WorkflowViolation extends RuntimeException {}
