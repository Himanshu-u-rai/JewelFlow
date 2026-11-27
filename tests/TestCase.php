<?php

namespace Tests;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The server-side daily metal-rate gate (EnsureDailyRatesEntered) refuses every
     * owner request until today's rates are saved. Most tests exercise one feature
     * with a test owner who has no rates, so they run as an owner past the gate;
     * DailyRateGateTest sets this to true and tests the gate itself.
     */
    protected bool $dailyRateGate = false;

    /**
     * TenantContext is a process-wide static and PHPUnit runs every test in
     * one process: without this, one test's shop is still set when the next
     * test starts, and a scoped query or assertion there runs as that shop.
     */
    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();

        if (! $this->dailyRateGate) {
            $this->withoutMiddleware(\App\Http\Middleware\EnsureDailyRatesEntered::class);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /**
     * Laravel calls this from setUp() after the container is built and BEFORE
     * setUpTraits() boots RefreshDatabase. It is the last point at which the
     * resolved configuration is known and nothing has been dropped yet.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        TestDatabaseGuard::enforce($this->app);
    }
}
