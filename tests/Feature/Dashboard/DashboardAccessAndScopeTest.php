<?php

namespace Tests\Feature\Dashboard;

use App\Services\DashboardMetricsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Dashboard / Compliance Alerts (Module 23). Compliance reports (GST/GSTR/CN/
 * day-book + sensitive-column masking) are covered by ComplianceReportsTest(10);
 * the main dashboard (DashboardController → DashboardMetricsService) had no test.
 * This covers: guest redirect, authorized + empty render, and that the KPI
 * metrics are shop-scoped (no cross-shop leak).
 */
class DashboardAccessAndScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_guest_cannot_reach_dashboard(): void
    {
        $res = $this->get(self::ERP . '/dashboard');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    public function test_dashboard_renders_for_authorized_user(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $this->createItem($shop->id, $lot->id, ['status' => 'in_stock']);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/dashboard'))->assertOk();
    }

    public function test_empty_dashboard_renders_safely(): void
    {
        // Brand-new shop, no items/sales/alerts — must not 500.
        [$user, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/dashboard'))->assertOk();
    }

    public function test_dashboard_kpis_are_shop_scoped(): void
    {
        // Shop A: 2 in-stock items. Shop B: 3. Each dashboard counts only its own.
        [, $shopA] = $this->createRetailerTenant();
        $lotA = $this->createMetalLot($shopA->id);
        TenantContext::runFor($shopA->id, function () use ($shopA, $lotA) {
            $this->createItem($shopA->id, $lotA->id, ['status' => 'in_stock']);
            $this->createItem($shopA->id, $lotA->id, ['status' => 'in_stock']);
        });

        [, $shopB] = $this->createRetailerTenant();
        $lotB = $this->createMetalLot($shopB->id);
        TenantContext::runFor($shopB->id, function () use ($shopB, $lotB) {
            $this->createItem($shopB->id, $lotB->id, ['status' => 'in_stock']);
            $this->createItem($shopB->id, $lotB->id, ['status' => 'in_stock']);
            $this->createItem($shopB->id, $lotB->id, ['status' => 'in_stock']);
        });

        $aStock = TenantContext::runFor($shopA->id, fn () => DashboardMetricsService::build($shopA->id)['stock']);
        $bStock = TenantContext::runFor($shopB->id, fn () => DashboardMetricsService::build($shopB->id)['stock']);

        $this->assertSame(2, (int) $aStock, 'shop A dashboard counts only its own in-stock items');
        $this->assertSame(3, (int) $bStock, 'shop B dashboard counts only its own in-stock items');
    }
}
