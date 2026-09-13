<?php

/**
 * A DISPOSABLE PROCESS THAT ASKS THE GUARD TO DO ITS WORST.
 *
 * Tests\TestDatabaseGuard::enforce() defends the suite by calling exit(1). That
 * is untestable from inside PHPUnit — the assertion that proves it works would
 * kill the run making it. So the refusal is exercised out here, in a child
 * process, and tests/Unit/TestDatabaseIsolationTest.php asserts on the exit code
 * and the output.
 *
 * NOTHING HERE CONNECTS TO ANYTHING. Every scenario is a configuration array
 * handed to a bare Illuminate application that has had no bootstrappers run and
 * holds no database manager. The "unsafe" hosts are RFC 5737 documentation
 * addresses (203.0.113.0/24, TEST-NET-3) and the database names are literals;
 * they are never reachable, and by design they never need to be — the guard's
 * whole claim is that it refuses BEFORE anything is opened.
 *
 * Usage: php tests/Support/guard-probe.php <scenario>
 * Exit 0 + SENTINEL on stdout  => the guard let the process through.
 * Exit 1 + refusal on stderr   => the guard stopped it, and the SENTINEL line
 *                                 (which stands in for migrate:fresh) was never
 *                                 reached.
 */

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Tests\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';

/** Stands in for the first destructive thing RefreshDatabase would do. */
const SENTINEL = 'SENTINEL: destructive setup was reached';

$repositoryRoot = dirname(__DIR__, 2);

/**
 * A real application object with a hand-built config repository. Constructing it
 * registers only the base bindings and the event/log/routing providers — no
 * database manager, no bootstrappers, no connection.
 */
$application = static function (array $connection, string $environment = 'testing', ?string $basePath = null) use ($repositoryRoot): Application {
    $app = new Application($basePath ?? $repositoryRoot);

    $app['env'] = $environment;
    $app->instance('config', new Repository([
        'database' => [
            'default'     => 'pgsql',
            'connections' => ['pgsql' => $connection],
        ],
    ]));

    return $app;
};

/** The configuration this repository is actually allowed to run against. */
$approved = [
    'driver'   => 'pgsql',
    'host'     => '127.0.0.1',
    'port'     => '5432',
    'database' => TestDatabaseGuard::EXPECTED_DATABASE,
];

$scenario = $argv[1] ?? '';

switch ($scenario) {
    // The control. If this one ever fails, every refusal below proves nothing,
    // because a guard that refuses everything is not a guard.
    case 'approved':
        TestDatabaseGuard::enforce($application($approved));
        break;

        // Every individual field is the approved one. The URL — which
        // config/database.php really does read from DB_URL — points somewhere
        // else, and ConnectionFactory would have honoured the URL.
    case 'url-host-override':
        TestDatabaseGuard::enforce($application($approved + [
            'url' => 'pgsql://someone:secret@203.0.113.10:5432/jewelflow_testing',
        ]));
        break;

        // Nastier: the URL itself looks local and correctly named, and only the
        // query string redirects it. Query options are merged LAST by
        // ConfigurationUrlParser, so this beats both the path and the literal
        // 'database' key sitting beside it.
    case 'url-database-query':
        TestDatabaseGuard::enforce($application($approved + [
            'url' => 'pgsql://someone:secret@127.0.0.1:5432/jewelflow_testing?database=jewelflow',
        ]));
        break;

        // The regression for the removed process-wide $cleared flag: a first,
        // genuinely safe application must not vouch for a second one. Laravel
        // builds a new application for EVERY test, so this is the ordinary case,
        // not an exotic one.
    case 'safe-then-unsafe':
        TestDatabaseGuard::enforce($application($approved));
        fwrite(STDOUT, 'first application cleared'.PHP_EOL);
        TestDatabaseGuard::enforce($application(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '5432', 'database' => 'jewelflow']));
        break;

    default:
        fwrite(STDERR, 'unknown scenario: '.$scenario.PHP_EOL);
        exit(2);
}

fwrite(STDOUT, SENTINEL.PHP_EOL);
