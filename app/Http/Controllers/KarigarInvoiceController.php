<?php

namespace App\Http\Controllers;

use App\Models\JobOrder;
use App\Models\JobOrderReceipt;
use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarPayment;
use App\Models\Shop;
use App\Models\ShopPaymentMethod;
use App\Services\KarigarInvoiceService;
use Illuminate\Http\Request;

class KarigarInvoiceController extends Controller
{
    public function __construct(private KarigarInvoiceService $service) {}

    public function index(Request $request)
    {
        $query = KarigarInvoice::query()->with(['karigar', 'jobOrder'])->latest();

        if ($status = $request->input('payment_status')) {
            $query->where('payment_status', $status);
        }
        if ($karigarId = $request->input('karigar_id')) {
            $query->where('karigar_id', $karigarId);
        }

        $invoices = $query->paginate(25)->withQueryString();
        $karigars = Karigar::query()->active()->orderBy('name')->get(['id', 'name']);

        return view('karigar-invoices.index', [
            'invoices' => $invoices,
            'karigars' => $karigars,
            'filterStatus' => $request->input('payment_status'),
            'filterKarigar' => $request->input('karigar_id'),
        ]);
    }

    public function create(Request $request)
    {
        $karigars = Karigar::query()->active()->orderBy('name')->get();

        $jobOrderId = (int) $request->input('job_order');
        $jobOrder = $jobOrderId ? JobOrder::query()->where('id', $jobOrderId)->with(['receipts.items', 'karigar'])->first() : null;
        if ($jobOrder) {
            abort_unless($jobOrder->shop_id === auth()->user()->shop_id, 403);
        }

        $receiptId = (int) $request->input('receipt');
        $receipt = $receiptId ? JobOrderReceipt::query()->where('id', $receiptId)->with(['items', 'jobOrder.karigar'])->first() : null;
        if ($receipt) {
            abort_unless($receipt->shop_id === auth()->user()->shop_id, 403);
        }

        $shopId = auth()->user()->shop_id;

        // Unlinked advance payments per karigar (so the form can show them)
        $unlinkedAdvances = KarigarPayment::query()
            ->where('shop_id', $shopId)
            ->whereNull('karigar_invoice_id')
            ->orderBy('paid_on')
            ->get(['id', 'karigar_id', 'amount', 'paid_on', 'mode', 'reference', 'job_order_id'])
            ->groupBy('karigar_id')
            ->map(fn ($g) => $g->map(fn ($p) => [
                'id'         => $p->id,
                'amount'     => (float) $p->amount,
                'paid_on'    => $p->paid_on->format('d M Y'),
                'mode'       => $p->mode,
                'reference'  => $p->reference,
            ])->values());

        $advancesByKarigar = $unlinkedAdvances->toJson();

        return view('karigar-invoices.create', compact('karigars', 'jobOrder', 'receipt', 'advancesByKarigar'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'karigar_id' => 'required|integer',
            'job_order_id' => 'nullable|integer',
            'mode' => 'required|in:purchase,job_work',
            'karigar_invoice_number' => 'required|string|max:100',
            'karigar_invoice_date' => 'required|date',
            'state_code' => 'nullable|string|max:5',
            'is_interstate' => 'nullable|boolean',
            'cgst_rate' => 'nullable|numeric|min:0|max:50',
            'sgst_rate' => 'nullable|numeric|min:0|max:50',
            'igst_rate' => 'nullable|numeric|min:0|max:50',
            'amount_in_words' => 'nullable|string|max:255',
            'tax_amount_in_words' => 'nullable|string|max:255',
            'jurisdiction' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_file' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:10240',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.hsn_code' => 'nullable|string|max:20',
            'lines.*.pieces' => 'required|integer|min:1',
            'lines.*.gross_weight' => 'required|numeric|min:0',
            'lines.*.stone_weight' => 'nullable|numeric|min:0',
            'lines.*.net_weight' => 'required|numeric|min:0',
            'lines.*.purity' => 'required|numeric|min:0|max:1000',
            'lines.*.rate_per_gram' => 'nullable|numeric|min:0',
            'lines.*.metal_amount' => 'nullable|numeric|min:0',
            'lines.*.making_charge' => 'nullable|numeric|min:0',
            'lines.*.wastage_charge' => 'nullable|numeric|min:0',
            'lines.*.extra_amount' => 'nullable|numeric|min:0',
            'lines.*.note'              => 'nullable|string|max:255',
            'advance_payment_ids'       => 'nullable|array',
            'advance_payment_ids.*'     => 'integer',
        ]);

        $karigar = Karigar::query()->where('id', $validated['karigar_id'])->first();
        abort_unless($karigar && $karigar->shop_id === auth()->user()->shop_id, 422);

        $invoice = $this->service->create(
            $validated,
            $validated['lines'],
            $request->file('invoice_file'),
            auth()->user()->shop_id,
            (int) auth()->id()
        );

        $message = "Karigar invoice #{$invoice->karigar_invoice_number} saved.";
        if (! empty($invoice->discrepancy_flags)) {
            $message .= ' Flagged: ' . implode(', ', $invoice->discrepancy_flags) . '.';
        }

        return redirect()->route('karigar-invoices.show', $invoice)->with('success', $message);
    }

