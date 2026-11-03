<x-app-layout>
    <x-stock-workspace-header active="sellers" />
    <div class="content-inner inventory-items-page">
        @include("inventory.items.partials.navigation", ["placement" => "body", "active" => "sellers"])
        <div class="items-analytics-tab items-sellers-register">
            @php
                $best = $sellersData['best'];
                $worst = $sellersData['worst'];
                $sellerPeriod = $sellersData['period'];
                $sellerByCategory = collect($best['by_category'] ?? []);
                $sellerWorstCategories = collect($worst ?? []);
                $sellerTotalSold = (int) $sellerByCategory->sum('sold_count');
                $sellerTotalRevenue = (float) $sellerByCategory->sum('total_revenue');
                $sellerTopCategory = $sellerByCategory->sortByDesc('sold_count')->first();
                $sellerWeakCategory = $sellerWorstCategories->sortBy('sold_count')->first();
                $sellerPeriodLabel = match ($sellerPeriod) {
                    '7' => 'Last 7 days',
                    '90' => 'Last 90 days',
                    '365' => 'Last year',
                    default => 'Last 30 days',
                };
            @endphp

            @include('inventory.items.partials.sell-trend-overview')

            <div class="items-sellers-tables-grid grid grid-cols-1 lg:grid-cols-2 gap-6">

                {{-- Best Sellers by Sub-Category --}}
                <details class="items-analytics-details">
                    <summary><span>Sub-category breakdown</span><span class="items-analytics-detail-count">{{ count($best['by_sub_category']) }} records</span><x-icon name="chevron-down" size="16" /></summary>
                    <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--sellers">
                        <table class="w-full items-data-table items-data-table--sellers jf-table">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sub-Cat</th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sold</th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($best['by_sub_category'] as $i => $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="jf-table-index">{{ $i + 1 }}</td>
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
                    <div class="items-seller-mobile-list" aria-label="Best sellers by sub-category mobile list">
                        @forelse($best['by_sub_category'] as $i => $row)
                            <article class="items-seller-mobile-card is-sub">
                                <div class="items-seller-mobile-head">
                                    <span>#{{ $i + 1 }}</span>
                                    <div>
                                        <h4>{{ $row['sub_category'] ?? '—' }}</h4>
                                        <p>{{ $row['category'] ?? '—' }}</p>
                                    </div>
                                </div>
                                <div class="items-seller-mobile-metrics">
                                    <div>
                                        <span>Sold</span>
                                        <strong>{{ number_format($row['sold_count'] ?? 0) }}</strong>
                                    </div>
                                    <div>
                                        <span>Revenue</span>
                                        <strong>₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</strong>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="items-seller-mobile-empty">No data.</div>
                        @endforelse
                    </div>
                </details>

                {{-- Worst Sellers --}}
                <details class="items-analytics-details">
                    <summary><span>Lowest movement categories</span><span class="items-analytics-detail-count">{{ count($worst) }} records</span><x-icon name="chevron-down" size="16" /></summary>
                    <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--sellers">
                        <table class="w-full items-data-table items-data-table--sellers jf-table">
                            <thead class="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Items Sold</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($worst as $i => $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="jf-table-index">{{ $i + 1 }}</td>
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
                    <div class="items-seller-mobile-list items-seller-mobile-list--wide" aria-label="Worst sellers mobile list">
                        @forelse($worst as $i => $row)
                            <article class="items-seller-mobile-card is-worst">
                                <div class="items-seller-mobile-head">
                                    <span>#{{ $i + 1 }}</span>
                                    <div>
                                        <h4>{{ $row['category'] ?? '—' }}</h4>
                                        <p>Lowest movement category</p>
                                    </div>
                                </div>
                                <div class="items-seller-mobile-metrics">
                                    <div>
                                        <span>Items Sold</span>
                                        <strong>{{ number_format($row['sold_count'] ?? 0) }}</strong>
                                    </div>
                                    <div>
                                        <span>Revenue</span>
                                        <strong>₹{{ number_format($row['total_revenue'] ?? 0, 0) }}</strong>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="items-seller-mobile-empty">No sales data in this period.</div>
                        @endforelse
                    </div>
                </details>
            </div>
        </div>
    </div>
</x-app-layout>
