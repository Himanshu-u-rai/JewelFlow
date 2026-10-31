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
                <x-icon name="menu" size="22" />
            </span>
            <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                <x-icon name="x" size="22" stroke-width="2.2" />
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

    <x-notice-bell />
</div>
