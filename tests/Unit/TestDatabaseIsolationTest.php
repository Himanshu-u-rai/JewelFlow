<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A SELF-EVIDENCING GUARD ON WHICH DATABASE THE SUITE JUST RAN AGAINST.
 *
 * RefreshDatabase runs migrate:fresh, which DROPS EVERY TABLE. If the effective
 * connection is ever the local dev database (jewelflow_test) or anything remote,
 * the suite destroys real data — and it does so silently, because a passing test
 * run looks identical either way.
 *
 * phpunit.xml sets DB_DATABASE=jewelflow_testing, but that is a DEFAULT, not a
 * guarantee: a PHPUnit <env> entry without force="true" loses to a real OS
 * environment variable of the same name. Reading the XML therefore does not
 * establish what the suite connected to. This test reads the value the framework
 * actually resolved, so every run carries its own proof.
 *
 * Deliberately in tests/Unit and deliberately WITHOUT RefreshDatabase: it must be
 * able to fail BEFORE anything has been dropped.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_the_suite_is_pointed_at_the_disposable_test_database(): void
    {
        $this->assertSame(
            'jewelflow_testing',
            DB::connection()->getDatabaseName(),
            'REFUSE TO RUN: RefreshDatabase drops every table. jewelflow_test is the local DEV database and must never be the target.'
        );
    }

    public function test_the_connection_is_local(): void
    {
        $this->assertContains(
            DB::connection()->getConfig('host'),
            ['127.0.0.1', 'localhost'],
            'A non-local host means the suite is about to migrate:fresh a remote database.'
        );
    }

    public function test_the_environment_is_testing(): void
    {
        $this->assertSame('testing', app()->environment());
    }

    /**
     * Pins the application base path to the repository root that owns this file.
     * A stale bootstrap path (a worktree deleted and recreated, a symlinked
     * vendor/) silently runs another checkout's code while reporting this one's.
     */
    public function test_the_application_base_path_is_this_repository(): void
    {
        $this->assertSame(
            realpath(dirname(__DIR__, 2)),
            realpath(base_path()),
            'The application booted from a different directory than the one holding this test.'
        );
    }
}
