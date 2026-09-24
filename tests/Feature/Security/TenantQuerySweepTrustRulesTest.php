<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The inventory scanner's trust rules, against fixtures whose outcome is
 * known (tests/Inventory/Fixtures/TrustRuleFixtures.php). An expression the
 * scanner cannot show to be constrained to the caller's shop must stay a row
 * to read: a negated or non-equality shop filter, a shop_id that is only
 * assigned, a record key whose record was not obtained with its ownership
 * established, and a key reached through a relation. Each has a control that
 * the scanner may exclude.
 *
 * Raw SQL (tests/Inventory/Fixtures/RawSqlFixtures.php): every table a
 * statement names — joins, subqueries, FROM lists — is found, a function
 * after FROM (NOW(), generate_series()) is not a table, and a table the
 * scanner cannot identify stays a row to read; it is never taken for a
 * platform-wide table because its ownership lookup came back empty.
 */
class TenantQuerySweepTrustRulesTest extends TestCase
{
    use RefreshDatabase;   // the scanner reads the migrated schema

    public function test_uncertain_expressions_remain_review_candidates_and_the_controls_do_not(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The scanner reads the PostgreSQL schema.');
        }
        $expected = [];
        foreach (glob(base_path('tests/Inventory/Fixtures/*.php')) as $fixture) {
            foreach (file($fixture) as $i => $line) {
                if (preg_match('/\/\/ expect: (review|trusted|global)(?: tables=(\S+))?$/', rtrim($line), $m)) {
                    $expected[basename($fixture).':'.($i + 1)] = [$m[1], $m[2] ?? null];
                }
            }
        }
        $this->assertCount(33, $expected, 'every fixture line carries a marker');

        $run = Process::path(base_path())->timeout(300)
            ->run(['php', 'tests/Inventory/tenant_query_sweep.php', '--path=tests/Inventory/Fixtures', '--all']);
        $this->assertSame(0, $run->exitCode(), $run->errorOutput());
        $this->assertStringContainsString('parse failures: 0', $run->output());

        $status = [];
        foreach (explode("\n", $run->output()) as $row) {
            $cols = explode("\t", $row);
            if (count($cols) === 11 && preg_match('/([^\/]+):(\d+)$/', $cols[2], $m)) {
                $status[$m[1].':'.$m[2]][] = [in_array($cols[9], ['trusted', 'global'], true) ? $cols[9] : 'review', $cols[5]];
            }
        }
        foreach ($expected as $at => [$want, $tables]) {
            $this->assertArrayHasKey($at, $status, "{$at}: the scanner found no expression");
            $this->assertSame([$want], array_values(array_unique(array_column($status[$at], 0))), "{$at}: expected {$want}");
            if ($tables !== null) {
                $this->assertSame($tables, $status[$at][0][1], "{$at}: the tables the statement names");
            }
        }
    }
}
