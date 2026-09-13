<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;
use Tests\TestDatabaseGuard;

/**
 * SELF-EVIDENCING PROOF OF WHICH DATABASE THE SUITE JUST RAN AGAINST, plus unit
 * coverage of the guard that enforces it.
 *
 * The enforcement itself lives in Tests\TestDatabaseGuard, which exits the
 * process from Tests\TestCase::refreshApplication() before RefreshDatabase can
 * drop anything. This class does two separate jobs:
 *
 *  1. records, in every run's output, the values the framework actually
 *     resolved — so the log is its own evidence; and
 *  2. exercises the guard's decision function against deliberately wrong
 *     configuration, WITHOUT any such configuration ever reaching a connection.
 *
 * Deliberately in tests/Unit and deliberately WITHOUT RefreshDatabase.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_the_guard_clears_this_run(): void
    {
        $this->assertSame(
            [],
            TestDatabaseGuard::violations($this->app),
            'The guard found a reason this run was unsafe; it should already have refused.'
        );
    }

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

    // ---------------------------------------------------------------------
    // The guard's decision function, against configuration that must be refused.
    // Nothing below opens a connection: violations() reads config only.
    // ---------------------------------------------------------------------

    public function test_the_guard_refuses_the_local_dev_database(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(['database' => 'jewelflow_test'])
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('jewelflow_test', $violations[0]);
        $this->assertStringContainsString('DROP EVERY TABLE', $violations[0]);
    }

    public function test_the_guard_refuses_a_remote_host(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(['host' => '203.0.113.10'])
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('203.0.113.10', $violations[0]);
    }

    public function test_the_guard_refuses_a_non_testing_environment(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(environment: 'production')
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('production', $violations[0]);
    }

    public function test_the_guard_refuses_a_foreign_base_path(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(basePath: sys_get_temp_dir())
        );

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('booted from', $violations[0]);
    }

    /** Every check reports independently — one wrong value must not mask another. */
    public function test_the_guard_reports_every_violation_at_once(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp(
            ['database' => 'jewelflow', 'host' => 'db.example.com'],
            environment: 'production',
            basePath: sys_get_temp_dir(),
        ));

        $this->assertCount(4, $violations);
    }

    /** sqlite has no host, so the host check must not fire a false refusal. */
    public function test_the_guard_does_not_demand_a_host_from_sqlite(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(['driver' => 'sqlite', 'host' => null])
        );

        $this->assertSame([], $violations);
    }

    /**
     * An application whose resolved configuration is safe, with the named keys
     * overridden. A mock, not a container: none of these values may ever be
     * usable to connect to anything.
     */
    private function fakeApp(
        array $connectionOverrides = [],
        string $environment = 'testing',
        ?string $basePath = null,
    ): Application {
        $config = new Repository(['database' => [
            'default'     => 'pgsql',
            'connections' => ['pgsql' => array_merge([
                'driver'   => 'pgsql',
                'host'     => '127.0.0.1',
                'database' => TestDatabaseGuard::EXPECTED_DATABASE,
            ], $connectionOverrides)],
        ]]);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')->with('config')->andReturn($config);
        $app->shouldReceive('environment')->withNoArgs()->andReturn($environment);
        $app->shouldReceive('basePath')->withNoArgs()->andReturn($basePath ?? dirname(__DIR__, 2));

        return $app;
    }
}
