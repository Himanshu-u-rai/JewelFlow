<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Services\InstallmentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InstallmentController extends Controller
{
    public function __construct(
        protected InstallmentService $installmentService
    ) {}

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = InstallmentPlan::where('shop_id', $shopId)
            ->with(['customer', 'invoice']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'active');
        }

        $plans = $query->latest()->paginate(15);

        $activePlansCount = InstallmentPlan::where('shop_id', $shopId)
            ->where('status', 'active')
            ->count();

        $overduePlans = InstallmentPlan::where('shop_id', $shopId)
            ->active()
            ->where('next_due_date', '<', now()->toDateString())
            ->count();

        $totalOutstanding = InstallmentPlan::where('shop_id', $shopId)
            ->where('status', 'active')
            ->get()
            ->sum(function ($plan) {
                return $plan->remainingAmount();
            });

        $thisMonthCollected = InstallmentPayment::where('shop_id', $shopId)
            ->whereBetween('payment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('amount');

        $upcomingDues = InstallmentPlan::where('shop_id', $shopId)
            ->active()
            ->whereBetween('next_due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        $defaultedPlans = InstallmentPlan::where('shop_id', $shopId)
            ->where('status', 'defaulted')
            ->count();

        return view('installments.index', compact(
            'plans',
            'activePlansCount',
            'overduePlans',
            'totalOutstanding',
            'thisMonthCollected',
            'upcomingDues',
            'defaultedPlans'
        ));
    }

    public function create(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $fromPosEmi = $request->boolean('from_pos_emi');

        $customers = Customer::where('shop_id', $shopId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile']);

        $invoices = $this->eligibleInvoices($shopId);
        $selectedInvoiceId = (int) $request->query('invoice_id');

        if ($selectedInvoiceId > 0 && !$invoices->contains('id', $selectedInvoiceId)) {
            if ($fromPosEmi) {
                $draftInvoice = Invoice::where('shop_id', $shopId)
                    ->where('status', Invoice::STATUS_DRAFT)
                    ->with(['customer:id,first_name,last_name,mobile'])
                    ->withSum('items as line_total_sum', 'line_total')
                    ->find($selectedInvoiceId);

                if ($draftInvoice) {
                    $draftInvoice->paid_amount = (float) ($draftInvoice->payments()->sum('amount') ?? 0);
                    $draftInvoice->total = $this->computeDraftInvoiceTotal($draftInvoice);
                    $draftInvoice->outstanding_amount = $draftInvoice->total - (float) $draftInvoice->paid_amount;
                    $invoices = $invoices->push($draftInvoice)->unique('id')->values();
                } else {
                    return redirect()->route('installments.index')
                        ->with('error', 'Selected invoice is not eligible for EMI conversion.');
                }
            } else {
                return redirect()->route('installments.index')
                    ->with('error', 'Selected invoice is not eligible for EMI conversion.');
            }
        }

        return view('installments.create', compact('customers', 'invoices', 'selectedInvoiceId', 'fromPosEmi'));
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('shop_id', $shopId),
            ],
            'invoice_id' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')->where('shop_id', $shopId),
            ],
            'down_payment' => 'required|numeric|min:0',
            'total_emis' => 'required|integer|min:2|max:24',
            'interest_rate_annual' => 'nullable|numeric|min:0|max:60',
            'from_pos_emi' => 'nullable|boolean',
            'down_payment_method' => 'nullable|in:cash,upi,bank,other',
            'down_payment_reference' => 'nullable|string|max:100',
        ]);

        $invoice = Invoice::where('shop_id', $shopId)
            ->with(['payments', 'customer'])
            ->findOrFail((int) $data['invoice_id']);

        $fromPosEmi = (bool) ($data['from_pos_emi'] ?? false);
        $isDraftPosInvoice = $fromPosEmi && $invoice->status === Invoice::STATUS_DRAFT;

        if (!$isDraftPosInvoice && $invoice->status !== Invoice::STATUS_FINALIZED) {
            return back()->withErrors(['invoice_id' => 'Only finalized invoices can be converted to EMI.'])->withInput();
        }

        if ((int) $invoice->customer_id !== (int) $data['customer_id']) {
            return back()->withErrors(['customer_id' => 'Selected customer does not match invoice customer.'])->withInput();
        }

        if (!$invoice->customer) {
            return back()->withErrors(['invoice_id' => 'Invoice is not linked to a customer.'])->withInput();
        }

        $alreadyPlanned = InstallmentPlan::where('shop_id', $shopId)
            ->where('invoice_id', $invoice->id)
            ->exists();

        if ($alreadyPlanned) {
            return back()->withErrors(['invoice_id' => 'An EMI plan already exists for this invoice.'])->withInput();
        }

        if ($isDraftPosInvoice) {
            try {
                $plan = $this->installmentService->finalizeDraftInvoiceToPlan(
                    $invoice,
                    round((float) $data['down_payment'], 2),
                    (int) $data['total_emis'],
                    (float) ($data['interest_rate_annual'] ?? 0),
                    $data['down_payment_method'] ?? 'cash',
                    $data['down_payment_reference'] ?? null
                );

                return redirect()->route('installments.show', $plan)
                    ->with('success', 'EMI plan created and invoice finalized successfully. First EMI can be recorded later from plan page.');
            } catch (\LogicException $e) {
                return back()->withErrors(['invoice_id' => $e->getMessage()])->withInput();
            }
        }

        $invoiceTotal = (float) $invoice->total;
        $alreadyPaid = (float) $invoice->payments->sum('amount');
        $outstanding = max(0, $invoiceTotal - $alreadyPaid);
        $downPayment = (float) $data['down_payment'];

        if ($outstanding <= 0) {
            return back()->withErrors(['invoice_id' => 'This invoice is already fully paid.'])->withInput();
        }

        if ($downPayment < $alreadyPaid) {
            return back()->withErrors(['down_payment' => 'Down payment cannot be less than amount already paid on invoice.'])->withInput();
        }

        if (abs($downPayment - $alreadyPaid) > 0.01) {
            return back()->withErrors([
                'down_payment' => 'To change down payment, first record payment on invoice. EMI creation currently uses actual paid amount only.',
            ])->withInput();
        }

        if ($downPayment >= $invoiceTotal) {
            return back()->withErrors(['down_payment' => 'Down payment must be less than invoice total to create EMI plan.'])->withInput();
        }

        $plan = $this->installmentService->createPlan(
            $invoice,
            $invoice->customer,
            $downPayment,
            (int) $data['total_emis'],
            null,
            (float) ($data['interest_rate_annual'] ?? 0)
        );

        return redirect()->route('installments.show', $plan)
            ->with('success', 'EMI plan created successfully.');
    }

    public function show(InstallmentPlan $plan)
    {
        abort_if($plan->shop_id !== auth()->user()->shop_id, 403);

        $plan->load(['customer', 'invoice', 'payments']);
        $summary = $this->installmentService->summary($plan);

        return view('installments.show', compact('plan', 'summary'));
    }

    public function recordPayment(Request $request, InstallmentPlan $plan)
    {
        abort_if($plan->shop_id !== auth()->user()->shop_id, 403);

        if (!$plan->isActive()) {
            return redirect()->route('installments.show', $plan)
                ->with('error', 'This plan is no longer active.');
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|in:cash,upi,card,bank_transfer',
            'notes' => 'nullable|string',
        ]);

        $this->installmentService->recordPayment(
            $plan,
            $data['amount'],
            $data['payment_method'],
            $data['notes'] ?? null
        );

        return redirect()->route('installments.show', $plan)
            ->with('success', 'EMI payment recorded.');
    }

    public function receipt(InstallmentPlan $plan, InstallmentPayment $payment)
    {
        abort_if($plan->shop_id !== auth()->user()->shop_id, 403);
        abort_if($payment->shop_id !== auth()->user()->shop_id, 403);
        abort_if((int) $payment->plan_id !== (int) $plan->id, 404);

        $plan->loadMissing(['customer', 'invoice']);

        return view('installments.receipt', compact('plan', 'payment'));
    }

    private function eligibleInvoices(int $shopId)
    {
        $plannedInvoiceIds = InstallmentPlan::where('shop_id', $shopId)->pluck('invoice_id');

        $finalized = Invoice::where('shop_id', $shopId)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereNotIn('id', $plannedInvoiceIds)
            ->with(['customer:id,first_name,last_name,mobile'])
            ->withSum('payments as paid_amount', 'amount')
            ->latest()
            ->get(['id', 'shop_id', 'customer_id', 'invoice_number', 'total', 'created_at'])
            ->map(function (Invoice $invoice) {
                $invoice->paid_amount = (float) ($invoice->paid_amount ?? 0);
                $invoice->outstanding_amount = max(0, (float) $invoice->total - $invoice->paid_amount);
                return $invoice;
            })
            ->filter(fn (Invoice $invoice) => $invoice->outstanding_amount > 0)
            ->values();

        $drafts = Invoice::where('shop_id', $shopId)
            ->where('status', Invoice::STATUS_DRAFT)
            ->whereNotIn('id', $plannedInvoiceIds)
            ->with(['customer:id,first_name,last_name,mobile'])
            ->withSum('payments as paid_amount', 'amount')
            ->withSum('items as line_total_sum', 'line_total')
            ->withCount('items')
            ->latest()
            ->get(['id', 'shop_id', 'customer_id', 'invoice_number', 'gst_rate', 'discount', 'round_off', 'wastage_charge', 'status', 'created_at'])
            ->map(function (Invoice $invoice) {
                $invoice->paid_amount = (float) ($invoice->paid_amount ?? 0);
                $invoice->total = $this->computeDraftInvoiceTotal($invoice);
                $invoice->outstanding_amount = max(0, (float) $invoice->total - $invoice->paid_amount);
                return $invoice;
            })
            ->filter(fn (Invoice $invoice) => $invoice->items_count > 0 && $invoice->outstanding_amount > 0)
            ->values();

        return $finalized
            ->concat($drafts)
            ->sortByDesc('created_at')
            ->values();
    }

    private function computeDraftInvoiceTotal(Invoice $invoice): float
    {
        $lineTotal = (float) ($invoice->line_total_sum ?? $invoice->items()->sum('line_total'));
        $subtotal = round($lineTotal, 2);
        $discount = (float) ($invoice->discount ?? 0);
        $roundOff = (float) ($invoice->round_off ?? 0);
        $wastage = (float) ($invoice->wastage_charge ?? 0);
        $gstRate = (float) ($invoice->gst_rate ?? config('business.gst_rate_default'));
        $taxable = round(max($subtotal - $discount, 0), 2);
        $gst = round($taxable * ($gstRate / 100), 2);

        return round($subtotal + $gst + $wastage - $discount + $roundOff, 2);
    }
}
