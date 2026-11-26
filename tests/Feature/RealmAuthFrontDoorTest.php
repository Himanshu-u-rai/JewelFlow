<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer-facing front door: registration creates an ERP-realm account, login
 * is realm-scoped, and the landing page is public.
 */
class RealmAuthFrontDoorTest extends TestCase
{
    use RefreshDatabase;

    private function strongPassword(): string
    {
        return 'StrongPass123!';
    }

    // ── Registration by realm ────────────────────────────────────────────

    public function test_erp_register_creates_an_erp_account(): void
    {
        $this->post('https://jewelflows.com/register', [
            'mobile_number' => '9300000002',
            'password' => $this->strongPassword(),
            'password_confirmation' => $this->strongPassword(),
        ])->assertRedirect(route('shops.choose-type'));

        $this->assertSame('erp', User::where('mobile_number', '9300000002')->first()->realm);
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

        // An unregistered mobile is rejected.
        $this->post('https://jewelflows.com/login', [
            'mobile_number' => '9300000099', 'password' => $this->strongPassword(),
        ])->assertSessionHasErrors('mobile_number');
        $this->assertGuest();
    }

    public function test_erp_root_guest_sees_landing(): void
    {
        // Guest at jewelflows.com/ → ERP landing (unchanged behavior).
        $this->get('https://jewelflows.com/')->assertOk();
    }

    public function test_realm_middleware_lets_guests_and_erp_accounts_through_and_refuses_others(): void
    {
        $middleware = new \App\Http\Middleware\EnsureRealm();
        $next = fn () => response('reached', 200);
        $request = fn (?User $u) => tap(\Illuminate\Http\Request::create('https://jewelflows.com/masters'))->setUserResolver(fn () => $u);

        // Guests are left to the route's own auth middleware.
        $this->assertSame('reached', $middleware->handle($request(null), $next, 'erp')->getContent());

        $erp = User::create(['mobile_number' => '9300000020', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $this->assertSame('reached', $middleware->handle($request($erp), $next, 'erp')->getContent());

        // A route gated to some other realm refuses an ERP account outright.
        try {
            $middleware->handle($request($erp), $next, 'other');
            $this->fail('A realm mismatch was let through.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
