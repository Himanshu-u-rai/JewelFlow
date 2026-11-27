<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression guard for the platform-admin 2FA pages.
 *
 * Both views once used `@extends('super-admin.layout')`, but that layout prints
 * `{{ $slot }}` and only works as the <x-super-admin.layout> component, so the
 * pages answered HTTP 500 ("Undefined variable $slot").
 */
class PlatformAdminTwoFactorPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function actingAsSuperAdmin(bool $twoFactorEnabled): self
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Two', 'last_name' => 'Factor', 'name' => 'Two Factor',
            'email' => 'twofactor' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
            'two_factor_enabled' => $twoFactorEnabled,
        ]);

        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    public function test_manage_page_renders_when_two_factor_is_enabled(): void
    {
        $res = $this->actingAsSuperAdmin(true)->get(route('admin.2fa.enroll'));

        $res->assertOk();
        $res->assertSee('<h2 class="admin-title">2FA Settings</h2>', false);
        $res->assertSee('Disable 2FA');
    }

    public function test_enroll_page_renders_when_two_factor_is_not_enabled(): void
    {
        $res = $this->actingAsSuperAdmin(false)->get(route('admin.2fa.enroll'));

        $res->assertOk();
        $res->assertSee('<h2 class="admin-title">2FA Setup</h2>', false);
        $res->assertSee('Set Up Two-Factor Authentication');
        $this->assertNotNull(session('2fa_enroll_secret'));
    }
}
