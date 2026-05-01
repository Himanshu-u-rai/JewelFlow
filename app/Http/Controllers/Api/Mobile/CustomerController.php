<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Repair;
use App\Services\PosSearchCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $results = PosSearchCacheService::customers($shopId, $request->input('search'));

        return response()->json($results);
    }

    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'mobile' => [
                'required', 'digits:10',
                Rule::unique('customers', 'mobile')->where('shop_id', $shopId),
            ],
        ]);

        $customer = Customer::create([
            'shop_id' => $shopId,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? null,
            'mobile' => $validated['mobile'],
        ]);

        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'customer_code' => $customer->customer_code,
            'message' => 'Customer added successfully.',
        ], 201);
    }

    public function context(Customer $customer, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $customer->shop_id !== $shopId) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $recentRepairs = Repair::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->limit(6)
            ->get([
                'id',
                'repair_number',
                'item_description',
                'status',
                'estimated_cost',
                'final_cost',
                'due_date',
                'created_at',
            ]);

        $recentInvoices = Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->limit(6)
            ->get([
                'id',
                'invoice_number',
                'status',
                'total',
                'created_at',
            ]);

        $timeline = collect();

        foreach ($recentRepairs as $repair) {
            $timeline->push([
                'id' => 'repair-' . $repair->id,
                'type' => 'repair',
                'title' => 'Repair #' . $repair->repair_number,
                'subtitle' => $repair->item_description,
                'status' => $repair->status,
                'amount' => (float) ($repair->final_cost ?? $repair->estimated_cost ?? 0),
                'date' => optional($repair->created_at)?->toIso8601String(),
            ]);
        }

        foreach ($recentInvoices as $invoice) {
            $timeline->push([
                'id' => 'invoice-' . $invoice->id,
                'type' => 'invoice',
                'title' => 'Invoice ' . $invoice->invoice_number,
                'subtitle' => 'Sale invoice',
                'status' => $invoice->status,
                'amount' => (float) ($invoice->total ?? 0),
                'date' => optional($invoice->created_at)?->toIso8601String(),
            ]);
        }

        $timeline = $timeline
            ->sortByDesc('date')
            ->values()
            ->take(12);

        $totalInvoices = Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->count();

        $lifetimeSpend = (float) Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        $totalRepairs = Repair::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->count();

        $openRepairs = Repair::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'delivered')
            ->count();

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'name' => $customer->name,
                'mobile' => $customer->mobile,
                'customer_code' => $customer->customer_code,
                'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
            ],
            'summary' => [
                'total_invoices' => $totalInvoices,
                'lifetime_spend' => $lifetimeSpend,
                'total_repairs' => $totalRepairs,
                'open_repairs' => $openRepairs,
            ],
            'timeline' => $timeline,
        ]);
    }
}
