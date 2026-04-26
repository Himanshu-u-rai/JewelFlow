<x-app-layout>
    <x-page-header
        :title="'Lot #' . $metalLot->lot_number"
        :subtitle="ucfirst($metalLot->metal_type ?? 'Gold') . ' · ' . rtrim(rtrim(number_format($metalLot->purity, 2), '0'), '.') . 'K · ' . ucfirst(str_replace('_', ' ', $metalLot->source))">
        <x-slot:actions>
            <a href="{{ route('vault.index') }}" class="btn btn-secondary btn-sm">← Vault</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        {{-- Lot summary cards --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-amber-200 shadow-sm p-4 text-center">
                <div class="text-[10px] uppercase tracking-wide text-amber-700 font-semibold mb-1">Total Fine</div>
                <div class="text-xl font-bold text-amber-800 font-mono">{{ number_format($metalLot->fine_weight_total, 3) }}<span class="text-xs font-normal ml-0.5">g</span></div>
                <div class="text-[10px] text-gray-400 mt-0.5">when received</div>
            </div>
            <div class="bg-white rounded-xl border border-emerald-200 shadow-sm p-4 text-center">
                <div class="text-[10px] uppercase tracking-wide text-emerald-700 font-semibold mb-1">Remaining</div>
                <div class="text-xl font-bold text-emerald-700 font-mono">{{ number_format($metalLot->fine_weight_remaining, 3) }}<span class="text-xs font-normal ml-0.5">g</span></div>
                @php $pct = $metalLot->fine_weight_total > 0 ? round((float)$metalLot->fine_weight_remaining / (float)$metalLot->fine_weight_total * 100) : 0; @endphp
                <div class="text-[10px] text-gray-400 mt-0.5">{{ $pct }}% left</div>
            </div>
            <div class="bg-white rounded-xl border border-blue-200 shadow-sm p-4 text-center">
                <div class="text-[10px] uppercase tracking-wide text-blue-700 font-semibold mb-1">Issued Out</div>
                @php $issued = (float)$metalLot->fine_weight_total - (float)$metalLot->fine_weight_remaining; @endphp
                <div class="text-xl font-bold text-blue-700 font-mono">{{ number_format($issued, 3) }}<span class="text-xs font-normal ml-0.5">g</span></div>
                <div class="text-[10px] text-gray-400 mt-0.5">to karigars</div>
            </div>
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 text-center">
                <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold mb-1">Purity</div>
                <div class="text-xl font-bold text-gray-800">{{ rtrim(rtrim(number_format($metalLot->purity, 2), '0'), '.') }}<span class="text-xs font-normal ml-0.5">K</span></div>
                <div class="text-[10px] text-gray-400 mt-0.5">{{ ucfirst($metalLot->metal_type ?? 'gold') }}</div>
            </div>
        </div>

        {{-- Lot metadata --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
            <h2 class="text-sm font-semibold text-gray-800 mb-3">Lot Details</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Source</div>
                    <div class="mt-0.5 text-gray-800 capitalize">{{ str_replace('_', ' ', $metalLot->source) }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Vendor / Supplier</div>
                    <div class="mt-0.5 text-gray-800">{{ $metalLot->vendor?->name ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Cost / Fine Gram</div>
                    <div class="mt-0.5 text-gray-800 font-mono">{{ $metalLot->cost_per_fine_gram ? '₹' . number_format($metalLot->cost_per_fine_gram, 2) : '—' }}</div>
                </div>
                <div>
                    <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Added On</div>
                    <div class="mt-0.5 text-gray-800">{{ $metalLot->created_at->format('d M Y, H:i') }}</div>
                </div>
                @if($metalLot->notes)
                    <div class="col-span-2 sm:col-span-4">
                        <div class="text-[10px] uppercase tracking-wide text-gray-500 font-semibold">Notes</div>
                        <div class="mt-0.5 text-gray-700">{{ $metalLot->notes }}</div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Running balance bar --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-gray-700">Fine Weight Usage</span>
                <span class="text-xs text-gray-500 font-mono">{{ number_format($issued, 3) }}g used of {{ number_format($metalLot->fine_weight_total, 3) }}g</span>
            </div>
            <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                <div class="h-3 rounded-full {{ $pct > 80 ? 'bg-rose-500' : ($pct > 50 ? 'bg-amber-400' : 'bg-emerald-500') }}"
                     style="width: {{ 100 - $pct }}%"></div>
            </div>
            <div class="flex justify-between text-[10px] text-gray-400 mt-1">
                <span>{{ number_format($metalLot->fine_weight_remaining, 3) }}g remaining</span>
                <span>{{ $pct }}% available</span>
            </div>
        </div>

        {{-- Movement history --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-800">Gold Movement History</h2>
                <p class="text-xs text-gray-400 mt-0.5">Every deduction and addition recorded against this lot</p>
            </div>
            @if($movements->isEmpty())
                <div class="py-10 text-center text-gray-400 text-sm">No movements recorded yet.</div>
            @else
                {{-- Running balance from the bottom up --}}
                @php
                    // Build running balance: start from current remaining, work backwards
                    $runningBalance = (float) $metalLot->fine_weight_remaining;
                    $rows = [];
                    foreach ($movements as $mv) {
                        $isDebit  = $mv->from_lot_id === $metalLot->id;  // gold left this lot
                        $isCredit = $mv->to_lot_id   === $metalLot->id;  // gold entered this lot
                        $amount   = (float) $mv->fine_weight;
                        $balanceAfter  = $runningBalance;
                        $balanceBefore = $isDebit ? $runningBalance + $amount : $runningBalance - $amount;

                        // Resolve reference label
                        $refLabel = $mv->reference_type . ' #' . $mv->reference_id;
                        if ($mv->reference_type === 'job_order' && isset($jobOrders[$mv->reference_id])) {
                            $refLabel = $jobOrders[$mv->reference_id]->job_order_number;
                        } elseif ($mv->reference_type === 'metal_lot') {
                            $refLabel = 'Lot #' . $mv->reference_id . ' (added)';
                        }

                        $rows[] = compact('mv', 'isDebit', 'isCredit', 'amount', 'balanceBefore', 'balanceAfter', 'refLabel');
                        $runningBalance = $balanceBefore;
                    }
                @endphp
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-2 text-left font-semibold">When</th>
                                <th class="px-4 py-2 text-left font-semibold">Type</th>
                                <th class="px-4 py-2 text-left font-semibold">Reference</th>
                                <th class="px-4 py-2 text-right font-semibold">Deducted</th>
                                <th class="px-4 py-2 text-right font-semibold">Added</th>
                                <th class="px-4 py-2 text-right font-semibold">Balance After</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($rows as $row)
                                <tr class="hover:bg-gray-50 {{ $row['mv']->reference_type === 'job_order' && isset($jobOrders[$row['mv']->reference_id]) ? 'cursor-pointer' : '' }}"
                                    @if($row['mv']->reference_type === 'job_order' && isset($jobOrders[$row['mv']->reference_id]))
                                        onclick="window.location='{{ route('job-orders.show', $row['mv']->reference_id) }}'"
                                    @endif
                                >
                                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap text-xs">{{ $row['mv']->created_at->format('d M Y, H:i') }}</td>
                                    <td class="px-4 py-2">
                                        @php
                                            $typeColors = [
                                                'purchase'   => 'bg-emerald-100 text-emerald-700',
                                                'opening'    => 'bg-emerald-100 text-emerald-700',
                                                'buyback'    => 'bg-teal-100 text-teal-700',
                                                'job_issue'  => 'bg-blue-100 text-blue-700',
                                                'job_return' => 'bg-amber-100 text-amber-700',
                                                'manufacture'=> 'bg-purple-100 text-purple-700',
                                            ];
                                            $typeClass = $typeColors[$row['mv']->type] ?? 'bg-gray-100 text-gray-600';
                                        @endphp
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $typeClass }}">
                                            {{ str_replace('_', ' ', $row['mv']->type) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-gray-600 text-xs">
                                        @if($row['mv']->reference_type === 'job_order' && isset($jobOrders[$row['mv']->reference_id]))
                                            <a href="{{ route('job-orders.show', $row['mv']->reference_id) }}" class="text-teal-700 hover:underline font-mono">{{ $row['refLabel'] }}</a>
                                        @else
                                            <span class="text-gray-500">{{ $row['refLabel'] }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold {{ $row['isDebit'] ? 'text-rose-600' : 'text-gray-300' }}">
                                        {{ $row['isDebit'] ? '− ' . number_format($row['amount'], 3) . 'g' : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono font-semibold {{ $row['isCredit'] ? 'text-emerald-600' : 'text-gray-300' }}">
                                        {{ $row['isCredit'] ? '+ ' . number_format($row['amount'], 3) . 'g' : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono font-bold text-gray-800">
                                        {{ number_format($row['balanceAfter'], 3) }}g
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 border-t-2 border-gray-200">
                                <td colspan="5" class="px-4 py-2 text-xs font-semibold text-gray-600 text-right">Current Balance</td>
                                <td class="px-4 py-2 text-right font-mono font-bold text-emerald-700">{{ number_format($metalLot->fine_weight_remaining, 3) }}g</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
