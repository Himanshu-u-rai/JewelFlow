<?php

namespace Tests\Feature\Icons;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Platform admin drew several icons as emoji (shop/factory/money cards, status marks in the activity feed). They are
 * Lucide icons now; the words next to them are unchanged. (The gear and lock-with-key in super-admin/layout.blade.php
 * were changed too, but that legacy layout does not render today, so it has no page to test.)
 */
class PlatformAdminIconsTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'Icon', 'last_name' => 'Test', 'name' => 'Icon Test',
            'email' => 'icons' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'), 'role' => 'super_admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function page(PlatformAdmin $admin, string $url): \DOMXPath
    {
        $html = $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get($url)->assertOk()->getContent();
        foreach (['⚙', '🔐', '🏪', '🏭', '💰', '⛔', '💳', '👤'] as $emoji) {
            $this->assertStringNotContainsString($emoji, $html, "{$emoji} is still drawn as an emoji");
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    public function test_the_settings_page_draws_its_edition_cards_with_lucide_icons(): void
    {
        $xp = $this->page($this->admin(), route('admin.settings.index'));

        foreach (['store', 'factory', 'coins'] as $card) {
            $this->assertSame(1, $xp->query("//span[contains(@class, 'admin-setting-icon')]//svg[@data-icon='{$card}' and @width='20']")->length, "{$card} card icon");
        }
    }

    public function test_the_activity_feed_marks_known_actions_with_an_icon_and_leaves_other_rows_with_a_bullet(): void
    {
        $admin = $this->admin();
        foreach (['shop.suspend', 'shop.restore', 'platform_admin.created', 'something.else'] as $action) {
            PlatformAuditLog::create(['actor_admin_id' => $admin->id, 'action' => $action, 'target_type' => 'App\\Models\\Shop', 'target_id' => 1, 'created_at' => now()]);
        }

        $xp = $this->page($admin, route('admin.dashboard'));

        $this->assertSame(1, $xp->query('//td[contains(., "shop.suspend")]//svg[@data-icon="ban"]')->length);
        $this->assertSame(1, $xp->query('//td[contains(., "shop.restore")]//svg[@data-icon="circle-check"]')->length);
        $this->assertSame(1, $xp->query('//td[contains(., "platform_admin.created")]//svg[@data-icon="user"]')->length);
        $other = $xp->query('//td[contains(., "something.else")]');
        $this->assertSame(1, $other->length);
        $this->assertSame(0, $xp->query('.//svg', $other->item(0))->length);
        $this->assertStringContainsString('•', $other->item(0)->textContent);
    }
}
