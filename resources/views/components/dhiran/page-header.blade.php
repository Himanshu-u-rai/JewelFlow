@props([
    'title' => null,
    'subtitle' => null,
    'badge' => null,
])

@php
    $slotMarkup = trim((string) $slot);
    // Dhiran views pass their title/actions as slot children (legacy style).
    $useSlotLayout = $title === null && $subtitle === null && $slotMarkup !== '';
@endphp

{{-- Dhiran-only page header. Deliberately carries NO ERP navigation button:
     the Dhiran shell (x-dhiran-layout) owns mobile navigation via its own
     top-bar hamburger + drawer. This component is title + actions only. --}}
<div {{ $attributes->merge(['class' => 'content-header']) }}>
    @if($useSlotLayout)
        {{ $slot }}
    @else
        <div class="min-w-0">
            <h1 class="page-title">{{ $title }}</h1>
            @if($subtitle)
                <p class="page-subtitle">{{ $subtitle }}</p>
            @endif
        </div>

        @if(isset($actions) || $badge)
            <div class="page-actions">
                @if($badge)
                    <span class="header-badge">{{ $badge }}</span>
                @endif
                {{ $actions ?? '' }}
            </div>
        @endif
    @endif
</div>
