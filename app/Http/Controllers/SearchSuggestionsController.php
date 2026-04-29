<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InstallmentPlan;
use App\Models\Item;
use App\Models\JobOrder;
use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\Product;
use App\Models\QuickBill;
use App\Models\Repair;
use App\Models\ReorderRule;
use App\Models\Scheme;
use App\Models\StockPurchase;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchSuggestionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = $request->input('type', '');
        $q = trim($request->input('q', ''));
        $shopId = auth()->user()->shop_id;

        if ($q === '' || mb_strlen($q) < 1) {
            return response()->json([]);
        }

        $results = match ($type) {
            'customers' => $this->customers($shopId, $q),
            'vendors' => $this->vendors($shopId, $q),
            'invoices' => $this->invoices($shopId, $q),
            'items' => $this->items($shopId, $q),
            'products' => $this->products($shopId, $q),
            'quick-bills' => $this->quickBills($shopId, $q),
            'repairs' => $this->repairs($shopId, $q),
            'karigars' => $this->karigars($shopId, $q),
            'job-orders' => $this->jobOrders($shopId, $q),
            'karigar-invoices' => $this->karigarInvoices($shopId, $q),
            'stock-purchases' => $this->stockPurchases($shopId, $q),
            'schemes' => $this->schemes($shopId, $q),
            'installments' => $this->installments($shopId, $q),
            'reorder-rules' => $this->reorderRules($shopId, $q),
            default => [],
        };

        return response()->json($results);
    }

    private function customers(int $shopId, string $q): array
    {
        return Customer::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('first_name', 'ilike', "%{$q}%")
                ->orWhere('last_name', 'ilike', "%{$q}%")
                ->orWhere('mobile', 'like', "%{$q}%"))
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'mobile'])
            ->map(fn ($c) => [
                'label' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'sub' => $c->mobile ?? '',
                'url' => route('customers.show', $c->id),
            ])->all();
    }

    private function vendors(int $shopId, string $q): array
    {
        return Vendor::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('contact_person', 'ilike', "%{$q}%")
                ->orWhere('mobile', 'like', "%{$q}%")
                ->orWhere('gst_number', 'ilike', "%{$q}%"))
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'contact_person', 'mobile'])
            ->map(fn ($v) => [
                'label' => $v->name,
                'sub' => $v->contact_person ? $v->contact_person . ' · ' . ($v->mobile ?? '') : ($v->mobile ?? ''),
                'url' => route('vendors.show', $v->id),
            ])->all();
    }

    private function invoices(int $shopId, string $q): array
    {
        return Invoice::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('invoice_number', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($cq) => $cq
                    ->where('first_name', 'ilike', "%{$q}%")
                    ->orWhere('last_name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%")))
            ->with('customer:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'invoice_number', 'customer_id', 'total', 'created_at'])
            ->map(fn ($inv) => [
                'label' => $inv->invoice_number,
                'sub' => trim(($inv->customer->first_name ?? '') . ' ' . ($inv->customer->last_name ?? ''))
                    . ' · ₹' . number_format($inv->total, 0),
                'url' => route('invoices.show', $inv->id),
            ])->all();
    }

    private function items(int $shopId, string $q): array
    {
        return Item::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('barcode', 'ilike', "%{$q}%")
                ->orWhere('design', 'ilike', "%{$q}%")
                ->orWhere('category', 'ilike', "%{$q}%"))
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'barcode', 'design', 'category', 'gross_weight', 'purity', 'status'])
            ->map(fn ($i) => [
                'label' => ($i->barcode ?? '') . ($i->design ? ' — ' . $i->design : ''),
                'sub' => ($i->category ?? '') . ' · ' . number_format($i->gross_weight, 3) . 'g · ' . $i->purity . 'K'
                    . ($i->status !== 'in_stock' ? ' · ' . ucfirst(str_replace('_', ' ', $i->status)) : ''),
                'url' => route('inventory.items.show', $i->id),
            ])->all();
    }

    private function products(int $shopId, string $q): array
    {
        return Product::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('design_code', 'ilike', "%{$q}%")
                ->orWhereHas('category', fn ($cq) => $cq->where('name', 'ilike', "%{$q}%")))
            ->with('category:id,name')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'design_code', 'category_id'])
            ->map(fn ($p) => [
                'label' => $p->name,
                'sub' => trim(($p->design_code ?? '') . ($p->category ? ' · ' . $p->category->name : '')),
                'url' => route('products.show', $p->id),
            ])->all();
    }

    private function quickBills(int $shopId, string $q): array
    {
        return QuickBill::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('bill_number', 'ilike', "%{$q}%")
                ->orWhere('customer_name', 'ilike', "%{$q}%")
                ->orWhere('customer_mobile', 'like', "%{$q}%"))
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'bill_number', 'customer_name', 'total_amount', 'status'])
            ->map(fn ($b) => [
                'label' => $b->bill_number,
                'sub' => ($b->customer_name ?? 'Walk-in') . ' · ₹' . number_format($b->total_amount, 0)
                    . ' · ' . ucfirst($b->status),
                'url' => route('quick-bills.show', $b->id),
            ])->all();
    }

    private function repairs(int $shopId, string $q): array
    {
        return Repair::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('repair_number', 'ilike', "%{$q}%")
                ->orWhere('item_description', 'ilike', "%{$q}%")
                ->orWhere('description', 'ilike', "%{$q}%")
                ->orWhereHas('customer', fn ($cq) => $cq
                    ->where('first_name', 'ilike', "%{$q}%")
                    ->orWhere('last_name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%")))
            ->with('customer:id,first_name,last_name,mobile')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'repair_number', 'item_description', 'status', 'customer_id'])
            ->map(function (Repair $repair): array {
                $customer = trim(($repair->customer?->first_name ?? '') . ' ' . ($repair->customer?->last_name ?? ''));
                $status = ucfirst(str_replace('_', ' ', (string) $repair->status));

                return [
                    'label' => $repair->repair_number ?: ('Repair #' . $repair->id),
                    'sub' => trim(($customer ?: ($repair->item_description ?? 'Repair item')) . ' · ' . $status, ' ·'),
                    'url' => route('repairs.edit', $repair->id),
                ];
            })->all();
    }

    private function karigars(int $shopId, string $q): array
    {
        return Karigar::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('contact_person', 'ilike', "%{$q}%")
                ->orWhere('mobile', 'like', "%{$q}%")
                ->orWhere('city', 'ilike', "%{$q}%"))
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'contact_person', 'mobile', 'is_active'])
            ->map(function (Karigar $karigar): array {
                $sub = $karigar->contact_person ?: '';
                if (!empty($karigar->mobile)) {
                    $sub .= ($sub ? ' · ' : '') . $karigar->mobile;
                }
                $sub .= ($sub ? ' · ' : '') . ($karigar->is_active ? 'Active' : 'Inactive');

                return [
                    'label' => $karigar->name,
                    'sub' => $sub,
                    'url' => route('karigars.show', $karigar->id),
                ];
            })->all();
    }

    private function jobOrders(int $shopId, string $q): array
    {
        return JobOrder::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('job_order_number', 'ilike', "%{$q}%")
                ->orWhere('challan_number', 'ilike', "%{$q}%")
                ->orWhere('metal_type', 'ilike', "%{$q}%")
                ->orWhereHas('karigar', fn ($kq) => $kq
                    ->where('name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%")))
            ->with('karigar:id,name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'karigar_id', 'job_order_number', 'metal_type', 'status'])
            ->map(function (JobOrder $order): array {
                $status = ucfirst(str_replace('_', ' ', (string) $order->status));
                $metalType = strtoupper((string) $order->metal_type);
                $karigarName = $order->karigar?->name ?? '';

                return [
                    'label' => $order->job_order_number ?: ('Job Order #' . $order->id),
                    'sub' => trim($karigarName . ($metalType ? ' · ' . $metalType : '') . ' · ' . $status, ' ·'),
                    'url' => route('job-orders.show', $order->id),
                ];
            })->all();
    }

    private function karigarInvoices(int $shopId, string $q): array
    {
        return KarigarInvoice::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('karigar_invoice_number', 'ilike', "%{$q}%")
                ->orWhereHas('karigar', fn ($kq) => $kq
                    ->where('name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%"))
                ->orWhereHas('jobOrder', fn ($jq) => $jq->where('job_order_number', 'ilike', "%{$q}%")))
            ->with('karigar:id,name', 'jobOrder:id,job_order_number')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'karigar_id', 'job_order_id', 'karigar_invoice_number', 'total_after_tax', 'payment_status'])
            ->map(function (KarigarInvoice $invoice): array {
                $sub = $invoice->karigar?->name ?? '';
                if (!empty($invoice->jobOrder?->job_order_number)) {
                    $sub .= ($sub ? ' · ' : '') . $invoice->jobOrder->job_order_number;
                }
                $sub .= ($sub ? ' · ' : '') . '₹' . number_format((float) $invoice->total_after_tax, 0);

                return [
                    'label' => $invoice->karigar_invoice_number ?: ('Karigar Invoice #' . $invoice->id),
                    'sub' => $sub,
                    'url' => route('karigar-invoices.show', $invoice->id),
                ];
            })->all();
    }

    private function stockPurchases(int $shopId, string $q): array
    {
        return StockPurchase::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('purchase_number', 'ilike', "%{$q}%")
                ->orWhere('invoice_number', 'ilike', "%{$q}%")
                ->orWhere('supplier_name', 'ilike', "%{$q}%")
                ->orWhere('supplier_gstin', 'ilike', "%{$q}%")
                ->orWhereHas('vendor', fn ($vq) => $vq
                    ->where('name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%")))
            ->with('vendor:id,name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'vendor_id', 'purchase_number', 'invoice_number', 'supplier_name', 'status', 'total_amount'])
            ->map(function (StockPurchase $purchase): array {
                $supplier = $purchase->supplier_name ?: ($purchase->vendor?->name ?? 'Supplier');
                $status = ucfirst(str_replace('_', ' ', (string) $purchase->status));

                return [
                    'label' => $purchase->purchase_number ?: ('Purchase #' . $purchase->id),
                    'sub' => $supplier . ' · ' . $status . ' · ₹' . number_format((float) $purchase->total_amount, 0),
                    'url' => route('inventory.purchases.show', $purchase->id),
                ];
            })->all();
    }

    private function schemes(int $shopId, string $q): array
    {
        return Scheme::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('name', 'ilike', "%{$q}%")
                ->orWhere('description', 'ilike', "%{$q}%")
                ->orWhere('type', 'ilike', "%{$q}%"))
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'name', 'type', 'is_active'])
            ->map(function (Scheme $scheme): array {
                $type = ucwords(str_replace('_', ' ', (string) $scheme->type));

                return [
                    'label' => $scheme->name,
                    'sub' => $type . ' · ' . ($scheme->is_active ? 'Active' : 'Inactive'),
                    'url' => route('schemes.show', $scheme->id),
                ];
            })->all();
    }

    private function installments(int $shopId, string $q): array
    {
        return InstallmentPlan::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->whereRaw('CAST(id AS TEXT) ILIKE ?', ["%{$q}%"])
                ->orWhereHas('customer', fn ($cq) => $cq
                    ->where('first_name', 'ilike', "%{$q}%")
                    ->orWhere('last_name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%"))
                ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'ilike', "%{$q}%")))
            ->with('customer:id,first_name,last_name', 'invoice:id,invoice_number')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'customer_id', 'invoice_id', 'remaining_amount', 'status'])
            ->map(function (InstallmentPlan $plan): array {
                $customer = trim(($plan->customer?->first_name ?? '') . ' ' . ($plan->customer?->last_name ?? ''));
                $invoiceNumber = $plan->invoice?->invoice_number ?? '';
                $status = ucfirst(str_replace('_', ' ', (string) $plan->status));
                $sub = $customer ?: 'Customer';
                if ($invoiceNumber !== '') {
                    $sub .= ' · ' . $invoiceNumber;
                }
                $sub .= ' · Due ₹' . number_format((float) $plan->remaining_amount, 0) . ' · ' . $status;

                return [
                    'label' => 'EMI Plan #' . $plan->id,
                    'sub' => $sub,
                    'url' => route('installments.show', $plan->id),
                ];
            })->all();
    }

    private function reorderRules(int $shopId, string $q): array
    {
        return ReorderRule::where('shop_id', $shopId)
            ->where(fn ($query) => $query
                ->where('category', 'ilike', "%{$q}%")
                ->orWhere('sub_category', 'ilike', "%{$q}%")
                ->orWhereHas('vendor', fn ($vq) => $vq
                    ->where('name', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'like', "%{$q}%")))
            ->with('vendor:id,name')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get(['id', 'vendor_id', 'category', 'sub_category', 'min_stock_threshold', 'is_active'])
            ->map(function (ReorderRule $rule): array {
                $category = trim((string) $rule->category);
                $subCategory = trim((string) $rule->sub_category);
                $vendor = $rule->vendor?->name ?? '';
                $scope = $category ?: 'All categories';
                if ($subCategory !== '') {
                    $scope .= ' / ' . $subCategory;
                }
                if ($vendor !== '') {
                    $scope .= ' · ' . $vendor;
                }
                $scope .= ' · Min ' . (int) $rule->min_stock_threshold;

                return [
                    'label' => 'Reorder Rule #' . $rule->id,
                    'sub' => $scope,
                    'url' => route('reorder.edit', $rule->id),
                ];
            })->all();
    }
}
