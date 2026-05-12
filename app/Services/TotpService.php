<?php

namespace App\Services;

/**
 * Pure PHP TOTP implementation (RFC 6238 / RFC 4226).
 * No external dependencies required.
 */
class TotpService
{
    private const DIGITS   = 6;
    private const PERIOD   = 30;  // seconds per window
    private const WINDOW   = 1;   // accept 1 window before/after for clock skew

    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a cryptographically random base32-encoded secret (160-bit).
     */
    public function generateSecret(): string
    {
        $bytes = random_bytes(20); // 160 bits
        return $this->base32Encode($bytes);
    }

    /**
     * Compute the TOTP code for a given secret and time window.
     */
    public function computeCode(string $secret, int $window): string
    {
        $key     = $this->base32Decode($secret);
        $message = pack('J', $window); // 8-byte big-endian unsigned 64-bit int

        $hash   = hash_hmac('sha1', $message, $key, true);
        $offset = ord($hash[19]) & 0x0F;

        $code = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-supplied OTP against the current window (±1 window for clock skew).
     */
    public function verify(string $secret, string $otp): bool
    {
        $otp     = preg_replace('/\D/', '', $otp);
        $current = (int) floor(time() / self::PERIOD);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            if (hash_equals($this->computeCode($secret, $current + $i), $otp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the otpauth:// URI for QR code generation.
     */
    public function otpauthUri(string $secret, string $accountLabel, string $issuer = 'JewelFlow'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountLabel)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    // ── base32 helpers ────────────────────────────────────────────────────────

    private function base32Encode(string $data): string
    {
        $chars   = self::BASE32_CHARS;
        $output  = '';
        $buffer  = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($data); $i++) {
            $buffer    = ($buffer << 8) | ord($data[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output   .= $chars[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $output .= $chars[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $output;
    }

    private function base32Decode(string $data): string
    {
        $chars  = self::BASE32_CHARS;
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;
        $data   = strtoupper(rtrim($data, '='));

        for ($i = 0; $i < strlen($data); $i++) {
            $val = strpos($chars, $data[$i]);
            if ($val === false) {
                continue;
            }

            $buffer    = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output   .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
