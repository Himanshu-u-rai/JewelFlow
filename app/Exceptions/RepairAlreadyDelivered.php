<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/** A write that needs an undelivered repair found it delivered (by another cashier, or a second click). */
class RepairAlreadyDelivered extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Repair is already delivered.');
    }

    /** The app's answer: a validation error on status (422), as before. */
    public function toValidation(): ValidationException
    {
        return ValidationException::withMessages(['status' => $this->getMessage()]);
    }
}
