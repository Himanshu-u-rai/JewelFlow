<x-app-layout>
    <x-stock-workspace-header active="aging" />
    <div class="content-inner inventory-items-page">
        @include("inventory.items.partials.navigation", ["placement" => "body", "active" => "aging"])
        <div class="items-analytics-tab items-aging-register">
            @php
                // Direct access on purpose: stockAging() always returns both keys,
                // and a ?? fallback here would paper over a shape change with a
                // silently empty analytics tab instead of a visible failure.
                $buckets = $stockAgingData['buckets'];
                $agingSummary = $stockAgingData['summary'];
                $bucketCollection = collect($buckets);
                $totalItems = $bucketCollection->sum('count');
                $totalValue = (float) $bucketCollection->sum('value');
                $agedValue = (float) (($buckets['91-180 days']['value'] ?? 0) + ($buckets['180+ days']['value'] ?? 0));
                $agedValueShare = $totalValue > 0 ? min(100, ($agedValue / $totalValue) * 100) : 0;
                $riskPct = min(100, max(0, (float) ($agingSummary['aged_pct'] ?? 0)));
                $slowItems = collect($buckets['91-180 days']['items'] ?? [])->merge($buckets['180+ days']['items'] ?? []);
                $topSlowCategories = $slowItems
                    ->groupBy(fn ($item) => $item->category ?: 'Uncategorized')
                    ->map(function ($group, $category) {
                        return [
                            'category' => $category,
                            'count' => $group->count(),
                            'value' => (float) $group->sum('selling_price'),
                            'oldest_days' => (int) $group->max(fn ($item) => $item->created_at->diffInDays(now())),
                        ];
                    })
                    ->sortByDesc('value')
                    ->take(4);
            @endphp

            @include('inventory.items.partials.aging-overview')

            @if($slowItems->count())
            <div class="items-table-card items-table-card--slow items-aging-slow-card">
                <div class="items-analytics-card-head">
                    <div>
                        <h3 class="items-analytics-card-title">Slow Moving Stock</h3>
                        <p class="items-analytics-card-subtitle">Items above 90 days in stock, limited to the first 50 records.</p>
                    </div>
                    <span class="items-analytics-card-pill">{{ number_format($slowItems->count()) }} flagged</span>
                </div>
                <div class="overflow-x-auto ui-table-shell items-table-shell items-table-shell--slow">
                    <table class="w-full items-data-table items-data-table--slow jf-table">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Barcode</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Category</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                                    {{ ($stockValueDisplay ?? 'total') === 'per_gram' ? 'Rate/g' : 'Price' }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Days in Stock</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach($slowItems->take(50) as $sItem)
                            <tr class="hover:bg-gray-50">
                                <td class="jf-table-index">{{ $loop->iteration }}</td>
                                <td class="px-6 py-3 text-sm font-mono">{{ $sItem->barcode }}</td>
                                <td class="px-6 py-3 text-sm">{{ $sItem->category }}{{ $sItem->sub_category ? ' · ' . $sItem->sub_category : '' }}</td>
                                <td class="px-6 py-3 text-sm text-right">
                                    @if(($stockValueDisplay ?? 'total') === 'per_gram' && $sItem->net_metal_weight > 0)
                                        ₹{{ number_format($sItem->selling_price / $sItem->net_metal_weight, 0) }}<span class="text-xs text-gray-400">/g</span>
                                    @else
                                        ₹{{ number_format($sItem->selling_price, 2) }}
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-sm text-right font-medium text-rose-600">{{ (int) $sItem->created_at->diffInDays(now()) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="items-aging-mobile-list" aria-label="Slow moving stock mobile list">
                    @foreach($slowItems->take(50) as $sItem)
                        @php
                            $daysInStock = (int) $sItem->created_at->diffInDays(now());
                            $agingPrice = (($stockValueDisplay ?? 'total') === 'per_gram' && $sItem->net_metal_weight > 0)
                                ? '₹' . number_format($sItem->selling_price / $sItem->net_metal_weight, 0) . '/g'
                                : '₹' . number_format($sItem->selling_price, 2);
                        @endphp
                        <article class="items-aging-mobile-card {{ $daysInStock > 180 ? 'is-critical' : 'is-risk' }}">
                            <div class="items-aging-mobile-head">
                                <div>
                                    <h3><span class="jf-record-index">#{{ $loop->iteration }}</span> {{ $sItem->barcode }}</h3>
                                    <p>{{ $sItem->category }}{{ $sItem->sub_category ? ' · ' . $sItem->sub_category : '' }}</p>
                                </div>
                                <span>{{ $daysInStock }}d</span>
                            </div>
                            <div class="items-aging-mobile-metrics">
                                <div>
                                    <span>{{ ($stockValueDisplay ?? 'total') === 'per_gram' ? 'Rate' : 'Price' }}</span>
                                    <strong>{{ $agingPrice }}</strong>
                                </div>
                                <div>
                                    <span>Bucket</span>
                                    <strong>{{ $daysInStock > 180 ? '180+ days' : '91-180 days' }}</strong>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
