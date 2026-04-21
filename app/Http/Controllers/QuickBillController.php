<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\QuickBill;
use App\Services\QuickBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        try {
            $quickBill = $this->quickBillService->create(auth()->user()->shop, auth()->user(), $payload);
        } catch (ValidationException $e) {
            throw $e;
        }

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

        return view('quick-bills.print', compact('quickBill'));
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
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
            'items' => 'required|array|min:1',
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
            'items.*.stone_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.wastage_percent' => 'nullable|numeric|min:0|max:1000',
            'items.*.line_discount' => 'nullable|numeric|min:0|max:9999999.99',
            'payments' => 'nullable|array',
            'payments.*.payment_mode' => 'nullable|string|max:30',
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
                'wastage_percent' => 0,
                'line_discount' => 0,
            ]]);

        $initialPayments = old('payments', $quickBill->exists
            ? $quickBill->payments->map(fn ($payment) => [
                'payment_mode' => $payment->payment_mode,
                'reference_no' => $payment->reference_no,
                'amount' => (float) $payment->amount,
                'notes' => $payment->notes,
            ])->values()->all()
            : []);

        return [
            'quickBill' => $quickBill,
            'customers' => $customers,
            'initialItems' => $initialItems,
            'initialPayments' => $initialPayments,
        ];
    }
}
