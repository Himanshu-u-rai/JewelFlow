<?php

namespace App\Services;

use App\Models\Otp;
use Carbon\Carbon;

class OtpService
{
    /**
     * OTP expiry time in minutes.
     */
    protected int $expiryMinutes = 5;

    /**
     * OTP length.
     */
    protected int $otpLength = 6;

    /**
     * Send OTP to mobile number.
     * 
     * @param string $mobile The mobile number to send OTP to
     * @param string $purpose The purpose of OTP (login, register, etc.)
     * @return array Contains 'success' boolean and 'message' string
     */
    public function send(string $mobile, string $purpose = 'auth'): array
    {
        // Delete any existing OTPs for this mobile and purpose
        Otp::forMobile($mobile)->where('purpose', $purpose)->delete();

        // Generate 6-digit OTP
        $otp = $this->generateOtp();

        // Calculate expiry time
        $expiresAt = Carbon::now()->addMinutes($this->expiryMinutes);

        // Save OTP to database
        Otp::create([
            'mobile_number' => $mobile,
            'otp_code' => $otp,
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'resend_count' => 0,
            'last_sent_at' => now(),
        ]);

        // ============================================================
        // 🔌 FUTURE: Replace with SMS API call
        // Example: SmsGateway::send($mobile, "Your OTP is: $otp");
        // ============================================================

        // Log OTP dispatch event WITHOUT the actual code (security: never log OTP values)
        \Illuminate\Support\Facades\Log::info("OTP dispatched for mobile ending in " . substr($mobile, -4));

        return [
            'success' => true,
            'message' => 'OTP sent successfully',
            'expires_in' => $this->expiryMinutes * 60, // seconds
        ];
    }

    /**
     * Verify OTP for mobile number.
     * 
     * @param string $mobile The mobile number
     * @param string $otp The OTP to verify
     * @param string $purpose The purpose of OTP
     * @return array Contains 'success' boolean and 'message' string
     */
    public function verify(string $mobile, string $otp, string $purpose = 'auth'): array
    {
        // Find valid OTP
        $otpRecord = Otp::forMobile($mobile)
            ->where('purpose', $purpose)
            ->valid()
            ->where('otp_code', $otp)
            ->first();

        if (!$otpRecord) {
            // Check if OTP exists but expired
            $expiredOtp = Otp::forMobile($mobile)
                ->where('purpose', $purpose)
                ->where('otp_code', $otp)
                ->first();

            if ($expiredOtp) {
                // Increment attempts
                $expiredOtp->increment('attempts');
                
                return [
                    'success' => false,
                    'message' => 'OTP has expired. Please request a new one.',
                ];
            }

            // Wrong OTP - increment attempts on any existing record
            $anyOtp = Otp::forMobile($mobile)->where('purpose', $purpose)->first();
            if ($anyOtp) {
                $anyOtp->increment('attempts');
                
                // Block after 5 wrong attempts
                if ($anyOtp->attempts >= 5) {
                    $anyOtp->delete();
                    return [
                        'success' => false,
                        'message' => 'Too many wrong attempts. Please request a new OTP.',
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Invalid OTP. Please try again.',
            ];
        }

        // OTP is valid - mark as verified and delete
        $otpRecord->update(['verified_at' => now()]);
        $otpRecord->delete();

        return [
            'success' => true,
            'message' => 'OTP verified successfully',
        ];
    }

    /**
     * Generate random OTP.
     */
    protected function generateOtp(): string
    {
        // Generate cryptographically secure random OTP
        $min = pow(10, $this->otpLength - 1);
        $max = pow(10, $this->otpLength) - 1;
        
        return (string) random_int($min, $max);
    }

    /**
     * Check if mobile has a valid (non-expired) OTP.
     */
    public function hasValidOtp(string $mobile, string $purpose = 'auth'): bool
    {
        return Otp::forMobile($mobile)->where('purpose', $purpose)->valid()->exists();
    }

    /**
     * Get remaining time for OTP expiry in seconds.
     */
    public function getRemainingTime(string $mobile, string $purpose = 'auth'): ?int
    {
        $otp = Otp::forMobile($mobile)->where('purpose', $purpose)->valid()->first();
        
        if (!$otp) {
            return null;
        }

        return max(0, $otp->expires_at->diffInSeconds(now()));
    }
}
