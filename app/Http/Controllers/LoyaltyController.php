<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

// The loyalty list and history pages live in Customers now (/loyalty and /loyalty/{customer}/history redirect
// there); this controller keeps the points adjustment only.
class LoyaltyController extends Controller
{
    public function __construct(
        protected LoyaltyService $loyaltyService
    ) {}

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

        try {
            $this->loyaltyService->adjustPoints(
                $customer,
                $data['points'],
                $data['type'],
                $data['description']
            );
        } catch (\LogicException $e) {
            // Checked against the balance as it is at the write, under its lock (S3-16).
            return back()->withErrors(['points' => $e->getMessage()])->withInput();
        }

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Points adjusted successfully.');
    }
}
