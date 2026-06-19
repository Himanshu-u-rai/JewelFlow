<?php

namespace Tests\Feature;

use App\Http\Middleware\UseRouteScopedSessionCookie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Dhiran separation — Phase 1 auth hardening.
 *
 * Verifies the two cross-realm hazards found in Phase 0 are closed:
 *  - password reset is realm-scoped (tokens keyed by (email, realm); a reset in
 *    one realm can't touch the same email's account in the other realm);
 *  - the session cookie name is chosen per host so a Dhiran login and an ERP
 *    login don't overwrite each other.
 */
class RealmAuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $mobile, string $email, string $realm): User
    {
        return User::create([
            'name' => 'T', 'mobile_number' => $mobile, 'email' => $email,
            'password' => bcrypt('OldPassword123!'), 'realm' => $realm,
        ]);
    }

    // ── Password reset realm scoping ─────────────────────────────────────

    public function test_reset_token_is_keyed_per_realm(): void
    {
        $erp = $this->user('9100000001', 'dual@example.com', 'erp');
        $dhiran = $this->user('9100000002', 'dual@example.com', 'dhiran');

        // Forgot-password on the ERP (main) host → erp-realm token.
        $this->post('/forgot-password', ['email' => 'dual@example.com']);

        // Exactly one token row, and it is the erp realm one.
        $rows = DB::table('password_reset_tokens')->where('email', 'dual@example.com')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('erp', $rows->first()->realm);

        // Forgot-password on the DHIRAN host → an independent dhiran-realm token,
        // for the SAME email (both coexist). The host is set via an absolute URL.
        $this->post('https://dhiran.jewelflows.com/forgot-password', ['email' => 'dual@example.com']);

        $realms = DB::table('password_reset_tokens')->where('email', 'dual@example.com')
            ->pluck('realm')->sort()->values()->all();
        $this->assertSame(['dhiran', 'erp'], $realms, 'each realm holds its own token for the same email');
    }

    public function test_reset_broker_resolves_only_within_the_request_realm(): void
    {
        $erp = $this->user('9100000003', 'scoped@example.com', 'erp');

        // A forgot-password on the DHIRAN host for an email that exists ONLY in
        // erp must NOT find/notify the erp user (realm-scoped lookup). The broker
        // returns the "user not found" status, so no token row is created.
        $this->post('https://dhiran.jewelflows.com/forgot-password', ['email' => 'scoped@example.com']);

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'scoped@example.com')->count(),
            'a dhiran reset must not create a token for an erp-only account');
    }

    // ── Session cookie isolation ─────────────────────────────────────────

    public function test_session_cookie_name_is_chosen_by_host(): void
    {
        $mw = new UseRouteScopedSessionCookie();

        $run = function (string $host, string $path = '/') use ($mw): string {
            $req = Request::create('https://' . $host . $path, 'GET');
            $mw->handle($req, fn () => response('ok'));
            return config('session.cookie');
        };

        $this->assertSame(config('session.dhiran_cookie'), $run('dhiran.jewelflows.com'));
        $this->assertSame(config('session.tenant_cookie'), $run('jewelflows.com'));
        $this->assertSame(config('session.platform_admin_cookie'), $run('jewelflows.com', '/admin'));
        // Distinct names → independent sessions in one browser.
        $this->assertNotSame(config('session.dhiran_cookie'), config('session.tenant_cookie'));
    }
}
