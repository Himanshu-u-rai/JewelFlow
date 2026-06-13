<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\JobOrder;
use App\Models\JobOrderSource;
use App\Models\Karigar;
use App\Models\MetalLot;
use App\Models\Shop;
use App\Models\ShopPaymentMethod;
use App\Services\JobOrderService;
use Illuminate\Http\Request;

class JobOrderController extends Controller
{
    public function __construct(private JobOrderService $service) {}

    public function index(Request $request)
    {
        $query = JobOrder::query()->with(['karigar'])->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($karigarId = $request->input('karigar_id')) {
            $query->where('karigar_id', $karigarId);
        }
        if ($from = $request->input('from')) {
            $query->whereDate('issue_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->whereDate('issue_date', '<=', $to);
        }

        $orders = $query->paginate(25)->withQueryString();
        $karigars = Karigar::query()->active()->orderBy('name')->get(['id', 'name']);

        return view('job-orders.index', [
            'orders' => $orders,
            'karigars' => $karigars,
            'filterStatus' => $request->input('status'),
            'filterKarigar' => $request->input('karigar_id'),
            'filterFrom' => $request->input('from'),
            'filterTo' => $request->input('to'),
        ]);
    }

    public function create()
    {
        $shopId = auth()->user()->shop_id;

        $karigars = Karigar::query()->active()->orderBy('name')->get(['id', 'name', 'gst_number', 'default_wastage_percent']);
        $lots = MetalLot::query()
            ->where('shop_id', $shopId)
            ->where('fine_weight_remaining', '>', 0)
            ->whereNotIn('source', [\App\Models\MetalLot::SOURCE_OLD_GOLD_WEEKLY, \App\Models\MetalLot::SOURCE_OLD_SILVER_WEEKLY])
            ->orderByDesc('id')
            ->get(['id', 'lot_number', 'source', 'purity', 'fine_weight_remaining', 'metal_type']);

        $paymentMethods = ShopPaymentMethod::query()
            ->where('shop_id', $shopId)
            ->whereRaw('is_active IS TRUE')
            ->orderBy('type')->orderBy('name')
            ->get(['id', 'name', 'type']);

        // Customers — for the "customer's own gold" metal-source mode.
        $customers = Customer::query()
            ->where('shop_id', $shopId)
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile']);

        return view('job-orders.create', compact('karigars', 'lots', 'paymentMethods', 'customers'));
    }

    public function store(Request $request)
    {
        $shopId      = auth()->user()->shop_id;
        $metalSource = (string) $request->input('metal_source', JobOrder::METAL_SOURCE_VAULT);
        $isLaborOnly = $metalSource === JobOrder::METAL_SOURCE_NONE;

        // Karat (gold ≤24) / fineness (silver ≤1000) cap, shared by the job and
        // each source leg.
        $purityCap = function ($attribute, $value, $fail) use ($request) {
            $max = $request->input('metal_type') === 'silver' ? 1000 : 24;
            if ((float) $value > $max) {
                $fail($request->input('metal_type') === 'silver'
                    ? 'Silver purity (fineness) cannot exceed 1000.'
                    : 'Gold purity (karats) cannot exceed 24.');
            }
        };

        $rules = [
            'karigar_id'                => 'required|integer',
            'job_type'                  => ['nullable', \Illuminate\Validation\Rule::in(['manufacture', 'repair', 'rework'])],
            'source_item_id'            => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('items', 'id')->where('shop_id', $shopId)],
            'metal_type'                => 'required|in:gold,silver',
            'purity'                    => ['required', 'numeric', 'min:1', $purityCap],
            'metal_source'              => ['nullable', \Illuminate\Validation\Rule::in([
                JobOrder::METAL_SOURCE_NONE, JobOrder::METAL_SOURCE_VAULT,
                JobOrder::METAL_SOURCE_KARIGAR_BALANCE, JobOrder::METAL_SOURCE_CUSTOMER_SUPPLIED,
                JobOrder::METAL_SOURCE_MIXED,
            ])],
            'allowed_wastage_percent'   => 'required|numeric|min:0|max:25',
            'issue_date'                => 'required|date',
            'expected_return_date'      => 'nullable|date|after_or_equal:issue_date',
            'notes'                     => 'nullable|string',
            'advance_amount'            => 'nullable|numeric|min:0',
            'advance_mode'              => 'nullable|string|max:20',
            'advance_payment_method_id' => ['nullable', \Illuminate\Validation\Rule::exists('shop_payment_methods', 'id')->where('shop_id', $shopId)],
        ];

        // Labor-only (none) needs no metal legs. Otherwise accept either the new
        // source SET ('sources') or the legacy 'issuances' (vault-only) input.
        $usesSourceSet = ! $isLaborOnly && $request->has('sources');
        if ($usesSourceSet) {
            $rules['sources']                = 'required|array|min:1';
            $rules['sources.*.source_type']  = ['required', \Illuminate\Validation\Rule::in([
                JobOrderSource::TYPE_VAULT, JobOrderSource::TYPE_KARIGAR_HELD, JobOrderSource::TYPE_CUSTOMER_ADVANCE,
            ])];
            $rules['sources.*.fine_weight']  = 'required|numeric|min:0.0001';
            $rules['sources.*.gross_weight'] = 'nullable|numeric|min:0';
            $rules['sources.*.purity']       = ['nullable', 'numeric', 'min:1', $purityCap];
            $rules['sources.*.metal_lot_id'] = ['nullable', 'integer', \Illuminate\Validation\Rule::exists('metal_lots', 'id')->where('shop_id', $shopId)];
            $rules['sources.*.customer_id']  = ['nullable', 'integer', \Illuminate\Validation\Rule::exists('customers', 'id')->where('shop_id', $shopId)];
        } elseif (! $isLaborOnly) {
            $rules['issuances']                = 'required|array|min:1';
            $rules['issuances.*.metal_lot_id'] = ['required', 'integer', \Illuminate\Validation\Rule::exists('metal_lots', 'id')->where('shop_id', $shopId)];
            $rules['issuances.*.gross_weight'] = 'required|numeric|min:0';
            $rules['issuances.*.fine_weight']  = 'required|numeric|min:0.0001';
            $rules['issuances.*.purity']       = ['required', 'numeric', 'min:1', $purityCap];
        }

        $validated = $request->validate($rules);

        $karigar = Karigar::query()->where('id', $validated['karigar_id'])->first();
        abort_unless($karigar && $karigar->shop_id === $shopId && $karigar->is_active, 422, 'Invalid karigar.');

        $data = $validated;
        if ($isLaborOnly) {
            $data['sources'] = []; // labor-only: no legs
        } elseif ($usesSourceSet) {
            $data['sources'] = $validated['sources'] ?? [];
        }
        // else: legacy — leave 'issuances'; the service maps them to vault legs.

        try {
            $jobOrder = $this->service->issue($data, $shopId, (int) auth()->id());
        } catch (\LogicException $e) {
            return back()->withInput()->withErrors(['metal_source' => $e->getMessage()]);
        }

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', "Job Order {$jobOrder->job_order_number} issued. Challan: {$jobOrder->challan_number}");
    }

