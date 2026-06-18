<?php

namespace App\Services\Reporting\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InstallmentPlan;
use App\Models\SchemeEnrollment;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use Illuminate\Support\Facades\DB;

/**
 * Customers — the shop's customer directory with spend, loyalty, EMI, scheme and
 * old-gold-balance aggregates (Owner class). A data export, not a financial
 * report: full directory, no period filter.
 *
 * Mirrors the columns of the legacy ExportController::exportCustomers so nothing
 * useful is lost, but routes through the canonical reporting pipeline (CSV /
 * Excel / PDF, provenance, async-for-large). PII (mobile / email / address / DOB
 * / anniversary / wedding) is marked SENSITIVE so it is excluded unless the user
 * holds reports.export_sensitive and opts in — this is how an owner shares a
 * customer list with a CA without leaking contact details.
 *
 * Aggregates are batch-loaded (keyed collections) to stay O(1) in queries.
 * BelongsToShop scopes every model to the caller's shop.
 */
class CustomersDataset extends ReportDatasetService
{
    public const KEY = 'customers';
    public const VERSION = 'customers@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Customers',
            classification: Cls::Owner,
            columns: [
                Col::mandatory('customer_code', 'Customer Code', T::String),
                Col::mandatory('name', 'Full Name', T::String),
                Col::sensitive('mobile', 'Mobile', T::String),
                Col::sensitive('email', 'Email', T::String),
                Col::sensitive('address', 'Address', T::String),
                Col::sensitive('date_of_birth', 'Date of Birth', T::Date),
                Col::sensitive('anniversary_date', 'Anniversary', T::Date),
                Col::sensitive('wedding_date', 'Wedding Date', T::Date),
                Col::optional('loyalty_points', 'Loyalty Points', T::Integer),
                Col::optional('invoice_count', 'Invoices', T::Integer),
                Col::optional('total_spent', 'Total Spent', T::Money),
                Col::optional('last_invoice_number', 'Last Invoice', T::String),
                Col::optional('last_invoice_date', 'Last Invoice Date', T::DateTime),
                Col::optional('emi_plan_count', 'EMI Plans', T::Integer),
                Col::optional('emi_outstanding', 'EMI Outstanding', T::Money),
                Col::optional('overdue_emi_count', 'Overdue EMIs', T::Integer),
                Col::optional('scheme_count', 'Scheme Enrolments', T::Integer),
                Col::optional('scheme_total_paid', 'Scheme Paid', T::Money),
                Col::optional('scheme_total_redeemed', 'Scheme Redeemed', T::Money),
                Col::optional('gold_balance', 'Old Gold Balance (g)', T::Weight),
                Col::optional('created_at', 'Created', T::DateTime),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $shopId = $request->shopId;

        $customers = Customer::query()->orderBy('customer_code')->orderBy('id')->get();

        $invoiceStats = Invoice::query()
            ->selectRaw('customer_id, COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as total_spent')
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $latestInvoice = Invoice::query()
            ->orderByDesc('id')->get(['customer_id', 'invoice_number', 'created_at'])
            ->unique('customer_id')->keyBy('customer_id');

        $emiStats = InstallmentPlan::query()
            ->selectRaw('customer_id, COUNT(*) as emi_plan_count, COALESCE(SUM(remaining_amount), 0) as emi_outstanding')
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $overdueEmi = InstallmentPlan::query()
            ->where('status', 'active')
            ->whereDate('next_due_date', '<', now()->toDateString())
            ->selectRaw('customer_id, COUNT(*) as overdue_emi_count')
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        $schemeStats = SchemeEnrollment::query()
            ->selectRaw('customer_id, COUNT(*) as scheme_count, COALESCE(SUM(total_paid), 0) as scheme_total_paid, COALESCE(SUM(redeemed_amount), 0) as scheme_total_redeemed')
            ->groupBy('customer_id')->get()->keyBy('customer_id');

        // Old-gold balance per customer (manufacturer/retailer who accept old gold).
        $goldBalances = DB::table('customer_gold_transactions')
            ->where('shop_id', $shopId)
            ->selectRaw('customer_id, COALESCE(SUM(fine_gold), 0) as balance')
            ->groupBy('customer_id')->pluck('balance', 'customer_id');

        $rows = [];
        $totalSpent = 0.0;
        foreach ($customers as $c) {
            $inv = $invoiceStats->get($c->id);
            $li = $latestInvoice->get($c->id);
            $emi = $emiStats->get($c->id);
            $od = $overdueEmi->get($c->id);
            $sc = $schemeStats->get($c->id);
            $spent = (float) ($inv->total_spent ?? 0);
            $totalSpent += $spent;

            $rows[] = [
                'customer_code' => $c->customer_code,
                'name' => $c->name,
                'mobile' => $c->mobile,
                'email' => $c->email,
                'address' => $c->address,
                'date_of_birth' => $c->date_of_birth,
                'anniversary_date' => $c->anniversary_date,
                'wedding_date' => $c->wedding_date,
                'loyalty_points' => (int) ($c->loyalty_points ?? 0),
                'invoice_count' => (int) ($inv->invoice_count ?? 0),
                'total_spent' => $spent,
                'last_invoice_number' => $li->invoice_number ?? '—',
                'last_invoice_date' => $li->created_at ?? null,
                'emi_plan_count' => (int) ($emi->emi_plan_count ?? 0),
                'emi_outstanding' => (float) ($emi->emi_outstanding ?? 0),
                'overdue_emi_count' => (int) ($od->overdue_emi_count ?? 0),
                'scheme_count' => (int) ($sc->scheme_count ?? 0),
                'scheme_total_paid' => (float) ($sc->scheme_total_paid ?? 0),
                'scheme_total_redeemed' => (float) ($sc->scheme_total_redeemed ?? 0),
                'gold_balance' => (float) ($goldBalances[$c->id] ?? 0),
                'created_at' => $c->created_at,
            ];
        }

        $keys = $request->columnKeys;
        $totals = in_array('total_spent', $keys, true) ? ['total_spent' => round($totalSpent, 2)] : [];

        $section = new ReportSection('customers', 'Customers', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return Customer::query()->count();
    }
}
