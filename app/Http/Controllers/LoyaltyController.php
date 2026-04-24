<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(
        protected LoyaltyService $loyaltyService
    ) {}

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = Customer::where('shop_id', $shopId)
            ->where('loyalty_points', '>', 0);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderByDesc('loyalty_points')->paginate(15);

        $totalPointsIssued = LoyaltyTransaction::where('shop_id', $shopId)
            ->where('type', 'earn')
            ->sum('points');

        $totalPointsRedeemed = LoyaltyTransaction::where('shop_id', $shopId)
            ->where('type', 'redeem')
            ->sum('points');

        return view('loyalty.index', compact('customers', 'totalPointsIssued', 'totalPointsRedeemed'));
    }

    public function customerHistory(Customer $customer)
    {
        abort_if($customer->shop_id !== auth()->user()->shop_id, 403);

        $transactions = $customer->loyaltyTransactions()
            ->with('invoice')
            ->latest()
            ->paginate(20);

        return view('loyalty.history', compact('customer', 'transactions'));
    }

    public function adjustForm(Customer $customer)
    {
        abort_if($customer->shop_id !== auth()->user()->shop_id, 403);

        return view('loyalty.adjust', compact('customer'));
    }

    public function adjust(Request $request, Customer $customer)
    {
        abort_if($customer->shop_id !== auth()->user()->shop_id, 403);

        $data = $request->validate([
            'type' => 'required|in:earn,redeem',
            'points' => 'required|integer|min:1',
            'description' => 'required|string|max:255',
        ]);

        $this->loyaltyService->adjustPoints(
            $customer,
            $data['points'],
            $data['type'],
            $data['description']
        );

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Points adjusted successfully.');
    }
}
