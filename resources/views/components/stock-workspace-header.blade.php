@props(['active' => null])

@php
    $stockTitle = match ($active ?? request('view', 'items')) {
        'aging' => 'Stock Aging',
        'sellers' => 'Sell Trend',
        'categories' => 'Categories',
        'tags' => 'Tag Printing',
        'reorder' => 'Reorder Alerts',
        default => 'Stock Items',
    };
@endphp

<x-page-header legacy {{ $attributes->class(['inventory-items-header']) }}>
    <div class="min-w-0 stock-header-title">
        <h1 class="page-title" @if($active === null) x-text="({ items: 'Stock Items', aging: 'Stock Aging', sellers: 'Sell Trend' })[view] || 'Stock Items'" @endif>{{ $stockTitle }}</h1>
    </div>
    <div class="page-actions">
        @include('inventory.items.partials.navigation', ['placement' => 'header', 'active' => $active])
        <div class="stock-header-actions">{{ $slot }}</div>
    </div>
</x-page-header>
