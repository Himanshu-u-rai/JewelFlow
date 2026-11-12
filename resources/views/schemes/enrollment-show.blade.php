@php
    $statusLabel = ucfirst($enrollment->status);
    $statusTone = ['active' => 'info', 'matured' => 'ok', 'cancelled' => 'bad', 'redeemed' => 'neutral'][$enrollment->status] ?? 'neutral';
    $progress = $enrollment->total_installments > 0 ? ($enrollment->installments_paid / $enrollment->total_installments) * 100 : 0;
    $canPay = $enrollment->isActive() && auth()->user()->can('schemes.enroll');
    $canRedeem = $redeemableValue > 0 && $eligibleInvoices->count() && auth()->user()->can('schemes.enroll');
    $methodLabels = ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank_transfer' => 'Bank transfer'];
@endphp

<x-app-layout>
    <style>
        .enrollment-show-page .es-facts { display: flex; flex-wrap: wrap; gap: 8px 28px; align-items: center; padding: 12px 16px; margin-bottom: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
        .enrollment-show-page .es-fact { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .enrollment-show-page .es-fact-label { font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #64748b; }
        .enrollment-show-page .es-fact-value { font-size: 15px; font-weight: 600; color: #0f172a; font-variant-numeric: tabular-nums; }
        .enrollment-show-page .es-chip { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid transparent; }
        .enrollment-show-page .es-chip--info { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .enrollment-show-page .es-chip--ok { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
        .enrollment-show-page .es-chip--bad { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .enrollment-show-page .es-chip--neutral { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .enrollment-show-page .enrollment-show-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
        .enrollment-show-page .es-col { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
        .enrollment-show-page .es-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; min-width: 0; }
        .enrollment-show-page .es-card-head { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        .enrollment-show-page .es-card-title { font-size: 14px; font-weight: 600; color: #0f172a; margin: 0; }
        .enrollment-show-page .es-card-body { padding: 14px 16px; }
        .enrollment-show-page .es-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; align-items: start; }
        .enrollment-show-page .es-group-title { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #64748b; margin: 0 0 8px; }
        .enrollment-show-page .es-list { margin: 0; display: grid; gap: 8px; }
        .enrollment-show-page .es-list > div { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; }
        .enrollment-show-page .es-list dt { color: #64748b; }
        .enrollment-show-page .es-list dd { margin: 0; color: #0f172a; font-weight: 500; text-align: right; font-variant-numeric: tabular-nums; }
        .enrollment-show-page .es-progress { height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 12px; }
        .enrollment-show-page .es-progress > span { display: block; height: 100%; background: var(--jf-erp-primary, #b45309); }
        .enrollment-show-page .es-note { font-size: 12px; color: #64748b; margin: 8px 0 0; }
        .enrollment-show-page .es-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .enrollment-show-page .es-field label { font-size: 12px; font-weight: 600; color: #475569; }
        .enrollment-show-page .es-field input, .enrollment-show-page .es-field select { width: 100%; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; }
        .enrollment-show-page .es-submit { width: 100%; justify-content: center; }
        .enrollment-show-page .es-danger-zone { border-color: #fecaca; }
        .enrollment-show-page .es-danger-zone .es-card-head { border-bottom-color: #fecaca; background: #fef2f2; border-radius: 10px 10px 0 0; }
        .enrollment-show-page .es-danger-zone .es-card-title { color: #991b1b; }
        .enrollment-show-page .es-btn-danger { width: 100%; justify-content: center; background: #fff; color: #b91c1c; border: 1px solid #fca5a5; }
        .enrollment-show-page .es-btn-danger:hover { background: #fef2f2; }
        .enrollment-show-page .es-table-wrap { overflow-x: auto; }
        .enrollment-show-page .es-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .enrollment-show-page .es-table th { padding: 8px 14px; text-align: left; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
        .enrollment-show-page .es-table td { padding: 8px 14px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
        .enrollment-show-page .es-table .jf-table-index { width: 44px; color: #64748b; text-align: left; }
        .enrollment-show-page .es-table .es-num { text-align: right; font-variant-numeric: tabular-nums; }
        .enrollment-show-page .es-table tbody tr:hover { background: #f8fafc; }
        .enrollment-show-page .es-credit { color: #047857; }
        .enrollment-show-page .es-debit { color: #b91c1c; }
        @media (max-width: 1023px) {
            .enrollment-show-page .enrollment-show-grid { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 640px) {
            .enrollment-show-page .es-groups { grid-template-columns: minmax(0, 1fr); }
            .enrollment-show-page .es-facts { gap: 10px 20px; }
        }
    </style>

    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Enrollment — {{ $enrollment->customer->name ?? 'Customer' }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $enrollment->scheme->name ?? 'Scheme' }}</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <a href="{{ route('schemes.show', $enrollment->scheme_id) }}" class="btn btn-secondary btn-sm"><x-icon name="arrow-left" size="14" class="mr-1" />Back to Scheme</a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page enrollment-show-page">
        <div class="es-facts" role="group" aria-label="Enrollment summary">
            <div class="es-fact">
                <span class="es-fact-label">Status</span>
                <span class="es-fact-value"><span class="es-chip es-chip--{{ $statusTone }}">{{ $statusLabel }}</span></span>
            </div>
            <div class="es-fact">
                <span class="es-fact-label">Total paid</span>
                <span class="es-fact-value">₹{{ number_format($enrollment->total_paid, 2) }}</span>
            </div>
            <div class="es-fact">
                <span class="es-fact-label">Redeemable value</span>
                <span class="es-fact-value">₹{{ number_format($redeemableValue, 2) }}</span>
            </div>
            <div class="es-fact">
                <span class="es-fact-label">Ledger balance</span>
                <span class="es-fact-value">₹{{ number_format($ledgerBalance, 2) }}</span>
            </div>
            <div class="es-fact">
                <span class="es-fact-label">Installments</span>
                <span class="es-fact-value">{{ $enrollment->installments_paid }} / {{ $enrollment->total_installments }}</span>
            </div>
        </div>

        <div class="enrollment-show-grid">
            <div class="es-col">
                <section class="es-card" aria-labelledby="es-details-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-details-title">Enrollment Details</h2></div>
                    <div class="es-card-body">
                        <div class="es-groups">
                            <div>
                                <h3 class="es-group-title">Plan</h3>
                                <dl class="es-list">
                                    <div><dt>Customer</dt><dd>{{ $enrollment->customer->name ?? '—' }}</dd></div>
                                    <div><dt>Scheme</dt><dd>{{ $enrollment->scheme->name ?? '—' }}</dd></div>
                                    <div><dt>Monthly amount</dt><dd>₹{{ number_format($enrollment->monthly_amount, 2) }}</dd></div>
                                </dl>
                            </div>
                            <div>
                                <h3 class="es-group-title">Dates</h3>
                                <dl class="es-list">
                                    <div><dt>Start date</dt><dd>{{ $enrollment->start_date?->format('d M Y') ?? '—' }}</dd></div>
                                    <div><dt>Maturity date</dt><dd>{{ $enrollment->maturity_date?->format('d M Y') ?? '—' }}</dd></div>
                                    @if($enrollment->terms_accepted_at)
                                        <div><dt>Terms accepted</dt><dd>{{ $enrollment->terms_accepted_at->format('d M Y, h:i A') }}</dd></div>
                                    @endif
                                </dl>
                            </div>
                        </div>
                        <div class="es-progress" role="progressbar" aria-label="Installments completed" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ round(min(100, $progress)) }}"><span style="width: {{ min(100, $progress) }}%"></span></div>
                        <p class="es-note">{{ round($progress) }}% complete
                            @if($enrollment->bonus_amount)
                                · includes ₹{{ number_format($enrollment->bonus_amount, 2) }} bonus{{ $enrollment->status !== 'matured' ? ' on maturity' : '' }}
                            @endif
                        </p>
                    </div>
                </section>

                @if($enrollment->payments->count())
                <section class="es-card" aria-labelledby="es-payments-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-payments-title">Payment History</h2></div>
                    <div class="es-table-wrap">
                        <table class="es-table jf-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                                    <th scope="col">Date</th>
                                    <th scope="col" class="es-num">Amount</th>
                                    <th scope="col">Method</th>
                                    <th scope="col">Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($enrollment->payments->sortByDesc('payment_date') as $i => $payment)
                                <tr>
                                    <td class="jf-table-index">{{ $enrollment->payments->count() - $i }}</td>
                                    <td>{{ $payment->payment_date->format('d M Y') }}</td>
                                    <td class="es-num font-medium">₹{{ number_format($payment->amount, 2) }}</td>
                                    <td>{{ $methodLabels[$payment->payment_method] ?? ucwords(str_replace('_', ' ', $payment->payment_method)) }}</td>
                                    <td class="text-gray-500">{{ $payment->receipt_number ?? '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                @endif

                @if($enrollment->redemptions->count())
                <section class="es-card" aria-labelledby="es-redemptions-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-redemptions-title">Redemption History</h2></div>
                    <div class="es-table-wrap">
                        <table class="es-table jf-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Invoice</th>
                                    <th scope="col" class="es-num">Amount</th>
                                    <th scope="col" class="es-num">Principal</th>
                                    <th scope="col" class="es-num">Bonus</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($enrollment->redemptions as $redemption)
                                <tr>
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
                                    <td>{{ $redemption->redeemed_at?->format('d M Y, h:i A') }}</td>
                                    <td>
                                        <a href="{{ route('invoices.show', $redemption->invoice_id) }}" class="text-amber-700 hover:text-amber-800">
                                            {{ $redemption->invoice?->invoice_number ?? ('Invoice #' . $redemption->invoice_id) }}
                                        </a>
                                    </td>
                                    <td class="es-num font-semibold">₹{{ number_format($redemption->amount, 2) }}</td>
                                    <td class="es-num">₹{{ number_format($redemption->principal_component, 2) }}</td>
                                    <td class="es-num">₹{{ number_format($redemption->bonus_component, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                @endif

                @if($enrollment->ledgerEntries->count())
                <section class="es-card" aria-labelledby="es-ledger-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-ledger-title">Scheme Ledger (Recent)</h2></div>
                    <div class="es-table-wrap">
                        <table class="es-table jf-table">
                            <thead>
                                <tr>
                                    <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Direction</th>
                                    <th scope="col" class="es-num">Amount</th>
                                    <th scope="col" class="es-num">Balance After</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($enrollment->ledgerEntries as $entry)
                                <tr>
                                    <td class="jf-table-index">{{ $loop->iteration }}</td>
                                    <td>{{ $entry->created_at->format('d M Y, h:i A') }}</td>
                                    <td>{{ ucwords(str_replace('_', ' ', $entry->entry_type)) }}</td>
                                    <td>
                                        <span class="es-chip {{ $entry->direction === 'credit' ? 'es-chip--ok' : 'es-chip--bad' }}">{{ ucfirst($entry->direction) }}</span>
                                    </td>
                                    <td class="es-num font-medium {{ $entry->direction === 'credit' ? 'es-credit' : 'es-debit' }}">
                                        {{ $entry->direction === 'credit' ? '+' : '-' }}₹{{ number_format($entry->amount, 2) }}
                                    </td>
                                    <td class="es-num font-semibold">₹{{ number_format($entry->balance_after, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                @endif
            </div>

            @if($canPay || $canRedeem)
            <div class="es-col">
                @if($canPay)
                <section class="es-card" aria-labelledby="es-pay-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-pay-title">Record Payment</h2></div>
                    <div class="es-card-body">
                        <form method="POST" action="{{ route('schemes.enrollment.pay', $enrollment) }}">
                            @csrf
                            <div class="es-field">
                                <label for="enrollment-pay-amount">Amount (₹)</label>
                                <input type="number" name="amount" id="enrollment-pay-amount" value="{{ $enrollment->monthly_amount }}" step="0.01" min="1" required aria-label="Payment amount">
                            </div>
                            <div class="es-field">
                                <label for="enrollment-pay-method">Payment method</label>
                                <select name="payment_method" id="enrollment-pay-method" required aria-label="Payment method">
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div class="es-field">
                                <label for="enrollment-pay-receipt">Receipt number (optional)</label>
                                <input type="text" name="receipt_number" id="enrollment-pay-receipt" aria-label="Receipt number (optional)">
                            </div>
                            <button type="submit" class="btn btn-primary es-submit"><x-icon name="credit-card" size="14" class="mr-1" />Record Payment</button>
                        </form>
                    </div>
                </section>
                @endif

                @if($canRedeem)
                <section class="es-card" aria-labelledby="es-redeem-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-redeem-title">Redeem to Invoice</h2></div>
                    <div class="es-card-body">
                        <form method="POST" action="{{ route('schemes.enrollment.redeem', $enrollment) }}">
                            @csrf
                            <div class="es-field">
                                <label for="scheme-redeem-invoice">Invoice</label>
                                <select name="invoice_id" id="scheme-redeem-invoice" required>
                                    <option value="">Select outstanding invoice</option>
                                    @foreach($eligibleInvoices as $invoice)
                                        <option value="{{ $invoice->id }}">
                                            {{ $invoice->invoice_number }} · Due ₹{{ number_format($invoice->outstanding_amount, 2) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="es-field">
                                <label for="scheme-redeem-amount">Redeem Amount (₹)</label>
                                <input type="number" name="amount" id="scheme-redeem-amount" step="0.01" min="1" max="{{ $redeemableValue }}" value="{{ number_format($redeemableValue, 2, '.', '') }}" required>
                            </div>
                            <div class="es-field">
                                <label for="scheme-redeem-note">Note (optional)</label>
                                <input type="text" name="note" id="scheme-redeem-note">
                            </div>
                            <button type="submit" class="btn btn-primary es-submit">Redeem to Invoice</button>
                        </form>
                    </div>
                </section>
                @endif

                @if($canPay)
                @can('schemes.cancel')
                <section class="es-card es-danger-zone" aria-labelledby="es-cancel-title">
                    <div class="es-card-head"><h2 class="es-card-title" id="es-cancel-title">Cancel Enrollment</h2></div>
                    <div class="es-card-body">
                        <p class="es-note" style="margin-top:0;margin-bottom:10px;">If the customer wants to leave the scheme, cancel the enrollment. All contributions paid so far (₹{{ number_format($redeemableValue, 2) }}) will be refunded in cash. The maturity bonus is not paid on cancellation.</p>
                        <form method="POST" action="{{ route('schemes.enrollment.cancel', $enrollment) }}" data-turbo-frame="_top"
                              data-confirm-message="{{ __('Cancel this enrollment and refund the contributions in cash? This cannot be undone.') }}">
                            @csrf
                            <div class="es-field">
                                <label for="enrollment-cancel-reason">Reason (optional)</label>
                                <input type="text" name="reason" id="enrollment-cancel-reason" aria-label="Cancellation reason (optional)">
                            </div>
                            <button type="submit" class="btn btn-sm es-btn-danger">Cancel &amp; Refund Contributions</button>
                        </form>
                    </div>
                </section>
                @endcan
                @endif
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
