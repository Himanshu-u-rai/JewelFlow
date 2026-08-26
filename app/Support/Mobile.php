<?php

namespace App\Support;

/**
 * The one place this app decides what a mobile number is.
 *
 * Three standards, all of which this app is bound by:
 *
 *   - ITU-T E.164   storage is country code + national number, digits only,
 *                   no punctuation, no leading trunk zero, 15 digits max.
 *   - ITU-T E.123   storage and display are different jobs. We store the bare
 *                   national number and let the view group it for humans.
 *   - TRAI National Numbering Plan
 *                   an Indian MOBILE is exactly ten digits and begins 6, 7, 8
 *                   or 9. Levels 2-5 are fixed-line area codes and level 1 is
 *                   service numbers, so neither is a mobile. A leading 0 is the
 *                   trunk prefix — a dialling instruction, not part of the
 *                   number.
 *
 * JewelFlow is India-only, so we store the ten-digit national number rather
 * than full E.164: the +91 is a constant, and a constant in every row is
 * storage, not information. Nothing here accepts a landline and nothing here
 * accepts a letter.
 *
 * WHY THIS IS NOT "TAKE THE LAST TEN DIGITS"
 *
 * It used to be, and that is unsafe in a way that is invisible afterwards.
 * substr($digits, -10) cannot tell a country code from a fat-fingered eleventh
 * digit, so 98123000999 became 8123000999 — a real, differently-owned, entirely
 * valid mobile number — and stored it as canonical. The typo did not look like
 * a typo from that moment on. A prefix is therefore only stripped when the
 * remainder is EXACTLY ten digits, which makes every accepted prefix an
 * unambiguous one; anything else is rejected rather than trimmed into a guess.
 */
final class Mobile
{
    /** TRAI: ten digits, first of them 6-9. */
    public const PATTERN = '/^[6-9][0-9]{9}$/';

    /** India. The constant we deliberately do NOT store in every row. */
    public const COUNTRY_CODE = '91';

    /**
     * Prefixes that can precede the national number, longest first.
     *
     * Each has a distinct total length (14, 13, 12, 11 digits), so no input can
     * match two of them and there is no ordering hazard — the ordering is for
     * readers, not for correctness.
     */
    private const PREFIXES = ['0091', '091', '91', '0'];

    /**
     * The canonical ten-digit form, or null if the value is not an Indian
     * mobile number. Null means REJECT — never "store it as typed anyway".
     */
    public static function normalize(?string $mobile): ?string
    {
        // Punctuation and spacing are presentation (E.123), so they are dropped
        // before anything is judged: '98123 00099' and '+91-98123-00099' are the
        // same number typed by two different people. Letters do not survive this
        // either, but they are not silently tolerated — 'ABC9812300099' reduces
        // to ten digits and would pass, so the length check below runs against
        // the ORIGINAL to make sure nothing non-numeric was thrown away.
        $raw    = trim((string) $mobile);
        $digits = preg_replace('/[\s\-().]+/', '', $raw) ?? '';
        $digits = ltrim($digits, '+');

        // Anything left that is not a digit means the input was never a number.
        if ($digits === '' || preg_match('/^[0-9]+$/', $digits) !== 1) {
            return null;
        }

        foreach (self::PREFIXES as $prefix) {
            if (strlen($digits) === strlen($prefix) + 10 && str_starts_with($digits, $prefix)) {
                $digits = substr($digits, strlen($prefix));
                break;
            }
        }

        return preg_match(self::PATTERN, $digits) === 1 ? $digits : null;
    }

    /** Convenience for call sites that only care whether it is acceptable. */
    public static function isValid(?string $mobile): bool
    {
        return self::normalize($mobile) !== null;
    }

    /**
     * Display form (E.123 grouping), for views. Falls back to the stored string
     * so a pre-canonicalisation row still renders as whatever the shop typed
     * rather than disappearing.
     */
    public static function forDisplay(?string $mobile): string
    {
        $canonical = self::normalize($mobile);

        return $canonical === null
            ? trim((string) $mobile)
            : substr($canonical, 0, 5) . ' ' . substr($canonical, 5);
    }

    /**
     * E.164 digits with no '+', which is the only form wa.me accepts.
     *
     * This exists because storing the bare national number has a cost, and this
     * is where it is paid. wa.me/9876543210 is not a valid link — WhatsApp reads
     * a number without a country code as unroutable — so every link builder has
     * to put the 91 back. Shops used to be told to TYPE the country code, which
     * made the links work by accident and the column inconsistent on purpose;
     * now the column is canonical and the link re-adds the constant.
     *
     * A value that does not normalise falls back to its bare digits, so a legacy
     * row that still holds '919876543210' (which normalises anyway) or something
     * stranger keeps whatever link it had before rather than losing it.
     */
    public static function forWhatsApp(?string $mobile): string
    {
        $canonical = self::normalize($mobile);

        return $canonical === null
            ? (preg_replace('/\D+/', '', (string) $mobile) ?? '')
            : self::COUNTRY_CODE . $canonical;
    }
}
