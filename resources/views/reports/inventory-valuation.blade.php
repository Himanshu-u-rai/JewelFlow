<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Inventory Valuation</h1>
            <p class="text-sm text-gray-500 mt-1">On-hand stock valued at cost — as of {{ now()->format('d M Y, H:i') }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.inventory-valuation') }}" class="flex flex-wrap gap-2 items-end">
                <label class="text-xs text-gray-500">Dead-stock after
                    <input type="number" name="dead_days" min="1" value="{{ $data->deadCapitalDays }}" class="ml-1 w-20 rounded-lg border-slate-200 text-sm h-10">
                    days
                </label>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <x-print-button />
                <a href="{{ route('report.inventory-valuation.csv', ['dead_days' => $data->deadCapitalDays]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Value at Cost</p><p class="text-xl font-semibold">₹{{ number_format($data->totalAtCost, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Tag (Retail) Value</p><p class="text-xl font-semibold text-gray-700">₹{{ number_format($data->totalAtRetail, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Items on Hand</p><p class="text-xl font-semibold">{{ $data->itemCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Dead Capital (&gt;{{ $data->deadCapitalDays }}d)</p><p class="text-xl font-semibold text-amber-600">₹{{ number_format($data->deadCapitalValue, 2) }}</p><p class="text-xs text-gray-500">{{ $data->deadCapitalCount }} items</p></div>
        </div>

        @if($data->costUnknownCount > 0)
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm text-amber-800">
            {{ $data->costUnknownCount }} on-hand item(s) have no recorded cost and are excluded from the cost valuation.
        </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">By Category</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cost Value</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($data->byCategory as $c)
                                <tr><td class="px-4 py-2">{{ $c->category }}</td><td class="px-4 py-2 text-right text-gray-600">{{ $c->count }}</td><td class="px-4 py-2 text-right">₹{{ number_format($c->cost_value, 2) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400 text-sm">No stock on hand.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">By Metal</h2></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50"><tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Metal</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Cost Value</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Fine (g)</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($data->byMetal as $m)
                                <tr><td class="px-4 py-2 capitalize">{{ $m->metal_type }}</td><td class="px-4 py-2 text-right text-gray-600">{{ $m->count }}</td><td class="px-4 py-2 text-right">₹{{ number_format($m->cost_value, 2) }}</td><td class="px-4 py-2 text-right text-gray-600">{{ number_format($m->fine_weight, 3) }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-400 text-sm">No stock on hand.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
