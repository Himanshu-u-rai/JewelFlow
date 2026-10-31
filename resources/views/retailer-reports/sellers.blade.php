<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Best & Worst Sellers</h1>
            <p class="text-sm text-gray-500 mt-1">Sales analytics by category</p>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('report.sellers') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Period</label>
                    <select name="period" class="rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <option value="7" {{ $period === '7' ? 'selected' : '' }}>Last 7 days</option>
                        <option value="30" {{ $period === '30' ? 'selected' : '' }}>Last 30 days</option>
                        <option value="90" {{ $period === '90' ? 'selected' : '' }}>Last 90 days</option>
                        <option value="365" {{ $period === '365' ? 'selected' : '' }}>Last year</option>
                    </select>
                </div>
                @if(request()->has('period'))
                    <a href="{{ route('report.sellers') }}" class="btn btn-secondary btn-sm"><x-icon name="x" size="14" class="mr-1" />Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="funnel" size="14" class="mr-1" />Apply</button>
                @endif
            </form>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Best Sellers by Category --}}
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-green-800">Best Sellers — by Category</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full jf-table">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sold</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse($best['by_category'] as $i => $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $row['category'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-right">{{ $row['sold_count'] }}</td>
                                <td class="px-4 py-3 text-sm text-right text-green-700">₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="jf-table-empty">No sales data in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Best Sellers by Sub-Category --}}
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-amber-800">Best Sellers — by Sub-Category</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full jf-table">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sub-Cat</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sold</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse($best['by_sub_category'] as $i => $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-4 py-3 text-sm text-gray-900">{{ $row['category'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $row['sub_category'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-sm text-right">{{ $row['sold_count'] }}</td>
                                <td class="px-4 py-3 text-sm text-right text-green-700">₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="jf-table-empty">No data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Worst Sellers --}}
            <div class="lg:col-span-2 bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-rose-800">Worst Sellers — Lowest Movement Categories</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full jf-table">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Items Sold</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @forelse($worst as $i => $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $row['category'] ?? '—' }}</td>
                                <td class="px-6 py-3 text-sm text-right text-rose-600 font-medium">{{ $row['sold_count'] }}</td>
                                <td class="px-6 py-3 text-sm text-right">₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="4" class="jf-table-empty">No sales data in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
