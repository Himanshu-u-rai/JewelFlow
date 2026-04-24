<x-app-layout>
    <style>
        .dhiran-show-root {
            --ds-ink: #0f172a;
            --ds-muted: #64748b;
            --ds-border: #e2e8f0;
            --ds-gold: #d98b00;
            --ds-shadow: 0 10px 24px rgba(20, 40, 75, 0.08);
        }
        .ds-fin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }
        .ds-fin-card {
            background: #fff;
            border: 1px solid var(--ds-border);
            border-radius: 14px;
            padding: 14px;
            box-shadow: var(--ds-shadow);
        }
        .ds-fin-label {
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.18em;
            color: var(--ds-muted); margin-bottom: 4px;
        }
        .ds-fin-value {
            font-size: 20px; font-weight: 700; color: var(--ds-ink);
        }
        .ds-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }
        .ds-info-item-label {
            font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.1em;
            color: var(--ds-muted);
        }
        .ds-info-item-value {
            font-size: 14px; font-weight: 600; color: var(--ds-ink);
            margin-top: 2px;
        }
    </style>

    <x-page-header>
        <div>
            <h1 class="page-title">Loan {{ $loan->loan_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">
                {{ $loan->customer->name ?? '---' }}
                @php
                    $statusColors = [
                        'active' => 'bg-emerald-100 text-emerald-800',
                        'overdue' => 'bg-rose-100 text-rose-800',
                        'closed' => 'bg-slate-100 text-slate-600',
                        'renewed' => 'bg-sky-100 text-sky-800',
                        'forfeited' => 'bg-red-100 text-red-800',
                    ];
                @endphp
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ml-2 {{ $statusColors[$loan->status] ?? 'bg-slate-100 text-slate-600' }}">
                    {{ ucfirst($loan->status) }}
                </span>
            </p>
        </div>
        <div class="page-actions flex flex-wrap gap-2">
            @if($loan->status === 'active')
                <button type="button" class="btn btn-dark btn-sm" onclick="document.getElementById('payInterestModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Pay Interest
                </button>
                <button type="button" class="btn btn-dark btn-sm" onclick="document.getElementById('repayModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    Repay
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('releaseItemModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4"/></svg>
                    Release Item
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('precloseModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Pre-Close
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('renewModal').showModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    Renew
                </button>
                <form method="POST" action="{{ route('dhiran.send-notice', $loan) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        Send Notice
                    </button>
                </form>
            @endif
            <a href="{{ route('dhiran.receipt', $loan) }}" target="_blank" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Print Receipt
            </a>
            <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                All Loans
            </a>
        </div>
    </x-page-header>

    <div class="content-inner dhiran-show-root">
        <x-app-alerts class="mb-6" />

        {{-- Loan Info Grid --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <div class="ds-info-grid">
                <div>
                    <div class="ds-info-item-label">Loan Date</div>
                    <div class="ds-info-item-value">{{ $loan->loan_date->format('d M Y') }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Maturity Date</div>
                    <div class="ds-info-item-value">{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Tenure</div>
                    <div class="ds-info-item-value">{{ $loan->tenure_months }} months</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Interest Rate</div>
                    <div class="ds-info-item-value">{{ $loan->interest_rate_monthly }}% / month</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Interest Type</div>
                    <div class="ds-info-item-value">{{ ucfirst($loan->interest_type) }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">LTV %</div>
                    <div class="ds-info-item-value">{{ number_format($loan->ltv_percent ?? 0, 1) }}%</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Gold Rate (at pledge)</div>
                    <div class="ds-info-item-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->gold_rate_on_date, 2) }}</div>
                </div>
                <div>
                    <div class="ds-info-item-label">Customer</div>
                    <div class="ds-info-item-value">
                        @if($loan->customer)
                            <a href="{{ route('dhiran.customer-loans', $loan->customer) }}" class="text-amber-700 hover:underline">{{ $loan->customer->name }}</a>
                        @else
                            ---
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Financial Summary --}}
        <div class="ds-fin-grid">
            <div class="ds-fin-card">
                <div class="ds-fin-label">Principal</div>
                <div class="ds-fin-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}</div>
            </div>
            <div class="ds-fin-card">
                <div class="ds-fin-label">Outstanding Principal</div>
                <div class="ds-fin-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->outstanding_principal ?? $loan->principal_amount, 2) }}</div>
            </div>
            <div class="ds-fin-card">
                <div class="ds-fin-label">Outstanding Interest</div>
                <div class="ds-fin-value text-amber-700">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->outstanding_interest ?? 0, 2) }}</div>
            </div>
            <div class="ds-fin-card">
                <div class="ds-fin-label">Outstanding Penalty</div>
                <div class="ds-fin-value text-rose-600">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->outstanding_penalty ?? 0, 2) }}</div>
            </div>
            <div class="ds-fin-card" style="border-color: var(--ds-gold);">
                <div class="ds-fin-label">Total Outstanding</div>
                <div class="ds-fin-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding ?? 0, 2) }}</div>
            </div>
        </div>

        {{-- Pledged Items --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Pledged Items</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Description</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Gross Wt</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Net Wt</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Purity</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Fine Wt</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Market Val</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan Val</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">HUID</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loan->items ?? [] as $item)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-4 whitespace-nowrap text-sm font-medium text-slate-700">{{ $item->description }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ number_format($item->gross_weight, 3) }}g</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ number_format($item->net_metal_weight, 3) }}g</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-center text-slate-600">{{ $item->purity }}K</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ number_format($item->fine_weight, 3) }}g</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">{{ $currencySymbol ?? '₹' }}{{ number_format($item->market_value, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">{{ $currencySymbol ?? '₹' }}{{ number_format($item->loan_value, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    @php
                                        $itemStatus = $item->status ?? 'pledged';
                                        $itemStatusColors = [
                                            'pledged' => 'bg-amber-100 text-amber-800',
                                            'released' => 'bg-emerald-100 text-emerald-800',
                                            'forfeited' => 'bg-red-100 text-red-800',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $itemStatusColors[$itemStatus] ?? 'bg-slate-100 text-slate-600' }}">
                                        {{ ucfirst($itemStatus) }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-slate-500">{{ $item->huid ?? '---' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-8 text-center text-slate-500 text-sm">No items recorded</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Payment History --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Payment History</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Type</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Interest</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Penalty</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Method</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($loan->payments ?? [] as $payment)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-4 whitespace-nowrap text-sm text-slate-600">{{ $payment->paid_at ? $payment->paid_at->format('d M Y') : '---' }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-slate-700">{{ ucfirst($payment->type ?? '---') }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->amount, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->principal_component ?? 0, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->interest_component ?? 0, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-600">{{ $currencySymbol ?? '₹' }}{{ number_format($payment->penalty_component ?? 0, 2) }}</td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-700">
                                        {{ ucfirst($payment->method ?? 'cash') }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
                                    @if($payment->receipt_url ?? false)
                                        <a href="{{ $payment->receipt_url }}" target="_blank" class="text-amber-700 hover:underline text-xs font-medium">View</a>
                                    @else
                                        <span class="text-slate-400 text-xs">---</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-slate-500 text-sm">No payments recorded yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- KYC Section --}}
        @if($loan->aadhaar || $loan->pan)
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">KYC Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @if($loan->aadhaar)
                <div>
                    <div class="ds-info-item-label">Aadhaar Number</div>
                    <div class="ds-info-item-value">{{ $loan->aadhaar }}</div>
                </div>
                @endif
                @if($loan->pan)
                <div>
                    <div class="ds-info-item-label">PAN</div>
                    <div class="ds-info-item-value">{{ $loan->pan }}</div>
                </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Notes --}}
        @if($loan->notes)
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-2">Notes</h2>
            <p class="text-sm text-slate-600 whitespace-pre-line">{{ $loan->notes }}</p>
        </div>
        @endif
    </div>

    {{-- Pay Interest Modal --}}
    <dialog id="payInterestModal" class="rounded-2xl border border-slate-200 shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Pay Interest</h3>
            <form method="POST" action="{{ route('dhiran.pay-interest', $loan) }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Amount</label>
                    <input type="number" step="0.01" name="amount" required placeholder="0.00"
                           class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payment Method</label>
                    <select name="method" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('payInterestModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Pay Interest</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Repay Modal --}}
    <dialog id="repayModal" class="rounded-2xl border border-slate-200 shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Repay Loan</h3>
            <form method="POST" action="{{ route('dhiran.repay', $loan) }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Amount</label>
                    <input type="number" step="0.01" name="amount" required placeholder="0.00"
                           class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payment Method</label>
                    <select name="method" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('repayModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Submit Payment</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Release Item Modal --}}
    <dialog id="releaseItemModal" class="rounded-2xl border border-slate-200 shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Release Pledged Item</h3>
            <form method="POST" action="{{ route('dhiran.release-item', $loan) }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Select Item</label>
                    <select name="item_id" required class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                        <option value="">Choose item...</option>
                        @foreach($loan->items ?? [] as $item)
                            @if(($item->status ?? 'pledged') === 'pledged')
                                <option value="{{ $item->id }}">{{ $item->description }} ({{ number_format($item->gross_weight, 3) }}g)</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('releaseItemModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Release Item</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Pre-Close Modal --}}
    <dialog id="precloseModal" class="rounded-2xl border border-slate-200 shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Pre-Close Loan</h3>
            <p class="text-sm text-slate-600 mb-4">This will close the loan and release all pledged items. Total outstanding: <strong>{{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding ?? 0, 2) }}</strong></p>
            <form method="POST" action="{{ route('dhiran.pre-close', $loan) }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payment Method</label>
                    <select name="method" class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('precloseModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Pre-Close Loan</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- Renew Modal --}}
    <dialog id="renewModal" class="rounded-2xl border border-slate-200 shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
        <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Renew Loan</h3>
            <form method="POST" action="{{ route('dhiran.renew', $loan) }}">
                @csrf
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">New Tenure (Months)</label>
                    <input type="number" name="tenure_months" required min="1" value="{{ $loan->tenure_months }}"
                           class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">New Interest Rate (%/month)</label>
                    <input type="number" step="0.01" name="interest_rate_monthly" required value="{{ $loan->interest_rate_monthly }}"
                           class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('renewModal').close()">Cancel</button>
                    <button type="submit" class="btn btn-dark btn-sm">Renew Loan</button>
                </div>
            </form>
        </div>
    </dialog>
</x-app-layout>
