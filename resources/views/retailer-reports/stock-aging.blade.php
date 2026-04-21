<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Stock Aging Report</h1>
            <p class="text-sm text-gray-500 mt-1">How long items have been sitting in stock</p>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            @php
                $colors = [
                    '0-30 days' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'border' => 'border-green-200'],
                    '31-60 days' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'border' => 'border-blue-200'],
                    '61-90 days' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'border' => 'border-amber-200'],
                    '91-180 days' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'border' => 'border-orange-200'],
                    '180+ days' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-700', 'border' => 'border-rose-200'],
                ];
                $totalItems = collect($buckets)->sum('count');
                $totalValue = collect($buckets)->sum('value');
            @endphp
            @foreach($buckets as $label => $data)
            <div class="bg-white rounded-lg shadow-sm border {{ $colors[$label]['border'] }} p-4">
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $colors[$label]['bg'] }} {{ $colors[$label]['text'] }}">{{ $label }}</span>
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ $data['count'] }}</div>
                <div class="text-xs text-gray-500">₹{{ number_format($data['value'], 0) }}</div>
            </div>
            @endforeach
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm text-gray-500">Total Items in Stock</p>
                <p class="text-2xl font-semibold text-gray-900">{{ number_format($totalItems) }}</p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm text-gray-500">Total Stock Value</p>
                <p class="text-2xl font-semibold text-gray-900">₹{{ number_format($totalValue, 0) }}</p>
            </div>
        </div>

        {{-- Detailed table for slow-moving stock --}}
        @php $slowItems = collect($buckets['91-180 days']['items'])->merge($buckets['180+ days']['items']); @endphp
        @if($slowItems->count())
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Slow-Moving Stock (90+ days)</h3>
                <p class="text-sm text-gray-500">{{ $slowItems->count() }} items need attention</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Barcode</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Days in Stock</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($slowItems->take(50) as $item)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm font-mono">{{ $item->barcode }}</td>
                            <td class="px-6 py-3 text-sm">{{ $item->category }}{{ $item->sub_category ? ' · ' . $item->sub_category : '' }}</td>
                            <td class="px-6 py-3 text-sm text-right">₹{{ number_format($item->selling_price, 2) }}</td>
                            <td class="px-6 py-3 text-sm text-right font-medium text-rose-600">{{ $item->created_at->diffInDays(now()) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
