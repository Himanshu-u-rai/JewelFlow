<x-app-layout>
    <x-page-header class="emi-create-header">
        <div>
            <h1 class="page-title">Create EMI Plan</h1>
            <p class="text-sm text-gray-500 mt-1">Convert an unpaid or partially-paid invoice into installments</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('installments.index') }}" class="btn btn-secondary btn-sm emi-create-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Back
            </a>
        </div>
    </x-page-header>

    <div class="content-inner" x-data="emiPlanForm()">
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">
                <p class="font-medium mb-2">Please correct the following:</p>
                <ul class="list-disc ml-5 text-sm space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg p-4 mb-6 text-sm">
            <p class="font-medium">How this works:</p>
            <p class="mt-1">This dropdown shows eligible unpaid invoices and POS EMI drafts. Final invoice and EMI plan are created only after you click <span class="font-semibold">Create EMI Plan</span>.</p>
        </div>

        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <form method="POST" action="{{ route('installments.store') }}" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                @csrf
                <input type="hidden" name="from_pos_emi" :value="isDraftInvoice() ? '1' : '0'">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                    <select name="customer_id"
                            x-model="customerId"
                            @change="onCustomerChange"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                            required>
                        <option value="">Select customer</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">
                                {{ $customer->name }} ({{ $customer->mobile }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Invoice</label>
                    <select name="invoice_id"
                            x-model="invoiceId"
                            @change="onInvoiceChange"
                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                            required>
                        <option value="">Select invoice</option>
                        <template x-for="inv in filteredInvoices()" :key="inv.id">
                            <option :value="String(inv.id)" x-text="invoiceOptionLabel(inv)"></option>
                        </template>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Only invoices with pending balance and no EMI plan are listed.</p>
                </div>

                <div class="lg:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                        <div>
                            <p class="text-gray-500">Invoice Total</p>
                            <p class="font-semibold text-gray-900" x-text="'₹' + formatMoney(currentInvoice.total)"></p>
                        </div>
                        <div>
                            <p class="text-gray-500">Already Paid</p>
                            <p class="font-semibold text-emerald-700" x-text="'₹' + formatMoney(currentInvoice.paid)"></p>
                        </div>
                        <div>
                            <p class="text-gray-500">Current Outstanding</p>
                            <p class="font-semibold text-rose-700" x-text="'₹' + formatMoney(currentInvoice.outstanding)"></p>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Down Payment (₹)</label>
                    <input type="number"
                           name="down_payment"
                           x-model.number="downPayment"
                           step="0.01"
                           min="0"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                           required>
                    <p class="text-xs text-gray-500 mt-1" x-show="!isDraftInvoice()">For accounting consistency, this must match amount already paid on invoice.</p>
                    <p class="text-xs text-gray-500 mt-1" x-show="isDraftInvoice()">For POS EMI checkout, this is the upfront amount collected right now.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Number of EMIs</label>
                    <input type="number"
                           name="total_emis"
                           x-model.number="totalEmis"
                           min="2"
                           max="24"
                           step="1"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500"
                           required>
                    <p class="text-xs text-gray-500 mt-1">Allowed range: 2 to 24</p>
                </div>

                <div class="lg:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Interest Rate (Annual %)</label>
                    <input type="number"
                           name="interest_rate_annual"
                           x-model.number="interestRateAnnual"
                           min="0"
                           max="60"
                           step="0.01"
                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                    <p class="text-xs text-gray-500 mt-1">Flat interest formula: Principal × Rate × (Months / 12)</p>
                </div>

                <template x-if="isDraftInvoice()">
                    <div class="lg:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-sm font-semibold text-amber-800 mb-3">POS EMI Checkout Details</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Down Payment Method</label>
                                <select name="down_payment_method" x-model="downPaymentMethod" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="bank">Bank</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Down Payment Reference</label>
                                <input type="text" name="down_payment_reference" x-model="downPaymentReference" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500" placeholder="Optional reference">
                            </div>
                            <div class="md:col-span-2 text-xs text-amber-800 bg-white border border-amber-200 rounded-md px-3 py-2">
                                First installment is not collected here. It will be recorded later from the EMI plan page as per due schedule.
                            </div>
                            <div class="md:col-span-2 text-xs text-gray-600">
                                Invoice will be finalized only after you click <span class="font-semibold">Create EMI Plan</span>.
                            </div>
                        </div>
                    </div>
                </template>

                <div class="lg:col-span-2 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                        <div>
                            <p class="text-gray-600">Principal</p>
                            <p class="font-semibold text-gray-900" x-text="'₹' + formatMoney(principalAmount())"></p>
                        </div>
                        <div>
                            <p class="text-gray-600">Interest Amount</p>
                            <p class="font-semibold text-amber-700" x-text="'₹' + formatMoney(interestAmount())"></p>
                        </div>
                        <div>
                            <p class="text-gray-600">Total Payable</p>
                            <p class="font-semibold text-gray-900" x-text="'₹' + formatMoney(totalPayable())"></p>
                        </div>
                        <div>
                            <p class="text-gray-600">Estimated EMI Amount</p>
                            <p class="font-semibold text-amber-700" x-text="'₹' + formatMoney(estimatedEmiAmount())"></p>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2 flex flex-wrap items-center gap-3 pt-1">
                    <button type="submit"
                            class="btn btn-dark btn-sm"
                            :disabled="!canSubmit()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                        Create EMI Plan
                    </button>
                    <a href="{{ route('installments.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    @php
        $invoicePayload = $invoices->map(function ($invoice) {
            return [
                'id' => (int) $invoice->id,
                'customer_id' => (int) $invoice->customer_id,
                'invoice_number' => (string) $invoice->invoice_number,
                'total' => (float) $invoice->total,
                'paid' => (float) $invoice->paid_amount,
                'outstanding' => (float) $invoice->outstanding_amount,
                'created_at' => $invoice->created_at?->format('d M Y'),
                'status' => (string) $invoice->status,
            ];
        })->values();
    @endphp

    <script>
        function emiPlanForm() {
            const invoices = @json($invoicePayload);

            const oldCustomerId = '{{ old('customer_id') }}' || '';
            const oldInvoiceId = '{{ old('invoice_id', $selectedInvoiceId ?? '') }}' || '';
            const oldDownPayment = '{{ old('down_payment') }}';
            const oldTotalEmis = '{{ old('total_emis', 6) }}';
            const oldInterestRateAnnual = '{{ old('interest_rate_annual', 0) }}';
            const oldDownPaymentMethod = '{{ old('down_payment_method', 'cash') }}' || 'cash';
            const oldDownPaymentReference = '{{ old('down_payment_reference') }}' || '';
            const hasOldDownPayment = oldDownPayment !== '';

            return {
                invoices,
                customerId: oldCustomerId,
                invoiceId: oldInvoiceId,
                downPayment: oldDownPayment !== '' ? parseFloat(oldDownPayment) : 0,
                totalEmis: oldTotalEmis !== '' ? parseInt(oldTotalEmis, 10) : 6,
                interestRateAnnual: oldInterestRateAnnual !== '' ? parseFloat(oldInterestRateAnnual) : 0,
                downPaymentMethod: oldDownPaymentMethod,
                downPaymentReference: oldDownPaymentReference,

                get currentInvoice() {
                    return this.invoices.find(i => String(i.id) === String(this.invoiceId)) || {
                        total: 0, paid: 0, outstanding: 0
                    };
                },

                filteredInvoices() {
                    if (!this.customerId) return [];
                    return this.invoices.filter(i => String(i.customer_id) === String(this.customerId));
                },

                isDraftInvoice() {
                    return (this.currentInvoice.status || '') === 'draft';
                },

                invoiceOptionLabel(inv) {
                    if ((inv.status || '') === 'draft') {
                        return `POS EMI Draft #${inv.id} · Estimated total ₹${this.formatMoney(inv.total)}`;
                    }
                    return `${inv.invoice_number} · Outstanding invoice balance ₹${this.formatMoney(inv.outstanding)}`;
                },

                onCustomerChange() {
                    if (!this.filteredInvoices().some(i => String(i.id) === String(this.invoiceId))) {
                        this.invoiceId = '';
                    }
                    this.ensureDownPaymentFloor();
                },

                onInvoiceChange() {
                    this.syncDownPaymentToPaid();
                },

                ensureDownPaymentFloor() {
                    const minDown = Number(this.currentInvoice.paid || 0);
                    if (!Number.isFinite(this.downPayment) || this.downPayment < minDown) {
                        this.downPayment = minDown;
                    }
                },

                syncDownPaymentToPaid() {
                    this.downPayment = Number(this.currentInvoice.paid || 0);
                },

                principalAmount() {
                    const remaining = Number(this.currentInvoice.total || 0) - Number(this.downPayment || 0);
                    return remaining > 0 ? remaining : 0;
                },

                interestAmount() {
                    const principal = this.principalAmount();
                    const rate = Number(this.interestRateAnnual || 0);
                    const months = Number(this.totalEmis || 0);
                    if (principal <= 0 || rate <= 0 || months <= 0) return 0;
                    return principal * (rate / 100) * (months / 12);
                },

                totalPayable() {
                    return this.principalAmount() + this.interestAmount();
                },

                estimatedEmiAmount() {
                    const emis = Number(this.totalEmis || 0);
                    if (emis <= 0) return 0;
                    return this.totalPayable() / emis;
                },

                formatMoney(amount) {
                    return Number(amount || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },

                canSubmit() {
                    const invoice = this.currentInvoice;
                    if (!this.customerId || !this.invoiceId) return false;
                    if (Number(this.totalEmis) < 2 || Number(this.totalEmis) > 24) return false;
                    if (Number(this.interestRateAnnual) < 0 || Number(this.interestRateAnnual) > 60) return false;
                    if (this.isDraftInvoice()) {
                        if (Number(this.downPayment) < 0) return false;
                        if (Number(this.downPayment) >= Number(invoice.total)) return false;
                        return true;
                    }
                    if (Math.abs(Number(this.downPayment) - Number(invoice.paid)) > 0.01) return false;
                    if (Number(this.downPayment) >= Number(invoice.total)) return false;
                    return true;
                },

                init() {
                    if (!this.customerId && this.invoiceId) {
                        const selected = this.invoices.find(i => String(i.id) === String(this.invoiceId));
                        if (selected) {
                            this.customerId = String(selected.customer_id);
                        }
                    }
                    if (!hasOldDownPayment) {
                        this.syncDownPaymentToPaid();
                    }
                }
            };
        }
    </script>
</x-app-layout>
