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
        <h1 class="page-title">
            <span class="page-title-text">{{ $stockTitle }}</span>
            {{-- Phones: the open Stock page is the title; a tap lists the Stock pages (the header's section links,
                 shown as a dropdown; section-switch.js). --}}
            <button type="button" class="jf-section-switch" data-section-switch data-section-fit
                    aria-haspopup="true" aria-expanded="false" aria-controls="stock-sections">
                <span data-section-label>{{ $stockTitle }}</span>
                <x-icon name="chevron-down" size="18" />
            </button>
        </h1>
    </div>
    <div class="page-actions">
        @include('inventory.items.partials.navigation', ['placement' => 'header', 'active' => $active])
        <div class="stock-header-actions" @if($slot->isEmpty()) data-empty @endif>{{ $slot }}</div>
    </div>
</x-page-header>
