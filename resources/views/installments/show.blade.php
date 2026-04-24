<x-app-layout>
    <x-page-header class="installments-show-header">
        <div>
            <h1 class="page-title">Installment Plan — {{ $plan->customer->name ?? 'Customer' }}</h1>
            <p class="text-sm text-gray-500 mt-1">Invoice {{ $plan->invoice?->invoice_number ?? ('#' . $plan->invoice_id) }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('installments.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
            @if($plan->invoice)
                <a href="{{ route('invoices.show', $plan->invoice) }}" class="btn btn-secondary btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    View Invoice
                </a>
            @endif
        </div>
    </x-page-header>

    <div class="content-inner">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border border-gray-200 p-6 installments-plan-card">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Plan Details</h3>
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 installments-plan-grid">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Total Amount</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-900">₹{{ number_format($summary['total_amount'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Principal</dt>
                        <dd class="mt-1 text-sm text-gray-900">₹{{ number_format($summary['principal_amount'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Interest Rate</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ number_format($summary['interest_rate_annual'], 2) }}% p.a.</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Interest Amount</dt>
                        <dd class="mt-1 text-sm text-gray-900">₹{{ number_format($summary['interest_amount'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Total Payable</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-900">₹{{ number_format($summary['total_payable'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Down Payment</dt>
                        <dd class="mt-1 text-sm text-gray-900">₹{{ number_format($summary['down_payment'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">EMI Amount</dt>
                        <dd class="mt-1 text-sm text-gray-900">₹{{ number_format($plan->emi_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Total Paid</dt>
                        <dd class="mt-1 text-sm text-green-700 font-medium">₹{{ number_format($summary['total_paid'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Outstanding</dt>
                        <dd class="mt-1 text-sm {{ $summary['outstanding'] > 0 ? 'text-rose-700 font-medium' : 'text-gray-900' }}">₹{{ number_format($summary['outstanding'], 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">EMIs Remaining</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $summary['emis_remaining'] }}</dd>
                    </div>
                </dl>

                @php $progress = $plan->total_emis > 0 ? ($plan->emis_paid / $plan->total_emis) * 100 : 0; @endphp
                <div class="mt-4 installments-plan-progress">
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-amber-600 h-2 rounded-full" style="width: {{ min(100, $progress) }}%"></div>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">{{ $plan->emis_paid }}/{{ $plan->total_emis }} EMIs paid ({{ round($progress) }}%)</p>
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    @php
                        $statusColors = ['active' => 'bg-blue-100 text-blue-800', 'completed' => 'bg-green-100 text-green-800', 'defaulted' => 'bg-red-100 text-red-800'];
                    @endphp
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $statusColors[$plan->status] ?? 'bg-gray-100' }}">
                        {{ ucfirst($plan->status) }}
                    </span>
                    @if($plan->next_due_date && $plan->isActive())
                        <div class="mt-3 text-sm">
                            <span class="text-gray-500">Next Due:</span>
                            <span class="{{ $summary['is_overdue'] ? 'text-rose-600 font-semibold' : 'text-gray-900' }}">
                                {{ \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') }}
                                @if($summary['is_overdue']) (OVERDUE) @endif
                            </span>
                        </div>
                    @endif
                </div>

                @if($plan->isActive())
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Record EMI Payment</h3>
                    <form method="POST" action="{{ route('installments.pay', $plan) }}">
                        @csrf
                        <div class="space-y-3">
                            <input type="number" name="amount" value="{{ $plan->emi_amount }}" step="0.01" min="1" required class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                            <select name="payment_method" required class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                                <option value="cash">Cash</option>
                                <option value="upi">UPI</option>
                                <option value="card">Card</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                            <input type="text" name="notes" placeholder="Notes (optional)" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                            <button type="submit" class="w-full px-4 py-2 rounded-md text-sm font-medium text-white" style="background: #0d9488;">Record Payment</button>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        @if($plan->payments->count())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Payment History</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($plan->payments->sortByDesc('payment_date') as $i => $payment)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-500">{{ $plan->payments->count() - $i }}</td>
                            <td class="px-6 py-3 text-sm">{{ $payment->payment_date->format('d M Y') }}</td>
                            <td class="px-6 py-3 text-sm text-right font-medium">₹{{ number_format($payment->amount, 2) }}</td>
                            <td class="px-6 py-3 text-sm capitalize">{{ $payment->payment_method }}</td>
                            <td class="px-6 py-3 text-sm text-gray-500">{{ $payment->notes ?? '—' }}</td>
                            <td class="px-6 py-3 text-center">
                                <a href="{{ route('installments.receipt', [$plan, $payment]) }}"
                                   target="_blank"
                                   class="btn btn-secondary btn-xs">
                                    Print
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
