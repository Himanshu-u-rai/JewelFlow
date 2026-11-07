<section class="items-analysis-board" aria-label="Stock age distribution">
    <header class="items-analysis-header">
        <div><h2>Stock Aging</h2><p>How long your available stock has been on the shelf.</p></div>
        <dl class="items-analysis-stats">
            <div><dt>Average age</dt><dd>{{ number_format($agingSummary['avg_days']) }} <small>days</small></dd></div>
            @can('reports.money')
            <div><dt>Selling value in stock</dt><dd>₹{{ number_format($totalValue, 0) }}</dd></div>
            @endcan
        </dl>
    </header>
    <div class="items-aging-layout">
        <figure class="items-age-figure">
            <div class="items-age-ring">
                <svg viewBox="0 0 120 120" aria-hidden="true" focusable="false">
                    <circle class="items-age-track" cx="60" cy="60" r="50" />
                    @php $ageOffset = 0; @endphp
                    @foreach($buckets as $label => $data)
                        @php $ageShare = $totalItems > 0 ? $data['count'] / $totalItems * 100 : 0; @endphp
                        @if($ageShare > 0)
                            <circle class="items-age-segment items-age-tone-{{ $loop->index }}" data-aging-segment="{{ $label }}"
                                    cx="60" cy="60" r="50" pathLength="100" stroke-dasharray="{{ $ageShare }} 100" stroke-dashoffset="{{ -$ageOffset }}" />
                        @endif
                        @php $ageOffset += $ageShare; @endphp
                    @endforeach
                </svg>
                <dl class="items-age-ring-total"><dt>In-stock items</dt><dd data-aging-total>{{ number_format($totalItems) }}</dd></dl>
            </div>
            <figcaption>Age distribution</figcaption>
        </figure>
        <div class="items-age-key">
            <div class="items-age-key-head" aria-hidden="true"><span>Stock age</span><span>Items</span><span>Share</span>@can('reports.money')<span>Selling value</span>@endcan</div>
            @foreach($buckets as $label => $data)
                @php $ageShare = $totalItems > 0 ? $data['count'] / $totalItems * 100 : 0; @endphp
                <dl class="items-age-key-row" data-aging-bucket="{{ $label }}">
                    <dt><span class="items-insight-dot items-age-tone-{{ $loop->index }}" aria-hidden="true"></span>{{ $label }}</dt>
                    <dd><span class="jf-sr-only">Items </span><strong>{{ number_format($data['count']) }}</strong></dd>
                    <dd><span class="jf-sr-only">Share </span>{{ number_format($ageShare, 1) }}%</dd>
                    @can('reports.money')<dd><span class="jf-sr-only">Selling value </span>₹{{ number_format($data['value'], 0) }}</dd>@endcan
                </dl>
            @endforeach
        </div>
        <aside class="items-age-status" aria-label="Stock over 90 days">
            @if($totalItems === 0)
                <span class="items-age-status-icon" aria-hidden="true"><x-icon name="package" size="22" /></span>
                <h3>Your aging report starts here</h3>
                <p data-aging-empty>No in-stock items yet. Add stock to see its age here.</p>
            @elseif($agingSummary['aged_count'] === 0)
                <span class="items-age-status-icon is-clear" aria-hidden="true"><x-icon name="circle-check" size="22" /></span>
                <h3 data-aging-clear>No overdue stock</h3>
                <p>None of your {{ number_format($totalItems) }} in-stock items are older than 90 days.</p>
                <span class="items-age-status-label">All stock within 90 days</span>
            @else
                <h3>Stock over 90 days</h3>
                <dl class="items-age-attention" data-aging-review>
                    <div><dt>Items to review</dt><dd>{{ number_format($agingSummary['aged_count']) }} <small>{{ number_format($riskPct, 1) }}%</small></dd></div>
                    @can('reports.money')<div><dt>Selling value</dt><dd>₹{{ number_format($agedValue, 0) }}</dd></div>@endcan
                </dl>
                @can('reports.money')<p>{{ number_format($agedValueShare, 1) }}% of stock value sits in these older items.</p>@endcan
                <ol class="items-age-category-ranks">
                    @foreach($topSlowCategories as $categoryData)
                        <li><div><strong>{{ $categoryData['category'] }}</strong><span>{{ number_format($categoryData['count']) }} items · oldest {{ $categoryData['oldest_days'] }}d</span></div>@can('reports.money')<span>₹{{ number_format($categoryData['value'], 0) }}</span>@endcan</li>
                    @endforeach
                </ol>
            @endif
        </aside>
    </div>
    <footer class="items-analysis-footer">In-stock items only · age is measured from the stock record's creation date · refreshed every 10 minutes</footer>
</section>
