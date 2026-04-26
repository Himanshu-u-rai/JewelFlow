<?php

namespace App\Http\Controllers;

use App\Models\JobOrder;
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
            ->orderByDesc('id')
            ->get(['id', 'lot_number', 'source', 'purity', 'fine_weight_remaining']);

        $paymentMethods = ShopPaymentMethod::query()
            ->where('shop_id', $shopId)
            ->whereRaw('is_active IS TRUE')
            ->orderBy('type')->orderBy('name')
            ->get(['id', 'name', 'type']);

        return view('job-orders.create', compact('karigars', 'lots', 'paymentMethods'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'karigar_id' => 'required|integer',
            'metal_type' => 'required|in:gold,silver',
            'purity' => 'required|numeric|min:1|max:1000',
            'allowed_wastage_percent' => 'required|numeric|min:0|max:25',
            'issue_date' => 'required|date',
            'expected_return_date' => 'nullable|date|after_or_equal:issue_date',
            'notes' => 'nullable|string',
            'issuances' => 'required|array|min:1',
            'issuances.*.metal_lot_id' => 'required|integer',
            'issuances.*.gross_weight' => 'required|numeric|min:0',
            'issuances.*.fine_weight' => 'required|numeric|min:0.0001',
            'issuances.*.purity'           => 'required|numeric|min:1|max:1000',
            'advance_amount'               => 'nullable|numeric|min:0',
            'advance_mode'                 => 'nullable|string|max:20',
            'advance_payment_method_id'    => 'nullable|integer',
        ]);

        $karigar = Karigar::query()->where('id', $validated['karigar_id'])->first();
        abort_unless($karigar && $karigar->shop_id === auth()->user()->shop_id && $karigar->is_active, 422, 'Invalid karigar.');

        $jobOrder = $this->service->issue(
            $validated,
            auth()->user()->shop_id,
            (int) auth()->id()
        );

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

        return view('job-orders.show', compact('jobOrder'));
    }

    public function cancel(JobOrder $jobOrder)
    {
        $this->authorizeShop($jobOrder);
        $this->service->cancel($jobOrder, (int) auth()->id());

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
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string|max:255',
            'items.*.hsn_code' => 'nullable|string|max:20',
            'items.*.pieces' => 'required|integer|min:1',
            'items.*.gross_weight' => 'required|numeric|min:0.0001',
            'items.*.stone_weight' => 'nullable|numeric|min:0',
            'items.*.net_weight' => 'required|numeric|min:0.0001',
            'items.*.purity' => 'required|numeric|min:1|max:1000',
        ]);

        $receipt = $this->service->receive($jobOrder, $validated, (int) auth()->id());

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', "Receipt {$receipt->receipt_number} recorded. {$receipt->total_pieces} pieces, {$receipt->total_net_weight}g net.");
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
        $this->service->acknowledgeAndComplete($jobOrder);

        return redirect()->route('job-orders.show', $jobOrder)
            ->with('success', 'Discrepancy acknowledged. Job order completed.');
    }

    private function authorizeShop(JobOrder $jobOrder): void
    {
        abort_unless($jobOrder->shop_id === auth()->user()->shop_id, 403);
    }
}
