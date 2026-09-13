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
            ['database' => 'jewelflow', 'host' => 'db.example.com', 'port' => '6432', 'driver' => 'mysql'],
            environment: 'production',
            basePath: sys_get_temp_dir(),
        ));

        $this->assertCount(6, $violations, 'environment, driver, database, host, port and base path each report separately: '.implode(' | ', $violations));
    }

    /**
     * sqlite used to be exempted from the host check. It is now refused outright:
     * this suite is approved for one driver on one port on this machine, and a
     * silent switch to an in-memory sqlite would "pass" thousands of tests
     * against a schema nobody deploys.
     */
    public function test_the_guard_refuses_a_driver_that_is_not_the_approved_one(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(['driver' => 'sqlite', 'host' => null, 'port' => null])
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('driver is "sqlite"', implode(' | ', $violations));
    }

    public function test_the_guard_refuses_a_port_that_is_not_the_approved_one(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp(['port' => '6432']));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('6432', $violations[0]);
        $this->assertStringContainsString('a different port is a different server', $violations[0]);
    }

    /** A missing port is ambiguity, and ambiguity is not permission. */
    public function test_the_guard_refuses_a_missing_port(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp(['port' => null]));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('(none)', $violations[0]);
    }

    // ---------------------------------------------------------------------
    // DB_URL. config/database.php declares `'url' => env('DB_URL')` on every
    // connection, and ConnectionFactory expands it OVER the individual fields.
    // A guard that reads `database.connections.*.database` is reading the value
    // the framework is about to throw away.
    // ---------------------------------------------------------------------

    public function test_a_connection_url_beats_the_individual_fields_and_is_refused(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp([
            'url' => 'pgsql://someone:secret@203.0.113.10:5432/jewelflow',
        ]));

        $joined = implode(' | ', $violations);
        $this->assertStringContainsString('203.0.113.10', $joined, 'The host inside the URL is the host that would be reached.');
        $this->assertStringContainsString('jewelflow"', $joined, 'The database inside the URL is the database that would be dropped.');
        $this->assertStringContainsString('after expanding the connection URL', $joined, 'The operator must be told WHERE the refused value came from.');
    }

    /**
     * The worst shape: the URL's own host and path are the approved ones, and
     * only the query string redirects it. ConfigurationUrlParser merges query
     * options LAST, so `?database=` outranks the path and the literal key alike.
     */
    public function test_a_query_string_option_inside_the_url_cannot_smuggle_a_database_past(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp([
            'url' => 'pgsql://someone:secret@127.0.0.1:5432/jewelflow_testing?database=jewelflow',
        ]));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('database is "jewelflow"', $violations[0]);
    }

    /**
     * An unparsable URL means the destination is unknown, and unknown is not
     * "safe". Laravel's parser throws here (parse_url rejects a non-numeric
     * port), so the guard must convert that throw into a refusal rather than
     * letting the exception escape into an unrelated stack trace.
     */
    public function test_an_unparsable_connection_url_is_refused_rather_than_ignored(): void
    {
        $violations = TestDatabaseGuard::violations(
            $this->fakeApp(['url' => 'pgsql://someone@127.0.0.1:notaport/jewelflow_testing'])
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('cannot be parsed', implode(' | ', $violations));
    }

    /**
     * Garbage that parse_url happens to ACCEPT must still be refused — by the
     * ordinary destination checks, because the expanded values cannot match.
     * Worth pinning separately: "it threw" and "it produced nonsense" are two
     * different paths and only one of them is the catch block above.
     */
    public function test_a_url_that_parses_into_nonsense_is_still_refused(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp(['url' => '://not-a-url']));

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('expected "jewelflow_testing"', implode(' | ', $violations));
    }

    /** A read/write split carries its own host and database per half. */
    public function test_a_split_read_write_connection_is_refused(): void
    {
        $violations = TestDatabaseGuard::violations($this->fakeApp([
            'read' => ['host' => '203.0.113.20'],
        ]));

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('"read" half', $violations[0]);
    }

    // ---------------------------------------------------------------------
    // The refusal ITSELF — exit(1) before anything destructive.
    //
    // enforce() kills the process, so it cannot be asserted on from inside this
    // one. Each case below runs tests/Support/guard-probe.php in a child
    // process, which ends with a SENTINEL line standing in for the first thing
    // RefreshDatabase would do. The refusal is proven by that line's ABSENCE.
    // No probe connects to anything; the unsafe hosts are RFC 5737
    // documentation addresses and the databases are literals.
    // ---------------------------------------------------------------------

    private const SENTINEL = 'SENTINEL: destructive setup was reached';

    /**
     * The control case. Without it, every refusal below would be satisfied by a
     * guard that simply refuses everything.
     */
    public function test_the_approved_configuration_still_proceeds(): void
    {
        [$status, $output] = $this->runGuardProbe('approved');

        $this->assertSame(0, $status, "The approved configuration was refused:\n".$output);
        $this->assertStringContainsString(self::SENTINEL, $output);
    }

    public function test_a_safe_looking_config_with_an_unsafe_url_is_refused_before_anything_destructive(): void
    {
        [$status, $output] = $this->runGuardProbe('url-host-override');

        $this->assertSame(1, $status, 'A URL pointing off-box must end the process.');
        $this->assertStringContainsString(TestDatabaseGuard::REFUSAL_MARKER, $output);
        $this->assertStringContainsString('203.0.113.10', $output);
        $this->assertStringNotContainsString(self::SENTINEL, $output, 'The process reached destructive setup despite the refusal.');
    }

    public function test_a_url_query_option_is_refused_before_anything_destructive(): void
    {
        [$status, $output] = $this->runGuardProbe('url-database-query');

        $this->assertSame(1, $status);
        $this->assertStringContainsString('jewelflow"', $output);
        $this->assertStringNotContainsString(self::SENTINEL, $output);
    }

    /**
     * The regression for the removed process-wide memo. Laravel builds a NEW
     * application for every test, so "this process was already cleared" is never
     * a safe thing to remember.
     */
    public function test_a_cleared_application_cannot_vouch_for_a_later_unsafe_one(): void
    {
        [$status, $output] = $this->runGuardProbe('safe-then-unsafe');

        $this->assertStringContainsString('first application cleared', $output, 'The first application was supposed to pass.');
        $this->assertSame(1, $status, 'The second application was waved through on the strength of the first.');
        $this->assertStringNotContainsString(self::SENTINEL, $output);
    }

    /** @return array{0: int, 1: string} exit status and combined output */
    private function runGuardProbe(string $scenario): array
    {
        $probe = base_path('tests/Support/guard-probe.php');
        $this->assertFileExists($probe);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($probe).' '.escapeshellarg($scenario).' 2>&1', $output, $status);

        return [$status, implode(PHP_EOL, $output)];
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
                'port'     => '5432',
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
