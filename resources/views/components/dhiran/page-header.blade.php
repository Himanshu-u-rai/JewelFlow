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

{{-- Dhiran-only page header. The mobile nav toggle lives INSIDE this sticky
     header (not floating separately) so it never overlaps the title on scroll.
     It dispatches a window event the shell (x-dhiran-layout) listens for to open
     the drawer. On desktop the toggle is hidden and the sidebar is the nav. --}}
<div {{ $attributes->merge(['class' => 'content-header dh-page-header']) }}>
    <button type="button" class="dh-header-toggle"
            onclick="window.dispatchEvent(new CustomEvent('dhiran-menu'))"
            aria-label="Open menu" aria-controls="dh-sidebar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
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
