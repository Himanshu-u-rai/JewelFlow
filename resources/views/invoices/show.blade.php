@php
    $emiQuickMenuEnabled = ($emiMeta['is_retailer'] ?? false) && (($emiMeta['eligible'] ?? false) || ($emiMeta['has_plan'] ?? false));
@endphp

<x-app-layout>
    <div
        x-data="{ invoicePreviewOpen: false }"
        x-effect="document.body.classList.toggle('invoice-preview-body-locked', invoicePreviewOpen)"
        x-on:keydown.escape.window="invoicePreviewOpen = false"
        x-on:turbo:before-cache.window="invoicePreviewOpen = false; document.body.classList.remove('invoice-preview-body-locked')"
    >
    <x-page-header class="invoice-show-header {{ $emiQuickMenuEnabled ? 'invoice-show-header-emi-fab' : '' }}">
        <div>
            <h1 class="page-title">Invoice&nbsp;{{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $invoice->created_at->format('d M Y, h:i A') }}</p>
        </div>
        <div class="page-actions flex flex-wrap gap-2">
            @if(($emiMeta['is_retailer'] ?? false) && ($emiMeta['eligible'] ?? false))
                <a href="{{ route('installments.create', ['invoice_id' => $invoice->id]) }}" class="btn btn-dark btn-sm invoice-emi-primary-action">
                    <x-icon name="dollar-sign" size="14" class="mr-1.5" />
                    Convert to EMI
                </a>
            @elseif(($emiMeta['is_retailer'] ?? false) && ($emiMeta['has_plan'] ?? false))
                <a href="{{ route('installments.show', $emiMeta['plan_id']) }}" class="btn btn-secondary btn-sm invoice-emi-primary-action">
                    <x-icon name="clipboard" size="14" class="mr-1.5" />
                    View EMI Plan
                </a>
            @endif
            @php
                $canEditInvoice = ($invoice->status === \App\Models\Invoice::STATUS_DRAFT && auth()->user()->can('sales.create')) || ($invoice->status !== \App\Models\Invoice::STATUS_DRAFT && auth()->user()->can('sales.void'));
            @endphp
            @if($canEditInvoice)
            <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-secondary btn-sm invoice-edit-action">
                <x-icon name="pencil" size="14" class="mr-1.5" />
                Edit Invoice
            </a>
            @endif
            <a href="{{ route('invoices.print', $invoice) }}" target="_blank"
               class="btn btn-secondary btn-sm invoice-print-action">
                <x-icon name="printer" class="w-4 h-4 mr-2" />
                Print
            </a>
            <button type="button" class="btn btn-secondary btn-sm invoice-preview-action" x-on:click="invoicePreviewOpen = true">
                <x-icon name="eye" class="w-4 h-4 mr-2" />
                Preview
            </button>
            @can('returns.create')
                @if($invoice->status === \App\Models\Invoice::STATUS_FINALIZED)
                <a href="{{ route('returns.create', $invoice) }}" class="btn btn-secondary btn-sm" data-turbo-frame="_top">
                    <x-icon name="undo-2" class="w-4 h-4 mr-2" />
                    Return
                </a>
                <a href="{{ route('exchanges.unified.create', $invoice) }}" class="btn btn-secondary btn-sm" data-turbo-frame="_top">
                    <x-icon name="maximize-2" class="w-4 h-4 mr-2" />
                    Exchange
                </a>
                @endif
            @endcan
            <a href="{{ route('invoices.index') }}" 
               class="btn btn-secondary btn-sm invoice-back-action">
                <x-icon name="arrow-left" class="w-4 h-4 mr-2" />
                All Invoices
            </a>
        </div>
    </x-page-header>

    @if($emiQuickMenuEnabled)
        <div x-data="{ invoiceEmiFabOpen: false }" class="invoice-emi-mobile-fab">
            <div class="invoice-emi-mobile-fab-shell" x-bind:class="{ 'is-open': invoiceEmiFabOpen }" @click.outside="invoiceEmiFabOpen = false">
                <nav class="invoice-emi-mobile-fab-nav" aria-label="EMI invoice quick actions">
                    @if(($emiMeta['is_retailer'] ?? false) && ($emiMeta['eligible'] ?? false))
                        <a href="{{ route('installments.create', ['invoice_id' => $invoice->id]) }}" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                            <x-icon name="dollar-sign" size="14" />
                            <span>Convert to EMI</span>
                        </a>
                    @elseif(($emiMeta['is_retailer'] ?? false) && ($emiMeta['has_plan'] ?? false))
                        <a href="{{ route('installments.show', $emiMeta['plan_id']) }}" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                            <x-icon name="clipboard" size="14" />
                            <span>View EMI Plan</span>
                        </a>
                    @endif
                    @if(($invoice->status === \App\Models\Invoice::STATUS_DRAFT && auth()->user()->can('sales.create')) || ($invoice->status !== \App\Models\Invoice::STATUS_DRAFT && auth()->user()->can('sales.void')))
                    <a href="{{ route('invoices.edit', $invoice) }}" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                        <x-icon name="pencil" size="14" />
                        <span>Edit Invoice</span>
                    </a>
                    @endif
                    <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                        <x-icon name="printer" size="14" />
                        <span>Print</span>
                    </a>
                </nav>
                <button type="button" class="invoice-emi-mobile-fab-toggle" x-on:click="invoiceEmiFabOpen = !invoiceEmiFabOpen" x-bind:aria-expanded="invoiceEmiFabOpen.toString()" aria-label="Toggle invoice actions">
                    <span class="invoice-emi-mobile-fab-bars" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>
        </div>
    @endif

    <div class="content-inner invoice-show-page jf-skeleton-host is-loading">
        @php
            $isRetailer = auth()->user()->shop?->isRetailer();
            // Authoritative: a repair bill is an invoice a Repair points at (repairs.invoice_id).
            // The REP- prefix is kept as a secondary signal for any legacy/imported rows.
            $isRepairInvoice = \App\Models\Repair::where('shop_id', auth()->user()->shop_id)
                    ->where('invoice_id', $invoice->id)->exists()
                || str_starts_with($invoice->invoice_number, 'REP-');
            $repair = null;
            $offerApplied = $invoice->offerApplication;
            $offerDiscount = (float) ($offerApplied->discount_amount ?? 0);
            $manualDiscount = max(0, (float) $invoice->discount - $offerDiscount);
            $schemeRedemptionTotal = (float) $invoice->schemeRedemptions->sum('amount');
            $lineItemsTotal = $isRepairInvoice ? (float) $invoice->total : (float) $invoice->items->sum('line_total');
            $paidAmount = (float) $invoice->payments->sum('amount');
            $lineCount = $isRepairInvoice ? 1 : $invoice->items->count();
            $paymentModeSummary = $invoice->payments->pluck('mode')
                ->filter()
                ->unique()
                ->map(fn ($mode) => \App\Support\PaymentMethodLabel::modeLabel($mode))
                ->implode(', ');
            $paymentModeSummary = $paymentModeSummary !== '' ? $paymentModeSummary : 'Not recorded';
            $invoiceTypeLabel = $isRepairInvoice ? 'Repair service' : 'Sale';
            $showDraftTotalsNote = $invoice->status === \App\Models\Invoice::STATUS_DRAFT
                && abs($lineItemsTotal - (float) $invoice->total) > 0.01;

            if ($isRepairInvoice) {
                // Primary: the Repair that points at this invoice (repairs.invoice_id).
                $repair = \App\Models\Repair::where('shop_id', auth()->user()->shop_id)
                    ->where('invoice_id', $invoice->id)
                    ->latest()
                    ->first();

                // Fallback for legacy rows where invoice_id was never back-filled.
                if (! $repair) {
                    $repairLog = \App\Models\AuditLog::where('shop_id', auth()->user()->shop_id)
                        ->where('action', 'repair_deliver')
                        ->where('model_type', 'repair')
                        ->whereRaw("(data->>'invoice_id')::bigint = ?", [(int) $invoice->id])
                        ->latest()
                        ->first();

                    if ($repairLog) {
                        $repair = \App\Models\Repair::where('shop_id', auth()->user()->shop_id)->find($repairLog->model_id);
                    }
                }
            }

            $statusBadgeClass = match($invoice->status) {
                \App\Models\Invoice::STATUS_FINALIZED => 'invoice-show-status invoice-show-status--finalized',
                \App\Models\Invoice::STATUS_CANCELLED => 'invoice-show-status invoice-show-status--cancelled',
                default                               => 'invoice-show-status invoice-show-status--draft',
            };
        @endphp

        <div class="invoice-show-layout">
            <main class="invoice-show-main">
                <section class="invoice-show-document" aria-labelledby="invoice-document-heading">
                    <div class="invoice-show-document-head jf-skel">
                        <div>
                            <p class="invoice-show-eyebrow">{{ $invoiceTypeLabel }}</p>
                            <h2 id="invoice-document-heading">{{ $invoice->invoice_number }}</h2>
                            <p>{{ $invoice->created_at->format('d M Y, h:i A') }}</p>
                        </div>
                        <div class="invoice-show-document-head__aside">
                            @if(!$isRetailer && !$isRepairInvoice)
                                <div class="invoice-show-rate-chip">
                                    <span>Gold rate</span>
                                    <strong>₹{{ number_format($invoice->gold_rate, 2) }}/g</strong>
                                </div>
                            @endif
                            <span class="{{ $statusBadgeClass }}">{{ ucfirst($invoice->status) }}</span>
                        </div>
                    </div>

                    <section class="invoice-show-customer-strip jf-skel" aria-labelledby="invoice-customer-heading">
                        <div>
                            <p class="invoice-show-section-kicker">Customer</p>
                            <h3 id="invoice-customer-heading">
                                {{ $invoice->customer?->name ?? 'Walk-in Customer' }}
                            </h3>
                        </div>
                        <div class="invoice-show-customer-meta">
                            @if($invoice->customer)
                                <span>{{ \App\Support\Mobile::forDisplay($invoice->customer->mobile) }}</span>
                                @if($invoice->customer->address)
                                    <span>{{ $invoice->customer->address }}</span>
                                @endif
                            @else
                                <span>No customer profile attached</span>
                            @endif
                        </div>
                    </section>

                    <section class="invoice-show-lines" aria-labelledby="invoice-lines-heading">
                        <div class="invoice-show-section-head">
                            <div>
                                <p class="invoice-show-section-kicker">{{ $lineCount }} {{ $lineCount === 1 ? 'line' : 'lines' }}</p>
                                <h3 id="invoice-lines-heading">{{ $isRepairInvoice ? 'Repair details' : 'Sold items' }}</h3>
                            </div>
                            <span class="invoice-show-line-total-pill jf-skel">
                                Line total ₹{{ number_format($lineItemsTotal, 2) }}
                            </span>
                        </div>

                        <div class="invoice-show-table-shell">
                            @if($isRepairInvoice)
                                <table class="invoice-show-data-table invoice-show-data-table--repair jf-table">
                                    <thead>
                                        <tr>
                                            <th>Description</th>
                                            <th class="text-right">Weight</th>
                                            <th class="text-center">Purity</th>
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="jf-skel-row">
                                            <td data-label="Description">{{ $repair?->item_description ?? 'Repair service' }}</td>
                                            <td data-label="Weight" class="text-right">{{ $repair ? number_format($repair->gross_weight, 3) . ' g' : '—' }}</td>
                                            <td data-label="Purity" class="text-center">{{ $repair ? number_format($repair->purity, 2) . 'K' : '—' }}</td>
                                            <td data-label="Amount" class="text-right invoice-show-money">₹{{ number_format($lineItemsTotal, 2) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            @else
                                <table class="invoice-show-data-table invoice-show-data-table--sales jf-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th class="text-right">Weight</th>
                                            <th class="text-center">Purity</th>
                                            @if(!$isRetailer)
                                                <th class="text-right">Rate</th>
                                                <th class="text-right">Making</th>
                                                <th class="text-right">Stone</th>
                                            @endif
                                            <th class="text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($invoice->items as $invoiceItem)
                                            @php
                                                $linkedItem = $invoiceItem->item;
                                            @endphp
                                            <tr class="jf-skel-row">
                                                <td data-label="Item">
                                                    <div class="invoice-show-item-cell">
                                                        @if($linkedItem && $linkedItem->image)
                                                            <img src="{{ asset('storage/' . $linkedItem->image) }}" alt="{{ $linkedItem->design ?: ($linkedItem->category ?: 'Item') }} image">
                                                        @else
                                                            <div class="invoice-show-item-media" aria-hidden="true"></div>
                                                        @endif
                                                        <div>
                                                            @if($linkedItem)
                                                                <strong>{{ $linkedItem->design ?? 'N/A' }}</strong>
                                                                <span>{{ $linkedItem->barcode }}</span>
                                                                <small>{{ $linkedItem->category }}</small>
                                                            @else
                                                                <strong>Item (unlinked)</strong>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="Weight" class="text-right">{{ number_format($invoiceItem->weight, 3) }} g</td>
                                                <td data-label="Purity" class="text-center">
                                                    <span class="invoice-show-purity">{{ $linkedItem?->purity_label ?? (($linkedItem->purity ?? 22) . 'K') }}</span>
                                                </td>
                                                @if(!$isRetailer)
                                                    <td data-label="Rate" class="text-right">₹{{ number_format($invoiceItem->rate, 2) }}</td>
                                                    <td data-label="Making" class="text-right">
                                                        ₹{{ number_format($invoiceItem->making_charges, 2) }}
                                                        @if($invoiceItem->making_charge_type === 'percentage')
                                                            <small>{{ rtrim(rtrim(number_format($invoiceItem->making_charge_value, 2), '0'), '.') }}% of metal</small>
                                                        @elseif($invoiceItem->making_charge_type === 'per_gram')
                                                            <small>₹{{ rtrim(rtrim(number_format($invoiceItem->making_charge_value, 2), '0'), '.') }}/g</small>
                                                        @endif
                                                    </td>
                                                    <td data-label="Stone" class="text-right">₹{{ number_format($invoiceItem->stone_amount, 2) }}</td>
                                                @endif
                                                <td data-label="Amount" class="text-right invoice-show-money">₹{{ number_format($invoiceItem->line_total, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </section>

                    <section class="invoice-show-totals jf-skel" aria-label="Invoice totals">
                        <div class="invoice-show-totals-grid">
                            <div class="invoice-show-totals-note">
                                @if($showDraftTotalsNote)
                                    <span>Draft totals are not finalized yet.</span>
                                @else
                                    <span>{{ ucfirst($invoice->status) }} invoice summary</span>
                                @endif
                            </div>
                            <div class="invoice-show-totals-box">
                                <div>
                                    <span>Line total</span>
                                    <strong>₹{{ number_format($lineItemsTotal, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Subtotal</span>
                                    <strong>₹{{ number_format($invoice->subtotal, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Wastage charge</span>
                                    <strong>₹{{ number_format($invoice->wastage_charge, 2) }}</strong>
                                </div>
                                <div>
                                    <span>GST ({{ $invoice->gst_rate ?? 3 }}%)</span>
                                    <strong>₹{{ number_format($invoice->gst, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Manual discount</span>
                                    <strong class="{{ $manualDiscount > 0 ? 'is-negative' : 'is-muted' }}">{{ $manualDiscount > 0 ? '−' : '' }}₹{{ number_format($manualDiscount, 2) }}</strong>
                                </div>
                                <div>
                                    <span>Offer discount @if($offerApplied)<small>{{ $offerApplied->scheme_name_snapshot }}</small>@endif</span>
                                    <strong class="{{ $offerDiscount > 0 ? 'is-negative' : 'is-muted' }}">{{ $offerDiscount > 0 ? '−' : '' }}₹{{ number_format($offerDiscount, 2) }}</strong>
                                </div>
                                @if($schemeRedemptionTotal > 0)
                                    <div>
                                        <span>Scheme redemption</span>
                                        <strong class="is-negative">−₹{{ number_format($schemeRedemptionTotal, 2) }}</strong>
                                    </div>
                                @endif
                                <div>
                                    <span>Round off</span>
                                    <strong>{{ $invoice->round_off > 0 ? '+' : ($invoice->round_off < 0 ? '' : '') }}₹{{ number_format($invoice->round_off, 2) }}</strong>
                                </div>
                                <div class="invoice-show-total-row">
                                    <span>Grand total</span>
                                    <strong class="{{ (float) $invoice->total < 0 ? 'is-negative' : '' }}">₹{{ number_format($invoice->total, 2) }}</strong>
                                </div>
                                <div class="invoice-show-paid-row">
                                    <span>Paid</span>
                                    <strong class="is-paid">₹{{ number_format($paidAmount, 2) }}</strong>
                                </div>
                                <div class="invoice-show-due-row {{ $outstandingAmount > 0 ? 'has-due' : 'is-clear' }}">
                                    <span>Due</span>
                                    <strong class="{{ $outstandingAmount > 0 ? 'is-due' : '' }}">₹{{ number_format($outstandingAmount, 2) }}</strong>
                                </div>
                            </div>
                        </div>
                    </section>
                </section>

                {{-- Record payment: money received later against the due (twin of the
                     app's Record payment). A bill on EMI is collected through its plan. --}}
                @if($invoice->status === \App\Models\Invoice::STATUS_FINALIZED && $outstandingAmount > 0)
                    @if($emiMeta['collect_through_plan'] ?? false)
                        <section class="invoice-show-collect" aria-labelledby="invoice-collect-heading">
                            <div>
                                <h2 id="invoice-collect-heading">Collect the due</h2>
                                <p>{{ \App\Models\InstallmentPlan::COLLECT_THROUGH_PLAN }}</p>
                            </div>
                            <a href="{{ route('installments.show', $emiMeta['plan_id']) }}" class="invoice-show-collect-link">Open EMI plan</a>
                        </section>
                    @else
                        @can('sales.create')
                            @php
                                $payModes = ['cash' => 'Cash'];
                                if ($paymentAccounts->contains('type', 'upi')) { $payModes['upi'] = 'UPI'; }
                                if ($paymentAccounts->contains('type', 'bank')) { $payModes['bank'] = 'Bank'; }
                                $payModes['other'] = 'Other';
                                // Starts empty, as the app's screen: nothing is recorded by default.
                                $payRows = collect(old('payments', [[]]))
                                    ->map(fn ($r) => [
                                        'mode' => $r['mode'] ?? 'cash',
                                        'amount' => (string) ($r['amount'] ?? ''),
                                        'payment_method_id' => (string) ($r['payment_method_id'] ?? ''),
                                        'reference' => (string) ($r['reference'] ?? ''),
                                    ])->values();
                                $payErrors = collect($errors->getMessages())
                                    ->filter(fn ($m, $key) => in_array($key, ['payments', 'amount', 'status'], true) || str_starts_with($key, 'payments.'))
                                    ->flatten();
                            @endphp
                            <section class="invoice-show-collect" aria-labelledby="invoice-collect-heading">
                                <div>
                                    <h2 id="invoice-collect-heading">Record payment</h2>
                                    <p>Money received now against this bill. Due: ₹{{ number_format($outstandingAmount, 2) }}.</p>
                                </div>
                                @if($payErrors->isNotEmpty())
                                    <ul class="invoice-show-collect-errors" role="alert">
                                        @foreach($payErrors as $message)<li>{{ $message }}</li>@endforeach
                                    </ul>
                                @endif
                                <form method="POST" action="{{ route('invoices.payments.store', $invoice) }}" data-turbo-frame="_top"
                                      x-data="{
                                          due: {{ round($outstandingAmount, 2) }},
                                          accounts: @js($paymentAccounts),
                                          rows: @js($payRows),
                                          needsAccount(row) { return row.mode === 'upi' || row.mode === 'bank'; },
                                          options(row) { return this.accounts.filter(a => a.type === row.mode); },
                                          entered() { return this.rows.reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0); },
                                          add() {
                                              const left = Math.max(0, this.due - this.entered());
                                              this.rows.push({ mode: 'cash', amount: left > 0 ? left.toFixed(2) : '', payment_method_id: '', reference: '' });
                                          },
                                      }">
                                    @csrf
                                    {{-- One key per rendered form: sending this form again records the payment once. --}}
                                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
                                    <template x-for="(row, i) in rows" :key="i">
                                        <div class="invoice-show-collect-row">
                                            <label>
                                                <span>Paid by</span>
                                                <select :name="`payments[${i}][mode]`" x-model="row.mode">
                                                    @foreach($payModes as $value => $label)
                                                        <option value="{{ $value }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                            <label x-show="needsAccount(row)" x-cloak>
                                                <span x-text="row.mode === 'upi' ? 'UPI account' : 'Bank account'"></span>
                                                <select :name="`payments[${i}][payment_method_id]`" x-model="row.payment_method_id" :disabled="!needsAccount(row)" :required="needsAccount(row)">
                                                    <option value="">Select account</option>
                                                    <template x-for="acc in options(row)" :key="acc.id">
                                                        <option :value="String(acc.id)" x-text="acc.label"></option>
                                                    </template>
                                                </select>
                                            </label>
                                            <label>
                                                <span>Amount (₹)</span>
                                                <input type="number" step="0.01" min="0.01" :max="due" inputmode="decimal" :name="`payments[${i}][amount]`" x-model="row.amount" required>
                                            </label>
                                            <label>
                                                <span>Reference</span>
                                                <input type="text" maxlength="100" :name="`payments[${i}][reference]`" x-model="row.reference" placeholder="Optional: UTR, cheque no.">
                                            </label>
                                            <button type="button" class="invoice-show-collect-secondary" x-show="rows.length > 1" x-cloak @click="rows.splice(i, 1)">Remove</button>
                                        </div>
                                    </template>
                                    <div class="invoice-show-collect-actions">
                                        <button type="button" class="invoice-show-collect-secondary" x-show="entered() < due - 0.005" @click="add()">Add another payment mode</button>
                                        <button type="submit" class="invoice-show-collect-submit" data-turbo-submits-with="Recording…"
                                                :disabled="entered() <= 0 || entered() > due + 0.005"
                                                x-text="entered() > 0 ? 'Record ₹' + entered().toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : 'Record payment'">Record payment</button>
                                    </div>
                                    <small x-show="entered() > due + 0.005" x-cloak>More than the due of ₹{{ number_format($outstandingAmount, 2) }}.</small>
                                </form>
                            </section>
                        @endcan
                    @endif
                @endif

                {{-- Apply Store Credit — only when the customer has wallet credit and
                     the finalized invoice still has an outstanding balance (M12). --}}
                @can('sales.create')
                    @if(($storeCreditApplicable ?? 0) > 0)
                        <section class="invoice-show-store-credit">
                            <div>
                                <h2>Apply store credit</h2>
                                <p>This customer has ₹{{ number_format($storeCreditAvailable, 2) }} in store credit. Outstanding on this bill: ₹{{ number_format($outstandingAmount, 2) }}.</p>
                            </div>
                            <form method="POST" action="{{ route('store-credit.apply', $invoice) }}" data-turbo-frame="_top">
                                @csrf
                                <label>
                                    <span>Amount to apply</span>
                                    <input type="number" name="amount" step="0.01" min="0.01" max="{{ $storeCreditApplicable }}" value="{{ $storeCreditApplicable }}" required>
                                </label>
                                <button type="submit">Apply credit</button>
                            </form>
                        </section>
                    @endif
                @endcan

            </main>

            <aside class="invoice-show-rail" aria-label="Invoice side details">
                <section class="invoice-show-panel invoice-show-facts jf-skel" aria-labelledby="invoice-facts-heading">
                    <h2 id="invoice-facts-heading">Invoice facts</h2>
                    <dl class="invoice-show-key-values">
                        <div>
                            <dt>Status</dt>
                            <dd><span class="{{ $statusBadgeClass }}">{{ ucfirst($invoice->status) }}</span></dd>
                        </div>
                        <div>
                            <dt>Invoice #</dt>
                            <dd>{{ $invoice->invoice_number }}</dd>
                        </div>
                        <div>
                            <dt>Date</dt>
                            <dd>{{ $invoice->created_at->format('d M Y') }}</dd>
                        </div>
                        <div>
                            <dt>Type</dt>
                            <dd>{{ $invoiceTypeLabel }}</dd>
                        </div>
                        <div>
                            <dt>Lines</dt>
                            <dd>{{ $lineCount }}</dd>
                        </div>
                        <div>
                            <dt>Payment</dt>
                            <dd>{{ $paymentModeSummary }}</dd>
                        </div>
                        @if(!$isRetailer && !$isRepairInvoice)
                            <div>
                                <dt>Gold rate</dt>
                                <dd>₹{{ number_format($invoice->gold_rate, 2) }}/g</dd>
                            </div>
                        @endif
                        @if($invoice->finalized_at)
                            <div>
                                <dt>Finalized</dt>
                                <dd>{{ $invoice->finalized_at->format('d M Y') }}</dd>
                            </div>
                        @endif
                        @if($invoice->cancelled_at)
                            <div>
                                <dt>Cancelled</dt>
                                <dd>{{ $invoice->cancelled_at->format('d M Y') }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="invoice-show-panel invoice-show-payments jf-skel" aria-labelledby="invoice-payments-heading">
                    <div class="invoice-show-panel-head">
                        <h2 id="invoice-payments-heading">Payment breakdown</h2>
                        <span>₹{{ number_format($paidAmount, 2) }} paid</span>
                    </div>
                    <div class="invoice-show-payment-list">
                        @forelse($invoice->payments as $payment)
                            <article class="invoice-show-payment-row">
                                <div>
                                    <strong>{{ \App\Support\PaymentMethodLabel::modeLabel($payment->mode) }}</strong>
                                    @if($payment->reference)
                                        <span>{{ $payment->reference }}</span>
                                    @endif
                                </div>
                                <strong>₹{{ number_format($payment->amount, 2) }}</strong>
                            </article>
                            @if(in_array($payment->mode, ['old_gold', 'old_silver'], true) && $payment->metal_fine_weight)
                                <div class="invoice-show-metal-detail">
                                    <span>{{ number_format($payment->metal_gross_weight, 3) }}g gross</span>
                                    <span>{{ $payment->mode === 'old_gold' ? $payment->metal_purity . 'K' : $payment->metal_purity . '‰' }}</span>
                                    <span>{{ number_format($payment->metal_fine_weight, 3) }}g fine</span>
                                    <span>₹{{ number_format($payment->metal_rate_per_gram, 2) }}/g</span>
                                </div>
                            @endif
                        @empty
                            <div class="invoice-show-empty-line">No payment recorded yet.</div>
                        @endforelse
                    </div>
                    <div class="invoice-show-payment-footer">
                        <div>
                            <span>Grand total</span>
                            <strong>₹{{ number_format($invoice->total, 2) }}</strong>
                        </div>
                        <div>
                            <span>Due</span>
                            <strong class="{{ $outstandingAmount > 0 ? 'is-due' : '' }}">₹{{ number_format($outstandingAmount, 2) }}</strong>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </div>

    <div
        class="invoice-preview-viewer"
        x-cloak
        x-show="invoicePreviewOpen"
        x-transition.opacity.duration.150ms
        x-bind:aria-hidden="(!invoicePreviewOpen).toString()"
    >
        <button type="button" class="invoice-preview-backdrop" aria-label="Close invoice preview" x-on:click="invoicePreviewOpen = false"></button>
        <section class="invoice-preview-panel" role="dialog" aria-modal="true" aria-labelledby="invoice-preview-title" x-on:click.stop>
            <header class="invoice-preview-panel-head">
                <div>
                    <p>Invoice display</p>
                    <h2 id="invoice-preview-title">{{ $invoice->invoice_number }}</h2>
                </div>
                <div class="invoice-preview-panel-actions">
                    <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="invoice-preview-print-link">
                        <x-icon name="printer" class="w-4 h-4" />
                        Print
                    </a>
                    <button type="button" class="invoice-preview-close" x-on:click="invoicePreviewOpen = false" aria-label="Close invoice preview">
                        <x-icon name="x" class="w-4 h-4" />
                    </button>
                </div>
            </header>

            <div class="invoice-preview-panel-body">
                <article class="invoice-preview-document">
                    <div class="invoice-preview-shop">
                        <h3>{{ auth()->user()->shop->name }}</h3>
                        <p>{{ auth()->user()->shop->phone }}</p>
                    </div>

                    <dl class="invoice-preview-meta">
                        <div>
                            <dt>Invoice</dt>
                            <dd>{{ $invoice->invoice_number }}</dd>
                        </div>
                        <div>
                            <dt>Date</dt>
                            <dd>{{ $invoice->created_at->format('d M Y, h:i A') }}</dd>
                        </div>
                        <div>
                            <dt>Customer</dt>
                            <dd>{{ $invoice->customer?->name ?? 'Walk-in' }}</dd>
                        </div>
                        <div>
                            <dt>Type</dt>
                            <dd>{{ $isRepairInvoice ? 'Repair Service' : 'Sale' }}</dd>
                        </div>
                    </dl>

                    <div class="invoice-preview-table-wrap">
                        @if($isRepairInvoice)
                            <table class="invoice-preview-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th class="text-center">Weight</th>
                                        <th class="text-center">Purity</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>{{ $repair?->item_description ?? 'Repair service' }}</td>
                                        <td class="text-center">{{ $repair ? number_format($repair->gross_weight, 3) . ' g' : '—' }}</td>
                                        <td class="text-center">{{ $repair ? number_format($repair->purity, 2) . 'K' : '—' }}</td>
                                        <td class="text-right">₹{{ number_format($invoice->total, 2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        @else
                            <table class="invoice-preview-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center">Weight</th>
                                        @if(!$isRetailer)
                                            <th class="text-right">Rate</th>
                                            <th class="text-right">Making</th>
                                            <th class="text-right">Stone</th>
                                        @endif
                                        <th class="text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoice->items as $line)
                                        <tr>
                                            <td>{{ optional($line->item)->design ?? 'Item #' . $line->item_id }}</td>
                                            <td class="text-center">{{ number_format($line->weight, 3) }}</td>
                                            @if(!$isRetailer)
                                                <td class="text-right">₹{{ number_format($line->rate, 2) }}</td>
                                                <td class="text-right">₹{{ number_format($line->making_charges, 2) }}</td>
                                                <td class="text-right">₹{{ number_format($line->stone_amount, 2) }}</td>
                                            @endif
                                            <td class="text-right">₹{{ number_format($line->line_total, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>

                    <div class="invoice-preview-total-list">
                        <div>
                            <span>Subtotal</span>
                            <strong>₹{{ number_format($invoice->subtotal, 2) }}</strong>
                        </div>
                        @if($invoice->wastage_charge > 0)
                            <div>
                                <span>Wastage charge</span>
                                <strong>₹{{ number_format($invoice->wastage_charge, 2) }}</strong>
                            </div>
                        @endif
                        <div>
                            <span>GST ({{ number_format($invoice->gst_rate ?? 0, 2) }}%)</span>
                            <strong>₹{{ number_format($invoice->gst, 2) }}</strong>
                        </div>
                        @if($invoice->discount > 0)
                            <div>
                                <span>Discount</span>
                                <strong class="is-negative">−₹{{ number_format($invoice->discount, 2) }}</strong>
                            </div>
                        @endif
                        @if($invoice->round_off != 0)
                            <div>
                                <span>Round off</span>
                                <strong>{{ $invoice->round_off > 0 ? '+' : '' }}₹{{ number_format($invoice->round_off, 2) }}</strong>
                            </div>
                        @endif
                        <div class="invoice-preview-grand-total">
                            <span>Grand total</span>
                            <strong>₹{{ number_format($invoice->total, 2) }}</strong>
                        </div>
                    </div>

                    @if($invoice->payments->count())
                        <section class="invoice-preview-payments" aria-label="Payment received">
                            <h4>Payment received</h4>
                            @foreach($invoice->payments as $payment)
                                <div>
                                    <span>{{ \App\Support\PaymentMethodLabel::modeLabel($payment->mode) }}@if($payment->reference) <small>{{ $payment->reference }}</small>@endif</span>
                                    <strong>₹{{ number_format($payment->amount, 2) }}</strong>
                                </div>
                            @endforeach
                        </section>
                    @endif

                    @if($schemeRedemptionTotal > 0)
                        <div class="invoice-preview-redemption">
                            Scheme redemption applied: ₹{{ number_format($schemeRedemptionTotal, 2) }}
                        </div>
                    @endif
                </article>
            </div>
        </section>
    </div>
    </div>
</x-app-layout>
