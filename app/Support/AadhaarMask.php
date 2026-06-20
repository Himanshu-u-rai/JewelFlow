<?php

namespace App\Support;

/**
 * Aadhaar privacy helper (Dhiran KYC).
 *
 * Full Aadhaar numbers must NEVER be persisted. This normalises any input to a
 * masked form that keeps only the last 4 digits — "XXXX-XXXX-1234" — which is the
 * only value stored in dhiran_loans.kyc_aadhaar and the only value ever displayed.
 *
 * Inputs accepted: a full 12-digit number (with/without spaces or hyphens) or an
 * already-masked value. Anything without at least 4 trailing digits returns null.
 */
final class AadhaarMask
{
    /** Mask an Aadhaar input to "XXXX-XXXX-1234", or null when nothing usable. */
    public static function mask(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input);
        if ($digits === null || strlen($digits) < 4) {
            return null;
        }

        return 'XXXX-XXXX-' . substr($digits, -4);
    }

    /** True when a stored value is already masked (defensive display guard). */
    public static function isMasked(?string $value): bool
    {
        return $value !== null && (bool) preg_match('/^X{4}-X{4}-\d{4}$/', $value);
    }
}
