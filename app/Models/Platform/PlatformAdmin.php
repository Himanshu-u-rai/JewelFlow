<?php

namespace App\Models\Platform;

use App\Notifications\PlatformAdminResetPassword;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;

class PlatformAdmin extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword;
    use Notifiable;

    protected $table = 'platform_admins';

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    public const PERMISSIONS = [
        'can_impersonate', 'can_billing_override', 'can_suspend_shop',
        'can_view_audit_logs', 'can_manage_admins', 'can_view_security',
        'can_manage_jobs', 'can_view_compliance',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'permissions' => 'array',
        ];
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): void
    {
        $this->forceFill(['email_verified_at' => now()])->save();
    }

    /** Email the reset link via our own platform-admin route, not the shop route. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PlatformAdminResetPassword($token));
    }

    protected static function booted(): void
    {
        static::deleting(function (PlatformAdmin $admin) {
            if ($admin->isSuperAdmin() && $admin->is_active && static::countActiveSuperAdminsExcluding($admin->id) < 1) {
                throw new LogicException('Cannot delete the last active super admin.');
            }
        });

        static::updating(function (PlatformAdmin $admin) {
            $wasSuperAdmin = $admin->getOriginal('role') === 'super_admin';
            $wasActive = (bool) $admin->getOriginal('is_active');
            $losingSuperRole = $admin->isDirty('role') && $wasSuperAdmin && $admin->role !== 'super_admin';
            $deactivating = $admin->isDirty('is_active') && $wasActive && !$admin->is_active;

            if ($losingSuperRole && $wasActive && static::countActiveSuperAdminsExcluding($admin->id) < 1) {
                throw new LogicException('Cannot demote the last active super admin.');
            }

            if ($deactivating && $wasSuperAdmin && static::countActiveSuperAdminsExcluding($admin->id) < 1) {
                throw new LogicException('Cannot suspend the last active super admin.');
            }
        });
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) return true;
        $perms = $this->permissions ?? [];
        return in_array($permission, $perms);
    }

    public static function countActiveSuperAdminsExcluding(?int $excludeId = null): int
    {
        return static::query()
            ->where('role', 'super_admin')
            ->whereRaw('is_active IS TRUE')
            ->when($excludeId, fn ($q) => $q->whereKeyNot($excludeId))
            ->count();
    }
}
