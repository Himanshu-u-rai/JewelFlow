<?php

namespace Tests\Feature\Security;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Staging and production share the parent domain (.jewelflows.com). With one
 * set of cookie names, a single staging page load replaced production's
 * tenant and platform-admin session cookies in the same browser (measured
 * 2026-09-25 with tests/Staging/cookie_isolation_probe.sh). Different APP_KEYs
 * do not prevent that: the browser overwrites by name, domain and path.
 *
 * config/session.php is evaluated in a child process per environment, so each
 * case sees exactly the environment variables that server would have.
 */
class SessionCookieNamespaceTest extends TestCase
{
    private const NAMES = ['cookie', 'tenant_cookie', 'platform_admin_cookie', 'dhiran_cookie'];

    private function sessionConfig(array $env): array
    {
        $unset = array_fill_keys([
            'SESSION_COOKIE', 'TENANT_SESSION_COOKIE', 'PLATFORM_ADMIN_SESSION_COOKIE',
            'DHIRAN_SESSION_COOKIE', 'SESSION_DOMAIN',
        ], false);
        $process = new Process(
            // A bare Application (for storage_path()); it loads no .env, so the
            // child sees only the variables passed here.
            [PHP_BINARY, '-r', 'require "vendor/autoload.php"; new Illuminate\\Foundation\\Application(getcwd()); echo json_encode(require "config/session.php");'],
            base_path(),
            $env + ['APP_NAME' => 'JewelFlows'] + $unset,
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_production_keeps_the_names_and_domain_its_users_hold(): void
    {
        $c = $this->sessionConfig(['APP_ENV' => 'production', 'SESSION_DOMAIN' => '.jewelflows.com']);

        $this->assertSame('jewelflows-session', $c['cookie']);
        $this->assertSame('jewelflows-session', $c['tenant_cookie']);
        $this->assertSame('jewelflows-platform-admin-session', $c['platform_admin_cookie']);
        $this->assertSame('jewelflows-dhiran-session', $c['dhiran_cookie']);
        $this->assertSame('.jewelflows.com', $c['domain']);
    }

    public function test_staging_uses_its_own_names_and_a_host_only_cookie(): void
    {
        // Staging's .env carries production's parent domain; that must not
        // reach the browser, or XSRF-TOKEN and remember_* (fixed names)
        // still land on production's hosts.
        $staging = $this->sessionConfig(['APP_ENV' => 'staging', 'SESSION_DOMAIN' => '.jewelflows.com']);
        $production = $this->sessionConfig(['APP_ENV' => 'production', 'SESSION_DOMAIN' => '.jewelflows.com']);

        $this->assertNull($staging['domain']);
        $shared = array_intersect(
            array_map(fn ($k) => $staging[$k], self::NAMES),
            array_map(fn ($k) => $production[$k], self::NAMES),
        );
        $this->assertSame([], array_values($shared), 'staging shares a cookie name with production');
    }

    public function test_a_production_name_copied_into_staging_is_still_namespaced(): void
    {
        $c = $this->sessionConfig([
            'APP_ENV' => 'staging',
            'SESSION_COOKIE' => 'jewelflows-session',
            'PLATFORM_ADMIN_SESSION_COOKIE' => 'jewelflows-platform-admin-session',
        ]);

        $this->assertNotSame('jewelflows-session', $c['tenant_cookie']);
        $this->assertNotSame('jewelflows-platform-admin-session', $c['platform_admin_cookie']);
    }

    public function test_a_page_load_here_sets_only_namespaced_host_only_cookies(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $cookies = $response->headers->getCookies();
        $this->assertNotEmpty($cookies);
        foreach ($cookies as $cookie) {
            $this->assertNull($cookie->getDomain(), $cookie->getName().' is scoped to a parent domain');
        }
        $names = array_map(fn ($c) => $c->getName(), $cookies);
        $this->assertContains(config('session.tenant_cookie'), $names);
        $this->assertStringEndsWith('-'.app()->environment(), config('session.tenant_cookie'));
    }
}
