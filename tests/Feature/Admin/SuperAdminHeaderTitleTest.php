<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression guard for the shared Super Admin header title.
 *
 * The layout component (components/super-admin/layout.blade.php) used to derive
 * its header title from a hard-coded request()->routeIs() chain. Any page whose
 * route was absent from that chain silently fell back to the "Platform Dashboard"
 * default. Title ownership now lives on each page via <x-super-admin.layout title>,
 * so this pins that every previously-defaulting route renders its OWN header
 * title and never the default.
 */
class SuperAdminHeaderTitleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function actingAsSuperAdmin(): self
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Header', 'last_name' => 'Test', 'name' => 'Header Test',
            'email' => 'header' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    /** Routes that previously fell through to the "Platform Dashboard" default. */
    public static function affectedRoutes(): array
    {
        return [
            'revenue' => ['admin.revenue.index', 'Revenue Analytics'],
            'announcements' => ['admin.announcements.index', 'Platform Announcements'],
            'compliance alerts' => ['admin.compliance-alerts.index', 'Compliance Alerts'],
            'edition requests' => ['admin.edition-requests.index', 'Edition Requests'],
            'feature flags' => ['admin.feature-flags.index', 'Feature Flags'],
            'security' => ['admin.security.index', 'Platform Security'],
            'backup' => ['admin.backup.index', 'Backup Status'],
            'account' => ['admin.account.show', 'Account Security'],
        ];
    }

    #[DataProvider('affectedRoutes')]
    public function test_affected_route_renders_its_own_header_title(string $routeName, string $expectedTitle): void
    {
        $res = $this->actingAsSuperAdmin()->get(route($routeName));

        $res->assertOk();
        // Header shows the page-specific title...
        $res->assertSee('<h2 class="admin-title">' . $expectedTitle . '</h2>', false);
        // ...and never the stale default.
        $res->assertDontSee('<h2 class="admin-title">Platform Dashboard</h2>', false);
    }

    /** The prop default still resolves for the dashboard itself. */
    public function test_dashboard_still_renders_default_header_title(): void
    {
        $res = $this->actingAsSuperAdmin()->get(route('admin.dashboard'));

        $res->assertOk();
        $res->assertSee('<h2 class="admin-title">Platform Dashboard</h2>', false);
    }
}
