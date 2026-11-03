@props(['active'])

<x-page-header title="Stock / Items" {{ $attributes->class(['inventory-items-header']) }}>
    <x-slot:actions>
        @include('inventory.items.partials.navigation', ['placement' => 'header', 'active' => $active])
        {{ $slot }}
    </x-slot:actions>
</x-page-header>
