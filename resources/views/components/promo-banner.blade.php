@props(['realm' => 'erp'])

@php
    // Big admin-editable offers/deals banner (type='banner'). Resolved here so any
    // dashboard can drop in <x-promo-banner realm="erp|dhiran" />. Targets by realm
    // (null = both), by shop edition/target, and respects per-user dismissal.
    $user = auth()->user();
    $shop = $user?->shop;
    $promoBanner = \App\Models\Platform\PlatformAnnouncement::active()
        ->ofType(\App\Models\Platform\PlatformAnnouncement::TYPE_BANNER)
        ->whereNotExists(function ($q) use ($user) {
            $q->select(\DB::raw(1))->from('platform_announcement_dismissals')
              ->where('user_id', $user?->id)
              ->whereColumn('announcement_id', 'platform_announcements.id');
        })
        ->orderByDesc('created_at')
        ->get()
        ->first(fn ($a) => $a->isForRealm($realm) && ($shop ? $a->isForShop($shop) : true));
@endphp

@if($promoBanner)
<div class="promo-banner" data-promo-banner>
    <style>
        .promo-banner {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 20px;
            padding: 20px 22px;
            border: 1px solid var(--jf-border, #e2e8f0);
            border-radius: 16px;
            /* Warm offers/deals surface, same family as the cross-promo toast. */
            background:
                radial-gradient(120% 140% at 0% 0%, rgba(244,163,0,0.10), transparent 60%),
                var(--jf-surface, #fff);
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        .promo-banner__accent {
            flex-shrink: 0;
            width: 42px; height: 42px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--jf-gold, #f4a300), var(--jf-gold-deep, #d98b00));
            color: #fff;
        }
        .promo-banner__accent svg { width: 22px; height: 22px; }
        .promo-banner__body { flex: 1; min-width: 0; }
        .promo-banner__title {
            font-size: 16px; font-weight: 700;
            color: var(--jf-ink, #0f172a);
            margin: 0 0 4px;
        }
        .promo-banner__text {
            font-size: 14px; line-height: 1.55;
            color: var(--jf-text-muted, #475569);
            margin: 0; max-width: 80ch;
            white-space: pre-line;
        }
        .promo-banner__cta {
            display: inline-flex; align-items: center; gap: 7px;
            margin-top: 12px;
            padding: 9px 16px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--jf-gold, #f4a300), var(--jf-gold-deep, #d98b00));
            color: #fff; font-size: 13.5px; font-weight: 600;
            text-decoration: none;
            transition: transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .promo-banner__cta:active { transform: scale(0.97); }
        .promo-banner__cta svg { width: 15px; height: 15px; }
        .promo-banner__close {
            flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px;
            margin: -6px -6px 0 0;
            border: 0; border-radius: 8px;
            background: transparent; color: var(--jf-text-muted, #64748b);
            cursor: pointer;
            transition: background 150ms cubic-bezier(0.23, 1, 0.32, 1), transform 150ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .promo-banner__close:active { transform: scale(0.94); }
        @media (hover: hover) and (pointer: fine) {
            .promo-banner__close:hover { background: var(--jf-surface-soft, #f1f5f9); color: var(--jf-ink, #0f172a); }
        }
        .promo-banner__close svg { width: 16px; height: 16px; }
    </style>

    <span class="promo-banner__accent" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1.5"/></svg>
    </span>

    <div class="promo-banner__body">
        <p class="promo-banner__title">{{ $promoBanner->title }}</p>
        <p class="promo-banner__text">{{ $promoBanner->body }}</p>
        @if($promoBanner->cta_label && $promoBanner->cta_url)
            <a href="{{ $promoBanner->cta_url }}" class="promo-banner__cta" data-turbo-frame="_top" rel="noopener">
                {{ $promoBanner->cta_label }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </a>
        @endif
    </div>

    <form method="POST" action="{{ route('announcements.dismiss', $promoBanner) }}" data-turbo-frame="_top">
        @csrf
        <button type="submit" class="promo-banner__close" aria-label="Dismiss">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </form>
</div>
@endif
