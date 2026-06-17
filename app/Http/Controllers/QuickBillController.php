<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\QuickBill;
use App\Services\QuickBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuickBillController extends Controller
{
    public function __construct(private QuickBillService $quickBillService)
    {
    }

    public function index(Request $request): View
    {
        $shopId = auth()->user()->shop_id;

        $query = QuickBill::query()->with('customer', 'creator');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('bill_number', 'ilike', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%")
                    ->orWhere('customer_mobile', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                        $customerQuery->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('bill_date', '>=', $request->date('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('bill_date', '<=', $request->date('to_date'));
        }

        $quickBills = $query->orderByDesc('bill_date')->orderByDesc('id')->paginate(20)->withQueryString();

        $stats = [
            'total_count' => QuickBill::query()->count(),
            'issued_count' => QuickBill::query()->where('status', QuickBill::STATUS_ISSUED)->count(),
            'draft_count' => QuickBill::query()->where('status', QuickBill::STATUS_DRAFT)->count(),
            'today_total' => QuickBill::query()
                ->whereDate('bill_date', today())
                ->where('status', '!=', QuickBill::STATUS_VOID)
                ->sum('total_amount'),
            'outstanding_total' => QuickBill::query()
                ->where('status', '!=', QuickBill::STATUS_VOID)
                ->sum('due_amount'),
            'shop_id' => $shopId,
        ];

        return view('quick-bills.index', compact('quickBills', 'stats'));
    }

    public function create(): View
    {
        $quickBill = new QuickBill();
        $quickBill->forceFill([
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'gst_exclusive',
            'gst_rate' => 3,
        ]);

        return view('quick-bills.form', $this->formPayload($quickBill));
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);

        $quickBill = $this->quickBillService->create(auth()->user()->shop, auth()->user(), $payload);

        return redirect()->route('quick-bills.show', $quickBill)
            ->with('success', 'Quick bill saved successfully.');
    }

    public function show(QuickBill $quickBill): View
    {
        $quickBill->load('customer', 'items', 'payments', 'creator');

        return view('quick-bills.show', compact('quickBill'));
    }

    public function edit(QuickBill $quickBill): View
    {
        $quickBill->load('items', 'payments');

        return view('quick-bills.form', $this->formPayload($quickBill));
    }

    public function update(Request $request, QuickBill $quickBill): RedirectResponse
    {
        $payload = $this->validatedPayload($request);

        $quickBill = $this->quickBillService->update($quickBill, auth()->user()->shop, auth()->user(), $payload);

        return redirect()->route('quick-bills.show', $quickBill)
            ->with('success', 'Quick bill updated successfully.');
    }

    public function void(Request $request, QuickBill $quickBill): RedirectResponse
    {
        $data = $request->validate([
            'void_reason' => 'nullable|string|max:1000',
        ]);

        $this->quickBillService->void($quickBill, auth()->user(), $data['void_reason'] ?? null);

        return redirect()->route('quick-bills.show', $quickBill)
            ->with('success', 'Quick bill voided successfully.');
    }

    public function print(QuickBill $quickBill): View
    {
        $quickBill->load('customer', 'items', 'payments');

        // The live bill is the (possibly edited) current version.
        $isEdited = $quickBill->isEdited();

        return view('quick-bills.print', [
            'quickBill'  => $quickBill,
            'isEdited'   => $isEdited,
            'isOriginal' => false,
        ]);
    }

    /**
     * Print the frozen as-issued original of an edited bill, hydrated from the
     * stored original_snapshot. Renders the same print view from a non-persisted
     * QuickBill so the template stays single-source.
     */
    public function printOriginal(QuickBill $quickBill): View
    {
        abort_unless($quickBill->isEdited() && ! empty($quickBill->original_snapshot), 404);

        $original = $this->hydrateFromSnapshot($quickBill, $quickBill->original_snapshot);

        return view('quick-bills.print', [
            'quickBill'  => $original,
            'isEdited'   => false,
            'isOriginal' => true,
        ]);
    }

    /**
     * Rebuild a read-only QuickBill (+ items + payments) from an original
     * snapshot array. Not persisted; used only for rendering the original print.
     */
    private function hydrateFromSnapshot(QuickBill $quickBill, array $snap): QuickBill
    {
        $bill = new QuickBill();
        $bill->forceFill([
            'id'               => $quickBill->id,
            'shop_id'          => $quickBill->shop_id,
            'bill_number'      => $snap['bill_number'] ?? $quickBill->bill_number,
            'bill_date'        => $snap['bill_date'] ?? null,
            'status'           => $snap['status'] ?? QuickBill::STATUS_ISSUED,
            'customer_name'    => $snap['customer_name'] ?? null,
            'customer_mobile'  => $snap['customer_mobile'] ?? null,
            'customer_address' => $snap['customer_address'] ?? null,
            'pricing_mode'     => $snap['pricing_mode'] ?? 'gst_exclusive',
            'gst_rate'         => $snap['gst_rate'] ?? 0,
            'subtotal'         => $snap['subtotal'] ?? 0,
            'discount_type'    => $snap['discount_type'] ?? null,
            'discount_value'   => $snap['discount_value'] ?? 0,
            'discount_amount'  => $snap['discount_amount'] ?? 0,
            'round_off'        => $snap['round_off'] ?? 0,
            'taxable_amount'   => $snap['taxable_amount'] ?? 0,
            'cgst_amount'      => $snap['cgst_amount'] ?? 0,
            'sgst_amount'      => $snap['sgst_amount'] ?? 0,
            'igst_amount'      => $snap['igst_amount'] ?? 0,
            'total_amount'     => $snap['total_amount'] ?? 0,
            'paid_amount'      => $snap['paid_amount'] ?? 0,
            'due_amount'       => $snap['due_amount'] ?? 0,
            'notes'            => $snap['notes'] ?? null,
            'terms'            => $snap['terms'] ?? null,
            'shop_snapshot'    => $snap['shop_snapshot'] ?? $quickBill->shop_snapshot,
        ]);
        $bill->exists = true;

        $items = collect($snap['items'] ?? [])->map(function ($i) use ($quickBill) {
            $line = new \App\Models\QuickBillItem();
            $line->forceFill(array_merge(['shop_id' => $quickBill->shop_id, 'quick_bill_id' => $quickBill->id], $i));
            return $line;
        })->values();

        $payments = collect($snap['payments'] ?? [])->map(function ($p) use ($quickBill) {
            $pay = new \App\Models\QuickBillPayment();
            $pay->forceFill(array_merge(['shop_id' => $quickBill->shop_id, 'quick_bill_id' => $quickBill->id], $p));
            return $pay;
        })->values();

        $bill->setRelation('items', $items);
        $bill->setRelation('payments', $payments);
        $bill->setRelation('customer', null);

        return $bill;
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id)],
            'customer_name' => 'nullable|string|max:255',
            'customer_mobile' => 'nullable|string|max:20',
            'customer_address' => 'nullable|string|max:1000',
            'bill_date' => 'required|date',
            'pricing_mode' => 'required|in:no_gst,gst_exclusive,gst_inclusive',
            'gst_rate' => 'required|numeric|min:0|max:100',
            'discount_type' => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0|max:9999999.99',
            'round_off' => 'nullable|numeric|min:-9999|max:9999',
            'notes' => 'nullable|string|max:5000',
            'terms' => 'nullable|string|max:5000',
            'save_action' => 'nullable|in:draft,issue',
            'items' => 'required|array|min:1|max:200',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.hsn_code' => 'nullable|string|max:40',
            'items.*.metal_type' => 'nullable|string|max:30',
            'items.*.purity' => 'nullable|string|max:30',
            'items.*.pcs' => 'nullable|integer|min:1|max:9999',
            'items.*.gross_weight' => 'nullable|numeric|min:0|max:999999.999',
            'items.*.stone_weight' => 'nullable|numeric|min:0|max:999999.999',
            'items.*.net_weight' => 'nullable|numeric|min:0|max:999999.999',
            'items.*.rate' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.making_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.making_charge_type' => 'nullable|string|in:fixed,percentage,per_gram',
            'items.*.making_charge_value' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.stone_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.hallmark_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.rhodium_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.other_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.wastage_percent' => 'nullable|numeric|min:0|max:1000',
            'items.*.line_discount' => 'nullable|numeric|min:0|max:9999999.99',
            'payments' => 'nullable|array|max:50',
            'payments.*.payment_mode' => 'nullable|string|max:30',
            'payments.*.payment_method_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('shop_payment_methods', 'id')->where('shop_id', auth()->user()->shop_id)],
            'payments.*.reference_no' => 'nullable|string|max:100',
            'payments.*.amount' => 'nullable|numeric|min:0|max:9999999.99',
            'payments.*.notes' => 'nullable|string|max:500',
        ]);
    }

    private function formPayload(QuickBill $quickBill): array
    {
        $customers = Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile', 'address']);

        $initialItems = old('items', $quickBill->exists
            ? $quickBill->items->map(fn ($item) => [
                'description' => $item->description,
                'hsn_code' => $item->hsn_code,
                'metal_type' => $item->metal_type,
                'purity' => $item->purity,
                'pcs' => $item->pcs,
                'gross_weight' => (float) $item->gross_weight,
                'stone_weight' => (float) $item->stone_weight,
                'net_weight' => (float) $item->net_weight,
                'rate' => (float) $item->rate,
                'making_charge' => (float) $item->making_charge,
                'stone_charge' => (float) $item->stone_charge,
                'hallmark_charge' => (float) ($item->hallmark_charge ?? 0),
                'rhodium_charge' => (float) ($item->rhodium_charge ?? 0),
                'other_charge' => (float) ($item->other_charge ?? 0),
                'wastage_percent' => (float) $item->wastage_percent,
                'line_discount' => (float) $item->line_discount,
            ])->values()->all()
            : [[
                'description' => '',
                'hsn_code' => '',
                'metal_type' => 'Gold',
                'purity' => '22K',
                'pcs' => 1,
                'gross_weight' => 0,
                'stone_weight' => 0,
                'net_weight' => 0,
                'rate' => 0,
                'making_charge' => 0,
                'stone_charge' => 0,
                'hallmark_charge' => 0,
                'rhodium_charge' => 0,
                'other_charge' => 0,
                'wastage_percent' => 0,
                'line_discount' => 0,
            ]]);

        $initialPayments = old('payments', $quickBill->exists
            ? $quickBill->payments->map(fn ($payment) => [
                'payment_mode' => $payment->payment_mode,
                'payment_method_id' => $payment->payment_method_id,
                'reference_no' => $payment->reference_no,
                'amount' => (float) $payment->amount,
                'notes' => $payment->notes,
            ])->values()->all()
            : []);

        // Shop's configured purity standards → cascading metal/purity dropdowns.
        // metal lowercased for matching; label is the display ("22K"/"925") and
        // also the submitted value (server parses the number for the factor).
        $shopId = (int) (auth()->user()->shop_id ?? $quickBill->shop_id);
        $purityProfiles = \App\Models\ShopMetalPurityProfile::where('shop_id', $shopId)
            ->whereRaw('is_active = true')
            ->orderBy('metal_type')
            ->orderBy('sort_order')
            ->get(['metal_type', 'label', 'purity_value'])
            ->map(fn ($p) => [
                'metal' => strtolower(trim((string) $p->metal_type)),
                'label' => (string) $p->label,
                'value' => (float) $p->purity_value,
            ])->values()->all();

        // Metals the owner has enabled (gold/silver always; platinum/copper when
        // opted in). Platinum/copper are piece-priced - purity never multiplies
        // their price - so they appear in the metal dropdown but use an optional
        // purity field (no profiles), and the purity factor stays 1 for them.
        $enabledMetals = \App\Services\MetalRegistry::enabledMetalsForShop($shopId);

        // Shop's saved UPI / Bank / Wallet accounts → method picker on payment
        // rows (same as POS). QuickBillService already requires + validates a
        // method id for upi/bank/wallet modes.
        $paymentMethods = \App\Models\ShopPaymentMethod::where('shop_id', $shopId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'type' => (string) $m->type,
                'label' => trim($m->name . ($m->account_label ? ' · ' . $m->account_label : '')),
            ])->values()->all();

        return [
            'quickBill' => $quickBill,
            'customers' => $customers,
            'initialItems' => $initialItems,
            'initialPayments' => $initialPayments,
            'purityProfiles' => $purityProfiles,
            'enabledMetals' => array_values($enabledMetals),
            'paymentMethods' => $paymentMethods,
        ];
    }
}
