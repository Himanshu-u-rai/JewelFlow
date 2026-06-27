<?php

namespace App\Console\Commands;

use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Emergency, shell-only contact recovery for a platform admin (e.g. they lost
 * access to the old email/mobile and can't run the verified web flow). Not a
 * web bypass. A CLI email change is marked UNVERIFIED so the admin must re-prove
 * it. The change revokes existing sessions and is audited. No secrets logged.
 */
class PlatformAdminUpdateContact extends Command
{
    protected $signature = 'platform-admin:update-contact {identifier : admin email or mobile} {--email= : new email} {--mobile= : new 10-digit mobile}';
    protected $description = '[emergency] Update a platform admin email/mobile from the CLI (revokes sessions, audited).';

    public function handle(PlatformAuditService $audit): int
    {
        $identifier = (string) $this->argument('identifier');
        $admin = PlatformAdmin::where('email', $identifier)->orWhere('mobile_number', $identifier)->first();
        if (! $admin) {
            $this->error("No platform admin matching {$identifier}.");
            return self::FAILURE;
        }

        $email = $this->option('email');
        $mobile = $this->option('mobile');
        if (! $email && ! $mobile) {
            $this->error('Provide --email and/or --mobile.');
            return self::FAILURE;
        }

        $rules = [];
        $data = [];
        if ($email !== null) {
            $rules['email'] = ['email', 'max:255', 'unique:platform_admins,email,' . $admin->id];
            $data['email'] = $email;
        }
        if ($mobile !== null) {
            $rules['mobile'] = ['digits:10', 'unique:platform_admins,mobile_number,' . $admin->id];
            $data['mobile'] = $mobile;
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $e) {
                $this->error($e);
            }
            return self::FAILURE;
        }

        $this->warn("Admin: {$admin->name} <{$admin->email}> / {$admin->mobile_number} (role {$admin->role})");
        if (! $this->confirm('Apply this contact change and sign out their other sessions?', true)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $before = $admin->only(['email', 'mobile_number']);
        $fill = ['password_changed_at' => now(), 'remember_token' => Str::random(60)]; // revoke sessions
        if ($email !== null) {
            $fill['email'] = $email;
            $fill['email_verified_at'] = null; // CLI cannot prove control → must re-verify
        }
        if ($mobile !== null) {
            $fill['mobile_number'] = $mobile;
        }
        $admin->forceFill($fill)->save();

        $audit->log($admin, 'platform_admin.contact_updated_cli', PlatformAdmin::class, $admin->id, $before, $admin->only(['email', 'mobile_number']), 'Emergency CLI contact update');

        $this->info('Updated. Existing sessions revoked.' . ($email !== null ? ' Email marked unverified — admin must re-verify it.' : ''));

        return self::SUCCESS;
    }
}