    public function show(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);
        $jobOrder->load([
            'karigar',
            'issuances.metalLot',
            'receipts.items',
            'invoices.lines',
        ]);

        // Active karigars (other than the current) — for the reassign action.
        $otherKarigars = $jobOrder->isOpen()
            ? Karigar::query()
                ->where('shop_id', auth()->user()->shop_id)
                ->whereRaw('is_active IS TRUE')
                ->where('id', '<>', $jobOrder->karigar_id)
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        return view('job-orders.show', compact('jobOrder', 'otherKarigars'));
    }

    public function cancel(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);

        try {
            $this->service->cancel($jobOrder, (int) auth()->id());
        } catch (\LogicException $e) {
            return redirect()->route('job-orders.show', $jobOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', "Job Order {$jobOrder->job_order_number} cancelled and bullion returned to vault.");
    }

    public function challan(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);
        $jobOrder->load(['karigar', 'issuances.metalLot']);
        $shop = Shop::find(auth()->user()->shop_id);

        return view('job-orders.challan', compact('jobOrder', 'shop'));
    }

    public function returnDoc(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);
        $jobOrder->load(['karigar', 'issuances.metalLot', 'receipts.items']);
        $shop = Shop::find(auth()->user()->shop_id);

        return view('job-orders.return-doc', compact('jobOrder', 'shop'));
    }

    public function receiveForm(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);
        abort_unless($jobOrder->isOpen(), 422, 'Job order is not open for receipts.');

        $jobOrder->load(['karigar', 'receipts.items']);

        return view('job-orders.receive', compact('jobOrder'));
    }

    public function storeReceipt(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);

        $validated = $request->validate([
            'receipt_date' => 'required|date',
            'notes' => 'nullable|string',
            // Metal the karigar keeps from this job (credited to their holding lot).
            'retained_fine_weight' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.hsn_code' => 'nullable|string|max:20',
            'items.*.pieces' => 'required|integer|min:1',
            'items.*.gross_weight' => 'required|numeric|min:0.0001',
            'items.*.stone_weight' => 'nullable|numeric|min:0',
            'items.*.net_weight' => 'required|numeric|min:0.0001',
            'items.*.purity' => 'required|numeric|min:1|max:1000',
        ]);

        try {
            $receipt = $this->service->receive($jobOrder, $validated, (int) auth()->id());
        } catch (\LogicException $e) {
            return back()->withInput()->withErrors(['retained_fine_weight' => $e->getMessage()]);
        }

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', "Receipt {$receipt->receipt_number} recorded. {$receipt->total_pieces} pieces, {$receipt->total_net_weight}g net.");
    }

    /**
     * Reassign an open job to a different karigar (metal moves with the job).
     */
    public function reassign(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);

        $validated = $request->validate([
            'to_karigar_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('karigars', 'id')->where('shop_id', auth()->user()->shop_id)],
        ]);

        try {
            $this->service->reassignOpenJob($jobOrder, (int) $validated['to_karigar_id'], (int) auth()->id());
        } catch (\LogicException $e) {
            return redirect()->route('job-orders.show', $jobOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', 'Job order reassigned to the new karigar.');
    }

    /**
     * Move retained (held) metal from one karigar to another.
     */
    public function transferBalance(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'from_karigar_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
            'to_karigar_id'   => ['required', 'integer', 'different:from_karigar_id', \Illuminate\Validation\Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
            'metal_type'      => 'required|in:gold,silver',
            'purity'          => 'required|numeric|min:1',
            'fine_weight'     => 'required|numeric|min:0.0001',
        ]);

        try {
            $this->service->transferHeldBalance(
                $shopId,
                (int) $validated['from_karigar_id'],
                (int) $validated['to_karigar_id'],
                $validated['metal_type'],
                (float) $validated['purity'],
                (float) $validated['fine_weight'],
                (int) auth()->id(),
            );
        } catch (\LogicException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Held metal transferred between karigars.');
    }

    public function leftoverReturn(Request $request, JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);

        $validated = $request->validate([
            'fine_weight' => 'required|numeric|min:0.0001',
            'metal_lot_id' => 'nullable|integer',
        ]);

        $this->service->recordLeftoverReturn($jobOrder, $validated, (int) auth()->id());

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', 'Leftover bullion credited to vault.');
    }

    public function acknowledge(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);

        try {
            $this->service->acknowledgeAndComplete($jobOrder);
        } catch (\LogicException $e) {
            return redirect()->route('job-orders.show', $jobOrder)->with('error', $e->getMessage());
        }

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', 'Discrepancy acknowledged. Job order completed.');
    }

    private function authorizeShop(JobOrder $jobOrder): void
    {
        abort_unless($jobOrder->shop_id === auth()->user()->shop_id, 403);
    }
}
