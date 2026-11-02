<header class="items-insight-intro">
    <div>
        <h2>Stock Aging</h2>
        <p>Age of items currently in stock. Reports refresh every 10 minutes.</p>
    </div>
    <dl class="items-insight-totals">
        <div><dt>Average age</dt><dd>{{ number_format($agingSummary['avg_days']) }} <small>days</small></dd></div>
        <div><dt>Stock value</dt><dd>₹{{ number_format($totalValue, 0) }}</dd></div>
    </dl>
</header>
<div class="items-aging-overview">
    <section class="items-insight-panel" aria-label="Stock age distribution">
        <div class="items-insight-heading"><h3>Age distribution</h3><span>Items and selling value</span></div>
        <div class="items-age-composition">
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
            <div class="items-age-key">
                <div class="items-age-key-head" aria-hidden="true"><span>Age</span><span>Items / share</span><span>Value</span></div>
                @foreach($buckets as $label => $data)
                    @php $ageShare = $totalItems > 0 ? $data['count'] / $totalItems * 100 : 0; @endphp
                    <dl class="items-age-key-row" data-aging-bucket="{{ $label }}">
                        <dt><span class="items-insight-dot items-age-tone-{{ $loop->index }}" aria-hidden="true"></span>{{ $label }}</dt>
                        <dd><strong>{{ number_format($data['count']) }}</strong><span class="jf-sr-only"> items</span><span>{{ number_format($ageShare, 1) }}%</span></dd>
                        <dd><span class="jf-sr-only">Selling value </span>₹{{ number_format($data['value'], 0) }}</dd>
                    </dl>
                @endforeach
            </div>
        </div>
        @if($totalItems === 0)
            <p class="items-insight-empty" data-aging-empty>No in-stock items to analyse yet. New stock will appear in these age groups.</p>
        @endif
    </section>
    <section class="items-insight-panel items-age-review" aria-label="Stock over 90 days">
        <div class="items-insight-heading"><h3>Stock over 90 days</h3><span class="items-insight-review-tag">{{ number_format($riskPct, 1) }}% of items</span></div>
        <dl class="items-age-review-metrics">
            <div><dt>Items to review</dt><dd>{{ number_format($agingSummary['aged_count']) }}</dd></div>
            <div><dt>Selling value</dt><dd>₹{{ number_format($agedValue, 0) }}</dd><dd class="items-insight-note">{{ number_format($agedValueShare, 1) }}% of stock value</dd></div>
        </dl>
        <h4>Slow-moving categories <small>by selling value</small></h4>
        <ol class="items-age-category-ranks">
            @forelse($topSlowCategories as $categoryData)
                <li>
                    <span class="items-insight-rank" aria-hidden="true">{{ $loop->iteration }}</span>
                    <div><strong>{{ $categoryData['category'] }}</strong><span>{{ number_format($categoryData['count']) }} items · oldest {{ $categoryData['oldest_days'] }}d</span></div>
                    <span class="items-age-category-value">₹{{ number_format($categoryData['value'], 0) }}</span>
                </li>
            @empty
                <li class="items-insight-empty">{{ $agingSummary['aged_count'] > 0 ? 'No category breakdown available.' : 'No stock older than 90 days.' }}</li>
            @endforelse
        </ol>
    </section>
</div>
