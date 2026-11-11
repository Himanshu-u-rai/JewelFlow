<x-dhiran-layout title="Cash Book">
    @php
        // $totals (opening, in, out, net, closing) and $pageStartBalance come from the controller: whole period,
        // every page, starting from the balance before the period. With the in/out filter on, rows skip the other
        // kind, so a per-row running balance would be wrong: it is left out.
        $fromDate = $from;
        $showRunning = ! $filters->get('cash');
    @endphp

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Dhiran Cash Book</h1>
            <p class="text-sm text-gray-500 mt-1">All cash inflows and outflows for pledge (gold & silver) loans</p>
        </div>
        <div class="page-actions">
            <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm" title="Print">
                <x-icon name="printer" size="14" />
                Print
            </button>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters" :action="route('dhiran.reports.cashbook')" default-period="this_month"
            :fields="['period', 'cash']" />

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            @foreach([
                ['opening', 'Opening balance', 'wallet', 'bg-slate-100', 'text-slate-700', 'Before ' . \Illuminate\Support\Carbon::parse($fromDate)->format('d M Y')],
                ['in', 'Total inflows', 'trending-up', 'bg-green-100', 'text-green-600', null],
                ['out', 'Total outflows', 'trending-down', 'bg-red-100', 'text-red-600', null],
                ['closing', 'Closing balance', 'chart-no-axes-column', 'bg-blue-100', $totals['closing'] >= 0 ? 'text-blue-600' : 'text-red-600', 'Net ' . number_format($totals['net'], 2) . ' in this period'],
            ] as [$key, $label, $icon, $tint, $ink, $sub])
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 {{ $tint }} flex items-center justify-center rounded-lg">
                            <x-icon :name="$icon" size="18" :class="$ink" />
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 font-medium uppercase tracking-wider">{{ $label }}</p>
                            <p class="text-xl font-bold {{ $ink }}" data-report-total="{{ $key }}">{{ number_format($totals[$key], 2) }}</p>
                            @if($sub)<p class="text-xs text-gray-500 mt-0.5">{{ $sub }}</p>@endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Table --}}
        <div class="bg-white rounded-lg shadow-sm" data-list-frame>
            <div class="overflow-x-auto" data-list-scroll tabindex="0" role="region" aria-label="Dhiran cashbook entries">
                <table class="w-full border-collapse jf-table dh-stack">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan #</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Source</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Running Balance</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @php $runningBalance = $pageStartBalance; @endphp
                        @forelse($entries as $entry)
                            @php
                                if ($entry->type === 'in') {
                                    $runningBalance += (float) $entry->amount;
                                } else {
                                    $runningBalance -= (float) $entry->amount;
                                }
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <x-dhiran.sno :rows="$entries" :n="$loop->iteration" />
                                <td data-label="Date" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $entry->entry_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Loan #" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    @if($entry->loan)
                                        <a href="{{ route('dhiran.show', $entry->loan) }}" class="text-blue-600 hover:underline">{{ $entry->loan->loan_number }}</a>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td data-label="Type" class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    @if($entry->type === 'in')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">IN</span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">OUT</span>
                                    @endif
                                </td>
                                <td data-label="Source" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ ucfirst(str_replace('_', ' ', $entry->source_type ?? '-')) }}</td>
                                <td data-label="Amount" class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-right {{ $entry->type === 'in' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $entry->type === 'in' ? '+' : '-' }}{{ number_format((float) $entry->amount, 2) }}
                                </td>
                                <td data-label="Method" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ \App\Models\Dhiran\DhiranPayment::methodLabel($entry->payment_method) }}</td>
                                <td data-label="Description" class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">{{ $entry->description ?? '-' }}</td>
                                <td data-label="Running Balance" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-right {{ $runningBalance >= 0 ? 'text-gray-900' : 'text-red-600' }}" data-running-balance>{{ $showRunning ? number_format($runningBalance, 2) : '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="jf-table-empty">No cash entries found for the selected period</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($entries->count())
                    <tfoot>
                        <tr class="bg-gray-50 border-t-2 border-gray-300 font-semibold">
                            <td colspan="5" class="px-6 py-3 text-sm text-gray-700">Period totals ({{ $entries->total() }} entries)</td>
                            <td class="px-6 py-3 text-sm text-gray-900 text-right">Net: {{ number_format($totals['net'], 2) }}</td>
                            <td colspan="2" class="px-6 py-3 text-sm text-gray-700 text-right">Closing balance</td>
                            <td class="px-6 py-3 text-sm font-bold text-right {{ $totals['closing'] >= 0 ? 'text-gray-900' : 'text-red-600' }}">{{ number_format($totals['closing'], 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$entries" noun="entries" />
        </div>
    </div>
</x-dhiran-layout>
