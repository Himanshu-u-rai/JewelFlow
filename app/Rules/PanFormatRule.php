<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PanFormatRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pan = strtoupper(trim((string) $value));

        if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $pan)) {
            $fail('Invalid PAN format. Expected: 5 letters + 4 digits + 1 letter (e.g. ABCDE1234F).');
        }
    }
}
