@php
    $isRetailer = $isRetailer ?? auth()->user()->shop?->isRetailer();
    $active = $active ?? null;
    $inlineViews = $active === null;
    $reorderAlertCount = $reorderAlertCount ?? ($isRetailer ? app(\App\Services\ReorderAlertService::class)->alertCount(auth()->user()->shop_id) : 0);
@endphp
<nav class="items-page-nav items-{{ $placement }}-nav stock-workspace-nav" aria-label="Stock navigation">
    <div class="ui-toggle-strip items-view-toggle">
        <div class="items-primary-views" @if($inlineViews && $isRetailer) role="tablist" aria-label="Stock views" @endif>
            @foreach(['items' => ['Stock Items', 'package'], 'aging' => ['Stock Aging', 'clock'], 'sellers' => ['Sell Trend', 'trending-up']] as $key => [$label, $icon])
                @continue(!$isRetailer && $key !== 'items')
                @if($inlineViews && $isRetailer)
                    <button type="button" role="tab" data-stock-section="{{ $key }}"
                            @click="view = '{{ $key }}'" :aria-selected="view === '{{ $key }}'" :aria-pressed="view === '{{ $key }}'"
                            :class="{ 'is-active': view === '{{ $key }}' }" class="items-view-tab">
                        <x-icon :name="$icon" size="14" />{{ $label }}
                    </button>
                @elseif(!$inlineViews)
                    <a href="{{ route('inventory.items.index', $key === 'items' ? [] : ['view' => $key]) }}" data-stock-section="{{ $key }}" class="items-view-tab">
                        <x-icon :name="$icon" size="14" />{{ $label }}
                    </a>
                @endif
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
