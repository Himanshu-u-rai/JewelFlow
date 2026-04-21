<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\CashTransaction;
use App\Models\InstallmentPlan;
use App\Models\SchemeEnrollment;
use App\Models\Item;
use App\Models\InvoicePayment;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    // Show the export page
    public function index()
    {
        return view('export.index');
    }

    // Export Customers
    public function exportCustomers()
    {
        $user = Auth::user();
        $shop = $user->shop;
        $shopId = (int) $user->shop_id;

        $customers = Customer::query()
            ->where('shop_id', $shopId)
            ->orderBy('customer_code')
            ->orderBy('id')
            ->get();

        $invoiceStats = Invoice::query()
            ->where('shop_id', $shopId)
            ->selectRaw('customer_id, COUNT(*) as invoice_count, COALESCE(SUM(total), 0) as total_spent')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $latestInvoiceByCustomer = Invoice::query()
            ->where('shop_id', $shopId)
            ->orderByDesc('id')
            ->get(['customer_id', 'invoice_number', 'created_at'])
            ->unique('customer_id')
            ->keyBy('customer_id');

        $emiStats = InstallmentPlan::query()
            ->where('shop_id', $shopId)
            ->selectRaw('customer_id, COUNT(*) as emi_plan_count, COALESCE(SUM(remaining_amount), 0) as emi_outstanding')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $overdueEmiByCustomer = InstallmentPlan::query()
            ->where('shop_id', $shopId)
            ->where('status', 'active')
            ->whereDate('next_due_date', '<', now()->toDateString())
            ->selectRaw('customer_id, COUNT(*) as overdue_emi_count')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        $schemeStats = SchemeEnrollment::query()
            ->where('shop_id', $shopId)
            ->selectRaw('customer_id, COUNT(*) as scheme_count, COALESCE(SUM(total_paid), 0) as scheme_total_paid, COALESCE(SUM(redeemed_amount), 0) as scheme_total_redeemed')
            ->groupBy('customer_id')
            ->get()
            ->keyBy('customer_id');

        if ($shop && $shop->isRetailer()) {
            $headers = [
                'Customer Code',
                'First Name',
                'Last Name',
                'Full Name',
                'Mobile',
                'Email',
                'Address',
                'Date of Birth',
                'Anniversary Date',
                'Wedding Date',
                'Notes',
                'Loyalty Points',
                'Invoice Count',
                'Total Spent',
                'Last Invoice Number',
                'Last Invoice Date',
                'EMI Plan Count',
                'EMI Outstanding',
                'Overdue EMI Count',
                'Scheme Enrollment Count',
                'Scheme Total Paid',
                'Scheme Total Redeemed',
                'Customer Created At',
                'Customer Updated At',
            ];

            $rows = $customers->map(function (Customer $customer) use ($invoiceStats, $latestInvoiceByCustomer, $emiStats, $overdueEmiByCustomer, $schemeStats) {
                $cId = $customer->id;
                $inv = $invoiceStats->get($cId);
                $latestInv = $latestInvoiceByCustomer->get($cId);
                $emi = $emiStats->get($cId);
                $overdue = $overdueEmiByCustomer->get($cId);
                $scheme = $schemeStats->get($cId);

                return [
                    $customer->customer_code,
                    $customer->first_name,
                    $customer->last_name,
                    $customer->name,
                    $customer->mobile,
                    $customer->email,
                    $customer->address,
                    optional($customer->date_of_birth)->format('Y-m-d'),
                    optional($customer->anniversary_date)->format('Y-m-d'),
                    optional($customer->wedding_date)->format('Y-m-d'),
                    $customer->notes,
                    (int) ($customer->loyalty_points ?? 0),
                    (int) ($inv->invoice_count ?? 0),
                    number_format((float) ($inv->total_spent ?? 0), 2, '.', ''),
                    $latestInv->invoice_number ?? '',
                    isset($latestInv->created_at) ? \Illuminate\Support\Carbon::parse($latestInv->created_at)->format('Y-m-d H:i:s') : '',
                    (int) ($emi->emi_plan_count ?? 0),
                    number_format((float) ($emi->emi_outstanding ?? 0), 2, '.', ''),
                    (int) ($overdue->overdue_emi_count ?? 0),
                    (int) ($scheme->scheme_count ?? 0),
                    number_format((float) ($scheme->scheme_total_paid ?? 0), 2, '.', ''),
                    number_format((float) ($scheme->scheme_total_redeemed ?? 0), 2, '.', ''),
                    optional($customer->created_at)->format('Y-m-d H:i:s'),
                    optional($customer->updated_at)->format('Y-m-d H:i:s'),
                ];
            })->all();

            return $this->streamCsv(
                'customers-' . date('Y-m-d') . '.csv',
                $headers,
                $rows
            );
        }

        // Manufacturer export shape — batch-load gold balances to avoid N+1
        $goldBalances = DB::table('customer_gold_transactions')
            ->where('shop_id', $shopId)
            ->selectRaw('customer_id, COALESCE(SUM(fine_gold), 0) as balance')
            ->groupBy('customer_id')
            ->pluck('balance', 'customer_id');

        $headers = [
            'Customer Code',
            'Name',
            'Mobile',
            'Email',
            'Address',
            'Gold Balance (Fine)',
            'Created At',
            'Updated At',
        ];

        $rows = $customers->map(function (Customer $customer) use ($goldBalances) {
            return [
                $customer->customer_code,
                $customer->name,
                $customer->mobile,
                $customer->email,
                $customer->address,
                number_format((float) ($goldBalances[$customer->id] ?? 0), 6, '.', ''),
                optional($customer->created_at)->format('Y-m-d H:i:s'),
                optional($customer->updated_at)->format('Y-m-d H:i:s'),
            ];
        })->all();

        return $this->streamCsv(
            'customers-' . date('Y-m-d') . '.csv',
            $headers,
            $rows
        );
    }

    // Export Products
    public function exportProducts()
    {
        $user = Auth::user();
        $shop = $user->shop;
        $shopId = (int) $user->shop_id;

        if ($shop && $shop->isRetailer()) {
            $items = Item::query()
                ->where('shop_id', $shopId)
                ->with(['vendor', 'product'])
                ->orderBy('barcode')
                ->orderBy('id')
                ->get();

            $headers = [
                'Barcode',
                'Design Name',
                'Category',
                'Sub Category',
                'Status',
                'Source',
                'Purity',
                'Gross Weight (g)',
                'Stone Weight (g)',
                'Net Metal Weight (g)',
                'Wastage (g)',
                'Making Charges',
                'Stone Charges',
                'Cost Price',
                'Selling Price',
                'Vendor Name',
                'Vendor Mobile',
                'HUID',
                'Hallmark Date',
                'Template Design Code',
                'Has Image',
                'Created At',
                'Updated At',
            ];

            $rows = $items->map(function (Item $item) {
                return [
                    $item->barcode,
                    $item->design,
                    $item->category,
                    $item->sub_category,
                    $item->status,
                    $item->source,
                    number_format((float) $item->purity, 2, '.', ''),
                    number_format((float) $item->gross_weight, 3, '.', ''),
                    number_format((float) $item->stone_weight, 3, '.', ''),
                    number_format((float) $item->net_metal_weight, 3, '.', ''),
                    number_format((float) $item->wastage, 3, '.', ''),
                    number_format((float) $item->making_charges, 2, '.', ''),
                    number_format((float) $item->stone_charges, 2, '.', ''),
                    number_format((float) $item->cost_price, 2, '.', ''),
                    number_format((float) $item->selling_price, 2, '.', ''),
                    $item->vendor?->name ?? '',
                    $item->vendor?->mobile ?? '',
                    $item->huid ?? '',
                    optional($item->hallmark_date)->format('Y-m-d'),
                    $item->product?->design_code ?? '',
                    !empty($item->image) ? 'Yes' : 'No',
                    optional($item->created_at)->format('Y-m-d H:i:s'),
                    optional($item->updated_at)->format('Y-m-d H:i:s'),
                ];
            })->all();

            return $this->streamCsv(
                'products-' . date('Y-m-d') . '.csv',
                $headers,
                $rows
            );
        }

        // Manufacturer export: product templates/catalog master data.
        $products = Product::query()
            ->where('shop_id', $shopId)
            ->with(['category', 'subCategory'])
            ->orderBy('design_code')
            ->orderBy('id')
            ->get();

        $headers = [
            'Design Code',
            'Name',
            'Category',
            'Sub Category',
            'Default Purity',
            'Approx Weight (g)',
            'Default Making',
            'Default Stone',
            'Notes',
            'Has Image',
            'Created At',
            'Updated At',
        ];

        $rows = $products->map(function (Product $product) {
            return [
                $product->design_code,
                $product->name,
                $product->category?->name ?? '',
                $product->subCategory?->name ?? '',
                $product->default_purity,
                number_format((float) $product->approx_weight, 3, '.', ''),
                number_format((float) $product->default_making, 2, '.', ''),
                number_format((float) $product->default_stone, 2, '.', ''),
                $product->notes,
                !empty($product->image) ? 'Yes' : 'No',
                optional($product->created_at)->format('Y-m-d H:i:s'),
                optional($product->updated_at)->format('Y-m-d H:i:s'),
            ];
        })->all();

        return $this->streamCsv(
            'products-' . date('Y-m-d') . '.csv',
            $headers,
            $rows
        );
    }

    // Export Invoices/Sales
    public function exportInvoices()
    {
        $user = Auth::user();
        $shop = $user->shop;
        $shopId = (int) $user->shop_id;

        $query = Invoice::query()
            ->where('shop_id', $shopId)
            ->with(['customer', 'items', 'payments', 'offerApplication', 'schemeRedemptions'])
            ->orderByDesc('created_at');

        $headers = [
            'Invoice Number',
            'Status',
            'Invoice Date',
            'Customer Code',
            'Customer Name',
            'Customer Mobile',
            'Item Count',
            'Gold Rate',
            'Subtotal',
            'GST Rate (%)',
            'GST Amount',
            'Wastage Charge',
            'Discount',
            'Round Off',
            'Grand Total',
            'Paid Amount',
            'Outstanding Amount',
            'Offer Scheme',
            'Offer Discount',
            'Scheme Redemption',
            'Payment Cash',
            'Payment UPI',
            'Payment Bank',
            'Payment Other',
            'Payment Old Gold',
            'Payment Old Silver',
            'Payment EMI',
            'Payment Scheme',
            'Created At',
            'Finalized At',
            'Cancelled At',
        ];

        $isRetailer = $shop && $shop->isRetailer();

        return $this->streamCsvChunked(
            'sales-' . date('Y-m-d') . '.csv',
            $headers,
            $query,
            function (Invoice $invoice) use ($isRetailer) {
                $payments = $invoice->payments;
                $sumByMode = $payments->groupBy('mode')->map(fn ($rows) => (float) $rows->sum('amount'));
                $paidAmount = (float) $payments->sum('amount');
                $total = (float) $invoice->total;
                $outstanding = max(0, $total - $paidAmount);

                $offerScheme = $invoice->offerApplication?->scheme_name_snapshot ?? '';
                $offerDiscount = (float) ($invoice->offerApplication?->discount_amount ?? 0);
                $schemeRedemption = (float) $invoice->schemeRedemptions->sum('amount');

                $goldRate = $isRetailer
                    ? ''
                    : number_format((float) ($invoice->gold_rate ?? 0), 2, '.', '');

                return [
                    $invoice->invoice_number,
                    $invoice->status,
                    optional($invoice->created_at)->format('Y-m-d'),
                    $invoice->customer?->customer_code ?? '',
                    $invoice->customer?->name ?? '',
                    $invoice->customer?->mobile ?? '',
                    (int) $invoice->items->count(),
                    $goldRate,
                    number_format((float) ($invoice->subtotal ?? 0), 2, '.', ''),
                    number_format((float) ($invoice->gst_rate ?? 0), 2, '.', ''),
                    number_format((float) ($invoice->gst ?? 0), 2, '.', ''),
                    number_format((float) ($invoice->wastage_charge ?? 0), 2, '.', ''),
                    number_format((float) ($invoice->discount ?? 0), 2, '.', ''),
                    number_format((float) ($invoice->round_off ?? 0), 2, '.', ''),
                    number_format($total, 2, '.', ''),
                    number_format($paidAmount, 2, '.', ''),
                    number_format($outstanding, 2, '.', ''),
                    $offerScheme,
                    number_format($offerDiscount, 2, '.', ''),
                    number_format($schemeRedemption, 2, '.', ''),
                    number_format((float) ($sumByMode['cash'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['upi'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['bank'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['other'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['old_gold'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['old_silver'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['emi'] ?? 0), 2, '.', ''),
                    number_format((float) ($sumByMode['scheme'] ?? 0), 2, '.', ''),
                    optional($invoice->created_at)->format('Y-m-d H:i:s'),
                    optional($invoice->finalized_at)->format('Y-m-d H:i:s'),
                    optional($invoice->cancelled_at)->format('Y-m-d H:i:s'),
                ];
            }
        );
    }

    // Export Metal Ledger (incoming old-metal payments from sales)
    public function exportGoldLedger()
    {
        $shopId = (int) Auth::user()->shop_id;

        $query = InvoicePayment::query()
            ->where('shop_id', $shopId)
            ->whereIn('mode', [InvoicePayment::MODE_OLD_GOLD, InvoicePayment::MODE_OLD_SILVER])
            ->with(['invoice.customer'])
            ->orderByDesc('created_at');

        $headers = [
            'Entry Date',
            'Invoice Number',
            'Customer Code',
            'Customer Name',
            'Payment Mode',
            'Metal Type',
            'Gross Weight',
            'Purity',
            'Test Loss (%)',
            'Fine Weight',
            'Rate per Gram',
            'Payment Amount',
            'Reference',
            'Note',
            'Created At',
        ];

        return $this->streamCsvChunked(
            'metal-ledger-' . date('Y-m-d') . '.csv',
            $headers,
            $query,
            function (InvoicePayment $payment) {
                return [
                    optional($payment->created_at)->format('Y-m-d'),
                    $payment->invoice?->invoice_number ?? '',
                    $payment->invoice?->customer?->customer_code ?? '',
                    $payment->invoice?->customer?->name ?? '',
                    $payment->mode,
                    $payment->metal_type ?? '',
                    number_format((float) ($payment->metal_gross_weight ?? 0), 3, '.', ''),
                    number_format((float) ($payment->metal_purity ?? 0), 2, '.', ''),
                    number_format((float) ($payment->metal_test_loss ?? 0), 2, '.', ''),
                    number_format((float) ($payment->metal_fine_weight ?? 0), 3, '.', ''),
                    number_format((float) ($payment->metal_rate_per_gram ?? 0), 2, '.', ''),
                    number_format((float) ($payment->amount ?? 0), 2, '.', ''),
                    $payment->reference ?? '',
                    $payment->note ?? '',
                    optional($payment->created_at)->format('Y-m-d H:i:s'),
                ];
            }
        );
    }

    // Export Cash Transactions
    public function exportCashTransactions()
    {
        $shopId = (int) Auth::user()->shop_id;

        $query = CashTransaction::query()
            ->where('shop_id', $shopId)
            ->with(['invoice', 'invoice.customer'])
            ->orderByDesc('created_at');

        $headers = [
            'Entry Date',
            'Direction',
            'Amount',
            'Source Type',
            'Payment Mode',
            'Invoice Number',
            'Customer Name',
            'Source ID',
            'Description',
            'Recorded By User ID',
            'Created At',
            'Updated At',
        ];

        return $this->streamCsvChunked(
            'cash-transactions-' . date('Y-m-d') . '.csv',
            $headers,
            $query,
            function (CashTransaction $txn) {
                return [
                    optional($txn->created_at)->format('Y-m-d'),
                    $txn->type === 'in' ? 'IN' : 'OUT',
                    number_format((float) ($txn->amount ?? 0), 2, '.', ''),
                    $txn->source_type ?? '',
                    $txn->payment_mode ?? '',
                    $txn->invoice?->invoice_number ?? '',
                    $txn->invoice?->customer?->name ?? '',
                    $txn->source_id ?? '',
                    $txn->description ?? '',
                    $txn->user_id ?? '',
                    optional($txn->created_at)->format('Y-m-d H:i:s'),
                    optional($txn->updated_at)->format('Y-m-d H:i:s'),
                ];
            }
        );
    }

    // Export All Data
    public function exportAll()
    {
        $user = Auth::user();
        $shop = $user->shop;
        $shopId = (int) $user->shop_id;

        // Retail edition must never access complete backup.
        if (!$shop || $shop->isRetailer()) {
            abort(404);
        }

        $filename = 'complete-backup-' . date('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($shopId): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['=== CUSTOMERS ===']);
            fputcsv($out, ['ID', 'Name', 'Mobile', 'Email', 'Gold Balance']);
            Customer::where('shop_id', $shopId)->chunk(500, function ($customers) use ($out) {
                foreach ($customers as $customer) {
                    fputcsv($out, [
                        $customer->id,
                        $customer->name,
                        $customer->mobile,
                        $customer->email,
                        $customer->gold_balance,
                    ]);
                }
            });

            fputcsv($out, []);
            fputcsv($out, ['=== PRODUCTS ===']);
            fputcsv($out, ['ID', 'Name', 'Barcode', 'Status']);
            Product::where('shop_id', $shopId)->chunk(500, function ($products) use ($out) {
                foreach ($products as $product) {
                    fputcsv($out, [
                        $product->id,
                        $product->name,
                        $product->barcode,
                        $product->status,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Stream CSV with chunked query to avoid loading all rows into memory.
     */
    private function streamCsvChunked(string $filename, array $headers, \Illuminate\Database\Eloquent\Builder $query, callable $rowMapper, int $chunkSize = 500): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $query, $rowMapper, $chunkSize): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            $query->chunk($chunkSize, function ($records) use ($out, $rowMapper) {
                foreach ($records as $record) {
                    fputcsv($out, $rowMapper($record));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
