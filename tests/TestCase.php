<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
