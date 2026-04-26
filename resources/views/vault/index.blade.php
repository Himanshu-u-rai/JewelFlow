<x-app-layout>
    <x-page-header title="Bullion Vault" subtitle="Real-time fine-weight balances per purity">
        <x-slot:actions>
            <a href="{{ route('vault.ledger') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                Full Ledger
            </a>
            <a href="{{ route('vault.lots.create') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Bullion
            </a>
            <a href="{{ route('job-orders.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><polygon points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Issue to Karigar
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        @if($balances->isEmpty())
            <div class="bg-white border border-amber-200 rounded-xl p-8 text-center text-gray-500">
                <p class="text-sm mb-3">No bullion lots in this shop yet.</p>
                <a href="{{ route('vault.lots.create') }}" class="btn btn-success btn-sm inline-flex">Add your first lot</a>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                @foreach($balances as $row)
                    <div class="bg-white rounded-xl border border-amber-200 shadow-sm p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Purity</div>
                                <div class="text-2xl font-bold text-amber-700">{{ rtrim(rtrim(number_format($row['purity'], 2), '0'), '.') }}<span class="text-xs ml-1 font-normal">K / fine</span></div>
                            </div>
                            <div class="text-xs text-gray-400">{{ $row['lots_count'] }} {{ Str::plural('lot', $row['lots_count']) }}</div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="bg-amber-50 rounded-lg p-2">
                                <div class="text-[10px] uppercase tracking-wide text-amber-700">In Vault</div>
                                <div class="text-sm font-bold text-amber-800 mt-1">{{ number_format($row['in_vault_fine'], 3) }}<span class="text-[10px] font-normal ml-0.5">g</span></div>
                            </div>
                            <div class="bg-blue-50 rounded-lg p-2">
                                <div class="text-[10px] uppercase tracking-wide text-blue-700">With Karigar</div>
                                <div class="text-sm font-bold text-blue-800 mt-1">{{ number_format($row['with_karigar_fine'], 3) }}<span class="text-[10px] font-normal ml-0.5">g</span></div>
                            </div>
                            <div class="bg-slate-100 rounded-lg p-2">
                                <div class="text-[10px] uppercase tracking-wide text-slate-700">Total</div>
                                <div class="text-sm font-bold text-slate-900 mt-1">{{ number_format($row['total_fine'], 3) }}<span class="text-[10px] font-normal ml-0.5">g</span></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Individual Lots --}}
        @if($lots->isNotEmpty())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">All Lots</h2>
                    <a href="{{ route('vault.lots.create') }}" class="text-xs text-teal-700 hover:underline">+ Add bullion</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-2 text-left font-semibold">Lot #</th>
                                <th class="px-4 py-2 text-left font-semibold">Source</th>
                                <th class="px-4 py-2 text-left font-semibold">Vendor</th>
                                <th class="px-4 py-2 text-center font-semibold">Metal</th>
                                <th class="px-4 py-2 text-center font-semibold">Purity</th>
                                <th class="px-4 py-2 text-right font-semibold">Total Fine</th>
                                <th class="px-4 py-2 text-right font-semibold">Remaining Fine</th>
                                <th class="px-4 py-2 text-right font-semibold">Issued</th>
                                <th class="px-4 py-2 text-center font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($lots as $lot)
                                @php
                                    $issued = (float)$lot->fine_weight_total - (float)$lot->fine_weight_remaining;
                                    $pct = $lot->fine_weight_total > 0
                                        ? round((float)$lot->fine_weight_remaining / (float)$lot->fine_weight_total * 100)
                                        : 0;
                                    $isEmpty = (float)$lot->fine_weight_remaining <= 0;
                                @endphp
                                <tr class="hover:bg-gray-50 cursor-pointer {{ $isEmpty ? 'opacity-50' : '' }}" onclick="window.location='{{ route('vault.lots.show', $lot) }}'"  >
                                    <td class="px-4 py-2 font-mono font-semibold text-amber-700">
                                        <a href="{{ route('vault.lots.show', $lot) }}" class="hover:underline">#{{ $lot->lot_number }}</a>
                                    </td>
                                    <td class="px-4 py-2 text-gray-600 capitalize">{{ str_replace('_', ' ', $lot->source) }}</td>
                                    <td class="px-4 py-2 text-gray-500 text-xs">{{ $lot->vendor?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-center text-gray-600 capitalize text-xs">{{ $lot->metal_type ?? '—' }}</td>
                                    <td class="px-4 py-2 text-center font-semibold text-amber-700">
                                        {{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}K
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono text-gray-500">{{ number_format($lot->fine_weight_total, 3) }}g</td>
                                    <td class="px-4 py-2 text-right font-mono font-bold {{ $isEmpty ? 'text-gray-400' : 'text-emerald-700' }}">
                                        {{ number_format($lot->fine_weight_remaining, 3) }}g
                                        <span class="text-[10px] font-normal text-gray-400 ml-0.5">({{ $pct }}%)</span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono text-blue-600">
                                        {{ $issued > 0 ? number_format($issued, 3).'g' : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        @if($isEmpty)
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 text-gray-500">Depleted</span>
                                        @elseif($issued > 0)
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-100 text-blue-700">Partial</span>
                                        @else
                                            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-700">Available</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if($openJobs->isNotEmpty())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">Open Job Orders</h2>
                    <a href="{{ route('job-orders.index') }}" class="text-xs text-teal-700 hover:underline">View all</a>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2 text-left font-semibold">Job #</th>
                            <th class="px-4 py-2 text-left font-semibold">Karigar</th>
                            <th class="px-4 py-2 text-left font-semibold">Purity</th>
                            <th class="px-4 py-2 text-right font-semibold">Issued (fine)</th>
                            <th class="px-4 py-2 text-right font-semibold">Outstanding (fine)</th>
                            <th class="px-4 py-2 text-center font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($openJobs as $jo)
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('job-orders.show', $jo) }}'"  >
                                <td class="px-4 py-2 font-mono text-teal-700"><a href="{{ route('job-orders.show', $jo) }}" class="hover:underline">{{ $jo->job_order_number }}</a></td>
                                <td class="px-4 py-2 text-gray-700">{{ $jo->karigar?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ rtrim(rtrim(number_format($jo->purity, 2), '0'), '.') }}K</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold">{{ number_format($jo->outstanding_fine, 3) }}g</td>
                                <td class="px-4 py-2 text-center">
                                    <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-800">Recent Vault Movements</h2>
                <a href="{{ route('vault.ledger') }}" class="text-xs text-teal-700 hover:underline">Full ledger</a>
            </div>
            @if($recentMovements->isEmpty())
                <div class="py-10 text-center text-gray-400 text-sm">No movements yet.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-2 text-left font-semibold">When</th>
                                <th class="px-4 py-2 text-left font-semibold">Type</th>
                                <th class="px-4 py-2 text-left font-semibold">From Lot</th>
                                <th class="px-4 py-2 text-left font-semibold">To Lot</th>
                                <th class="px-4 py-2 text-right font-semibold">Fine Wt</th>
                                <th class="px-4 py-2 text-left font-semibold">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($recentMovements as $mv)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap">{{ $mv->created_at->format('d M, H:i') }}</td>
                                    <td class="px-4 py-2"><span class="text-[11px] uppercase font-semibold text-gray-700">{{ str_replace('_', ' ', $mv->type) }}</span></td>
                                    <td class="px-4 py-2 text-gray-600 font-mono text-xs">{{ $mv->from_lot_id ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-600 font-mono text-xs">{{ $mv->to_lot_id ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ number_format($mv->fine_weight, 3) }}g</td>
                                    <td class="px-4 py-2 text-gray-500 text-xs">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
