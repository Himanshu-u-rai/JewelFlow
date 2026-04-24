<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MobileChangedNotificationMail;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Admin-side override of a user's login mobile.
 *
 * No OTP — the admin is the authority, but a mandatory reason is logged,
 * and the affected user always receives a confirmation email. "Sign user
 * out of all devices" defaults on to close the window on a lingering
 * hostile session that triggered the support call.
 */
class UserMobileController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'new_mobile_number'  => ['required', 'string', 'digits:10'],
            'reason'             => ['required', 'string', 'min:6', 'max:500'],
            'signout_other_sessions' => ['nullable', 'boolean'],
        ]);

        $signoutOthers = $request->boolean('signout_other_sessions', true);

        if ($validated['new_mobile_number'] === $user->mobile_number) {
            return back()->with('error', 'That is already the user\'s current mobile number.');
        }

        $collision = User::where('mobile_number', $validated['new_mobile_number'])
            ->where('id', '!=', $user->id)
            ->exists();

        if ($collision) {
            return back()->with('error', 'Another user already owns that mobile number.');
        }

        $oldMobile = $user->mobile_number;

        DB::transaction(function () use ($user, $validated, $signoutOthers) {
            $updates = [
                'mobile_number'  => $validated['new_mobile_number'],
                'remember_token' => Str::random(60),
            ];

            if ($signoutOthers) {
                $updates['password'] = $user->password; // no-op, but forces fresh timestamp via save
            }

            $user->forceFill($updates)->save();

            if ($signoutOthers) {
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->delete();
            }
        });

        $adminEmail = auth('platform_admin')->user()?->email ?? 'a platform admin';

        if ($user->email) {
            Mail::to($user->email)->send(new MobileChangedNotificationMail(
                oldMobileMasked: $this->maskMobile($oldMobile ?? ''),
                newMobileMasked: $this->maskMobile($validated['new_mobile_number']),
                userName:        $user->first_name ?? $user->name ?? 'there',
                changedBy:       "Platform admin ({$adminEmail})",
                ipAddress:       $request->ip() ?? 'unknown',
                appName:         config('app.name', 'JewelFlow'),
                reason:          $validated['reason']
            ));
        }

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.user.mobile_change',
            User::class,
            $user->id,
            ['mobile_number' => $this->maskMobile($oldMobile ?? '')],
            ['mobile_number' => $this->maskMobile($validated['new_mobile_number']), 'signout_others' => $signoutOthers],
            $validated['reason'],
            $request
        );

        return back()->with('success', "Mobile number updated for {$user->mobile_number}.");
    }

    private function maskMobile(string $mobile): string
    {
        if (strlen($mobile) < 4) return $mobile;
        return str_repeat('X', max(0, strlen($mobile) - 4)) . substr($mobile, -4);
    }
}
