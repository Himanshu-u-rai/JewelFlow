<x-app-layout>
    <x-page-header class="metal-exchange-header">
        <div>
            <h1 class="page-title">{{ __('Metal Exchange Report') }}</h1>
            <p class="text-sm text-gray-500 mt-1">Old Gold &amp; Old Silver received as payment at POS</p>
        </div>
        <div class="page-actions metal-exchange-header-actions">
            <form method="GET" action="{{ route('report.metal-exchange') }}" class="metal-exchange-filter-form">
                <div class="metal-exchange-filter-field">
                    <label>From</label>
                    <input type="date" name="from" value="{{ $from }}"
                           class="metal-exchange-date-input">
                </div>
                <div class="metal-exchange-filter-field">
                    <label>To</label>
                    <input type="date" name="to" value="{{ $to }}"
                           class="metal-exchange-date-input">
                </div>
                <button type="submit" class="metal-exchange-filter-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Filter
                </button>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner metal-exchange-page">

        {{-- Summary cards --}}
        <div class="metal-exchange-summary-grid">
            {{-- Gold summary --}}
            <div class="metal-exchange-summary-card metal-exchange-summary-card--gold">
                <div class="metal-exchange-summary-head">
                    <div class="metal-exchange-summary-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <div>
                        <div class="metal-exchange-summary-title">Old Gold Received</div>
                        <div class="metal-exchange-summary-subtitle">{{ $goldSummary['count'] }} transaction{{ $goldSummary['count'] == 1 ? '' : 's' }}</div>
                    </div>
                </div>
                <div class="metal-exchange-summary-metrics">
                    <div class="metal-exchange-summary-metric">
                        <span>Gross Weight</span>
                        <strong>{{ number_format($goldSummary['gross'], 3) }}<small>g</small></strong>
                    </div>
                    <div class="metal-exchange-summary-metric">
                        <span>Fine Weight</span>
                        <strong>{{ number_format($goldSummary['fine'], 3) }}<small>g</small></strong>
                    </div>
                    <div class="metal-exchange-summary-metric">
                        <span>Total Value</span>
                        <strong>₹{{ number_format($goldSummary['value'], 0) }}</strong>
                    </div>
                </div>
            </div>

            {{-- Silver summary --}}
            <div class="metal-exchange-summary-card metal-exchange-summary-card--silver">
                <div class="metal-exchange-summary-head">
                    <div class="metal-exchange-summary-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div>
                        <div class="metal-exchange-summary-title">Old Silver Received</div>
                        <div class="metal-exchange-summary-subtitle">{{ $silverSummary['count'] }} transaction{{ $silverSummary['count'] == 1 ? '' : 's' }}</div>
                    </div>
                </div>
                <div class="metal-exchange-summary-metrics">
                    <div class="metal-exchange-summary-metric">
                        <span>Gross Weight</span>
                        <strong>{{ number_format($silverSummary['gross'], 3) }}<small>g</small></strong>
                    </div>
                    <div class="metal-exchange-summary-metric">
                        <span>Fine Weight</span>
                        <strong>{{ number_format($silverSummary['fine'], 3) }}<small>g</small></strong>
                    </div>
                    <div class="metal-exchange-summary-metric">
                        <span>Total Value</span>
                        <strong>₹{{ number_format($silverSummary['value'], 0) }}</strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- Transactions table --}}
        <div class="metal-exchange-table-card">
            <div class="metal-exchange-table-head">
                <div>
                    <h2>All Transactions</h2>
                    <p>Old metal payment entries recorded through POS invoices.</p>
                </div>
                <span>{{ \Carbon\Carbon::parse($from)->format('d M Y') }} — {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</span>
            </div>

            @if($rows->isEmpty())
                <div class="metal-exchange-empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-3 opacity-40"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <p class="text-sm">No metal exchange transactions in this period.</p>
                </div>
            @else
                <div class="metal-exchange-table-shell">
                    <table class="metal-exchange-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th class="text-center">Type</th>
                                <th class="text-right">Gross Wt (g)</th>
                                <th class="text-right">Purity</th>
                                <th class="text-right">Test Loss %</th>
                                <th class="text-right">Fine Wt (g)</th>
                                <th class="text-right">Rate/g (₹)</th>
                                <th class="text-right">Value (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php
                                    $isGold = $row->mode === 'old_gold';
                                    $customer = $row->invoice?->customer;
                                @endphp
                                <tr>
                                    <td class="metal-exchange-date-cell">
                                        <strong>{{ $row->created_at->format('d M Y') }}</strong>
                                        <span>{{ $row->created_at->format('h:i A') }}</span>
                                    </td>
                                    <td>
                                        @if($row->invoice)
                                            <a href="{{ route('invoices.show', $row->invoice) }}"
                                               class="metal-exchange-invoice-link">
                                                {{ $row->invoice->invoice_number }}
                                            </a>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="metal-exchange-customer-cell">
                                        <strong>{{ $customer?->name ?? '—' }}</strong>
                                        @if($customer?->phone)
                                            <span>{{ $customer->phone }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($isGold)
                                            <span class="metal-exchange-type-pill metal-exchange-type-pill--gold">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                Gold
                                            </span>
                                        @else
                                            <span class="metal-exchange-type-pill metal-exchange-type-pill--silver">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="12" cy="12" r="10"/></svg>
                                                Silver
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-right tabular">{{ number_format($row->metal_gross_weight, 3) }}</td>
                                    <td class="text-right">
                                        {{ $row->metal_purity }}{{ $isGold ? 'K' : '‰' }}
                                    </td>
                                    <td class="text-right">{{ $row->metal_test_loss ?? 0 }}%</td>
                                    <td class="text-right tabular metal-exchange-fine-cell {{ $isGold ? 'is-gold' : 'is-silver' }}">
                                        {{ number_format($row->metal_fine_weight, 3) }}
                                    </td>
                                    <td class="text-right tabular">{{ number_format($row->metal_rate_per_gram, 2) }}</td>
                                    <td class="text-right tabular metal-exchange-value-cell">₹{{ number_format($row->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right">Totals</td>
                                <td class="text-right tabular">{{ number_format($rows->sum('metal_gross_weight'), 3) }} g</td>
                                <td></td>
                                <td></td>
                                <td class="text-right tabular">{{ number_format($rows->sum('metal_fine_weight'), 3) }} g</td>
                                <td></td>
                                <td class="text-right tabular">₹{{ number_format($rows->sum('amount'), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>

    </div>
</x-app-layout>
