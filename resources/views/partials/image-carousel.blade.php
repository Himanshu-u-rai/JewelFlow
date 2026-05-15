{{--
    Shared image carousel with tap-to-zoom lightbox.

    Usage:
        @include('partials.image-carousel', [
            'urls'      => $imageUrls,     // array of absolute URLs (required)
            'alt'       => 'Ring design',  // alt text base (required)
            'idPrefix'  => 'product-2841', // unique DOM id prefix (required)
            'autoplay'  => true,           // optional, default true
        ])

    Renders nothing when $urls is empty (caller decides the fallback).
--}}

@php
    $urls     = $urls ?? [];
    $alt      = $alt ?? 'Image';
    $idPrefix = $idPrefix ?? 'img-carousel';
    $autoplay = $autoplay ?? true;
    $count    = count($urls);
@endphp

@if ($count > 0)
<div
    id="{{ $idPrefix }}-carousel"
    class="jf-carousel"
    x-data="{
        active: 0,
        total: {{ $count }},
        autoplayTimer: null,
        lightboxOpen: false,
        lightboxIndex: 0,
        syncFromScroll() {
            if (!this.$refs.track) return;
            const width = Math.max(this.$refs.track.clientWidth, 1);
            this.active = Math.max(0, Math.min(this.total - 1, Math.round(this.$refs.track.scrollLeft / width)));
        },
        goTo(index) {
            if (!this.$refs.track || this.total < 1) return;
            const target = ((index % this.total) + this.total) % this.total;
            this.$refs.track.scrollTo({
                left: this.$refs.track.clientWidth * target,
                behavior: 'smooth'
            });
            this.active = target;
        },
        prev() { this.goTo(this.active - 1); },
        next() { this.goTo(this.active + 1); },
        stopAutoPlay() {
            if (this.autoplayTimer) {
                clearInterval(this.autoplayTimer);
                this.autoplayTimer = null;
            }
        },
        startAutoPlay() {
            this.stopAutoPlay();
            if (!{{ $autoplay ? 'true' : 'false' }} || this.total <= 1) return;
            this.autoplayTimer = setInterval(() => {
                if (!document.hidden && !this.lightboxOpen) this.next();
            }, 4200);
        },
        openLightbox(index) {
            this.lightboxIndex = index;
            this.lightboxOpen = true;
            this.stopAutoPlay();
            document.body.style.overflow = 'hidden';
        },
        closeLightbox() {
            this.lightboxOpen = false;
            document.body.style.overflow = '';
            this.startAutoPlay();
        },
        lightboxPrev() { this.lightboxIndex = (this.lightboxIndex - 1 + this.total) % this.total; },
        lightboxNext() { this.lightboxIndex = (this.lightboxIndex + 1) % this.total; },
        init() {
            this.$nextTick(() => {
                this.syncFromScroll();
                this.startAutoPlay();
            });
        }
    }"
    x-init="init()"
    @mouseenter="stopAutoPlay()"
    @mouseleave="startAutoPlay()"
    @focusin="stopAutoPlay()"
    @focusout="startAutoPlay()"
    @keydown.window.escape="if (lightboxOpen) closeLightbox()"
    @keydown.window.arrow-left="if (lightboxOpen) lightboxPrev()"
    @keydown.window.arrow-right="if (lightboxOpen) lightboxNext()"
>
    <div
        class="jf-carousel-track"
        x-ref="track"
        @scroll.debounce.80ms="syncFromScroll()"
    >
        @foreach ($urls as $i => $url)
            <div class="jf-carousel-slide">
                <button
                    type="button"
                    class="jf-carousel-image-btn"
                    @click="openLightbox({{ $i }})"
                    aria-label="View {{ $alt }} {{ $i + 1 }} fullscreen"
                >
                    <img src="{{ $url }}" alt="{{ $alt }} {{ $i + 1 }}" class="jf-carousel-image" loading="{{ $i === 0 ? 'eager' : 'lazy' }}">
                    <span class="jf-carousel-zoom-hint" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                            <line x1="11" y1="8" x2="11" y2="14"/>
                            <line x1="8" y1="11" x2="14" y2="11"/>
                        </svg>
                    </span>
                </button>
            </div>
        @endforeach
    </div>

    @if ($count > 1)
        <button
            type="button"
            class="jf-carousel-nav jf-carousel-nav--prev"
            aria-label="Previous {{ $alt }} image"
            @click="prev()"
        >
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>
        <button
            type="button"
            class="jf-carousel-nav jf-carousel-nav--next"
            aria-label="Next {{ $alt }} image"
            @click="next()"
        >
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </button>
        <div class="jf-carousel-dots" aria-label="{{ $alt }} image selector">
            @foreach ($urls as $i => $url)
                <button
                    type="button"
                    aria-label="Show {{ $alt }} {{ $i + 1 }}"
                    :class="{ 'is-active': active === {{ $i }} }"
                    @click="goTo({{ $i }})"
                ></button>
            @endforeach
        </div>
    @endif

    {{-- ─── Lightbox overlay ─── --}}
    <div
        class="jf-lightbox"
        x-cloak
        x-show="lightboxOpen"
        x-transition.opacity
        @click.self="closeLightbox()"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $alt }} fullscreen image"
    >
        <button
            type="button"
            class="jf-lightbox-close"
            @click="closeLightbox()"
            aria-label="Close fullscreen view"
        >
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <div class="jf-lightbox-stage">
            @foreach ($urls as $i => $url)
                <img
                    src="{{ $url }}"
                    alt="{{ $alt }} {{ $i + 1 }}"
                    class="jf-lightbox-image"
                    x-show="lightboxIndex === {{ $i }}"
                    loading="lazy"
                >
            @endforeach
        </div>

        @if ($count > 1)
            <button
                type="button"
                class="jf-lightbox-nav jf-lightbox-nav--prev"
                @click.stop="lightboxPrev()"
                aria-label="Previous image"
            >
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <button
                type="button"
                class="jf-lightbox-nav jf-lightbox-nav--next"
                @click.stop="lightboxNext()"
                aria-label="Next image"
            >
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
            <div class="jf-lightbox-counter" aria-live="polite">
                <span x-text="lightboxIndex + 1"></span> / {{ $count }}
            </div>
        @endif
    </div>
</div>
@endif
