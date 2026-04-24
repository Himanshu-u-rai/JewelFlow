<x-app-layout>
    <x-page-header>
        <h1 class="page-title">Gold Ledger</h1>
        <div class="page-actions">
            <form method="POST" action="{{ route('export.gold-ledger') }}" class="inline-flex" data-turbo="false">
                @csrf
                <button type="submit" class="btn btn-dark btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export CSV</button>
            </form>
            <a href="{{ url('/report/daily') }}" class="btn btn-dark btn-sm ml-2"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Daily</a>
        </div>
    </x-page-header>

    <div class="content-inner ledger-page">
        <div class="bg-white rounded-lg shadow-sm ledger-table-card">
            <div class="p-6 border-b border-gray-200 flex items-center justify-between ledger-table-head">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Ledger Entries</h2>
                    <p class="text-sm text-gray-500 mt-1">All gold movements ordered by most recent</p>
                </div>
                <div class="text-right">
                    <div class="text-sm text-gray-500">Net movement</div>
                    <div class="text-xl font-semibold text-gray-900">{{ number_format($movements->sum('fine_weight'), 6) }} g</div>
                </div>
            </div>

            <div class="overflow-x-auto p-6 ledger-table-shell">
                <table class="w-full ledger-data-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Gold (g)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($movements as $m)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-700">{{ $m->created_at->format('d M Y H:i') }}</td>
                                <td class="px-6 py-3 text-sm">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">{{ ucfirst($m->type) }}</span>
                                </td>
                                <td class="px-6 py-3 text-right text-sm font-medium {{ $m->fine_weight >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ number_format($m->fine_weight, 6) }}</td>
                                <td class="px-6 py-3 text-sm text-gray-700">
                                    @if($m->fromLot)
                                        Lot {{ $m->fromLot->lot_number }} ({{ $m->fromLot->purity }}K)
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-700">
                                    @if($m->toLot)
                                        Lot {{ $m->toLot->lot_number }} ({{ $m->toLot->purity }}K)
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-700">
                                    @if($m->reference_type === 'item' && $m->item)
                                        Item {{ $m->item->barcode }}
                                    @elseif($m->reference_type === 'invoice' && $m->invoice)
                                        Invoice {{ $m->invoice->invoice_number }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-sm text-gray-700">{{ $m->user?->name ?? 'system' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500">No ledger entries found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            
            @if($movements->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $movements->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
