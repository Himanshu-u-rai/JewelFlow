<?php

namespace App\Rules;

use App\Support\Mobile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One validation rule for every mobile field in the app.
 *
 * Delegates to App\Support\Mobile so the rule that REJECTS a number and the
 * normaliser that STORES one can never disagree. They used to: forms validated
 * `digits:10` while the normaliser accepted anything ten digits or longer, so a
 * value the form refused could still reach the column through an import.
 *
 * Deliberately not `digits:10`. That accepts 1234567890 and 0000000000, neither
 * of which is a mobile number under the TRAI plan — level 1 is service numbers
 * and levels 2-5 are landlines.
 */
class IndianMobileRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value)) {
            $fail('Enter a valid 10-digit mobile number.');

            return;
        }

        $raw = trim((string) $value);

        if (Mobile::isValid($raw)) {
            return;
        }

        // A specific reason beats "invalid": the operator is holding a number
        // and needs to know whether to recount the digits, drop the country
        // code, or accept that a landline will not go in this field.
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        $fail(match (true) {
            preg_match('/[A-Za-z]/', $raw) === 1 => 'Mobile number cannot contain letters.',
            $digits === ''                       => 'Enter a valid 10-digit mobile number.',
            strlen($digits) < 10                 => 'Mobile number must be exactly 10 digits — this one has only ' . strlen($digits) . '.',
            strlen($digits) > 10                 => 'Mobile number must be exactly 10 digits — this one has ' . strlen($digits) . '. Do not include the country code.',
            default                              => 'Mobile number must start with 6, 7, 8 or 9. Landline numbers are not accepted here.',
        });
    }
}
