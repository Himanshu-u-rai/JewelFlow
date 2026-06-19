@props([
    'heading',
    'body',
    'cta',
    'url',
])

{{-- Calm, non-intrusive product cross-promotion card (Phase 4). No popup, no
     banner, no blocking — a single quiet suggestion on the dashboard. The CTA is
     a normal link to the OTHER product's separate register front door; it never
     grants an edition or links accounts. data-turbo-frame="_top" because it is a
     full-page navigation to a different product/host. --}}
<div class="cross-promo">
    <style>
        .cross-promo {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
            border: 1px solid var(--jf-border, #e2e8f0);
            background: var(--jf-surface, #fff);
            border-radius: 16px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .cross-promo__text { min-width: 240px; flex: 1; }
        .cross-promo__heading {
            font-size: 15px;
            font-weight: 700;
            color: var(--jf-ink, #0f172a);
            margin: 0 0 4px;
        }
        .cross-promo__body {
            font-size: 13.5px;
            line-height: 1.5;
            color: var(--jf-text-muted, #64748b);
            margin: 0;
            max-width: 64ch;
        }
        .cross-promo__cta {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid var(--jf-border, #e2e8f0);
            background: var(--jf-surface-soft, #f8fafc);
            color: var(--jf-ink, #0f172a);
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 150ms cubic-bezier(0.23, 1, 0.32, 1), background 150ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .cross-promo__cta:active { transform: scale(0.97); }
        @media (hover: hover) and (pointer: fine) {
            .cross-promo__cta:hover { background: var(--jf-surface-accent, #fff7ea); }
        }
        .cross-promo__cta svg { width: 15px; height: 15px; }
    </style>

    <div class="cross-promo__text">
        <p class="cross-promo__heading">{{ $heading }}</p>
        <p class="cross-promo__body">{{ $body }}</p>
    </div>

    <a href="{{ $url }}" class="cross-promo__cta" data-turbo-frame="_top" rel="noopener">
        {{ $cta }}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
</div>
