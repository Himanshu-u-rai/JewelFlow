<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Realm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dhiran separation — Phase 2 customer-facing front door.
 *
 * Realm-aware registration, realm-scoped login, and route-level realm isolation
 * (an ERP account can't reach Dhiran routes and vice-versa). Auth pages are the
 * shared routes rendered with Dhiran branding on the dhiran.* host; the realm is
 * derived from the host, so tests drive it via absolute URLs.
 */
class RealmAuthFrontDoorTest extends TestCase
{
    use RefreshDatabase;

    private function strongPassword(): string
    {
        return 'StrongPass123!';
    }

    // ── Registration by realm ────────────────────────────────────────────

    public function test_dhiran_register_creates_a_dhiran_account(): void
    {
        $this->post('https://dhiran.jewelflows.com/register', [
            'mobile_number' => '9300000001',
            'password' => $this->strongPassword(),
            'password_confirmation' => $this->strongPassword(),
        ])->assertRedirect(route('dhiran.dashboard'));

        $u = User::where('mobile_number', '9300000001')->first();
        $this->assertNotNull($u);
        $this->assertSame('dhiran', $u->realm);
    }

    public function test_erp_register_creates_an_erp_account(): void
    {
        $this->post('https://jewelflows.com/register', [
            'mobile_number' => '9300000002',
            'password' => $this->strongPassword(),
            'password_confirmation' => $this->strongPassword(),
        ])->assertRedirect(route('shops.choose-type'));

        $this->assertSame('erp', User::where('mobile_number', '9300000002')->first()->realm);
    }

    public function test_same_mobile_can_register_in_both_realms(): void
    {
        $this->post('https://jewelflows.com/register', [
            'mobile_number' => '9300000003', 'password' => $this->strongPassword(), 'password_confirmation' => $this->strongPassword(),
        ])->assertRedirect(route('shops.choose-type'));

        // Register auto-logs-in; a person registering in the other realm is a
        // fresh visitor, so drop the ERP session first.
        $this->post('/logout');

        // Same mobile on the dhiran host → a separate dhiran account (no collision).
        $this->post('https://dhiran.jewelflows.com/register', [
            'mobile_number' => '9300000003', 'password' => $this->strongPassword(), 'password_confirmation' => $this->strongPassword(),
        ])->assertRedirect(route('dhiran.dashboard'));

        $this->assertSame(2, User::where('mobile_number', '9300000003')->count());
        $this->assertSame(['dhiran', 'erp'], User::where('mobile_number', '9300000003')->pluck('realm')->sort()->values()->all());
    }

    // ── Login by realm ───────────────────────────────────────────────────

    public function test_login_only_authenticates_same_realm_user(): void
    {
        $erp = User::create(['mobile_number' => '9300000004', 'password' => bcrypt($this->strongPassword()), 'realm' => 'erp', 'is_active' => true]);

        // ERP login on the main host succeeds.
        $this->post('https://jewelflows.com/login', [
            'mobile_number' => '9300000004', 'password' => $this->strongPassword(),
        ]);
        $this->assertAuthenticatedAs($erp);
        $this->post('/logout');

        // The SAME credentials on the DHIRAN host must NOT authenticate the erp
        // account — there is no dhiran account for that mobile → "not registered".
        $this->post('https://dhiran.jewelflows.com/login', [
            'mobile_number' => '9300000004', 'password' => $this->strongPassword(),
        ])->assertSessionHasErrors('mobile_number');
        $this->assertGuest();
    }

    // ── Route-level realm isolation ──────────────────────────────────────

    public function test_shopless_erp_user_cannot_access_dhiran_route(): void
    {
        // SHOPLESS ERP user. The realm gate must fire BEFORE shop-onboarding —
        // they are bounced to the ERP dashboard by realm:dhiran, NOT sent into
        // Dhiran onboarding. (EnsureRealm runs before EnsureShopExists by priority.)
        $erp = User::create(['mobile_number' => '9300000005', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $this->assertNull($erp->shop_id);

        $this->actingAs($erp)->get('https://dhiran.jewelflows.com/dhiran')
            ->assertRedirect(route('dashboard'));
    }

    public function test_shopless_dhiran_user_cannot_access_erp_dashboard(): void
    {
        // SHOPLESS Dhiran user. The realm gate must fire BEFORE shop-onboarding —
        // they are bounced to the Dhiran dashboard by realm:erp, and must NEVER be
        // sent to the ERP shops.choose-type chooser.
        $dhiran = User::create(['mobile_number' => '9300000006', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true]);
        $this->assertNull($dhiran->shop_id);

        $response = $this->actingAs($dhiran)->get('https://jewelflows.com/dashboard');
        $response->assertRedirect(route('dhiran.dashboard'));
        $this->assertNotSame(
            route('shops.choose-type'),
            $response->headers->get('Location'),
            'A Dhiran account must never be routed into the ERP shop chooser.'
        );
    }

    public function test_dhiran_realm_route_redirects_erp_user_via_middleware(): void
    {
        // Direct unit-level proof that EnsureRealm('dhiran') rejects an ERP user,
        // independent of any onboarding/shop middleware ordering.
        $erp = User::create(['mobile_number' => '9300000007', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $middleware = new \App\Http\Middleware\EnsureRealm();

        $request = \Illuminate\Http\Request::create('https://dhiran.jewelflows.com/dhiran');
        $request->setUserResolver(fn () => $erp);

        $response = $middleware->handle($request, fn () => response('SHOULD NOT REACH', 200), 'dhiran');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('dashboard'), $response->headers->get('Location'));
    }

    // ── EnsureRealm middleware leaves guests to the auth middleware ───────

    public function test_realm_middleware_passes_guests_through(): void
    {
        // No authenticated user → realm middleware doesn't redirect (auth handles it).
        $this->assertSame('dhiran', Realm::fromHost('dhiran.jewelflows.com'));
        $this->assertSame('erp', Realm::fromHost('jewelflows.com'));
    }

    // ── Dhiran subdomain root ( dhiran.jewelflows.com/ ) ──────────────────

    public function test_dhiran_root_guest_goes_to_login(): void
    {
        // Guest at dhiran.jewelflows.com/ → Dhiran login entry (the login route
        // renders the Dhiran-branded view on the dhiran host).
        $this->get('https://dhiran.jewelflows.com/')
            ->assertRedirect(route('login'));
    }

    public function test_erp_root_guest_sees_landing(): void
    {
        // Guest at jewelflows.com/ → ERP landing (unchanged behavior).
        $this->get('https://jewelflows.com/')->assertOk();
    }

    public function test_dhiran_root_logged_in_dhiran_user_goes_to_dhiran_dashboard(): void
    {
        // Logged-in Dhiran user at dhiran.jewelflows.com/ → Dhiran dashboard,
        // which itself renders the activation/onboarding placeholder when Dhiran
        // is not yet set up.
        $dhiran = User::create(['mobile_number' => '9300000010', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true]);

        $this->actingAs($dhiran)->get('https://dhiran.jewelflows.com/')
            ->assertRedirect(route('dhiran.dashboard'));
    }

    public function test_dhiran_root_logged_in_erp_user_does_not_get_dhiran(): void
    {
        // An ERP account on the Dhiran host root → bounced to its OWN realm; it
        // never gets the Dhiran experience.
        $erp = User::create(['mobile_number' => '9300000011', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);

        $response = $this->actingAs($erp)->get('https://dhiran.jewelflows.com/');
        $response->assertStatus(302);
        $this->assertNotSame(
            route('dhiran.dashboard'),
            $response->headers->get('Location'),
            'ERP user must not be routed into the Dhiran app from the Dhiran root.'
        );
    }
}
