<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformSetting;
use App\Services\PlatformAuditService;
use Illuminate\Http\Request;

class PlatformSettingsController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index()
    {
        $settings = [
            'retailer_enabled'     => PlatformSetting::retailerEnabled(),
            'manufacturer_enabled' => PlatformSetting::manufacturerEnabled(),
            'dhiran_enabled'       => PlatformSetting::dhiranEnabled(),
            'maintenance_mode'     => PlatformSetting::bool('maintenance_mode', false),
            'maintenance_message'  => PlatformSetting::get('maintenance_message', 'JewelFlow is temporarily down for maintenance. We\'ll be back shortly.'),
            'subscription_trial_days' => PlatformSetting::trialDays(),
            'active_trial_count'   => \App\Models\Platform\ShopSubscription::where('status', 'trial')->count(),
        ];

        $mailSettings = [
            'mail_mailer'       => PlatformSetting::get('mail_mailer', config('mail.default', 'smtp')),
            'mail_host'         => PlatformSetting::get('mail_host', config('mail.mailers.smtp.host', '')),
            'mail_port'         => PlatformSetting::get('mail_port', (string) config('mail.mailers.smtp.port', '587')),
            'mail_username'     => PlatformSetting::get('mail_username', config('mail.mailers.smtp.username', '')),
            'mail_encryption'   => PlatformSetting::get('mail_encryption', config('mail.mailers.smtp.encryption', 'tls')),
            'mail_from_address' => PlatformSetting::get('mail_from_address', config('mail.from.address', '')),
            'mail_from_name'    => PlatformSetting::get('mail_from_name', config('mail.from.name', '')),
            'mail_password_set' => (bool) PlatformSetting::get('mail_password'),
        ];

        return view('super-admin.settings.index', compact('settings', 'mailSettings'));
    }

    public function update(Request $request)
    {
        // ── Mail settings (separate sub-form via ?section=mail) ────────────
        if ($request->input('section') === 'mail') {
            return $this->updateMailSettings($request);
        }

        $retailer            = $request->boolean('retailer_enabled');
        $manufacturer        = $request->boolean('manufacturer_enabled');
        $dhiran              = $request->boolean('dhiran_enabled');
        $maintenanceMode     = $request->boolean('maintenance_mode');
        $maintenanceMessage  = strip_tags(trim((string) $request->input('maintenance_message', '')));

        if (!$retailer && !$manufacturer && !$dhiran) {
            return back()->with('error', 'At least one edition must remain enabled. You cannot disable all.');
        }

        $before = [
            'retailer_enabled'     => PlatformSetting::retailerEnabled(),
            'manufacturer_enabled' => PlatformSetting::manufacturerEnabled(),
            'dhiran_enabled'       => PlatformSetting::dhiranEnabled(),
            'maintenance_mode'     => PlatformSetting::bool('maintenance_mode', false),
        ];

        PlatformSetting::set('retailer_enabled',     $retailer     ? 'true' : 'false');
        PlatformSetting::set('manufacturer_enabled', $manufacturer ? 'true' : 'false');
        PlatformSetting::set('dhiran_enabled',       $dhiran       ? 'true' : 'false');
        PlatformSetting::set('maintenance_mode',     $maintenanceMode ? 'true' : 'false');
        if ($maintenanceMessage) {
            PlatformSetting::set('maintenance_message', $maintenanceMessage);
        }

        $after = [
            'retailer_enabled'     => $retailer,
            'manufacturer_enabled' => $manufacturer,
            'dhiran_enabled'       => $dhiran,
            'maintenance_mode'     => $maintenanceMode,
        ];

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.platform_settings.updated',
            PlatformSetting::class,
            null,
            $before,
            $after,
            'Platform settings updated',
            $request
        );

        // ── Free-trial length ─────────────────────────────────────────────
        $trialMsg = $this->updateTrialLength($request);

        $msg = $maintenanceMode
            ? 'Settings saved. Maintenance mode is NOW ACTIVE — tenant traffic is blocked.'
            : 'Platform settings saved.';
        if ($trialMsg) {
            $msg .= ' ' . $trialMsg;
        }

        return back()->with('success', $msg);
    }

    /**
     * Save the free-trial length and, if the admin opted in, recompute the end
     * date for shops currently on trial. Returns a status fragment for the flash
     * message, or null if the field wasn't submitted.
     */
    private function updateTrialLength(Request $request): ?string
    {
        if (! $request->has('subscription_trial_days')) {
            return null;
        }

        $validated = $request->validate([
            'subscription_trial_days' => 'required|integer|min:1|max:365',
            'apply_trial_to_existing' => 'sometimes|boolean',
        ], [
            'subscription_trial_days.min' => 'Trial length must be at least 1 day.',
            'subscription_trial_days.max' => 'Trial length cannot exceed 365 days.',
        ]);

        $days     = (int) $validated['subscription_trial_days'];
        $oldDays  = PlatformSetting::trialDays();
        $applyAll = $request->boolean('apply_trial_to_existing');

        PlatformSetting::set('subscription_trial_days', (string) $days);

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.platform_settings.trial_length_updated',
            PlatformSetting::class,
            null,
            ['subscription_trial_days' => $oldDays],
            ['subscription_trial_days' => $days, 'apply_to_existing' => $applyAll],
            'Free trial length updated',
            $request
        );

        $fragment = "Free trial length set to {$days} day" . ($days === 1 ? '' : 's') . '.';

        if ($applyAll) {
            $adminId = auth('platform_admin')->id();
            $result = app(\App\Services\SubscriptionTrialService::class)
                ->applyTrialLengthToActiveTrials($days, $adminId ? (int) $adminId : null);
            $fragment .= " Updated {$result['updated']} shop(s) currently on trial"
                . ($result['clamped'] > 0 ? " ({$result['clamped']} clamped to today)" : '')
                . (($result['skipped_upgraded'] ?? 0) > 0
                    ? " ({$result['skipped_upgraded']} already upgraded to a paid plan — left unchanged)"
                    : '')
                . '.';
        } else {
            $fragment .= ' Applies to new trials only.';
        }

        return $fragment;
    }

    private function updateMailSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'mail_mailer'       => 'required|in:smtp,sendmail,mailgun,ses,postmark,log,array',
            'mail_host'         => 'required|string|max:255',
            'mail_port'         => 'required|integer|min:1|max:65535',
            'mail_username'     => 'nullable|string|max:255',
            'mail_password'     => 'nullable|string|max:500',
            'mail_encryption'   => 'nullable|in:tls,ssl,starttls,',
            'mail_from_address' => 'required|email|max:255',
            'mail_from_name'    => 'required|string|max:255',
        ]);

        PlatformSetting::set('mail_mailer',       $validated['mail_mailer']);
        PlatformSetting::set('mail_host',         $validated['mail_host']);
        PlatformSetting::set('mail_port',         (string) $validated['mail_port']);
        PlatformSetting::set('mail_username',     $validated['mail_username'] ?? '');
        PlatformSetting::set('mail_encryption',   $validated['mail_encryption'] ?? 'tls');
        PlatformSetting::set('mail_from_address', $validated['mail_from_address']);
        PlatformSetting::set('mail_from_name',    $validated['mail_from_name']);

        // Only overwrite password if a new one was provided
        if (!empty($validated['mail_password'])) {
            PlatformSetting::set('mail_password', $validated['mail_password']);
        }

        $this->audit->log(
            auth('platform_admin')->user(),
            'admin.platform_settings.mail_updated',
            PlatformSetting::class,
            null,
            ['mail_host' => PlatformSetting::get('mail_host')],
            ['mail_host' => $validated['mail_host'], 'mail_from_address' => $validated['mail_from_address']],
            'SMTP / mail settings updated',
            $request
        );

        return back()->with('success', 'Mail settings saved. Changes take effect on the next request.');
    }
}
