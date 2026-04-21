<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Enrollment — {{ $enrollment->customer->name ?? 'Customer' }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $enrollment->scheme->name ?? 'Scheme' }}</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <a href="{{ route('schemes.show', $enrollment->scheme_id) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back to Scheme</a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-4 mb-6">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Enrollment Details</h3>
                <dl class="grid grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Customer</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $enrollment->customer->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Monthly Amount</dt>
                        <dd class="mt-1 text-sm text-gray-900">₹{{ number_format($enrollment->monthly_amount, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Start Date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $enrollment->start_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Maturity Date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $enrollment->maturity_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Installments Paid</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $enrollment->installments_paid }} / {{ $enrollment->total_installments }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Total Paid</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-900">₹{{ number_format($enrollment->total_paid, 2) }}</dd>
                    </div>
                </dl>
            </div>

            <div class="space-y-6">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Status</h3>
                    @php
                        $statusColors = ['active' => 'bg-blue-100 text-blue-800', 'matured' => 'bg-green-100 text-green-800', 'cancelled' => 'bg-red-100 text-red-800', 'redeemed' => 'bg-purple-100 text-purple-800'];
                    @endphp
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $statusColors[$enrollment->status] ?? 'bg-gray-100' }}">
                        {{ ucfirst($enrollment->status) }}
                    </span>
                    <div class="mt-4 text-sm">
                        <span class="text-gray-500">Redeemable Value:</span>
                        <span class="font-semibold text-amber-700">₹{{ number_format($redeemableValue, 2) }}</span>
                    </div>
                    <div class="mt-1 text-sm">
                        <span class="text-gray-500">Ledger Balance:</span>
                        <span class="font-semibold text-amber-700">₹{{ number_format($ledgerBalance, 2) }}</span>
                    </div>
                    @if($enrollment->terms_accepted_at)
                        <div class="mt-1 text-xs text-gray-500">
                            Terms accepted on {{ $enrollment->terms_accepted_at->format('d M Y, h:i A') }}
                        </div>
                    @endif
                    @if($enrollment->bonus_amount)
                        <div class="mt-1 text-xs text-gray-500">
                            (includes ₹{{ number_format($enrollment->bonus_amount, 2) }} bonus{{ $enrollment->status !== 'matured' ? ' on maturity' : '' }})
                        </div>
                    @endif

                    {{-- Progress bar --}}
                    @php $progress = $enrollment->total_installments > 0 ? ($enrollment->installments_paid / $enrollment->total_installments) * 100 : 0; @endphp
                    <div class="mt-4">
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-amber-600 h-2 rounded-full" style="width: {{ min(100, $progress) }}%"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">{{ round($progress) }}% complete</p>
                    </div>
                </div>

                @if($enrollment->isActive())
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Record Payment</h3>
                    <form method="POST" action="{{ route('schemes.enrollment.pay', $enrollment) }}">
                        @csrf
                        <div class="space-y-3">
                            <div>
                                <input type="number" name="amount" value="{{ $enrollment->monthly_amount }}" step="0.01" min="1" required placeholder="Amount" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div>
                                <select name="payment_method" required class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="cash">Cash</option>
                                    <option value="upi">UPI</option>
                                    <option value="card">Card</option>
                                    <option value="bank_transfer">Bank Transfer</option>
                                </select>
                            </div>
                            <div>
                                <input type="text" name="receipt_number" placeholder="Receipt # (optional)" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <button type="submit" class="w-full px-4 py-2 rounded-md text-sm font-medium text-white" style="background: #0d9488;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Record Payment</button>
                        </div>
                    </form>
                </div>
                @endif

                @if($redeemableValue > 0 && $eligibleInvoices->count())
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Redeem to Invoice</h3>
                    <form method="POST" action="{{ route('schemes.enrollment.redeem', $enrollment) }}">
                        @csrf
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Invoice</label>
                                <select name="invoice_id" required class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="">Select outstanding invoice</option>
                                    @foreach($eligibleInvoices as $invoice)
                                        <option value="{{ $invoice->id }}">
                                            {{ $invoice->invoice_number }} · Due ₹{{ number_format($invoice->outstanding_amount, 2) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Redeem Amount (₹)</label>
                                <input type="number" name="amount" step="0.01" min="1" max="{{ $redeemableValue }}" value="{{ number_format($redeemableValue, 2, '.', '') }}" required class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Note</label>
                                <input type="text" name="note" placeholder="Optional note" class="w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <button type="submit" class="w-full px-4 py-2 rounded-md text-sm font-medium text-white" style="background: #0d9488;">
                                Redeem to Invoice
                            </button>
                        </div>
                    </form>
                </div>
                @endif
            </div>
        </div>

        @if($enrollment->payments->count())
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($enrollment->payments->sortByDesc('payment_date') as $i => $payment)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-500">{{ $enrollment->payments->count() - $i }}</td>
                            <td class="px-6 py-3 text-sm">{{ $payment->payment_date->format('d M Y') }}</td>
                            <td class="px-6 py-3 text-sm text-right font-medium">₹{{ number_format($payment->amount, 2) }}</td>
                            <td class="px-6 py-3 text-sm capitalize">{{ $payment->payment_method }}</td>
                            <td class="px-6 py-3 text-sm text-gray-500">{{ $payment->receipt_number ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        @if($enrollment->redemptions->count())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden mt-6">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Redemption History</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Principal</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Bonus</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($enrollment->redemptions as $redemption)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-700">{{ $redemption->redeemed_at?->format('d M Y, h:i A') }}</td>
                                <td class="px-6 py-3 text-sm">
                                    <a href="{{ route('invoices.show', $redemption->invoice_id) }}" class="text-amber-700 hover:text-amber-800">
                                        {{ $redemption->invoice?->invoice_number ?? ('Invoice #' . $redemption->invoice_id) }}
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-sm text-right font-semibold text-gray-900">₹{{ number_format($redemption->amount, 2) }}</td>
                                <td class="px-6 py-3 text-sm text-right text-gray-700">₹{{ number_format($redemption->principal_component, 2) }}</td>
                                <td class="px-6 py-3 text-sm text-right text-gray-700">₹{{ number_format($redemption->bonus_component, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        @if($enrollment->ledgerEntries->count())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden mt-6">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Scheme Ledger (Recent)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Direction</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance After</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($enrollment->ledgerEntries as $entry)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-700">{{ $entry->created_at->format('d M Y, h:i A') }}</td>
                                <td class="px-6 py-3 text-sm text-gray-900">{{ ucwords(str_replace('_', ' ', $entry->entry_type)) }}</td>
                                <td class="px-6 py-3 text-center text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $entry->direction === 'credit' ? 'bg-green-100 text-green-700' : 'bg-rose-100 text-rose-700' }}">
                                        {{ ucfirst($entry->direction) }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-sm text-right font-medium {{ $entry->direction === 'credit' ? 'text-green-700' : 'text-rose-700' }}">
                                    {{ $entry->direction === 'credit' ? '+' : '-' }}₹{{ number_format($entry->amount, 2) }}
                                </td>
                                <td class="px-6 py-3 text-sm text-right text-gray-900 font-semibold">₹{{ number_format($entry->balance_after, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
