<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerGoldTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerGoldController extends Controller
{
    public function create(Customer $customer)
    {
        $this->ensureManufacturerShop();
        $this->authorize('update', $customer);

        return view('customers.gold.create', compact('customer'));
    }

    public function store(Request $request, Customer $customer)
    {
        $this->ensureManufacturerShop();
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'gross_weight' => 'required|numeric|min:0.001',
            'purity'       => 'required|numeric|min:1|max:24',
            'notes'        => 'nullable|string|max:1000',
        ]);

        $shopId   = auth()->user()->shop_id;
        $gross    = (float) $validated['gross_weight'];
        $purity   = (float) $validated['purity'];
        $fineGold = $gross * ($purity / 24);

        DB::transaction(function () use ($shopId, $customer, $gross, $purity, $fineGold, $validated) {
            $lot = \App\Models\MetalLot::firstOrCreate(
                ['shop_id' => $shopId, 'source' => 'customer_advance'],
                ['purity' => 24, 'fine_weight_total' => 0, 'fine_weight_remaining' => 0]
            );

            $lot->fine_weight_total     += $fineGold;
            $lot->fine_weight_remaining += $fineGold;
            $lot->save();

            $txn = CustomerGoldTransaction::record([
                'shop_id'        => $shopId,
                'customer_id'    => $customer->id,
                'gross_weight'   => $gross,
                'purity'         => $purity,
                'fine_gold'      => $fineGold,
                'type'           => 'advance',
                'reference_type' => 'metal_lot',
                'reference_id'   => $lot->id,
                'invoice_id'     => null,
            ]);

            \App\Models\AuditLog::create([
                'shop_id'    => $shopId,
                'user_id'    => auth()->id(),
                'action'     => 'customer_gold_advance',
                'model_type' => 'customer_gold_transactions',
                'model_id'   => $txn->id,
                'data'       => [
                    'customer_id' => $customer->id,
                    'gross'       => $gross,
                    'purity'      => $purity,
                    'fine_gold'   => $fineGold,
                ],
            ]);
        });

        return redirect()->route('customers.show', $customer->id)
            ->with('success', 'Gold advance recorded.');
    }

    private function ensureManufacturerShop(): void
    {
        if (!auth()->user()?->shop?->isManufacturer()) {
            abort(403, 'Customer gold advance is available only in manufacturer edition.');
        }
    }
}
