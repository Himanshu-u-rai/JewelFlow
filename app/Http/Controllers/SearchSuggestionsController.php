<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Product;
use App\Models\QuickBill;
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
}
