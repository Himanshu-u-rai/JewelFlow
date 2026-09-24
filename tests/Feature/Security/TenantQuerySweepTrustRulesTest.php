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
 */
class TenantQuerySweepTrustRulesTest extends TestCase
{
    use RefreshDatabase;   // the scanner reads the migrated schema

    public function test_uncertain_expressions_remain_review_candidates_and_the_controls_do_not(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The scanner reads the PostgreSQL schema.');
        }
        $fixture = base_path('tests/Inventory/Fixtures/TrustRuleFixtures.php');
        $expected = [];
        foreach (file($fixture) as $i => $line) {
            if (preg_match('/;\s*\/\/ expect: (review|trusted)$/', rtrim($line), $m)) {
                $expected[$i + 1] = $m[1];
            }
        }
        $this->assertCount(14, $expected, 'every fixture line carries a marker');

        $run = Process::path(base_path())->timeout(300)
            ->run(['php', 'tests/Inventory/tenant_query_sweep.php', '--path=tests/Inventory/Fixtures', '--all']);
        $this->assertSame(0, $run->exitCode(), $run->errorOutput());
        $this->assertStringContainsString('parse failures: 0', $run->output());

        $status = [];
        foreach (explode("\n", $run->output()) as $row) {
            $cols = explode("\t", $row);
            if (count($cols) === 11 && preg_match('/:(\d+)$/', $cols[2], $m)) {
                $status[(int) $m[1]][] = $cols[9] === 'trusted' ? 'trusted' : 'review';
            }
        }
        foreach ($expected as $line => $want) {
            $this->assertArrayHasKey($line, $status, "line {$line}: the scanner found no expression");
            $this->assertSame([$want], array_values(array_unique($status[$line])), "line {$line}: expected {$want}");
        }
    }
}
