<?php

namespace Tests\Feature\Reporting;

use App\Reporting\AuditService;
use App\Reporting\ReportPeriod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Suspicious activity / compliance alerts (#17). Surfaces the alerts the system
 * already records (split transaction / missing PAN / threshold breach) for a
 * period, with unresolved count and per-type breakdown.
 */
class SuspiciousActivityReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function alert(int $shopId, string $type, bool $resolved): void
    {
        DB::table('compliance_alerts')->insert([
            'shop_id' => $shopId, 'alert_type' => $type,
            'alert_data' => '{"note":"test"}', 'resolved' => DB::raw($resolved ? 'true' : 'false'),
            'created_at' => '2026-03-10 12:00:00', 'updated_at' => '2026-03-10 12:00:00',
        ]);
    }

    public function test_alerts_grouped_with_unresolved_count(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->alert($shop->id, 'split_transaction', false);
        $this->alert($shop->id, 'split_transaction', true);
        $this->alert($shop->id, 'missing_pan', false);
        // Out-of-period alert — excluded.
        DB::table('compliance_alerts')->insert([
            'shop_id' => $shop->id, 'alert_type' => 'threshold_breach', 'alert_data' => '{}',
            'resolved' => DB::raw('false'), 'created_at' => '2026-01-05 12:00:00', 'updated_at' => '2026-01-05 12:00:00',
        ]);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(AuditService::class)->suspiciousActivity($shop->id, ReportPeriod::month(2026, 3))
        );

        $this->assertSame(3, $data->totalCount, 'only in-period alerts');
        $this->assertSame(2, $data->unresolvedCount);
        $this->assertSame(2, $data->countsByType['split_transaction']);
        $this->assertSame(1, $data->countsByType['missing_pan']);
        // Per-type counts sum to the total (consistency).
        $this->assertSame($data->totalCount, (int) $data->countsByType->sum());
    }

    public function test_empty_when_no_alerts(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $data = TenantContext::runFor($shop->id, fn () =>
            app(AuditService::class)->suspiciousActivity($shop->id, ReportPeriod::month(2026, 3))
        );
        $this->assertSame(0, $data->totalCount);
        $this->assertTrue($data->rows->isEmpty());
    }
}
