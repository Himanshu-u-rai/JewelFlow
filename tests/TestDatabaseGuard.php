<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;

/**
 * REFUSES TO LET THE SUITE RUN AGAINST ANYTHING BUT THE DISPOSABLE TEST DATABASE.
 *
 * RefreshDatabase runs migrate:fresh, which DROPS EVERY TABLE. Pointed at the
 * local dev database (jewelflow_test), at staging, or at production, the suite
 * destroys real data — and it does so silently, because a passing run looks
 * identical either way.
 *
 * phpunit.xml sets DB_DATABASE=jewelflow_testing, but that is a DEFAULT, not a
 * guarantee: a PHPUnit <env> entry WITHOUT force="true" loses to a real OS
 * environment variable of the same name. Reading the XML therefore establishes
 * nothing about what the suite will connect to. This guard reads the values the
 * framework actually RESOLVED, after the container has booted and after every
 * override has been applied.
 *
 * Why it exits instead of failing a test: a failing test aborts one test. The
 * remaining suite keeps running, and the next class with RefreshDatabase drops
 * the tables anyway. Refusal has to end the PROCESS, before any destructive
 * setup, or it is not a safeguard.
 *
 * Called from Tests\TestCase::refreshApplication(), which Laravel runs inside
 * setUp() AFTER the application is created and BEFORE setUpTraits() boots
 * RefreshDatabase. That ordering is the whole point of the hook choice.
 */
final class TestDatabaseGuard
{
    /** The only database this suite may touch. Created disposable, wiped freely. */
    public const EXPECTED_DATABASE = 'jewelflow_testing';

    /** The only driver approved for this suite. */
    public const EXPECTED_DRIVER = 'pgsql';

    /** The only port approved for this suite. A different port is a different server. */
    public const EXPECTED_PORT = 5432;

    /** Loopback only. A pooler, a tunnel or a VPN host is not this machine. */
    public const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    /** Greppable marker so a refusal is unmistakable in CI output and evidence logs. */
    public const REFUSAL_MARKER = 'REFUSING TO RUN THE TEST SUITE';

    /**
     * Every reason the suite must not proceed. Empty array means safe.
     *
     * Reads configuration only — nothing here opens a connection, so a guard
     * check can never itself reach the database it is protecting.
     *
     * @return list<string>
     */
    public static function violations(Application $app): array
    {
        $config     = $app->make('config');
        $connection = $config->get('database.default');
        $declared   = $config->get('database.connections.'.$connection, []);

        $violations = [];

        if ($app->environment() !== 'testing') {
            $violations[] = sprintf(
                'environment is "%s", expected "testing"',
                $app->environment()
            );
        }

        // config/database.php declares `'url' => env('DB_URL')` on every
        // connection. Laravel expands that URL in ConnectionFactory::parseConfig
        // and it WINS over the individual fields — and because getQueryOptions()
        // is merged LAST, even `?database=…` in the query string overrides them.
        // Reading `database.connections.*.database` alone would therefore have
        // waved through a URL pointed at production while reporting the safe
        // literal value sitting next to it. Resolve the destination exactly the
        // way the framework will, using the framework's own parser, and still
        // without opening anything.
        $hadUrl = ($declared['url'] ?? null) !== null;

        try {
            $settings = (new ConfigurationUrlParser)->parseConfiguration($declared);
        } catch (InvalidArgumentException $e) {
            // An unparsable URL is not "probably fine". Refuse and say so.
            return [...$violations, sprintf(
                'connection "%s" carries a DB_URL that cannot be parsed (%s), so the real destination is unknowable',
                $connection,
                $e->getMessage()
            )];
        }

        $describe = $hadUrl
            ? sprintf(' (after expanding the connection URL on "%s")', $connection)
            : sprintf(' on connection "%s"', $connection);

        // A read/write split carries its own host and database per half, merged
        // later by the connection factory. This suite never uses one, so its
        // presence means the effective destination is not what is checked here.
        foreach (['read', 'write'] as $half) {
            if (isset($settings[$half])) {
                $violations[] = sprintf(
                    'connection "%s" declares a "%s" half, whose own host/database this guard does not resolve',
                    $connection,
                    $half
                );
            }
        }

        $driver = $settings['driver'] ?? null;
        if ($driver !== self::EXPECTED_DRIVER) {
            $violations[] = sprintf(
                'driver is "%s"%s, expected "%s"',
                $driver ?? '(none)',
                $describe,
                self::EXPECTED_DRIVER
            );
        }

        $database = $settings['database'] ?? null;
        if ($database !== self::EXPECTED_DATABASE) {
            $violations[] = sprintf(
                'database is "%s"%s, expected "%s" — migrate:fresh would DROP EVERY TABLE in it',
                $database ?? '(none)',
                $describe,
                self::EXPECTED_DATABASE
            );
        }

        $host = $settings['host'] ?? null;
        if (! in_array($host, self::LOCAL_HOSTS, true)) {
            $violations[] = sprintf(
                'database host is "%s"%s, expected a local host — a remote host means a remote database is about to be rebuilt',
                $host ?? '(none)',
                $describe
            );
        }

        // Compared numerically because the config carries it as a string and a
        // URL carries it as an int. A missing port is a violation, not a
        // default: this suite knows which server it is allowed to reach.
        $port = $settings['port'] ?? null;
        if ($port === null || (int) $port !== self::EXPECTED_PORT) {
            $violations[] = sprintf(
                'database port is "%s"%s, expected %d — a different port is a different server',
                $port === null ? '(none)' : $port,
                $describe,
                self::EXPECTED_PORT
            );
        }

        // A stale bootstrap path (worktree deleted and recreated, vendor/ symlinked
        // to another checkout) silently runs a different repository's code while
        // reporting this one's results.
        $expectedBasePath = realpath(dirname(__DIR__));
        $actualBasePath   = realpath($app->basePath());
        if ($expectedBasePath !== $actualBasePath) {
            $violations[] = sprintf(
                'application booted from "%s" but this test suite lives in "%s"',
                $actualBasePath ?: '(unresolvable)',
                $expectedBasePath ?: '(unresolvable)'
            );
        }

        return $violations;
    }

    /**
     * Hard stop. Prints why, then kills the process before any destructive setup.
     *
     * Deliberately NOT memoized. Laravel builds a fresh application for every
     * single test, and `config(['database.default' => …])` inside one test — or
     * a second application created in the same process — can point the next one
     * somewhere else entirely. A process-wide "already cleared" flag would let
     * the first safe application vouch for every later unsafe one, which is the
     * precise hole this guard exists to close. The checks are array reads; the
     * cost of repeating them is not measurable against booting the app anyway.
     */
    public static function enforce(Application $app): void
    {
        $violations = self::violations($app);

        if ($violations === []) {
            return;
        }

        fwrite(STDERR, PHP_EOL.str_repeat('=', 78).PHP_EOL);
        fwrite(STDERR, self::REFUSAL_MARKER.PHP_EOL.PHP_EOL);
        foreach ($violations as $violation) {
            fwrite(STDERR, '  - '.$violation.PHP_EOL);
        }
        fwrite(STDERR, PHP_EOL.'No migration, truncation or seeding has been performed. Nothing was changed.'.PHP_EOL);
        fwrite(STDERR, str_repeat('=', 78).PHP_EOL.PHP_EOL);

        exit(1);
    }
}
