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

    <div class="content-inner emi-create-page" x-data="emiPlanForm()">
        @if ($errors->any())
            <div class="emi-create-alert">
                <p>Please correct the following:</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('installments.store') }}" class="emi-create-layout">
            @csrf
            <input type="hidden" name="from_pos_emi" :value="isDraftInvoice() ? '1' : '0'">

            <section class="emi-create-panel emi-create-panel--main">
                <div class="emi-create-panel-head">
                    <div>
                        <h2>Invoice selection</h2>
                        <p>Choose the customer and invoice that should become an EMI plan.</p>
                    </div>
                    <span class="emi-create-state">Eligible invoices only</span>
                </div>

                <div class="emi-create-grid emi-create-grid--two">
                    <label class="emi-create-field">
                        <span>Customer</span>
                        <select name="customer_id"
                                x-model="customerId"
                                @change="onCustomerChange"
                                required>
                            <option value="">Select customer</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}">
                                    {{ $customer->name }} ({{ $customer->mobile }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="emi-create-field">
                        <span>Invoice</span>
                        <select name="invoice_id"
                                x-model="invoiceId"
                                @change="onInvoiceChange"
                                required>
                            <option value="">Select invoice</option>
                            <template x-for="inv in filteredInvoices()" :key="inv.id">
                                <option :value="String(inv.id)" x-text="invoiceOptionLabel(inv)"></option>
                            </template>
                        </select>
                        <small>Only invoices with pending balance and no EMI plan are listed.</small>
                    </label>
                </div>

                <div class="emi-create-note">
                    Final invoice and EMI plan are created only after you submit this form.
                </div>

                <div class="emi-create-strip" aria-label="Selected invoice summary">
                    <div>
                        <span>Invoice total</span>
                        <strong x-text="'₹' + formatMoney(currentInvoice.total)"></strong>
                    </div>
                    <div>
                        <span>Already paid</span>
                        <strong class="is-success" x-text="'₹' + formatMoney(currentInvoice.paid)"></strong>
                    </div>
                    <div>
                        <span>Current outstanding</span>
                        <strong class="is-danger" x-text="'₹' + formatMoney(currentInvoice.outstanding)"></strong>
                    </div>
                </div>

                <div class="emi-create-section-title">
                    <h2>Plan terms</h2>
                    <p>Set the repayment length and interest for the selected balance.</p>
                </div>

                <div class="emi-create-grid emi-create-grid--three">
                    <label class="emi-create-field">
                        <span>Down Payment (₹)</span>
                        <input type="number"
                               name="down_payment"
                               x-model.number="downPayment"
                               step="0.01"
                               min="0"
                               required>
                        <small x-show="!isDraftInvoice()">Must match amount already paid on invoice.</small>
                        <small x-show="isDraftInvoice()">Upfront amount collected for POS EMI checkout.</small>
                    </label>

                    <label class="emi-create-field">
                        <span>Number of EMIs</span>
                        <input type="number"
                               name="total_emis"
                               x-model.number="totalEmis"
                               min="2"
                               max="24"
                               step="1"
                               required>
                        <small>Allowed range: 2 to 24</small>
                    </label>

                    <label class="emi-create-field">
                        <span>Interest Rate (Annual %)</span>
                        <input type="number"
                               name="interest_rate_annual"
                               x-model.number="interestRateAnnual"
                               min="0"
                               max="60"
                               step="0.01">
                        <small>Flat formula: Principal x Rate x (Months / 12)</small>
                    </label>
                </div>

                <template x-if="isDraftInvoice()">
                    <div class="emi-create-pos-box">
                        <div class="emi-create-section-title">
                            <h2>POS EMI checkout</h2>
                            <p>Capture the down payment details used while finalizing the draft invoice.</p>
                        </div>
                        <div class="emi-create-grid emi-create-grid--two">
                            <label class="emi-create-field">
                                <span>Down Payment Method</span>
                                <select name="down_payment_method" x-model="downPaymentMethod">
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="bank">Bank</option>
                                    <option value="other">Other</option>
                                </select>
                            </label>
                            <label class="emi-create-field">
                                <span>Down Payment Reference</span>
                                <input type="text" name="down_payment_reference" x-model="downPaymentReference" placeholder="Optional reference">
                            </label>
                        </div>
                        <div class="emi-create-note is-amber">
                            First installment is recorded later from the EMI plan page. The invoice is finalized only after this form is submitted.
                        </div>
                    </div>
                </template>
            </section>

            <aside class="emi-create-panel emi-create-panel--summary" aria-label="EMI plan preview">
                <div class="emi-create-panel-head">
                    <div>
                        <h2>Plan preview</h2>
                        <p>Calculated from the selected invoice and terms.</p>
                    </div>
                </div>

                <div class="emi-create-summary-total">
                    <span>Estimated EMI Amount</span>
                    <strong x-text="'₹' + formatMoney(estimatedEmiAmount())"></strong>
                </div>

                <div class="emi-create-summary-list">
                    <div>
                        <span>Principal</span>
                        <strong x-text="'₹' + formatMoney(principalAmount())"></strong>
                    </div>
                    <div>
                        <span>Interest Amount</span>
                        <strong class="is-gold" x-text="'₹' + formatMoney(interestAmount())"></strong>
                    </div>
                    <div>
                        <span>Total Payable</span>
                        <strong x-text="'₹' + formatMoney(totalPayable())"></strong>
                    </div>
                    <div>
                        <span>EMI Count</span>
                        <strong x-text="Number(totalEmis || 0)"></strong>
                    </div>
                </div>

                <div class="emi-create-summary-note">
                    <strong>Before creating</strong>
                    <span>Confirm the selected customer matches the invoice and the down payment is correct.</span>
                </div>

                <div class="emi-create-actions">
                    <button type="submit"
                            class="emi-create-primary"
                            :disabled="!canSubmit()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                        Create EMI Plan
                    </button>
                    <a href="{{ route('installments.index') }}" class="emi-create-secondary">Cancel</a>
                </div>
            </aside>
        </form>
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
