<?php

namespace App\Providers;

use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportSizeRouter;
use App\Services\Reporting\Render\ChromiumPdfService;
use App\Services\Reporting\Render\HtmlToPdf;
use App\Services\Reporting\Reports\CreditNoteRegisterDataset;
use App\Services\Reporting\Reports\CreditNotesDataset;
use App\Services\Reporting\Reports\CustomersDataset;
use App\Services\Reporting\Reports\DailySummaryDataset;
use App\Services\Reporting\Reports\DayBookDataset;
use App\Services\Reporting\Reports\GoldBalancesDataset;
use App\Services\Reporting\Reports\InstallmentPlansDataset;
use App\Services\Reporting\Reports\InventoryItemsDataset;
use App\Services\Reporting\Reports\JobOrdersDataset;
use App\Services\Reporting\Reports\KarigarInvoicesDataset;
use App\Services\Reporting\Reports\KarigarsDataset;
use App\Services\Reporting\Reports\LoyaltyDataset;
use App\Services\Reporting\Reports\OldGoldDataset;
use App\Services\Reporting\Reports\ProductsDataset;
use App\Services\Reporting\Reports\RepairsDataset;
use App\Services\Reporting\Reports\ReturnsDataset;
use App\Services\Reporting\Reports\SchemeEnrollmentsDataset;
use App\Services\Reporting\Reports\StockPurchasesDataset;
use App\Services\Reporting\Reports\StoreCreditDataset;
use App\Services\Reporting\Reports\GstReportDataset;
use App\Services\Reporting\Reports\Gstr1Dataset;
use App\Services\Reporting\Reports\CashFlowDataset;
use App\Services\Reporting\Reports\DailyClosingDataset;
use App\Services\Reporting\Reports\Gstr3bDataset;
use App\Services\Reporting\Reports\InventoryValuationDataset;
use App\Services\Reporting\Reports\MetalLiabilityDataset;
use App\Services\Reporting\Reports\MetalMovementLedgerDataset;
use App\Services\Reporting\Reports\PaymentReconciliationDataset;
use App\Services\Reporting\Reports\ProfitLossDataset;
use App\Services\Reporting\Reports\SalesRegisterDataset;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the reporting-export spine (Phase 0). The registry is a singleton that
 * reports self-register into (per-report bindings arrive with each report in
 * Phase 1+). The Chromium engine is bound behind the HtmlToPdf interface so the
 * PDF renderer stays testable/mockable. Every other service auto-resolves from
 * the container.
 */
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportRegistry::class, fn (Container $app) => new ReportRegistry($app));

        $this->app->bind(HtmlToPdf::class, fn () => new ChromiumPdfService(
            config('reporting.chromium_path'),
            (int) config('reporting.chromium_timeout_seconds', 60),
        ));

        $this->app->singleton(ExportSizeRouter::class, fn () => ExportSizeRouter::fromConfig());
    }

    public function boot(): void
    {
        /** @var ReportRegistry $registry */
        $registry = $this->app->make(ReportRegistry::class);

        // Phase 1 pilot: the canonical Sales / Invoice Register (Addendum C §27).
        if (! $registry->has(SalesRegisterDataset::KEY)) {
            $registry->register(SalesRegisterDataset::KEY, SalesRegisterDataset::class);
        }

        // Phase 2: the compliance family + the day book (each wraps an existing
        // canonical service so totals reconcile by construction).
        if (! $registry->has(GstReportDataset::KEY)) {
            $registry->register(GstReportDataset::KEY, GstReportDataset::class);
        }
        if (! $registry->has(Gstr1Dataset::KEY)) {
            $registry->register(Gstr1Dataset::KEY, Gstr1Dataset::class);
        }
        if (! $registry->has(Gstr3bDataset::KEY)) {
            $registry->register(Gstr3bDataset::KEY, Gstr3bDataset::class);
        }
        if (! $registry->has(CreditNoteRegisterDataset::KEY)) {
            $registry->register(CreditNoteRegisterDataset::KEY, CreditNoteRegisterDataset::class);
        }
        if (! $registry->has(DayBookDataset::KEY)) {
            $registry->register(DayBookDataset::KEY, DayBookDataset::class);
        }

        // Phase 3 — Accounting: Metal Movement Ledger (reconciles to vault:reconcile).
        if (! $registry->has(MetalMovementLedgerDataset::KEY)) {
            $registry->register(MetalMovementLedgerDataset::KEY, MetalMovementLedgerDataset::class);
        }

        // Phase 3 — Accounting: Inventory Valuation (reconciles to items cost aggregate).
        if (! $registry->has(InventoryValuationDataset::KEY)) {
            $registry->register(InventoryValuationDataset::KEY, InventoryValuationDataset::class);
        }

        // Phase 3 — Accounting: Cash Flow (reconciles to cash_transactions aggregate).
        if (! $registry->has(CashFlowDataset::KEY)) {
            $registry->register(CashFlowDataset::KEY, CashFlowDataset::class);
        }

        // Phase 3 — Accounting: Daily Closing (cross-phase: Sales + GST + Cash).
        if (! $registry->has(DailyClosingDataset::KEY)) {
            $registry->register(DailyClosingDataset::KEY, DailyClosingDataset::class);
        }

        // Phase 3 — Accounting: Payment Reconciliation (billed vs collected; variance).
        if (! $registry->has(PaymentReconciliationDataset::KEY)) {
            $registry->register(PaymentReconciliationDataset::KEY, PaymentReconciliationDataset::class);
        }

        // Phase 3 — Accounting: Daily Sales Summary (one day's sales/GST + metal movement).
        if (! $registry->has(DailySummaryDataset::KEY)) {
            $registry->register(DailySummaryDataset::KEY, DailySummaryDataset::class);
        }

        // Phase 3 — Accounting: Metal Liability (customer-advance gold owed vs on hand).
        if (! $registry->has(MetalLiabilityDataset::KEY)) {
            $registry->register(MetalLiabilityDataset::KEY, MetalLiabilityDataset::class);
        }

        // Phase 4 — Owner: Profit & Loss (revenue − COGS = gross margin).
        if (! $registry->has(ProfitLossDataset::KEY)) {
            $registry->register(ProfitLossDataset::KEY, ProfitLossDataset::class);
        }

        // Phase 4 — Owner: Gold Balances (vault fine-weight holdings by metal/purity).
        if (! $registry->has(GoldBalancesDataset::KEY)) {
            $registry->register(GoldBalancesDataset::KEY, GoldBalancesDataset::class);
        }

        // Data Exports (Phase 1): bulk data dumps that the /export hub links to.
        // These replace the legacy standalone CSV exporter (customers/products/
        // items/purchases/karigar) — same data, now through the canonical
        // pipeline (CSV/Excel/PDF, sensitive-column gating, async-for-large).
        foreach ([
            // Phase 1
            CustomersDataset::class,
            ProductsDataset::class,
            InventoryItemsDataset::class,
            StockPurchasesDataset::class,
            KarigarsDataset::class,
            KarigarInvoicesDataset::class,
            // Phase 2
            JobOrdersDataset::class,
            ReturnsDataset::class,
            CreditNotesDataset::class,
            RepairsDataset::class,
            InstallmentPlansDataset::class,
            SchemeEnrollmentsDataset::class,
            StoreCreditDataset::class,
            LoyaltyDataset::class,
            OldGoldDataset::class,
        ] as $dataset) {
            if (! $registry->has($dataset::KEY)) {
                $registry->register($dataset::KEY, $dataset);
            }
        }
    }
}
