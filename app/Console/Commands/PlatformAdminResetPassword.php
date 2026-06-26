<?php

namespace App\Console\Commands;

use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Emergency, shell-only platform-admin password reset.
 *
 * This is the last-resort fallback when self-service recovery is unavailable
 * (e.g. the admin's email was never verified). It is NOT a replacement for the
 * web flow. The new password is read from a hidden prompt and never appears in
 * arguments, output, or logs. The reset bumps password_changed_at, which
 * signs out every existing session, and writes an audit-log entry.
 */
class PlatformAdminResetPassword extends Command
{
    protected $signature = 'platform-admin:reset-password {email : The platform admin email}';
    protected $description = '[emergency] Reset a platform admin password from the CLI (revokes sessions, audited).';

    public function handle(PlatformAuditService $audit): int
    {
        $email = Str::lower((string) $this->argument('email'));
        $admin = PlatformAdmin::where('email', $email)->first();

        if (! $admin) {
            $this->error("No platform admin with email {$email}.");
            return self::FAILURE;
        }

        $this->warn("Resetting password for: {$admin->name} <{$admin->email}> (role: {$admin->role})");
        if (! $this->confirm('Continue?', true)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $password = $this->secret('New password');
        $confirm = $this->secret('Confirm new password');

        if ($password !== $confirm) {
            $this->error('Passwords do not match.');
            return self::FAILURE;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', PasswordRule::min(12)->mixedCase()->numbers()->symbols()]]
        );
        if ($validator->fails()) {
            // Print rule failures only — never the password itself.
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $admin->forceFill([
            'password' => Hash::make($password),
            'password_changed_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        $audit->log($admin, 'platform_admin.password_reset_cli', PlatformAdmin::class, $admin->id, null, null, 'Emergency CLI reset');

        $this->info('Password updated. All existing sessions for this admin are now invalid.');
        $this->line('They can sign in with the new password (2FA still applies if enabled).');

        return self::SUCCESS;
    }
}
