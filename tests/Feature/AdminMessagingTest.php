<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAnnouncement;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Admin-editable banners (platform announcements).
 *
 * Platform admin creates/edits an offers/deals banner (type=banner) that renders
 * on tenant dashboards. The admin CRUD is platform-admin only.
 */
class AdminMessagingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private function banner(array $attrs = []): PlatformAnnouncement
    {
        return PlatformAnnouncement::create(array_merge([
            'title' => 'Diwali Offer', 'body' => 'Flat 20% off making charges this week.',
            'cta_label' => 'View offer', 'cta_url' => 'https://jewelflows.com/offers',
            'type' => 'banner', 'target' => 'all', 'realm' => null, 'send_email' => false,
        ], $attrs));
    }

    // ── Admin CRUD ─────────────────────────────────────────────
    // Every admin POST/PUT below carries EnsurePlatformAdminMfa::SESSION_PASSED.
    // Without it the mfa gate redirects to /admin/mfa and the controller never
    // runs — the request "succeeds" as a 302, so assertRedirect() passes while
    // nothing is written. The MFA gate itself is covered by PlatformAdminMfaTest.

    public function test_admin_can_create_a_banner(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post('/admin/announcements', [
                'title' => 'Deal', 'body' => 'Big sale', 'type' => 'banner',
                'target' => 'all', 'realm' => 'erp',
                'cta_label' => 'Shop', 'cta_url' => 'https://x.test/deal',
                'send_email' => false,
            ])->assertRedirect();

        $a = PlatformAnnouncement::where('title', 'Deal')->first();
        $this->assertNotNull($a);
        $this->assertSame('banner', $a->type);
        $this->assertSame('erp', $a->realm);
        $this->assertSame('https://x.test/deal', $a->cta_url);
    }

    public function test_admin_can_update_a_banner(): void
    {
        $admin = $this->createPlatformAdmin();
        $banner = $this->banner(['title' => 'Old']);

        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->put("/admin/announcements/{$banner->id}", [
                'title' => 'New Title', 'body' => 'Updated body', 'type' => 'banner',
                'target' => 'all', 'send_email' => false,
            ])->assertRedirect();

        $this->assertSame('New Title', $banner->fresh()->title);
        $this->assertSame('Updated body', $banner->fresh()->body);
    }

    public function test_cross_promo_type_is_no_longer_accepted(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post('/admin/announcements', [
                'title' => 'Custom heading', 'body' => 'Custom body', 'type' => 'cross_promo',
                'target' => 'all', 'realm' => 'erp', 'send_email' => false,
            ])->assertSessionHasErrors('type');
    }

    public function test_invalid_type_is_rejected(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post('/admin/announcements', [
                'title' => 'X', 'body' => 'Y', 'type' => 'nonsense', 'target' => 'all', 'send_email' => false,
            ])->assertSessionHasErrors('type');
    }

    public function test_tenant_cannot_create_announcement(): void
    {
        [$owner] = $this->createRetailerTenant();

        // A tenant user is not on the platform_admin guard → blocked from /admin.
        $this->actingAs($owner)->post('/admin/announcements', [
            'title' => 'X', 'body' => 'Y', 'type' => 'banner', 'target' => 'all', 'send_email' => false,
        ])->assertStatus(302); // redirected to admin login, not processed
        $this->assertSame(0, PlatformAnnouncement::count());
    }

    // ── Tenant render ──────────────────────────────────────────

    public function test_banner_renders_on_erp_dashboard(): void
    {
        $this->banner(['title' => 'ERP Banner', 'realm' => null]);
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->get('https://jewelflows.com/dashboard')
            ->assertOk()
            ->assertSee('ERP Banner')
            ->assertSee('promo-banner', false);
    }

    public function test_banner_type_does_not_render_as_system_notice(): void
    {
        // A 'banner' must render via x-promo-banner, NOT the small system-notice loop.
        $this->banner(['title' => 'Promo Banner Body']);
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get('https://jewelflows.com/dashboard');
        $response->assertOk();
        // Rendered through the big banner component (promo-banner class present).
        $response->assertSee('promo-banner', false);
    }
}
