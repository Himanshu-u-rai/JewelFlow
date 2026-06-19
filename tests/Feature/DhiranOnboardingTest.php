<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Dhiran customer onboarding — Phase 3.
 *
 * A Dhiran-realm customer goes: register/login → choose the Dhiran yearly plan →
 * pay (shared engine) → create a Dhiran business → Dhiran dashboard. They never
 * touch the ERP shop-type chooser, ERP plans, or ERP navigation. The realm is
 * host-derived, so tests drive the Dhiran host via absolute URLs.
 */
class DhiranOnboardingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const DHIRAN = 'https://dhiran.jewelflows.com';

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase starts with no plans — seed the Dhiran yearly plan the
        // onboarding flow depends on. grantsEdition() resolves 'dhiran_*' → dhiran
        // via the code-prefix fallback, so no PlatformProduct row is required.
        Plan::create([
            'code'          => 'dhiran_yearly',
            'name'          => 'Dhiran Yearly',
            'price_monthly' => 0,
            'price_yearly'  => 14999,
            'grace_days'    => 5,
            'is_active'     => true,
        ]);
    }

    private function dhiranUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'mobile_number' => '9390000000',
            'password'      => bcrypt('x'),
            'realm'         => 'dhiran',
            'is_active'     => true,
        ], $attrs));
    }

    private function erpUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'mobile_number' => '9391000000',
            'password'      => bcrypt('x'),
            'realm'         => 'erp',
            'is_active'     => true,
        ], $attrs));
    }

    private function dhiranYearlyPlan(): Plan
    {
        return Plan::whereRaw('is_active IS TRUE')->get()
            ->first(fn (Plan $p) => $p->grantsEdition() === ShopEdition::DHIRAN && ! is_null($p->price_yearly));
    }

    /** Seed a paid Dhiran subscription waiting for a shop (post-payment state). */
    private function pendingDhiranSubscription(User $user): ShopSubscription
    {
        return ShopSubscription::create([
            'shop_id'       => null,
            'plan_id'       => $this->dhiranYearlyPlan()->id,
            'user_id'       => $user->id,
            'status'        => 'active',
            'billing_cycle' => 'yearly',
            'price_paid'    => 14999,
            'starts_at'     => now(),
            'ends_at'       => now()->addYear(),
        ]);
    }

    /** Fully onboard a Dhiran user (paid sub → Dhiran shop) and return the fresh user. */
    private function onboardDhiranUser(string $mobile = '9390000050'): User
    {
        $user = $this->dhiranUser(['mobile_number' => $mobile]);
        $this->pendingDhiranSubscription($user);

        $this->actingAs($user)->post(self::DHIRAN.'/dhiran/onboarding', [
            'name' => 'Dhiran Biz', 'owner_name' => 'Owner Name', 'phone' => '9876500001',
            'address' => 'Addr', 'city' => 'City', 'state' => 'State',
        ]);

        return $user->fresh();
    }

    /**
     * ERP navigation must NEVER appear in the Dhiran shell. We assert on real ERP
     * nav link text and hrefs (not bundled-JS string matches like "main-sidebar",
     * which legitimately appear inside app.js regardless of layout).
     */
    private function assertNoErpNavigation($response): void
    {
        // ERP nav link labels.
        $response->assertDontSee('Point of Sale');
        $response->assertDontSee('Quick Bills');
        $response->assertDontSee('Job Orders');
        $response->assertDontSee('Returns &amp; Exchanges');
        // ERP nav link targets (the sidebar <a href> URLs).
        $response->assertDontSee(route('pos.index'), false);
        $response->assertDontSee(route('invoices.index'), false);
        $response->assertDontSee('href="'.route('dashboard').'"', false);
        // It MUST be the Dhiran shell.
        $response->assertSee('dh-sidebar', false);
    }

    // 1. No subscription → Dhiran plan page.
    public function test_dhiran_user_without_subscription_is_sent_to_plan_page(): void
    {
        $user = $this->dhiranUser();

        $this->actingAs($user)->get(self::DHIRAN.'/dhiran')
            ->assertRedirect(route('dhiran.plans'));
    }

    // 2. Plan page shows only the Dhiran yearly plan (no ERP/manufacturing).
    public function test_plan_page_shows_only_dhiran_yearly_plan(): void
    {
        $user = $this->dhiranUser();

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran/plans');
        $response->assertOk();
        $response->assertSee('Dhiran');
        $response->assertSee('per year', false);
        // No ERP plan names / no shop-type chooser leakage.
        $response->assertDontSee('Retailer');
        $response->assertDontSee('Manufacturer');
        $response->assertDontSee('Choose your business type');
    }

    // 3. Completing onboarding (with a paid sub) grants ONLY the dhiran edition.
    public function test_dhiran_onboarding_grants_only_dhiran_edition(): void
    {
        $user = $this->dhiranUser();
        $this->pendingDhiranSubscription($user);

        $this->actingAs($user)->post(self::DHIRAN.'/dhiran/onboarding', [
            'name'       => 'Sharma Gold Finance',
            'owner_name' => 'Ramesh Sharma',
            'phone'      => '9876543210',
            'address'    => '12 Market Road',
            'city'       => 'Jaipur',
            'state'      => 'Rajasthan',
        ])->assertRedirect(route('dhiran.dashboard'));

        $user->refresh();
        $shop = Shop::find($user->shop_id);
        $this->assertNotNull($shop);
        $this->assertSame(['dhiran'], ShopEdition::activeFor($shop));
        $this->assertNotContains('retailer', ShopEdition::activeFor($shop));
        $this->assertNotContains('manufacturer', ShopEdition::activeFor($shop));
    }

    // 4. Dhiran onboarding creates shop_type = dhiran.
    public function test_dhiran_onboarding_creates_dhiran_shop_type(): void
    {
        $user = $this->dhiranUser();
        $this->pendingDhiranSubscription($user);

        $this->actingAs($user)->post(self::DHIRAN.'/dhiran/onboarding', [
            'name'       => 'Gold Loan Co',
            'owner_name' => 'Owner',
            'phone'      => '9876500000',
            'address'    => 'Addr',
            'city'       => 'City',
            'state'      => 'State',
        ])->assertRedirect(route('dhiran.dashboard'));

        $user->refresh();
        $this->assertSame('dhiran', Shop::find($user->shop_id)->shop_type);
    }

    // 5. Dhiran onboarding never shows/uses the ERP shop-type chooser.
    public function test_dhiran_onboarding_does_not_show_erp_chooser(): void
    {
        $user = $this->dhiranUser();
        $this->pendingDhiranSubscription($user);

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran/onboarding');
        $response->assertOk();
        $response->assertSee('Dhiran');
        $response->assertDontSee('Choose your business type');
        // Dhiran-specific fields, NOT ERP setup.
        $response->assertSee('Loan receipt prefix', false);
        $response->assertDontSee('Wastage');
        $response->assertDontSee('GST rate', false);
    }

    // 6. After onboarding, the Dhiran user lands on the Dhiran dashboard.
    public function test_dhiran_user_with_shop_lands_on_dashboard(): void
    {
        $user = $this->dhiranUser();
        $this->pendingDhiranSubscription($user);

        $this->actingAs($user)->post(self::DHIRAN.'/dhiran/onboarding', [
            'name' => 'X', 'owner_name' => 'Y', 'phone' => '9876511111',
            'address' => 'A', 'city' => 'C', 'state' => 'S',
        ]);

        $user->refresh();
        $response = $this->actingAs($user->fresh())->get(self::DHIRAN.'/dhiran');
        $response->assertOk();
    }

    // 7. Dhiran dashboard does not expose ERP navigation.
    public function test_dhiran_dashboard_has_no_erp_navigation(): void
    {
        $user = $this->onboardDhiranUser('9390000022');

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran');
        $response->assertOk();
        $this->assertNoErpNavigation($response);
    }

    // 7b. Dhiran LOANS page uses the Dhiran shell, no ERP nav.
    public function test_dhiran_loans_page_has_no_erp_navigation(): void
    {
        $user = $this->onboardDhiranUser('9390000023');

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran/loans');
        $response->assertOk();
        $this->assertNoErpNavigation($response);
    }

    // 7c. Dhiran REPORTS page uses the Dhiran shell, no ERP nav.
    public function test_dhiran_reports_page_has_no_erp_navigation(): void
    {
        $user = $this->onboardDhiranUser('9390000024');

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran/reports');
        $response->assertOk();
        $this->assertNoErpNavigation($response);
    }

    // 7d. Dhiran SETTINGS page uses the Dhiran shell, no ERP nav.
    public function test_dhiran_settings_page_has_no_erp_navigation(): void
    {
        $user = $this->onboardDhiranUser('9390000025');

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran/settings');
        $response->assertOk();
        $this->assertNoErpNavigation($response);
    }

    // 7e. Dhiran NEW-LOAN (create) page uses the Dhiran shell, no ERP nav.
    public function test_dhiran_create_loan_page_has_no_erp_navigation(): void
    {
        $user = $this->onboardDhiranUser('9390000026');

        $response = $this->actingAs($user)->get(self::DHIRAN.'/dhiran/create');
        $response->assertOk();
        $this->assertNoErpNavigation($response);
    }

    // 7f. EVERY Dhiran module view uses the Dhiran shell, never the ERP app layout.
    //     This is the deterministic proof of the layout-leakage fix: it asserts on
    //     the view source for every Dhiran blade (loan detail, payment receipts,
    //     closure certificate, forfeiture notice, etc.), independent of route
    //     binding / tenant-context test plumbing.
    public function test_every_dhiran_view_uses_dhiran_layout_not_erp(): void
    {
        $dir = resource_path('views/dhiran');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $checked = 0;

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $rel = str_replace($dir.'/', '', $file->getPathname());

            // No Dhiran view may ever use the ERP app layout (the leakage rule).
            $this->assertStringNotContainsString(
                'x-app-layout', $contents,
                "Dhiran view {$rel} must not use the ERP <x-app-layout>."
            );

            // App-chrome pages must use the Dhiran shell. Standalone printable
            // documents (receipts, certificates, notices) are full <!DOCTYPE html>
            // pages with NO app navigation at all — they carry no ERP chrome and
            // are correctly exempt from the shell requirement.
            $isStandaloneDocument = str_contains($contents, '<!DOCTYPE html>')
                && ! str_contains($contents, '<x-');

            if (! $isStandaloneDocument) {
                $this->assertStringContainsString(
                    'x-dhiran-layout', $contents,
                    "Dhiran app view {$rel} must use <x-dhiran-layout>."
                );
            }
            $checked++;
        }

        $this->assertGreaterThanOrEqual(16, $checked, 'Expected to audit all Dhiran views.');
    }

    // 7g. ERP dashboard still uses the ERP layout/navigation (not the Dhiran shell).
    public function test_erp_dashboard_still_uses_erp_layout(): void
    {
        // Build a real ERP retailer tenant and view its dashboard.
        [$erpOwner, $erpShop] = $this->createRetailerTenant();

        $response = $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard');
        $response->assertOk();
        // ERP shell present, Dhiran shell absent.
        $response->assertSee('main-sidebar', false);
        $response->assertDontSee('dh-sidebar', false);
    }

    // 8. ERP user cannot access Dhiran onboarding.
    public function test_erp_user_cannot_access_dhiran_onboarding(): void
    {
        $erp = $this->erpUser();

        $this->actingAs($erp)->get(self::DHIRAN.'/dhiran/plans')
            ->assertRedirect('/dashboard');
        $this->actingAs($erp)->get(self::DHIRAN.'/dhiran/onboarding')
            ->assertRedirect('/dashboard');
    }

    // 9. Dhiran user cannot access ERP onboarding/dashboard.
    public function test_dhiran_user_cannot_access_erp_dashboard(): void
    {
        $dhiran = $this->dhiranUser();

        // Realm boundary fires before ERP onboarding — bounced to Dhiran home,
        // never the ERP shop chooser.
        $response = $this->actingAs($dhiran)->get('https://jewelflows.com/dashboard');
        $response->assertRedirect(route('dhiran.dashboard'));
        $this->assertNotSame(route('shops.choose-type'), $response->headers->get('Location'));
    }

    // 10. ERP registration still creates an ERP account → ERP chooser.
    public function test_erp_registration_still_works(): void
    {
        $this->post('https://jewelflows.com/register', [
            'mobile_number' => '9395000000',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
        ])->assertRedirect(route('shops.choose-type'));

        $this->assertSame('erp', User::where('mobile_number', '9395000000')->first()->realm);
    }

    // (11) Existing auth/realm tests remain green — covered by the realm suites;
    //      this asserts the subscribe handoff seeds the shared payment flow.
    public function test_subscribe_hands_off_to_shared_payment(): void
    {
        $user = $this->dhiranUser();

        $this->actingAs($user)->post(self::DHIRAN.'/dhiran/subscribe')
            ->assertRedirect(route('subscription.payment'));

        $this->assertSame(ShopEdition::DHIRAN, session('onboarding_shop_type'));
        $this->assertSame([ShopEdition::DHIRAN], session('onboarding_editions'));
        $this->assertSame('yearly', session('pending_billing_cycle'));
        $this->assertSame($this->dhiranYearlyPlan()->id, session('pending_plan_id'));
    }

    // Regression: the shared payment page MUST be reachable on the dhiran.* host.
    // ForceDhiranSubdomain previously bounced /subscription/payment back to the
    // Dhiran dashboard, dead-looping the "Continue to payment" step.
    public function test_shared_payment_page_reachable_on_dhiran_host(): void
    {
        $user = $this->dhiranUser(['mobile_number' => '9390000099']);

        // Seed the pending-plan session via the real subscribe handoff.
        $this->actingAs($user)->post(self::DHIRAN.'/dhiran/subscribe')
            ->assertRedirect(route('subscription.payment'));

        // The payment page must render (200), NOT redirect back to /dhiran.
        $this->actingAs($user)->get(self::DHIRAN.'/subscription/payment')
            ->assertOk();
    }
}
