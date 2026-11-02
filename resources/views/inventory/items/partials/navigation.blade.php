<nav class="items-page-nav items-{{ $placement }}-nav" aria-label="Stock navigation">
    @if($isRetailer)
    <div class="ui-toggle-strip items-view-toggle inline-flex flex-wrap items-center gap-1" role="tablist" aria-label="Stock views">
    <button type="button" role="tab" @click="view = 'items'" :aria-selected="view === 'items'" :aria-pressed="view === 'items'" :class="{ 'is-active': view === 'items' }" class="items-view-tab inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold">
        <x-icon name="package" size="14" />
        Stock Items
    </button>
    <button type="button" role="tab" @click="view = 'aging'" :aria-selected="view === 'aging'" :aria-pressed="view === 'aging'" :class="{ 'is-active': view === 'aging' }" class="items-view-tab inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold">
        <x-icon name="clock" size="14" />
        Stock Aging
    </button>
    <button type="button" role="tab" @click="view = 'sellers'" :aria-selected="view === 'sellers'" :aria-pressed="view === 'sellers'" :class="{ 'is-active': view === 'sellers' }" class="items-view-tab inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold">
        <x-icon name="trending-up" size="14" />
        Sell Trend
    </button>
    </div>
    @endif
    <div class="items-page-links">
    <a href="{{ route('categories.index') }}" class="items-page-link inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold">
        <x-icon name="tag" size="14" />
        Categories
        <x-icon name="chevron-right" size="14" class="items-page-link-chevron" />
    </a>
    @if($isRetailer)
    <a href="{{ route('tags.index') }}" class="items-page-link inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold">
        <x-icon name="printer" size="14" />
        Tag Printing
        <x-icon name="chevron-right" size="14" class="items-page-link-chevron" />
    </a>
    <a href="{{ route('reorder.index') }}" class="items-page-link inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold">
        <x-icon name="triangle-alert" size="14" />
        Reorder Alerts
        @if($reorderAlertCount > 0)
            <span class="items-page-link-count">{{ $reorderAlertCount }}</span>
        @endif
        <x-icon name="chevron-right" size="14" class="items-page-link-chevron" />
    </a>
    @endif
    </div>
</nav>
