<?php

namespace Tests\Feature;

use App\Support\Realm;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Cross-promotion "Explore Dhiran" URL safety (defect D9).
 *
 * The dashboard CTA must never send a staging user to the production Dhiran host.
 * With no explicit DHIRAN_REGISTER_URL override, the URL is derived from the
 * CURRENT request host by prefixing the `dhiran.` subdomain — so the environment
 * (local/staging/prod) is always preserved. An explicit env override still wins.
 */
class DhiranCrossPromoUrlTest extends TestCase
{
    private function urlForHost(string $url): string
    {
        return Realm::dhiranRegisterUrl(Request::create($url));
    }

    /** Production ERP host derives the production Dhiran host. */
    public function test_production_host_derives_production_dhiran_url(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => null]);

        $this->assertSame(
            'https://dhiran.jewelflows.com/register',
            $this->urlForHost('https://jewelflows.com/dashboard')
        );
    }

    /** Staging ERP host derives the STAGING Dhiran host — never production. */
    public function test_staging_host_never_resolves_to_production(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => null]);

        $url = $this->urlForHost('https://staging.jewelflows.com/dashboard');

        $this->assertSame('https://dhiran.staging.jewelflows.com/register', $url);
        $this->assertStringNotContainsString('dhiran.jewelflows.com', $url);
    }

    /** An already-Dhiran host is used as-is (no double subdomain), scheme preserved. */
    public function test_dhiran_host_is_used_as_is(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => null]);

        $this->assertSame(
            'http://dhiran.localhost/register',
            $this->urlForHost('http://dhiran.localhost/dashboard')
        );
    }

    /** An explicit env/config override always wins. */
    public function test_explicit_override_wins(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => 'https://custom.example/register']);

        $this->assertSame(
            'https://custom.example/register',
            $this->urlForHost('https://staging.jewelflows.com/dashboard')
        );
    }
}
