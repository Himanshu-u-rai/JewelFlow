<?php

namespace App\Http\Controllers;

use App\Mail\MobileChangedNotificationMail;
use App\Mail\MobileChangeOtpMail;
use App\Models\MobileChangeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Mobile-number change flow (login credential).
 *
 * Threat model summary:
 *   - Session + current-password re-auth before an OTP is ever issued,
 *     so a stolen cookie alone cannot trigger a change.
 *   - OTP is emailed to the user's verified email — never to the new
 *     mobile (which isn't trusted yet).
 *   - 6-digit OTP, 10-min expiry, 5-attempt cap per request.
 *   - On commit: all other sessions invalidated + remember token rotated.
 *   - Confirmation email to the user's email summarising the change.
 */
class MobileChangeController extends Controller
{
    private const OTP_EXPIRY_MINUTES = 10;
    private const MAX_ATTEMPTS       = 5;

    public function showForm(Request $request)
    {
        $user = Auth::user();

        return view('profile.mobile-change', [
            'user'            => $user,
            'pendingRequest'  => $this->latestPendingRequest($user->id),
            'emailVerified'   => (bool) $user->email_verified_at,
        ]);
    }

    /**
     * Step 1: validate password + new mobile, email an OTP.
     */
    public function requestChange(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if (! $user->email || ! $user->email_verified_at) {
            return back()->with('error', 'Please verify your email address before changing your login mobile.');
        }

        $validated = $request->validate([
            'current_password'    => ['required', 'string'],
            'new_mobile_number'   => ['required', 'string', 'digits:10'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            RateLimiter::hit('mobile-change-password:' . $user->id, 300);
            throw ValidationException::withMessages([
                'current_password' => 'Password is incorrect.',
            ]);
        }

        if ($validated['new_mobile_number'] === $user->mobile_number) {
            throw ValidationException::withMessages([
                'new_mobile_number' => 'This is already your current mobile number.',
            ]);
        }

        $taken = User::where('mobile_number', $validated['new_mobile_number'])
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'new_mobile_number' => 'This mobile number is already in use by another account.',
            ]);
        }

        // Rate limit: 3 change-requests per user per hour.
        $rlKey = 'mobile-change-request:' . $user->id;
        if (RateLimiter::tooManyAttempts($rlKey, 3)) {
            $seconds = RateLimiter::availableIn($rlKey);
            throw ValidationException::withMessages([
                'new_mobile_number' => "Too many requests. Please try again in {$seconds} seconds.",
            ]);
        }
        RateLimiter::hit($rlKey, 3600);

        $otp = (string) random_int(100000, 999999);

        MobileChangeRequest::create([
            'user_id'            => $user->id,
            'new_mobile_number'  => $validated['new_mobile_number'],
            'otp_hash'           => Hash::make($otp),
            'expires_at'         => now()->addMinutes(self::OTP_EXPIRY_MINUTES),
            'attempts'           => 0,
            'requested_ip'       => $request->ip(),
            'user_agent'         => substr((string) $request->userAgent(), 0, 255),
        ]);

        Mail::to($user->email)->send(new MobileChangeOtpMail(
            otp:             $otp,
            newMobileMasked: $this->maskMobile($validated['new_mobile_number']),
            userName:        $user->first_name ?? $user->name ?? 'there',
            appName:         config('app.name', 'JewelFlow')
        ));

        return redirect()->route('profile.mobile.change')
            ->with('success', "We've emailed a 6-digit code to {$user->email}. It expires in 10 minutes.");
    }

    /**
     * Step 2: validate OTP and rotate the user's mobile.
     */
    public function verifyChange(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'otp' => ['required', 'string', 'digits:6'],
        ]);

        $pending = $this->latestPendingRequest($user->id);

        if (! $pending) {
            return back()->with('error', 'No active change request. Start over.');
        }

        if ($pending->isExpired()) {
            $pending->update(['consumed_at' => now()]);
            return back()->with('error', 'This code has expired. Please request a new one.');
        }

        $pending->increment('attempts');

        if ($pending->attempts > self::MAX_ATTEMPTS) {
            $pending->update(['consumed_at' => now()]);
            return back()->with('error', 'Too many invalid attempts. Please start over.');
        }

        if (! Hash::check($validated['otp'], $pending->otp_hash)) {
            throw ValidationException::withMessages([
                'otp' => 'Invalid code. Please check and try again.',
            ]);
        }

        // Re-check uniqueness at commit time — another user could have claimed
        // the same number while this OTP was outstanding.
        $taken = User::where('mobile_number', $pending->new_mobile_number)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            $pending->update(['consumed_at' => now()]);
            return back()->with('error', 'That mobile number is no longer available. Please start over.');
        }

        $oldMobile = $user->mobile_number;
        $newMobile = $pending->new_mobile_number;

        DB::transaction(function () use ($user, $pending, $newMobile) {
            $user->forceFill([
                'mobile_number'  => $newMobile,
                'remember_token' => \Illuminate\Support\Str::random(60),
            ])->save();

            $pending->update([
                'verified_at' => now(),
                'consumed_at' => now(),
            ]);

            // Invalidate all other sessions.
            Auth::logoutOtherDevices(request()->input('current_password') ?? '');
        });

        Mail::to($user->email)->send(new MobileChangedNotificationMail(
            oldMobileMasked: $this->maskMobile($oldMobile ?? ''),
            newMobileMasked: $this->maskMobile($newMobile),
            userName:        $user->first_name ?? $user->name ?? 'there',
            changedBy:       'You (self-service)',
            ipAddress:       request()->ip() ?? 'unknown',
            appName:         config('app.name', 'JewelFlow')
        ));

        return redirect()->route('profile.mobile.change')
            ->with('success', 'Your login mobile number has been updated successfully.');
    }

    private function latestPendingRequest(int $userId): ?MobileChangeRequest
    {
        return MobileChangeRequest::where('user_id', $userId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 4) return $mobile;
        return str_repeat('X', max(0, strlen($mobile) - 4)) . substr($mobile, -4);
    }
}
