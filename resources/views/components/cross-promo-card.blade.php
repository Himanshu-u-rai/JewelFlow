@props([
    'heading',
    'body',
    'cta',
    'url',
    'key' => 'default',
    'realm' => null,   // 'erp' | 'dhiran' — used to pick up an admin override
])

@php
    // Admin override: a platform 'cross_promo' announcement for this realm replaces
    // the default heading/body/cta/url. Falls back to the passed-in defaults.
    $override = $realm ? \App\Models\Platform\PlatformAnnouncement::crossPromoFor($realm) : null;
    if ($override) {
        $heading = $override->title ?: $heading;
        $body    = $override->body ?: $body;
        $cta     = $override->cta_label ?: $cta;
        $url     = $override->cta_url ?: $url;
    }
@endphp

{{-- Cross-promotion as a dismissable toast/pop notification (not a body card).
     It is fixed-position (overlays the corner), so it NEVER pushes page content.
     It can be dismissed (×) and shows at most once per calendar day per promo
     (tracked in localStorage by `key`). The CTA links to the OTHER product's
     separate register front door — it never grants an edition or links accounts.
     data-turbo-frame="_top" for the cross-host navigation. --}}
<div
    class="cross-promo-toast"
    data-cross-promo
    data-cross-promo-key="cross_promo_seen:{{ $key }}"
    role="status"
    aria-live="polite"
    hidden
>
    <style>
        .cross-promo-toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 1080; /* above content, below modals */
            width: min(360px, calc(100vw - 32px));
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 16px 16px 18px;
            border: 1px solid var(--jf-border, #e2e8f0);
            border-radius: 14px;
            background: var(--jf-surface, #fff);
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.16), 0 2px 6px rgba(15, 23, 42, 0.06);
            /* Enter animation — ease-out, slide up + fade in (not from scale 0). */
            transform: translateY(12px);
            opacity: 0;
            transition: transform 260ms cubic-bezier(0.23, 1, 0.32, 1), opacity 260ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .cross-promo-toast[data-show] { transform: translateY(0); opacity: 1; }
        /* Exit — snappier than enter. */
        .cross-promo-toast[data-hide] {
            transform: translateY(12px);
            opacity: 0;
            transition: transform 180ms cubic-bezier(0.23, 1, 0.32, 1), opacity 180ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .cross-promo-toast__body { flex: 1; min-width: 0; }
        .cross-promo-toast__heading {
            font-size: 14px;
            font-weight: 700;
            color: var(--jf-ink, #0f172a);
            margin: 0 0 3px;
        }
        .cross-promo-toast__text {
            font-size: 13px;
            line-height: 1.45;
            color: var(--jf-text-muted, #64748b);
            margin: 0 0 12px;
        }
        .cross-promo-toast__cta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 9px;
            background: linear-gradient(135deg, var(--jf-gold, #f4a300), var(--jf-gold-deep, #d98b00));
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .cross-promo-toast__cta:active { transform: scale(0.97); }
        .cross-promo-toast__cta svg { width: 14px; height: 14px; }
        .cross-promo-toast__close {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            margin: -4px -4px 0 0;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--jf-text-muted, #64748b);
            cursor: pointer;
            transition: background 150ms cubic-bezier(0.23, 1, 0.32, 1), transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .cross-promo-toast__close:active { transform: scale(0.94); }
        @media (hover: hover) and (pointer: fine) {
            .cross-promo-toast__close:hover { background: var(--jf-surface-soft, #f1f5f9); color: var(--jf-ink, #0f172a); }
        }
        .cross-promo-toast__close svg { width: 16px; height: 16px; }
        @media (max-width: 520px) {
            .cross-promo-toast { right: 12px; left: 12px; bottom: 12px; width: auto; }
        }
        @media (prefers-reduced-motion: reduce) {
            .cross-promo-toast,
            .cross-promo-toast[data-hide] { transition: opacity 160ms ease; transform: none; }
            .cross-promo-toast[data-show] { transform: none; }
        }
    </style>

    <div class="cross-promo-toast__body">
        <p class="cross-promo-toast__heading">{{ $heading }}</p>
        <p class="cross-promo-toast__text">{{ $body }}</p>
        <a href="{{ $url }}" class="cross-promo-toast__cta" data-turbo-frame="_top" rel="noopener">
            {{ $cta }}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
    </div>

    <button type="button" class="cross-promo-toast__close" data-cross-promo-close aria-label="Dismiss">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
</div>

@once
@push('scripts')
<script>
    (function () {
        function todayKey() {
            var d = new Date();
            return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
        }
        function initCrossPromo(el) {
            if (!el || el.dataset.cpInit) return;
            el.dataset.cpInit = '1';
            var storeKey = el.getAttribute('data-cross-promo-key');
            var seen = null;
            try { seen = window.localStorage.getItem(storeKey); } catch (e) {}
            // Only stay hidden once it has been DISMISSED today. A plain refresh
            // does NOT mark it seen, so the toast persists across reloads until the
            // user cancels it; after dismissal it stays gone until the next day.
            if (seen === todayKey()) { el.remove(); return; }

            el.hidden = false;
            // Next frame so the enter transition runs.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { el.setAttribute('data-show', ''); });
            });

            function dismiss() {
                // Record the dismissal — this is the ONLY thing that hides it for
                // the rest of the day.
                try { window.localStorage.setItem(storeKey, todayKey()); } catch (e) {}
                el.removeAttribute('data-show');
                el.setAttribute('data-hide', '');
                window.setTimeout(function () { el.remove(); }, 220);
            }
            var closeBtn = el.querySelector('[data-cross-promo-close]');
            if (closeBtn) closeBtn.addEventListener('click', dismiss);
            // Dismiss after clicking the CTA too (navigation is _top anyway).
            var cta = el.querySelector('.cross-promo-toast__cta');
            if (cta) cta.addEventListener('click', function () { try { window.localStorage.setItem(storeKey, todayKey()); } catch (e) {} });
        }
        function boot() {
            document.querySelectorAll('[data-cross-promo]').forEach(initCrossPromo);
        }
        document.addEventListener('DOMContentLoaded', boot);
        document.addEventListener('turbo:load', boot);
        // Turbo may restore from cache without firing DOMContentLoaded.
        if (document.readyState !== 'loading') boot();
    })();
</script>
@endpush
@endonce