    public function show(KarigarInvoice $karigarInvoice)
    {
        $this->authorizeShop($karigarInvoice);
        $karigarInvoice->load(['lines', 'karigar', 'jobOrder', 'payments.paymentMethod']);

        $paymentMethods = ShopPaymentMethod::query()
            ->where('shop_id', auth()->user()->shop_id)
            ->whereRaw('is_active IS TRUE')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('karigar-invoices.show', [
            'invoice' => $karigarInvoice,
            'paymentMethods' => $paymentMethods,
        ]);
    }

    public function edit(KarigarInvoice $karigarInvoice)
    {
        $this->authorizeShop($karigarInvoice);
        $karigarInvoice->load(['lines', 'karigar']);
        $karigars = Karigar::query()->active()->orderBy('name')->get();

        return view('karigar-invoices.edit', [
            'invoice' => $karigarInvoice,
            'karigars' => $karigars,
        ]);
    }

    public function update(Request $request, KarigarInvoice $karigarInvoice)
    {
        $this->authorizeShop($karigarInvoice);

        $validated = $request->validate([
            'mode' => 'required|in:purchase,job_work',
            'karigar_invoice_number' => 'required|string|max:100',
            'karigar_invoice_date' => 'required|date',
            'state_code' => 'nullable|string|max:5',
            'is_interstate' => 'nullable|boolean',
            'cgst_rate' => 'nullable|numeric|min:0|max:50',
            'sgst_rate' => 'nullable|numeric|min:0|max:50',
            'igst_rate' => 'nullable|numeric|min:0|max:50',
            'amount_in_words' => 'nullable|string|max:255',
            'tax_amount_in_words' => 'nullable|string|max:255',
            'jurisdiction' => 'nullable|string|max:100',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_file' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:10240',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.hsn_code' => 'nullable|string|max:20',
            'lines.*.pieces' => 'required|integer|min:1',
            'lines.*.gross_weight' => 'required|numeric|min:0',
            'lines.*.stone_weight' => 'nullable|numeric|min:0',
            'lines.*.net_weight' => 'required|numeric|min:0',
            'lines.*.purity' => 'required|numeric|min:0|max:1000',
            'lines.*.rate_per_gram' => 'nullable|numeric|min:0',
            'lines.*.metal_amount' => 'nullable|numeric|min:0',
            'lines.*.making_charge' => 'nullable|numeric|min:0',
            'lines.*.wastage_charge' => 'nullable|numeric|min:0',
            'lines.*.extra_amount' => 'nullable|numeric|min:0',
            'lines.*.note' => 'nullable|string|max:255',
        ]);

        $this->service->update($karigarInvoice, $validated, $validated['lines'], $request->file('invoice_file'));

        return redirect()->route('karigar-invoices.show', $karigarInvoice)->with('success', 'Invoice updated.');
    }

    public function destroy(KarigarInvoice $karigarInvoice)
    {
        $this->authorizeShop($karigarInvoice);

        if ($karigarInvoice->payments()->exists()) {
            return back()->with('error', 'Cannot delete an invoice with recorded payments.');
        }

        if ($karigarInvoice->invoice_file_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($karigarInvoice->invoice_file_path);
        }

        $karigarInvoice->lines()->delete();
        $karigarInvoice->delete();

        return redirect()->route('karigar-invoices.index')->with('success', 'Invoice deleted.');
    }

    public function print(KarigarInvoice $karigarInvoice)
    {
        $this->authorizeShop($karigarInvoice);
        $karigarInvoice->load(['lines', 'karigar', 'jobOrder']);
        $shop = Shop::find(auth()->user()->shop_id);

        return view('karigar-invoices.print', [
            'invoice' => $karigarInvoice,
            'shop' => $shop,
        ]);
    }

    public function recordPayment(Request $request, KarigarInvoice $karigarInvoice)
    {
        $this->authorizeShop($karigarInvoice);

        $validated = $request->validate([
            'payments'                      => 'required|array|min:1',
            'payments.*.amount'             => 'required|numeric|min:0.01',
            'payments.*.mode'               => 'required|string|max:20',
            'payments.*.payment_method_id'  => 'nullable|integer',
            'payments.*.reference'          => 'nullable|string|max:255',
            'payments.*.paid_on'            => 'required|date',
        ]);

        foreach ($validated['payments'] as $split) {
            $this->service->recordPayment($karigarInvoice, $split, (int) auth()->id());
        }

        $count = count($validated['payments']);

        return redirect()->route('karigar-invoices.show', $karigarInvoice)
            ->with('success', $count === 1 ? 'Payment recorded.' : "{$count} payment splits recorded.");
    }

    private function authorizeShop(KarigarInvoice $invoice): void
    {
        abort_unless($invoice->shop_id === auth()->user()->shop_id, 403);
    }
}
