@php
    $isRetailer = $isRetailer ?? auth()->user()->shop?->isRetailer();
    $active = $active ?? 'items';
    $reorderAlertCount = $reorderAlertCount ?? ($isRetailer ? app(\App\Services\ReorderAlertService::class)->alertCount(auth()->user()->shop_id) : 0);
@endphp
<nav class="items-page-nav items-{{ $placement }}-nav stock-workspace-nav" aria-label="Stock navigation"
     @if($placement === 'header')
         x-data
         x-init="$nextTick(() => { if (window.matchMedia('(max-width: 768px)').matches) { const current = $el.querySelector('[aria-current=page]'); if (current) $el.scrollLeft += current.getBoundingClientRect().left - $el.getBoundingClientRect().left - 4; } })"
     @endif>
    <div class="ui-toggle-strip items-view-toggle">
        <div class="items-primary-views">
            @foreach(['items' => ['Stock Items', 'package', 'inventory.items.index'], 'aging' => ['Stock Aging', 'clock', 'inventory.stock-aging'], 'sellers' => ['Sell Trend', 'trending-up', 'inventory.sell-trend']] as $key => [$label, $icon, $route])
                @continue(!$isRetailer && $key !== 'items')
                <a href="{{ route($route) }}" data-stock-section="{{ $key }}" @if($active === $key) aria-current="page" @endif class="items-view-tab {{ $active === $key ? 'is-active' : '' }}">
                    <x-icon :name="$icon" size="14" />{{ $label }}
                </a>
            @endforeach
        </div>
        <div class="items-page-links">
            @foreach(['categories' => ['Categories', 'tag', 'categories.index'], 'tags' => ['Tag Printing', 'printer', 'tags.index'], 'reorder' => ['Reorder Alerts', 'triangle-alert', 'reorder.index']] as $key => [$label, $icon, $route])
                @continue(!$isRetailer && $key !== 'categories')
                <a href="{{ route($route) }}" data-stock-section="{{ $key }}" @if($active === $key) aria-current="page" @endif
                   class="items-page-link {{ $active === $key ? 'is-active' : '' }}">
                    <x-icon :name="$icon" size="14" />{{ $label }}
                    @if($key === 'reorder' && $reorderAlertCount > 0)
                        <span class="items-page-link-count">{{ $reorderAlertCount }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</nav>
