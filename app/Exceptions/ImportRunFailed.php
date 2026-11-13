<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/** A bulk import failed after it started (a refusal before it starts stays a LogicException). */
class ImportRunFailed extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
