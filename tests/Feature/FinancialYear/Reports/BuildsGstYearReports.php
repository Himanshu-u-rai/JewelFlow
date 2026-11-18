<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Shop;
use App\Services\InvoiceAccountingService;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;

/** Shared helpers: real-path invoices at IST instants, and building a report dataset for a date range. */
trait BuildsGstYearReports
{
    protected function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
    }

    protected function billing(Shop $shop, array $values): void
    {
        DB::table('shop_billing_settings')->where('shop_id', $shop->id)->update($values);
    }

    /** A finalized invoice (no lines) dated now. */
    protected function issue(Shop $shop, Customer $customer): Invoice
    {
        return TenantContext::runFor($shop->id, fn () => Invoice::issue([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'gold_rate' => 7200,
            'subtotal' => 1000, 'gst' => 0, 'total' => 1000,
            'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ]))->refresh();
    }

    protected function draft(Shop $shop, Customer $customer): Invoice
    {
        return TenantContext::runFor($shop->id, fn () => InvoiceAccountingService::createDraft([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'gold_rate' => 7200,
        ]))->refresh();
    }

    protected function finalize(Shop $shop, Invoice $draft): Invoice
    {
        return TenantContext::runFor($shop->id, fn () => InvoiceAccountingService::finalizeDraft($draft))->refresh();
    }

    protected function cancel(Shop $shop, Invoice $invoice, string $reason = 'Wrong customer'): void
    {
        TenantContext::runFor($shop->id, fn () => InvoiceAccountingService::cancelByReversal($invoice, $reason));
    }

    /**
     * A finalized bill dated now with one line per [hsn, line_total, gst_amount, weight].
     * Goes through createDraft + InvoiceItem::record + finalizeDraft, like the point of sale.
     * $interState stamps igst_amount on the draft (finalizeDraft leaves it alone).
     *
     * @param array<int, array{0:string,1:float,2:float,3?:float}> $lines
     */
    protected function makeBill(Shop $shop, Customer $customer, array $lines, bool $interState = false): Invoice
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $customer, $lines, $interState) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $shop->id, 'customer_id' => $customer->id, 'gold_rate' => 7200,
                'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3, 'wastage_charge' => 0, 'discount' => 0,
                'round_off' => 0, 'total' => 0, 'status' => Invoice::STATUS_DRAFT,
            ])->save();
            foreach ($lines as $line) {
                $item = $this->createItem($shop->id, null, ['selling_price' => $line[1]]);
                InvoiceItem::record([
                    'invoice_id' => $draft->id, 'item_id' => $item->id, 'metal_type' => $item->metal_type,
                    'weight' => $line[3] ?? 10, 'rate' => 0, 'making_charges' => 0, 'stone_amount' => 0,
                    'hallmark_charges' => 0, 'line_total' => $line[1], 'gst_rate' => 3, 'gst_amount' => $line[2],
                    'hsn_code' => $line[0], 'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
                ]);
            }
            $gst = array_sum(array_column($lines, 2));
            if ($interState) {
                $draft->forceFill(['igst_amount' => $gst, 'cgst_amount' => 0, 'sgst_amount' => 0])->save();
            }

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    /**
     * Build a dataset for [$from, $to] (Y-m-d IST calendar days).
     */
    protected function buildReport(Shop $shop, string $key, string $from, string $to): ReportDataset
    {
        $registry = app(ReportRegistry::class);
        $definition = $registry->definition($key);
        $user = Mockery::mock(\App\Models\User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($definition, $definition->profiles[0], $user, includeSensitive: false)->columnKeys;

        $request = new ReportRequest(
            definition: $definition, shopId: (int) $shop->id, userId: 1, userName: 'Tester',
            profile: $definition->profiles[0], format: F::Csv,
            filters: ['period' => [
                'from' => CarbonImmutable::parse($from, 'Asia/Kolkata')->startOfDay(),
                'to' => CarbonImmutable::parse($to, 'Asia/Kolkata')->endOfDay(),
            ]],
            columnKeys: $keys,
        );
        $meta = new ReportMeta(
            reportKey: $key, reportVersion: $definition->version, title: $definition->title, profileLabel: 'Fixed',
            format: 'csv', filtersApplied: [], periodLabel: "$from to $to", shopLegalName: 'Shop', shopAddress: null,
            shopGstin: null, shopStateCode: '29', generatedByName: 'Tester', generatedAt: now(), generatorTag: 'test',
        );

        return TenantContext::runFor((int) $shop->id, fn () => $registry->datasetService($key)->build($request, $meta));
    }

    /** Calendar-day bounds of a financial year label such as '2026-27'. @return array{0:string,1:string} */
    protected function fy(string $label): array
    {
        $start = (int) explode('-', $label)[0];

        return ["$start-04-01", ($start + 1) . '-03-31'];
    }
}
