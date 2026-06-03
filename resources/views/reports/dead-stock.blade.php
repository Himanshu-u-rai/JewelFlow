<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Dead Stock / Inventory Aging</h1>
            <p class="text-sm text-gray-500 mt-1">On-hand stock at cost, by how long it's been sitting — as of {{ \Carbon\Carbon::parse($data->asOf)->format('d M Y') }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('report.dead-stock.csv') }}" class="btn btn-success btn-sm">Export CSV</a>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Fresh (0–90)</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->freshValue, 0) }}</p><p class="text-xs text-gray-400">{{ $data->freshCount }} items</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Aging (91–180)</p><p class="text-lg font-semibold text-amber-600">₹{{ number_format($data->agingValue, 0) }}</p><p class="text-xs text-gray-400">{{ $data->agingCount }} items</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Stale (181–365)</p><p class="text-lg font-semibold text-orange-600">₹{{ number_format($data->staleValue, 0) }}</p><p class="text-xs text-gray-400">{{ $data->staleCount }} items</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Dead (365+)</p><p class="text-lg font-semibold text-rose-600">₹{{ number_format($data->deadValue, 0) }}</p><p class="text-xs text-gray-400">{{ $data->deadCount }} items</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total at Cost</p><p class="text-lg font-semibold">₹{{ number_format($data->totalValue, 0) }}</p><p class="text-xs text-gray-400">{{ $data->totalCount }} items</p></div>
        </div>

        @if($data->costUnknownCount > 0)
            <p class="text-xs text-amber-600">{{ $data->costUnknownCount }} item(s) have no recorded cost and contribute ₹0 to these values.</p>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Oldest Non-Moving Items <span class="text-sm font-normal text-gray-400">(in stock &gt; 180 days)</span></h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Barcode</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Design</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Metal</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Age</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->oldest as $i)
                            <tr>
                                <td class="px-4 py-2 font-mono text-gray-600">{{ $i->barcode }}</td>
                                <td class="px-4 py-2 text-gray-800">{{ $i->design }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $i->category }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $i->metal_type }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($i->cost_price, 2) }}</td>
                                <td class="px-4 py-2 text-right"><span class="px-2 py-0.5 rounded text-xs {{ $i->age_days > 365 ? 'bg-rose-100 text-rose-700' : 'bg-orange-100 text-orange-700' }}">{{ $i->age_days }}d</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No items aged beyond 180 days — inventory is turning over well.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
