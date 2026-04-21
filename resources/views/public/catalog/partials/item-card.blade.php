@php
    $imageUrl = $imageUrls[$item->id] ?? null;
    $showPrices = $catalogSettings->show_prices ?? true;
    $showWeights = $catalogSettings->show_weights ?? true;
    $token = $item->share_token;
@endphp

<a href="{{ $token ? route('catalog.website.product', [$shop->catalog_slug, $token]) : '#' }}" class="item-card">
    <div class="item-card-img-wrap">
        @if($imageUrl)
            <img src="{{ $imageUrl }}" alt="{{ $item->design ?? $item->barcode }}" class="item-card-img" loading="lazy">
        @else
            <div class="item-card-placeholder">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity="0.3">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                </svg>
            </div>
        @endif
        @if($item->category)
            <span class="item-card-badge">{{ $item->category }}</span>
        @endif
    </div>
    <div class="item-card-body">
        <h3 class="item-card-name">{{ $item->design ?? $item->barcode }}</h3>
        @if($item->sub_category)
            <p class="item-card-sub">{{ $item->sub_category }}</p>
        @endif
        <div class="item-card-meta">
            @if($showPrices && $item->selling_price)
                <span class="item-card-price">&#8377;{{ number_format((float) $item->selling_price, 0) }}</span>
            @endif
            @if($showWeights && $item->gross_weight)
                <span class="item-card-weight">{{ number_format((float) $item->gross_weight, 2) }}g</span>
            @endif
            @if($item->purity)
                <span class="item-card-purity">{{ $item->purity }}K</span>
            @endif
        </div>
    </div>
</a>
