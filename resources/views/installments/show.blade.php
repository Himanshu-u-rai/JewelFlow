<x-app-layout>
    @php
        $customerName = $plan->customer->name ?? 'Customer';
        $customerMobile = $plan->customer->mobile ?? null;
        $invoiceLabel = $plan->invoice?->invoice_number ?? ('#' . $plan->invoice_id);
        $statusLabels = [
            'active' => 'Active',
            'completed' => 'Completed',
            'defaulted' => 'Defaulted',
        ];
        $statusClass = [
            'active' => 'is-active',
            'completed' => 'is-completed',
            'defaulted' => 'is-defaulted',
        ][$plan->status] ?? 'is-neutral';
        $progress = $plan->total_emis > 0 ? min(100, ($plan->emis_paid / $plan->total_emis) * 100) : 0;
        $payments = $plan->payments->sortByDesc('payment_date')->values();
        $nextDue = $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date) : null;
        $paymentAmountDefault = number_format(min((float) $plan->emi_amount, max((float) $summary['outstanding'], 1)), 2, '.', '');
    @endphp

    <x-page-header class="installments-show-header" title="Installment Plan" subtitle="EMI account and payment activity">
        <x-slot:actions>
            <a href="{{ route('installments.index') }}" class="installments-show-action installments-show-action--neutral btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                <span class="installments-show-action-text">Back to Installments</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner installments-show-page">
        <section class="installments-show-overview" aria-label="Installment overview">
            <div class="installments-show-profile">
                <div class="installments-show-profile-main">
                    <span class="installments-show-profile-kicker">EMI plan</span>
                    <h2>{{ $customerName }}</h2>
                    <div class="installments-show-profile-meta">
                        <div>
                            <span>Invoice</span>
                            <strong>{{ $invoiceLabel }}</strong>
                        </div>
                        <div>
                            <span>Mobile</span>
                            <strong>{{ $customerMobile ?? '—' }}</strong>
                        </div>
                        <div>
                            <span>EMI amount</span>
                            <strong>₹{{ number_format($plan->emi_amount, 2) }}</strong>
                        </div>
                        <div>
                            <span>Next due</span>
                            <strong class="{{ $summary['is_overdue'] ? 'is-overdue' : '' }}">{{ $nextDue ? $nextDue->format('d M Y') : '—' }}</strong>
                        </div>
                    </div>
                </div>

                <div class="installments-show-profile-actions" aria-label="Plan actions">
                    @if($plan->customer)
                        <a href="{{ route('customers.show', $plan->customer) }}" class="installments-show-profile-action">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                            <span>Customer Profile</span>
                        </a>
                    @endif
                    @if($plan->invoice)
                        <a href="{{ route('invoices.show', $plan->invoice) }}" class="installments-show-profile-action installments-show-profile-action--primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span>View Invoice</span>
                        </a>
                    @endif
                </div>
            </div>

            <div class="installments-show-summary">
                <div class="installments-show-summary-stat installments-show-summary-stat--wide">
                    <span>Outstanding</span>
                    <strong>₹{{ number_format($summary['outstanding'], 2) }}</strong>
                    <small>{{ $summary['emis_remaining'] }} EMIs remaining</small>
                </div>
                <div class="installments-show-summary-stat">
                    <span>Total paid</span>
                    <strong>₹{{ number_format($summary['total_paid'], 2) }}</strong>
                    <small>{{ $payments->count() }} recorded {{ \Illuminate\Support\Str::plural('payment', $payments->count()) }}</small>
                </div>
                <div class="installments-show-summary-stat {{ $statusClass }}">
                    <span>Status</span>
                    <strong>{{ $statusLabels[$plan->status] ?? ucfirst($plan->status) }}</strong>
                    <small>{{ round($progress) }}% complete</small>
                </div>
            </div>
        </section>

        <div class="installments-show-layout">
            <main class="installments-show-main">
                <section class="installments-show-card installments-show-plan-card">
                    <div class="installments-show-card-head">
                        <div>
                            <h2>Plan overview</h2>
                            <p>{{ $plan->emis_paid }}/{{ $plan->total_emis }} EMIs paid, {{ round($progress) }}% complete</p>
                        </div>
                        <div class="installments-show-progress-value">{{ round($progress) }}%</div>
                    </div>

                    <div class="installments-show-progress-track" aria-label="Installment progress">
                        <span style="width: {{ $progress }}%"></span>
                    </div>

                    <dl class="installments-show-detail-grid">
                        <div>
                            <dt>Total amount</dt>
                            <dd>₹{{ number_format($summary['total_amount'], 2) }}</dd>
                        </div>
                        <div>
                            <dt>Principal</dt>
                            <dd>₹{{ number_format($summary['principal_amount'], 2) }}</dd>
                        </div>
                        <div>
                            <dt>Interest rate</dt>
                            <dd>{{ number_format($summary['interest_rate_annual'], 2) }}% p.a.</dd>
                        </div>
                        <div>
                            <dt>Interest amount</dt>
                            <dd>₹{{ number_format($summary['interest_amount'], 2) }}</dd>
                        </div>
                        <div>
                            <dt>Total payable</dt>
                            <dd>₹{{ number_format($summary['total_payable'], 2) }}</dd>
                        </div>
                        <div>
                            <dt>Down payment</dt>
                            <dd>₹{{ number_format($summary['down_payment'], 2) }}</dd>
                        </div>
                        <div>
                            <dt>EMI amount</dt>
                            <dd>₹{{ number_format($plan->emi_amount, 2) }}</dd>
                        </div>
                        <div>
                            <dt>EMIs remaining</dt>
                            <dd>{{ $summary['emis_remaining'] }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="installments-show-card installments-show-history-card">
                    <div class="installments-show-card-head">
                        <div>
                            <h2>Payment history</h2>
                            <p>{{ $payments->count() }} recorded {{ \Illuminate\Support\Str::plural('payment', $payments->count()) }}</p>
                        </div>
                    </div>

                    @if($payments->count())
                        <div class="installments-show-table-wrap">
                            <table class="installments-show-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Date</th>
                                        <th class="is-right">Amount</th>
                                        <th>Method</th>
                                        <th>Notes</th>
                                        <th class="is-center">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($payments as $i => $payment)
                                        <tr>
                                            <td>{{ $payments->count() - $i }}</td>
                                            <td>{{ $payment->payment_date->format('d M Y') }}</td>
                                            <td class="is-right">₹{{ number_format($payment->amount, 2) }}</td>
                                            <td>
                                                {{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}
                                                @if($payment->paymentMethod)
                                                    <span style="color:#6b7280;">· {{ $payment->paymentMethod->account_label }}</span>
                                                @endif
                                            </td>
                                            <td>{{ $payment->notes ?? '—' }}</td>
                                            <td class="is-center">
                                                <a href="{{ route('installments.receipt', [$plan, $payment]) }}" target="_blank" class="installments-show-receipt-link">
                                                    Print
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="installments-show-mobile-payments">
                            @foreach($payments as $i => $payment)
                                <article class="installments-show-payment-card">
                                    <div>
                                        <strong>₹{{ number_format($payment->amount, 2) }}</strong>
                                        <span>{{ $payment->payment_date->format('d M Y') }} · {{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}@if($payment->paymentMethod) · {{ $payment->paymentMethod->account_label }}@endif</span>
                                    </div>
                                    <a href="{{ route('installments.receipt', [$plan, $payment]) }}" target="_blank">Print</a>
                                    @if($payment->notes)
                                        <p>{{ $payment->notes }}</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @else
                        <div class="installments-show-empty">
                            <strong>No EMI payments recorded yet</strong>
                            <span>Record the first payment from the payment panel when the customer pays.</span>
                        </div>
                    @endif
                </section>
            </main>

            <aside class="installments-show-rail">
                @if($plan->isActive())
                    <section class="installments-show-card installments-show-payment-card-main">
                        <div class="installments-show-card-head is-compact">
                            <div>
                                <h2>Record EMI payment</h2>
                                <p>Collect against this active plan.</p>
                            </div>
                        </div>

                        @php
                            $payMethodAccounts = ($paymentMethods ?? collect())
                                ->map(fn ($m) => ['id' => (int) $m->id, 'type' => (string) $m->type, 'account_label' => (string) $m->account_label])
                                ->values();
                        @endphp
                        <form method="POST" action="{{ route('installments.pay', $plan) }}" class="installments-show-form"
                              x-data="{
                                  method: '{{ old('payment_method', 'cash') }}',
                                  accountId: '{{ old('payment_method_id') }}',
                                  accounts: @js($payMethodAccounts),
                                  accountType() { return this.method === 'upi' ? 'upi' : (this.method === 'bank_transfer' ? 'bank' : null); },
                                  needsAccount() { return this.accountType() !== null; },
                                  options() { return this.accounts.filter(a => a.type === this.accountType()); },
                              }">
                            @csrf
                            <label>
                                <span>Amount</span>
                                <input type="number" name="amount" value="{{ old('amount', $paymentAmountDefault) }}" step="0.01" min="1" required>
                                @error('amount')<small>{{ $message }}</small>@enderror
                            </label>
                            <label>
                                <span>Payment method</span>
                                <select name="payment_method" x-model="method" required>
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                </select>
                                @error('payment_method')<small>{{ $message }}</small>@enderror
                            </label>
                            <label x-show="needsAccount()" x-cloak>
                                <span x-text="method === 'upi' ? 'UPI account' : 'Bank account'"></span>
                                <select name="payment_method_id" x-model="accountId">
                                    <option value="">Select account</option>
                                    <template x-for="acc in options()" :key="acc.id">
                                        <option :value="String(acc.id)" x-text="acc.account_label"></option>
                                    </template>
                                </select>
                                <small x-show="options().length === 0" x-text="'No account set up - add one in Settings > Payment methods.'"></small>
                                @error('payment_method_id')<small>{{ $message }}</small>@enderror
                            </label>
                            <label>
                                <span>Notes</span>
                                <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Optional note">
                                @error('notes')<small>{{ $message }}</small>@enderror
                            </label>
                            <button type="submit" class="installments-show-primary">Record payment</button>
                        </form>
                    </section>

                    @can('sales.void')
                        <section class="installments-show-card installments-show-danger-card">
                            <div class="installments-show-card-head is-compact">
                                <div>
                                    <h2>Close as defaulted</h2>
                                    <p>Write off the unpaid balance without changing the original invoice.</p>
                                </div>
                            </div>
                            <form method="POST"
                                  action="{{ route('installments.default', $plan) }}"
                                  data-turbo-frame="_top"
                                  data-confirm-message="{{ __('Close this plan as defaulted and write off the unpaid balance? This cannot be undone.') }}"
                                  class="installments-show-form">
                                @csrf
                                <label>
                                    <span>Reason</span>
                                    <input type="text" name="reason" value="{{ old('reason') }}" placeholder="Optional reason">
                                    @error('reason')<small>{{ $message }}</small>@enderror
                                </label>
                                <button type="submit" class="installments-show-danger">Close as defaulted</button>
                            </form>
                        </section>
                    @endcan
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>
