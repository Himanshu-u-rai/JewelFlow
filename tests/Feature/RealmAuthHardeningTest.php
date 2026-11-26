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
 * Auth hardening:
 *  - password reset tokens are keyed by (email, realm);
 *  - the session cookie name is chosen per route (tenant vs platform admin).
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
        $this->user('9100000001', 'dual@example.com', 'erp');

        $this->post('/forgot-password', ['email' => 'dual@example.com']);

        $rows = DB::table('password_reset_tokens')->where('email', 'dual@example.com')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('erp', $rows->first()->realm);
    }

    // ── Session cookie isolation ─────────────────────────────────────────

    public function test_session_cookie_name_is_chosen_by_route(): void
    {
        $mw = new UseRouteScopedSessionCookie();

        $run = function (string $host, string $path = '/') use ($mw): string {
            $req = Request::create('https://' . $host . $path, 'GET');
            $mw->handle($req, fn () => response('ok'));
            return config('session.cookie');
        };

        $this->assertSame(config('session.tenant_cookie'), $run('jewelflows.com'));
        $this->assertSame(config('session.platform_admin_cookie'), $run('jewelflows.com', '/admin'));
        // Distinct names → independent sessions in one browser.
        $this->assertNotSame(config('session.platform_admin_cookie'), config('session.tenant_cookie'));
    }
}
