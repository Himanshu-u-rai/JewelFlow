<?php

namespace App\Http\Controllers;

use App\Models\InvoicePayment;
use App\Models\JobOrder;
use App\Models\KarigarPayment;
use App\Models\QuickBillPayment;
use App\Models\ShopPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodController extends Controller
{
    public function index()
    {
        $shop    = auth()->user()->shop;
        $methods = ShopPaymentMethod::where('shop_id', $shop->id)
            ->orderBy('sort_order')
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->groupBy('type');

        return view('settings.payment-methods', compact('shop', 'methods'));
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $validated = $this->validateMethod($request, $shopId);

        $count = ShopPaymentMethod::where('shop_id', $shopId)->where('type', $validated['type'])->count();

        ShopPaymentMethod::create(array_merge($validated, [
            'shop_id'    => $shopId,
            'is_active'  => true,
            'sort_order' => $count,
        ]));

        return back()->with('success', "Payment method \"{$validated['name']}\" added.");
    }

    public function update(Request $request, ShopPaymentMethod $method)
    {
        $this->authorizeShop($method);

        $validated = $this->validateMethod($request, $method->shop_id, $method->id);

        $method->update($validated);

        return back()->with('success', "Payment method \"{$method->name}\" updated.");
    }

    public function destroy(ShopPaymentMethod $method)
    {
        $this->authorizeShop($method);

        $inUse = InvoicePayment::where('payment_method_id', $method->id)->exists()
            || KarigarPayment::withoutTenant()->where('payment_method_id', $method->id)->exists()
            || QuickBillPayment::where('payment_method_id', $method->id)->exists()
            || JobOrder::withoutTenant()->where('advance_payment_method_id', $method->id)->exists();

        if ($inUse) {
            return back()->with('error', "Cannot delete \"{$method->name}\" — it has been used in past transactions.");
        }

        $name = $method->name;
        $method->delete();

        return back()->with('success', "Payment method \"{$name}\" deleted.");
    }

    public function toggle(ShopPaymentMethod $method)
    {
        $this->authorizeShop($method);

        $method->update(['is_active' => ! $method->is_active]);

        $state = $method->is_active ? 'enabled' : 'disabled';

        return back()->with('success', "\"{$method->name}\" {$state}.");
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function authorizeShop(ShopPaymentMethod $method): void
    {
        if ($method->shop_id !== auth()->user()->shop_id) {
            abort(403);
        }
    }

    private function validateMethod(Request $request, int $shopId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'type'            => ['required', Rule::in(ShopPaymentMethod::TYPES)],
            'name'            => [
                'required', 'string', 'max:100',
                Rule::unique('shop_payment_methods')
                    ->where('shop_id', $shopId)
                    ->where('type', $request->input('type'))
                    ->ignore($ignoreId),
            ],
            'upi_id'          => 'nullable|string|max:100',
            'bank_name'       => 'nullable|string|max:100',
            'account_holder'  => 'nullable|string|max:100',
            'account_number'  => 'nullable|string|max:50',
            'ifsc_code'       => 'nullable|string|max:20',
            'account_type'    => ['nullable', Rule::in(['current', 'savings', 'overdraft'])],
            'branch'          => 'nullable|string|max:100',
            'wallet_id'       => 'nullable|string|max:100',
        ]);
    }
}
