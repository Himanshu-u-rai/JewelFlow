<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Production and staging set SESSION_SECURE_COOKIE=true (they are served over
 * HTTPS only). A developer's machine sets nothing and is served over plain
 * HTTP. Both have to keep working: the first must mark every cookie Secure,
 * the second must not, or a local login would never stick.
 */
class SessionCookieSecurityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function cookies(string $url): array
    {
        $cookies = [];
        foreach ($this->get($url)->assertOk()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        $this->assertCount(2, $cookies, 'expected the session cookie and XSRF-TOKEN');

        return $cookies;
    }

    public function test_with_the_deployed_setting_every_cookie_is_secure(): void
    {
        config(['session.secure' => true]);

        foreach ($this->cookies('https://jewelflows.com/login') as $name => $cookie) {
            $this->assertTrue($cookie->isSecure(), "{$name} is not Secure");
        }
    }

    public function test_the_session_cookie_stays_http_only_and_lax(): void
    {
        config(['session.secure' => true]);

        $session = $this->cookies('https://jewelflows.com/login')[config('session.cookie')];

        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $session->getSameSite()));
    }

    public function test_with_no_setting_a_plain_http_page_still_gets_usable_cookies(): void
    {
        // What a local .env has: the key absent, so the value is null.
        config(['session.secure' => null]);

        foreach ($this->cookies('http://localhost/login') as $name => $cookie) {
            $this->assertFalse($cookie->isSecure(), "{$name} would be dropped by a browser on http://localhost");
        }
    }

    public function test_the_repository_default_is_unset_not_forced(): void
    {
        // config/session.php must not hard-code a value: `true` would break
        // local HTTP, `false` would switch the attribute off on HTTPS too.
        $this->assertStringContainsString("'secure' => env('SESSION_SECURE_COOKIE'),", file_get_contents(config_path('session.php')));
    }
}
