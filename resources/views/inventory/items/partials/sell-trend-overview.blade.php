<header class="items-insight-intro items-sell-intro">
    <div>
        <h2>Sell Trend</h2>
        <p>{{ $sellerPeriodLabel }} · up to 10 top categories · reports refresh every 10 minutes.</p>
    </div>
    <form method="GET" action="{{ route('inventory.items.index') }}" class="items-sell-period" data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
        <input type="hidden" name="view" value="sellers">
        <div>
            <label for="stock-seller-period">Period</label>
            <select id="stock-seller-period" name="seller_period" class="items-analytics-select">
                @foreach(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'] as $period => $label)
                    <option value="{{ $period }}" @selected((string) $period === $sellerPeriod)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        @if(request()->has('seller_period'))
            <a href="{{ route('inventory.items.index', ['view' => 'sellers']) }}" class="btn btn-secondary btn-sm">Clear</a>
        @endif
    </form>
</header>
<section class="items-insight-panel" aria-label="Sell trend summary">
    <dl class="items-sell-summary">
        <div><dt>Units · shown categories</dt><dd>{{ number_format($sellerTotalSold) }}</dd></div>
        <div><dt>Revenue · shown categories</dt><dd>₹{{ number_format($sellerTotalRevenue, 0) }}</dd></div>
        <div><dt>Top category by units</dt><dd>{{ $sellerTopCategory['category'] ?? '—' }}</dd></div>
        <div><dt>Lowest movement category</dt><dd>{{ $sellerWeakCategory['category'] ?? '—' }}</dd></div>
    </dl>
</section>
<section class="items-insight-panel" aria-label="Category units and revenue comparison">
    <div class="items-insight-heading"><h3>Units vs revenue</h3><span>{{ number_format($sellerByCategory->count()) }} shown categories</span></div>
    @if($sellerByCategory->isEmpty())
        <p class="items-insight-empty" data-sales-empty>No sales data in this period. Choose another period to compare category movement.</p>
    @else
        @php
            // Presentation coordinates only; the existing report provides every figure.
            $chartMaxUnits = max(1, (int) $sellerByCategory->max('sold_count'));
            $chartMaxRevenue = max(1, (float) $sellerByCategory->max('total_revenue'));
            $chartMinRevenue = min(0, (float) $sellerByCategory->min('total_revenue'));
            $chartRevenueRange = max(1, $chartMaxRevenue - $chartMinRevenue);
            $salesPlotGroups = $sellerByCategory->map(fn ($row, $i) => array_merge($row, ['rank' => $i + 1]))
                ->groupBy(fn ($row) => $row['sold_count'].'|'.(float) $row['total_revenue']);
            $salesAxisMoney = fn ($value) => '₹'.match (true) {
                abs($value) >= 10000000 => number_format($value / 10000000, 1).'cr',
                abs($value) >= 100000 => number_format($value / 100000, 1).'L',
                abs($value) >= 1000 => number_format($value / 1000, 1).'k',
                default => number_format($value, 0),
            };
        @endphp
        <figure class="items-sell-comparison">
            <div class="items-sell-plot-scroll" tabindex="0" role="region" aria-label="Category comparison chart; exact values follow in the category tables">
                <svg class="items-sell-plot" viewBox="0 0 520 260" role="img" aria-label="Category comparison: units sold on the horizontal axis, revenue on the vertical axis">
                    <text class="items-sell-axis-title" x="54" y="14">Revenue</text>
                    @foreach([0, 0.5, 1] as $tick)
                        @php $tickY = 24 + 180 * $tick; @endphp
                        <line class="items-sell-gridline" x1="54" y1="{{ $tickY }}" x2="474" y2="{{ $tickY }}" />
                        <text class="items-sell-tick" x="46" y="{{ $tickY + 4 }}" text-anchor="end">{{ $salesAxisMoney($chartMaxRevenue - ($chartMaxRevenue - $chartMinRevenue) * $tick) }}</text>
                    @endforeach
                    <line class="items-sell-axis" x1="54" y1="24" x2="54" y2="204" />
                    <line class="items-sell-axis" x1="54" y1="204" x2="474" y2="204" />
                    <text class="items-sell-tick" x="54" y="224" text-anchor="middle">0</text>
                    <text class="items-sell-tick" x="264" y="224" text-anchor="middle">{{ number_format($chartMaxUnits / 2, 1) }}</text>
                    <text class="items-sell-tick" x="474" y="224" text-anchor="middle">{{ number_format($chartMaxUnits) }}</text>
                    <text class="items-sell-axis-title" x="264" y="250" text-anchor="middle">Units sold</text>
                    @foreach($salesPlotGroups as $group)
                        @php
                            $point = $group->first();
                            $pointX = 54 + (int) $point['sold_count'] / $chartMaxUnits * 420;
                            $pointY = 24 + ($chartMaxRevenue - (float) $point['total_revenue']) / $chartRevenueRange * 180;
                        @endphp
                        <g data-sales-point>
                            <title>{{ $group->pluck('category')->join(', ') }}: {{ number_format($point['sold_count']) }} units, ₹{{ number_format($point['total_revenue'], 0) }} each</title>
                            <circle class="items-sell-point" data-sales-units="{{ $point['sold_count'] }}" cx="{{ $pointX }}" cy="{{ $pointY }}" r="6" />
                            <text class="items-sell-point-label" x="{{ $pointX }}" y="{{ $pointY + ($pointY < 46 ? 20 : -12) }}" text-anchor="middle">{{ $point['rank'] }}{{ $group->count() > 1 ? ' +'.($group->count() - 1) : '' }}</text>
                        </g>
                    @endforeach
                </svg>
            </div>
            <figcaption>More units to the right, more revenue above. Point numbers match category ranks below; matching values share a point. This compares categories, not change over time.</figcaption>
        </figure>
    @endif
</section>
