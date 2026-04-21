<?php

namespace App\Http\Controllers;

use App\Mail\EmailOtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailVerificationOtpController extends Controller
{
    private const OTP_EXPIRY_MINUTES = 10;

    /**
     * Step 1: Send OTP to the provided email.
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        /** @var User $user */
        $user  = Auth::user();
        $email = strtolower(trim($request->email));

        // Reject if this email is already verified by another account
        $taken = User::where('email', $email)
            ->where('id', '!=', $user->id)
            ->whereNotNull('email_verified_at')
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'email' => 'This email is already associated with another account.',
            ]);
        }

        // Rate limit: max 3 OTP sends per hour per user
        $key = 'email-otp-send:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Too many attempts. Please wait {$seconds} seconds before trying again.",
            ]);
        }
        RateLimiter::hit($key, 3600);

        $otp = (string) random_int(100000, 999999);

        $user->forceFill([
            'email'                 => $email,
            'email_verified_at'     => null,
            'email_verify_otp'      => Hash::make($otp),
            'email_otp_expires_at'  => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
        ])->save();

        $shopName = $user->shop?->name ?? config('app.name');
        Mail::to($email)->send(new EmailOtpMail($otp, $shopName));

        return response()->json([
            'status'  => 'sent',
            'message' => "We've sent a 6-digit code to {$email}. It expires in 10 minutes.",
        ]);
    }

    /**
     * Step 2: Verify the OTP entered by the user.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => ['required', 'string', 'digits:6'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        if (!$user->email_verify_otp || !$user->email_otp_expires_at) {
            throw ValidationException::withMessages([
                'otp' => 'No OTP has been sent. Please request a new code.',
            ]);
        }

        if (now()->isAfter($user->email_otp_expires_at)) {
            $user->forceFill([
                'email_verify_otp'     => null,
                'email_otp_expires_at' => null,
            ])->save();

            throw ValidationException::withMessages([
                'otp' => 'This code has expired. Please request a new one.',
            ]);
        }

        if (!Hash::check($request->otp, $user->email_verify_otp)) {
            throw ValidationException::withMessages([
                'otp' => 'Invalid code. Please check and try again.',
            ]);
        }

        // Mark email as verified and clear OTP
        $user->forceFill([
            'email_verified_at'    => now(),
            'email_verify_otp'     => null,
            'email_otp_expires_at' => null,
        ])->save();

        return response()->json([
            'status'  => 'verified',
            'message' => 'Email verified! You can now reset your password via email.',
        ]);
    }

    /**
     * Resend OTP to the current email on record.
     */
    public function resend(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->email) {
            return response()->json(['error' => 'No email address on file.'], 422);
        }

        if ($user->email_verified_at) {
            return response()->json(['error' => 'Email is already verified.'], 422);
        }

        $request->merge(['email' => $user->email]);
        return $this->sendOtp($request);
    }
}
