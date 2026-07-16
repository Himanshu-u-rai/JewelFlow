<?php

namespace Tests\Feature;

use App\Support\Realm;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Cross-promotion "Explore Dhiran" URL safety (defects D9 + staging follow-up).
 *
 * There is no Dhiran DNS/cert outside production, so the CTA must:
 *  - use an explicit DHIRAN_REGISTER_URL when one is configured (any env);
 *  - derive from the request host ONLY in production and local dev;
 *  - return null (hide the CTA) on staging / any other env with no override;
 *  - never derive or link to production from a non-production environment;
 *  - treat an explicitly EMPTY override as "disabled" — never falling back to prod.
 */
class DhiranCrossPromoUrlTest extends TestCase
{
    private function url(string $host, string $environment): ?string
    {
        return Realm::dhiranRegisterUrl(Request::create($host), $environment);
    }

    /** Production + no override → derive the production Dhiran host. */
    public function test_production_no_override_derives_production_url(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => null]);

        $this->assertSame(
            'https://dhiran.jewelflows.com/register',
            $this->url('https://jewelflows.com/dashboard', 'production')
        );
    }

    /** Local + no override → derive the local Dhiran host (resolves locally). */
    public function test_local_no_override_derives_local_url(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => null]);

        $this->assertSame(
            'http://dhiran.localhost/register',
            $this->url('http://localhost/dashboard', 'local')
        );
    }

    /** Staging + no override → null (CTA hidden), and never production. */
    public function test_staging_no_override_returns_null_and_never_production(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => null]);

        $url = $this->url('https://staging.jewelflows.com/dashboard', 'staging');

        $this->assertNull($url);
        // Belt-and-braces: whatever it is, it is not the production host.
        $this->assertStringNotContainsString('dhiran.jewelflows.com', (string) $url);
    }

    /** Staging + explicit staging URL → that URL is used verbatim. */
    public function test_staging_explicit_url_is_used(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => 'https://dhiran.staging.example/register']);

        $this->assertSame(
            'https://dhiran.staging.example/register',
            $this->url('https://staging.jewelflows.com/dashboard', 'staging')
        );
    }

    /** An explicit production override is respected. */
    public function test_explicit_production_override_wins(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => 'https://dhiran.jewelflows.com/register']);

        $this->assertSame(
            'https://dhiran.jewelflows.com/register',
            $this->url('https://jewelflows.com/dashboard', 'production')
        );
    }

    /** An empty/disabled override must NOT fall back to production — even in prod env. */
    public function test_empty_override_disables_and_never_falls_back_to_production(): void
    {
        config(['platform.cross_promotion.dhiran_register_url' => '']);

        $this->assertNull($this->url('https://staging.jewelflows.com/dashboard', 'staging'));
        $this->assertNull($this->url('https://jewelflows.com/dashboard', 'production'));
    }
}
