<?php

namespace Tests;

use Illuminate\Contracts\Foundation\Application;

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

    /** Greppable marker so a refusal is unmistakable in CI output and evidence logs. */
    public const REFUSAL_MARKER = 'REFUSING TO RUN THE TEST SUITE';

    private static bool $cleared = false;

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
        $settings   = $config->get('database.connections.'.$connection, []);

        $violations = [];

        if ($app->environment() !== 'testing') {
            $violations[] = sprintf(
                'environment is "%s", expected "testing"',
                $app->environment()
            );
        }

        $database = $settings['database'] ?? null;
        if ($database !== self::EXPECTED_DATABASE) {
            $violations[] = sprintf(
                'database is "%s" on connection "%s", expected "%s" — migrate:fresh would DROP EVERY TABLE in it',
                $database ?? '(none)',
                $connection,
                self::EXPECTED_DATABASE
            );
        }

        // sqlite has no host; every server-backed driver must be on this machine.
        if (($settings['driver'] ?? null) !== 'sqlite') {
            $host = $settings['host'] ?? null;
            if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
                $violations[] = sprintf(
                    'database host is "%s", expected a local host — a remote host means a remote database is about to be rebuilt',
                    $host ?? '(none)'
                );
            }
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
     * Memoized: once a process is proven safe the checks are a single bool read,
     * so this costs nothing across thousands of tests.
     */
    public static function enforce(Application $app): void
    {
        if (self::$cleared) {
            return;
        }

        $violations = self::violations($app);

        if ($violations === []) {
            self::$cleared = true;

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
