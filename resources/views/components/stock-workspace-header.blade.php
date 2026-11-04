@props(['active' => 'items'])

@php
    $stockTitle = match ($active) {
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
        <h1 class="page-title">{{ $stockTitle }}</h1>
    </div>
    <div class="page-actions">
        @include('inventory.items.partials.navigation', ['placement' => 'header', 'active' => $active])
        <div class="stock-header-actions" @if($slot->isEmpty()) data-empty @endif>{{ $slot }}</div>
    </div>
</x-page-header>
