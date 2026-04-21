@props([
    'title' => null,
    'subtitle' => null,
    'badge' => null,
    'legacy' => null,
])

@php
    $slotMarkup = trim((string) $slot);
    $useLegacyLayout = (bool) $legacy || ($title === null && $subtitle === null && $slotMarkup !== '');
@endphp

<div {{ $attributes->merge(['class' => 'content-header']) }}>
    <div class="content-header-nav">
        <button type="button" class="mobile-menu-btn" data-mobile-menu-toggle="tenant" aria-controls="main-sidebar" aria-expanded="false" aria-label="Open navigation">
            <span class="drawer-toggle-icon drawer-toggle-icon-menu" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </span>
            <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </span>
        </button>
    </div>

    @if($useLegacyLayout)
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
