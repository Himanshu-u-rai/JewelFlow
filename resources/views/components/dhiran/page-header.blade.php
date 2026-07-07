@props([
    'title' => null,
    'subtitle' => null,
    'badge' => null,
])

@php
    $slotMarkup = trim((string) $slot);
    // Dhiran views pass their title/actions as slot children (legacy style).
    $useSlotLayout = $title === null && $subtitle === null && $slotMarkup !== '';
    $hasHeaderActions = ($useSlotLayout && str_contains($slotMarkup, 'page-actions')) || isset($actions) || $badge;
@endphp

{{-- Dhiran-only page header. The shell keeps this as a full-width sticky top
     band, matching the ERP header behavior without floating controls. --}}
<div {{ $attributes->merge(['class' => 'content-header dh-page-header', 'data-legacy-header-normalized' => 'true']) }}
     x-data="{
        dhHeaderActionsOpen: false,
        dhHeaderHasFab: false,
        dhHeaderSource: null,
        dhHeaderFab: null,
        dhHeaderFabNav: null,
        dhHeaderOriginalActions: [],
        dhHeaderMedia: null,
        initDhHeaderActions() {
            this.dhHeaderSource = this.$el.querySelector('.dh-page-header-content > .page-actions');
            this.dhHeaderFab = this.$el.querySelector('.dh-header-mobile-fab');
            this.dhHeaderFabNav = this.dhHeaderFab ? this.dhHeaderFab.querySelector('[data-dh-header-fab-nav]') : null;
            if (!this.dhHeaderSource || !this.dhHeaderFabNav) return;

            this.dhHeaderOriginalActions = Array.from(this.dhHeaderSource.children);
            this.dhHeaderMedia = window.matchMedia('(max-width: 720px)');
            this.dhSyncHeaderActions();

            const onChange = () => this.dhSyncHeaderActions();
            if (this.dhHeaderMedia.addEventListener) {
                this.dhHeaderMedia.addEventListener('change', onChange);
            } else {
                this.dhHeaderMedia.addListener(onChange);
            }

            this.$nextTick(() => {
                if (this.dhHeaderFab && this.dhHeaderFab.parentElement !== document.body) {
                    document.body.appendChild(this.dhHeaderFab);
                    this.dhHeaderHasFab = false;
                    this.dhSyncHeaderActions();
                }
            });
        },
        dhIsPinnedHeaderAction(el) {
            const label = (el.textContent || '').trim().replace(/\s+/g, ' ').toLowerCase();
            return el.dataset.dhMobilePin === 'true' || label.startsWith('back') || label === 'dashboard' || label === 'all loans';
        },
        dhPreparePinnedHeaderAction(el) {
            const label = (el.textContent || '').trim().replace(/\s+/g, ' ').toLowerCase();
            if (label.startsWith('back')) {
                el.dataset.dhMobileLabel = 'Back';
            } else {
                delete el.dataset.dhMobileLabel;
            }
        },
        dhSyncHeaderActions() {
            if (!this.dhHeaderSource || !this.dhHeaderFabNav || !this.dhHeaderMedia) return;

            this.dhHeaderActionsOpen = false;
            this.dhHeaderSource.classList.remove('dh-has-pinned-actions');

            if (!this.dhHeaderMedia.matches) {
                this.dhHeaderOriginalActions.forEach((el) => {
                    el.classList.remove('dh-header-pinned-action');
                    delete el.dataset.dhMobileLabel;
                    this.dhHeaderSource.appendChild(el);
                });
                this.dhHeaderHasFab = false;
                return;
            }

            let pinnedCount = 0;
            let fabCount = 0;
            this.dhHeaderOriginalActions.forEach((el) => {
                if (this.dhIsPinnedHeaderAction(el)) {
                    el.classList.add('dh-header-pinned-action');
                    this.dhPreparePinnedHeaderAction(el);
                    this.dhHeaderSource.appendChild(el);
                    pinnedCount += 1;
                } else {
                    el.classList.remove('dh-header-pinned-action');
                    delete el.dataset.dhMobileLabel;
                    this.dhHeaderFabNav.appendChild(el);
                    fabCount += 1;
                }
            });

            this.dhHeaderSource.classList.toggle('dh-has-pinned-actions', pinnedCount > 0);
            this.dhHeaderHasFab = fabCount > 0;
        },
        dhRestoreHeaderActions() {
            if (!this.dhHeaderSource || !this.dhHeaderOriginalActions.length) return;
            this.dhHeaderOriginalActions.forEach((el) => {
                el.classList.remove('dh-header-pinned-action');
                delete el.dataset.dhMobileLabel;
                this.dhHeaderSource.appendChild(el);
            });
            if (this.dhHeaderFab && this.dhHeaderFab.parentElement === document.body) {
                this.$el.appendChild(this.dhHeaderFab);
            }
        }
     }"
     x-init="initDhHeaderActions()"
     :class="{ 'dh-actions-open': dhHeaderActionsOpen }"
     @keydown.escape.window="dhHeaderActionsOpen = false"
     @resize.window.debounce.150ms="dhSyncHeaderActions()"
     @turbo:before-cache.window="dhRestoreHeaderActions()"
     @click.outside="if (!$event.target.closest('.dh-header-mobile-fab')) dhHeaderActionsOpen = false"
     x-on:click="if ($event.target.closest('[data-dh-header-fab-nav] a, [data-dh-header-fab-nav] button')) dhHeaderActionsOpen = false">
    <div class="dh-page-header-content">
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

    @if($hasHeaderActions)
        <div class="dh-header-mobile-fab" x-show="dhHeaderHasFab" x-cloak>
            <div class="dh-header-mobile-fab-shell"
                 :class="{ 'is-open': dhHeaderActionsOpen }"
                 @click.outside="dhHeaderActionsOpen = false"
                 x-on:click="if ($event.target.closest('[data-dh-header-fab-nav] a, [data-dh-header-fab-nav] button')) dhHeaderActionsOpen = false">
                <nav class="dh-header-mobile-fab-nav" data-dh-header-fab-nav aria-label="Page actions"></nav>
                <button type="button" class="dh-header-mobile-fab-toggle"
                        x-on:click.stop="dhHeaderActionsOpen = !dhHeaderActionsOpen"
                        x-bind:aria-expanded="dhHeaderActionsOpen.toString()"
                        aria-label="Toggle page actions">
                    <span class="dh-header-mobile-fab-bars" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>
        </div>
    @endif
</div>
