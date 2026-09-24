<?php

namespace Tests;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * TenantContext is a process-wide static and PHPUnit runs every test in
     * one process: without this, one test's shop is still set when the next
     * test starts, and a scoped query or assertion there runs as that shop.
     */
    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
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
