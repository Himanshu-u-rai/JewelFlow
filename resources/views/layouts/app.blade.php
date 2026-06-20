<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="turbo-cache-control" content="no-preview">
        @if(session('success'))
            <meta name="flash-success" content="{{ session('success') }}">
        @endif
        @if(session('error'))
            <meta name="flash-error" content="{{ session('error') }}">
        @endif
        @if(session('warning'))
            <meta name="flash-warning" content="{{ session('warning') }}">
        @endif

        <title>{{ config('app.name', 'JewelFlow') }}</title>
        @include('partials.favicon')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        {{-- M7: CA-friendly print. Hides app chrome so any report prints clean via Ctrl+P. --}}
        <style>
            .print-only { display: none; }
            @media print {
                .sidebar, .sidebar-overlay, #_auto-logout-form,
                [data-mobile-menu-toggle], [data-mobile-drawer-overlay],
                .page-actions, .no-print, .impersonation-banner { display: none !important; }
                .app-shell { display: block !important; }
                .content-area { margin: 0 !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
                .content-body, .content-inner { padding: 0 !important; }
                .page-header { border: none !important; padding: 0 0 8px !important; }
                .bg-white, table, .rounded-xl { box-shadow: none !important; }
                .print-only { display: block !important; }
                @page { margin: 14mm; }
            }
        </style>

        {{-- Daily metal-rates modal: scoped, mobile-first redesign. Kept in the
             blade (not app.css) so it ships without a Vite rebuild. --}}
        <style>
            .rate-modal { --rm-gold:#d97706; --rm-gold-deep:#b45309; --rm-ink:#1e2530; --rm-muted:#667085; --rm-line:#e6e8ec; --rm-ease:cubic-bezier(0.23,1,0.32,1); }
            .rate-modal__backdrop {
                position: absolute; inset: 0; background: rgba(15,20,30,0.55);
                backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
                animation: rm-fade .2s ease forwards;
            }
            .rate-modal__panel {
                position: relative; z-index: 1; width: 100%; max-width: 520px;
                background: #fff; border: 1px solid var(--rm-line); border-radius: 20px;
                box-shadow: 0 1px 2px rgba(16,24,40,0.06), 0 30px 60px -24px rgba(16,24,40,0.4);
                max-height: calc(100svh - 1.5rem); overflow-y: auto; overflow-x: hidden;
                animation: rm-rise .34s var(--rm-ease) forwards;
            }
            .rate-modal__panel::before {
                content: ''; position: sticky; top: 0; display: block; height: 3px;
                background: linear-gradient(90deg, #fcd34d 0%, #f59e0b 48%, #d97706 100%);
            }
            .rate-modal__head { padding: 22px 24px 18px; border-bottom: 1px solid var(--rm-line); }
            .rate-modal__badge {
                display: inline-flex; align-items: center; gap: 7px;
                font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
                color: var(--rm-gold-deep); background: #fdf6ec; border: 1px solid #f3dcb6;
                border-radius: 999px; padding: 5px 11px;
            }
            .rate-modal__badge svg { width: 13px; height: 13px; }
            .rate-modal__title { margin-top: 12px; font-size: 21px; font-weight: 800; color: var(--rm-ink); letter-spacing: -0.4px; }
            .rate-modal__desc { margin-top: 7px; font-size: 13.5px; line-height: 1.5; color: var(--rm-muted); }
            .rate-modal__date {
                margin-top: 14px; display: inline-flex; align-items: center; gap: 7px; flex-wrap: wrap;
                font-size: 12.5px; font-weight: 600; color: #4a4334;
                background: #f7f8fa; border: 1px solid var(--rm-line); border-radius: 9px; padding: 7px 11px;
            }
            .rate-modal__date svg { width: 14px; height: 14px; color: var(--rm-gold); flex: 0 0 auto; }
            .rate-modal__date .tz { color: var(--rm-muted); font-weight: 500; }

            .rate-modal__body { padding: 20px 24px 24px; }
            .rate-modal__grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
            .rate-field label { display: block; font-size: 13px; font-weight: 600; color: #4a4334; margin-bottom: 7px; }
            .rate-input-wrap { position: relative; }
            .rate-input-wrap .cur {
                position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
                font-size: 15px; font-weight: 600; color: var(--rm-muted); pointer-events: none;
            }
            .rate-modal input[type="number"] {
                width: 100%; padding: 11px 13px 11px 28px;
                border: 1px solid #d3d8e0; border-radius: 11px; background: #fdfbf7;
                font: inherit; font-size: 16px; font-weight: 600; color: var(--rm-ink);
                transition: border-color .16s ease, box-shadow .18s var(--rm-ease), background .16s ease;
            }
            .rate-modal input[type="number"]::placeholder { color: #b3a892; font-weight: 400; }
            @media (hover: hover) and (pointer: fine) {
                .rate-modal input[type="number"]:hover { border-color: #b8c0cc; }
            }
            .rate-modal input[type="number"]:focus {
                outline: none; border-color: #f59e0b; background: #fff;
                box-shadow: 0 0 0 3px rgba(245,158,11,0.18);
            }
            .rate-field .hint { margin-top: 7px; font-size: 11.5px; line-height: 1.4; color: var(--rm-muted); }

            .rate-modal__note {
                margin-top: 18px; display: flex; gap: 9px; align-items: flex-start;
                font-size: 12px; line-height: 1.5; color: #6b5d44;
                background: #fffaf0; border: 1px solid #f4dcae; border-radius: 11px; padding: 11px 13px;
            }
            .rate-modal__note svg { width: 15px; height: 15px; color: var(--rm-gold); flex: 0 0 auto; margin-top: 1px; }

            .rate-modal__cta {
                margin-top: 18px; width: 100%;
                display: inline-flex; align-items: center; justify-content: center; gap: 8px;
                padding: 13px 22px; border: none; border-radius: 12px; cursor: pointer;
                background: var(--rm-gold-deep); color: #fff; font: inherit; font-size: 15px; font-weight: 700;
                box-shadow: 0 1px 2px rgba(16,24,40,0.08), 0 10px 24px -10px rgba(180,83,9,0.55);
                transition: background .16s ease, transform .12s var(--rm-ease), box-shadow .16s ease;
            }
            .rate-modal__cta:hover { background: #92400e; box-shadow: 0 1px 2px rgba(16,24,40,0.08), 0 14px 28px -10px rgba(180,83,9,0.6); }
            .rate-modal__cta:active { transform: scale(0.985); }
            .rate-modal__cta svg { width: 16px; height: 16px; }

            .rate-modal__errors {
                margin-bottom: 16px; border: 1px solid #fecdca; background: #fef3f2; color: #b42318;
                border-radius: 11px; padding: 12px 14px; font-size: 13px;
            }
            .rate-modal__errors ul { list-style: none; margin: 0; padding: 0; }
            .rate-modal__errors li { padding: 2px 0; }
            .rate-modal__errors li::before { content: "• "; color: #d92d20; font-weight: 700; }

            @keyframes rm-fade { from { opacity: 0; } to { opacity: 1; } }
            @keyframes rm-rise { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }

            /* Mobile: full-width sheet, single column, comfortable tap targets. */
            @media (max-width: 560px) {
                .rate-modal { padding: 0; align-items: flex-end; }
                .rate-modal__panel {
                    max-width: 100%; border-radius: 20px 20px 0 0; border-bottom: 0;
                    max-height: 92svh;
                    animation: rm-sheet .34s var(--rm-ease) forwards;
                }
                .rate-modal__head { padding: 20px 18px 16px; }
                .rate-modal__title { font-size: 19px; }
                .rate-modal__body { padding: 18px 18px 22px; }
                .rate-modal__grid { grid-template-columns: 1fr; gap: 16px; }
            }
            @keyframes rm-sheet { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }

            @media (prefers-reduced-motion: reduce) {
                .rate-modal__backdrop, .rate-modal__panel { animation: none; opacity: 1; transform: none; }
                .rate-modal__cta, .rate-modal input[type="number"] { transition: none; }
            }
        </style>
    </head>
    <body class="app-shell">

        @php
            // ERP app shell. Dhiran is a separate customer-facing product served by
            // its own x-dhiran-layout on the dhiran.* subdomain, so this layout is
            // always the ERP (JewelFlow) chrome — it never re-skins itself as Dhiran.
            $authUser = auth()->user();
            $authShop = $authUser?->shop;
            $hasRetailer = (bool) $authShop?->isRetailer();
            $hasManufacturer = (bool) $authShop?->isManufacturer();
            $hasDhiran = (bool) $authShop?->hasDhiran();
            $homeRoute = 'dashboard';
            $brandName = 'JewelFlow';
            $brandSubtitle = __('Enterprise System');
            $settingsRoute = 'settings.edit';
        @endphp

        <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>
        <div id="turbo-stream-toasts" style="display:none"></div>

        @php
            $viewErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
            $pricingModalErrors = $viewErrors->getBag('pricingModal');
            $pricingTodayRate = $pricingShellState['today_rate'] ?? null;
        @endphp
        @if(($pricingShellState['show_owner_modal'] ?? false) === true)
            <div class="pricing-shell-modal rate-modal" role="dialog" aria-modal="true" aria-labelledby="rate-modal-title">
                <div class="rate-modal__backdrop"></div>
                <div class="pricing-shell-modal__panel rate-modal__panel">
                    <div class="rate-modal__head">
                        <span class="rate-modal__badge">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                            {{ __('Retailer Pricing Required') }}
                        </span>
                        <h2 id="rate-modal-title" class="rate-modal__title">{{ __('Enter Today\'s Metal Rates') }}</h2>
                        <p class="rate-modal__desc">
                            {{ __('Save today\'s rates so the team can price stock and bill at the counter.') }}
                        </p>
                        <span class="rate-modal__date">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v4M16 2v4M3 9h18M5 5h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z"/></svg>
                            {{ $pricingShellState['business_date'] ?? '-' }}
                            <span class="tz">{{ $pricingShellState['timezone'] ?? config('app.timezone', 'UTC') }}</span>
                        </span>
                    </div>
                    <form method="POST" action="{{ route('settings.pricing.save-rates') }}" class="rate-modal__body">
                        @csrf
                        <input type="hidden" name="context" value="modal">

                        @if($pricingModalErrors->any())
                            <div class="rate-modal__errors">
                                <ul>
                                    @foreach($pricingModalErrors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="rate-modal__grid">
                            <div class="rate-field">
                                <label for="rm-gold">{{ __('24K Gold Price / Gram') }}</label>
                                <div class="rate-input-wrap">
                                    <span class="cur">₹</span>
                                    <input
                                        id="rm-gold"
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        placeholder="0.00"
                                        name="gold_24k_rate_per_gram"
                                        value="{{ old('gold_24k_rate_per_gram', $pricingTodayRate ? (float) $pricingTodayRate->gold_24k_rate_per_gram : null) }}"
                                        required
                                    >
                                </div>
                            </div>
                            <div class="rate-field">
                                <label for="rm-silver">{{ __('Silver 999 Price / Kg') }}</label>
                                <div class="rate-input-wrap">
                                    <span class="cur">₹</span>
                                    <input
                                        id="rm-silver"
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        placeholder="0.00"
                                        name="silver_999_rate_per_kg"
                                        value="{{ old('silver_999_rate_per_kg', $pricingTodayRate ? round((float) $pricingTodayRate->silver_999_rate_per_gram * 1000, 4) : null) }}"
                                        required
                                    >
                                </div>
                                <p class="hint">{{ __('We convert silver to a per-gram rate for you.') }}</p>
                            </div>
                        </div>

                        <div class="rate-modal__note">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16v-4M12 8h.01M12 22a10 10 0 100-20 10 10 0 000 20z"/></svg>
                            <span>{{ __('Saving updates your stock prices and gets the counter ready for billing.') }}</span>
                        </div>

                        <button type="submit" class="rate-modal__cta">
                            {{ __('Save Today\'s Rates') }}
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        @endif

        <div class="sidebar-overlay" data-mobile-menu-overlay="tenant" data-mobile-drawer-overlay="tenant"></div>
        <div class="workspace">
            <!-- Left Sidebar -->
            <div class="sidebar" id="main-sidebar" data-mobile-drawer="tenant" data-turbo-permanent>
                <div class="sidebar-header">
                    <div class="sidebar-header-main">
                        <a href="{{ route($homeRoute) }}" class="sidebar-logo">
                            <span>{{ $brandName }}</span>
                        </a>
                        <div class="sidebar-subtitle">{{ $brandSubtitle }}</div>
                    </div>
                    <button type="button" class="sidebar-close-btn" data-mobile-menu-toggle="tenant" aria-controls="main-sidebar" aria-expanded="false" aria-label="Close navigation">
                        <span class="drawer-toggle-icon drawer-toggle-icon-menu" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        </span>
                        <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </span>
                    </button>
                </div>
                
                <div class="sidebar-nav" id="sidebar-nav">
                    {{-- ─── WORKSPACE ─── --}}
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Workspace') }}</div>
                        <a href="{{ route($homeRoute) }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                            {{ __('Dashboard') }}
                        </a>
                        @if($hasRetailer || $hasManufacturer)
                        <a href="{{ route('pos.index') }}" class="nav-link {{ request()->routeIs('pos.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                            {{ __('Point of Sale') }}
                        </a>
                        <a href="{{ route('quick-bills.index') }}" class="nav-link {{ request()->routeIs('quick-bills.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 2h8l4 4v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg></span>
                            {{ __('Quick Bills') }}
                        </a>
                        @endif
                    </div>

                    {{-- ─── SALES ─── --}}
                    @if($hasRetailer || $hasManufacturer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Sales') }}</div>
                        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            {{ __('Customers') }}
                        </a>
                        <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                            {{ __('Invoices') }}
                        </a>
                        @can('returns.view')
                        <a href="{{ route('returns.index') }}" class="nav-link {{ request()->routeIs('returns.index') || request()->routeIs('returns.show') || request()->routeIs('exchanges.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></span>
                            {{ __('Returns & Exchanges') }}
                        </a>
                        @endcan
                        @can('returns.approve')
                        <a href="{{ route('returns.control-center') }}" class="nav-link {{ request()->routeIs('returns.control-center') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
                            {{ __('Operations') }}
                        </a>
                        @endcan
                        @if($hasRetailer)
                        <a href="{{ route('schemes.index') }}" class="nav-link {{ request()->routeIs('schemes.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg></span>
                            {{ __('Schemes') }}
                        </a>
                        @can('sales.view')
                        <a href="{{ route('installments.index') }}" class="nav-link {{ request()->routeIs('installments.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></span>
                            {{ __('Installments') }}
                        </a>
                        @endcan
                        <a href="{{ route('catalog.index') }}" class="nav-link {{ request()->routeIs('catalog.*') && ! request()->routeIs('catalog.website.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>
                            {{ __('Catalog') }}
                        </a>
                        @endif
                    </div>
                    @endif

                    {{-- ─── INVENTORY ─── --}}
                    @if($hasRetailer || $hasManufacturer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Inventory') }}</div>
                        @if($hasManufacturer)
                        <a href="{{ route('inventory.gold.index') }}" class="nav-link {{ request()->routeIs('inventory.gold.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg></span>
                            {{ __('Gold Inventory') }}
                        </a>
                        @endif
                        <a href="{{ route('inventory.items.index') }}" class="nav-link {{ request()->routeIs('inventory.items.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                            {{ __('Stock / Items') }}
                        </a>
                        @if($hasRetailer)
                        <a href="{{ route('inventory.purchases.index') }}" class="nav-link {{ request()->routeIs('inventory.purchases.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></span>
                            {{ __('Stock Purchases') }}
                        </a>
                        @endif
                        <a href="{{ route('categories.index') }}" class="nav-link {{ request()->routeIs('categories.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span>
                            {{ __('Categories') }}
                        </a>
                        @if($hasManufacturer)
                        <a href="{{ route('products.index') }}" class="nav-link {{ request()->routeIs('products.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                            {{ __('Product Catalog') }}
                        </a>
                        @endif
                        <a href="{{ route('vendors.index') }}" class="nav-link {{ request()->routeIs('vendors.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
                            {{ __('Vendors') }}
                        </a>
                        @if($hasRetailer)
                        <a href="{{ route('tags.index') }}" class="nav-link {{ request()->routeIs('tags.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></span>
                            {{ __('Tag Printing') }}
                        </a>
                        <a href="{{ route('reorder.index') }}" class="nav-link {{ request()->routeIs('reorder.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                            {{ __('Reorder Alerts') }}
                            @if(($reorderAlertCount ?? 0) > 0)
                                <span class="sidebar-alert-pill">{{ $reorderAlertCount }}</span>
                            @endif
                        </a>
                        @endif
                    </div>
                    @endif

                    {{-- ─── JOB WORK (retailer edition — bullion vault + karigar workflow) ─── --}}
                    @if($hasRetailer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Job Work') }}</div>
                        <a href="{{ route('vault.index') }}" class="nav-link {{ request()->routeIs('vault.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16.5" r="1"/></svg></span>
                            {{ __('Bullion Vault') }}
                        </a>
                        <a href="{{ route('karigars.index') }}" class="nav-link {{ request()->routeIs('karigars.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                            {{ __('Karigars') }}
                        </a>
                        <a href="{{ route('job-orders.index') }}" class="nav-link {{ request()->routeIs('job-orders.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11H3v10h6V11z"/><path d="M21 3h-6v18h6V3z"/><path d="M15 11H9V3h6v8z"/></svg></span>
                            {{ __('Job Orders') }}
                        </a>
                        <a href="{{ route('karigar-invoices.index') }}" class="nav-link {{ request()->routeIs('karigar-invoices.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                            {{ __('Karigar Invoices') }}
                        </a>
                    </div>
                    @endif

                    {{-- ─── SERVICES ─── --}}
                    @if($hasRetailer || $hasManufacturer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Services') }}</div>
                        <a href="{{ route('repairs.index') }}" class="nav-link {{ request()->routeIs('repairs.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                            {{ __('Repairs') }}
                        </a>
                    </div>
                    @endif

                    {{-- ─── REPORTS ─── --}}
                    @if($hasRetailer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Reports') }}</div>
                        <a href="{{ route('report.hub') }}" class="nav-link {{ request()->routeIs('report.hub') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>
                            {{ __('All Reports') }}
                        </a>
                        <a href="{{ route('cashbook.index') }}" class="nav-link {{ request()->routeIs('cashbook.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
                            {{ __('Cash Ledger') }}
                        </a>
                        <a href="{{ route('report.closing') }}" class="nav-link {{ request()->routeIs('report.closing') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                            {{ __('Daily Closing') }}
                        </a>
                        <a href="{{ route('report.gst') }}" class="nav-link {{ request()->routeIs('report.gst') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg></span>
                            {{ __('GST Reports') }}
                        </a>
                        <a href="{{ route('report.gstr1') }}" class="nav-link {{ request()->routeIs('report.gstr1') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                            {{ __('GSTR-1') }}
                        </a>
                        <a href="{{ route('report.gstr3b') }}" class="nav-link {{ request()->routeIs('report.gstr3b') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" y1="12" x2="16" y2="12"/></svg></span>
                            {{ __('GSTR-3B') }}
                        </a>
                        <a href="{{ route('report.cn-register') }}" class="nav-link {{ request()->routeIs('report.cn-register') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></span>
                            {{ __('Credit Note Register') }}
                        </a>
                        <a href="{{ route('report.payment-reconciliation') }}" class="nav-link {{ request()->routeIs('report.payment-reconciliation') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 21H3v-5"/><path d="M21 3l-7.5 7.5"/><path d="M3 21l7.5-7.5"/></svg></span>
                            {{ __('Payment Reconciliation') }}
                        </a>
                        <a href="{{ route('report.day-book') }}" class="nav-link {{ request()->routeIs('report.day-book') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                            {{ __('Day Book') }}
                        </a>
                        <a href="{{ route('report.inventory-valuation') }}" class="nav-link {{ request()->routeIs('report.inventory-valuation') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg></span>
                            {{ __('Inventory Valuation') }}
                        </a>
                        <a href="{{ route('report.dues-aging') }}" class="nav-link {{ request()->routeIs('report.dues-aging') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                            {{ __('Customer Dues') }}
                        </a>
                        <a href="{{ route('report.emi') }}" class="nav-link {{ request()->routeIs('report.emi') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span>
                            {{ __('Pending EMI') }}
                        </a>
                        <a href="{{ route('report.scheme-liability') }}" class="nav-link {{ request()->routeIs('report.scheme-liability') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                            {{ __('Scheme Liability') }}
                        </a>
                        <a href="{{ route('report.metal-liability') }}" class="nav-link {{ request()->routeIs('report.metal-liability') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v10M9 9.5h4.5a1.5 1.5 0 0 1 0 3H9"/></svg></span>
                            {{ __('Metal Liability') }}
                        </a>
                        <a href="{{ route('report.dead-stock') }}" class="nav-link {{ request()->routeIs('report.dead-stock') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg></span>
                            {{ __('Dead Stock') }}
                        </a>
                        <a href="{{ route('report.karigar-settlement') }}" class="nav-link {{ request()->routeIs('report.karigar-settlement') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                            {{ __('Karigar Settlement') }}
                        </a>
                        <a href="{{ route('report.purchase-efficiency') }}" class="nav-link {{ request()->routeIs('report.purchase-efficiency') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span>
                            {{ __('Purchase Efficiency') }}
                        </a>
                        <a href="{{ route('report.operator-performance') }}" class="nav-link {{ request()->routeIs('report.operator-performance') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            {{ __('Operator Performance') }}
                        </a>
                        <a href="{{ route('report.suspicious-activity') }}" class="nav-link {{ request()->routeIs('report.suspicious-activity') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V5z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
                            {{ __('Suspicious Activity') }}
                        </a>
                        <a href="{{ route('report.shrinkage') }}" class="nav-link {{ request()->routeIs('report.shrinkage') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8"/><path d="M21 7v6h-6"/></svg></span>
                            {{ __('Metal Loss / Shrinkage') }}
                        </a>
                        <a href="{{ route('report.metal-exchange') }}" class="nav-link {{ request()->routeIs('report.metal-exchange') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span>
                            {{ __('Metal Exchange') }}
                        </a>
                        <a href="{{ route('report.daily') }}" class="nav-link {{ request()->routeIs('report.daily') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></span>
                            {{ __('Daily Reports') }}
                        </a>
                    </div>
                    @elseif($hasManufacturer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Reports') }}</div>
                        <a href="{{ route('report.hub') }}" class="nav-link {{ request()->routeIs('report.hub') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>
                            {{ __('All Reports') }}
                        </a>
                        <a href="{{ route('cashbook.index') }}" class="nav-link {{ request()->routeIs('cashbook.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
                            {{ __('Cash Ledger') }}
                        </a>
                        <a href="{{ route('report.cash') }}" class="nav-link {{ request()->routeIs('report.cash') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                            {{ __('Cash Flow Dashboard') }}
                        </a>
                        <a href="{{ route('report.pnl') }}" class="nav-link {{ request()->routeIs('report.pnl') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                            {{ __('Profit & Loss') }}
                        </a>
                        <a href="{{ route('report.gst') }}" class="nav-link {{ request()->routeIs('report.gst') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg></span>
                            {{ __('GST Reports') }}
                        </a>
                        <a href="{{ route('report.gstr1') }}" class="nav-link {{ request()->routeIs('report.gstr1') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                            {{ __('GSTR-1') }}
                        </a>
                        <a href="{{ route('report.gstr3b') }}" class="nav-link {{ request()->routeIs('report.gstr3b') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" y1="12" x2="16" y2="12"/></svg></span>
                            {{ __('GSTR-3B') }}
                        </a>
                        <a href="{{ route('report.cn-register') }}" class="nav-link {{ request()->routeIs('report.cn-register') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></span>
                            {{ __('Credit Note Register') }}
                        </a>
                        <a href="{{ route('report.payment-reconciliation') }}" class="nav-link {{ request()->routeIs('report.payment-reconciliation') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 21H3v-5"/><path d="M21 3l-7.5 7.5"/><path d="M3 21l7.5-7.5"/></svg></span>
                            {{ __('Payment Reconciliation') }}
                        </a>
                        <a href="{{ route('report.day-book') }}" class="nav-link {{ request()->routeIs('report.day-book') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                            {{ __('Day Book') }}
                        </a>
                        <a href="{{ route('report.inventory-valuation') }}" class="nav-link {{ request()->routeIs('report.inventory-valuation') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/></svg></span>
                            {{ __('Inventory Valuation') }}
                        </a>
                        <a href="{{ route('report.dead-stock') }}" class="nav-link {{ request()->routeIs('report.dead-stock') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg></span>
                            {{ __('Dead Stock') }}
                        </a>
                        <a href="{{ route('report.karigar-settlement') }}" class="nav-link {{ request()->routeIs('report.karigar-settlement') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                            {{ __('Karigar Settlement') }}
                        </a>
                        <a href="{{ route('report.purchase-efficiency') }}" class="nav-link {{ request()->routeIs('report.purchase-efficiency') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span>
                            {{ __('Purchase Efficiency') }}
                        </a>
                        <a href="{{ route('report.operator-performance') }}" class="nav-link {{ request()->routeIs('report.operator-performance') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            {{ __('Operator Performance') }}
                        </a>
                        <a href="{{ route('report.suspicious-activity') }}" class="nav-link {{ request()->routeIs('report.suspicious-activity') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V5z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span>
                            {{ __('Suspicious Activity') }}
                        </a>
                        <a href="{{ route('report.daily') }}" class="nav-link {{ request()->routeIs('report.daily') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></span>
                            {{ __('Daily Reports') }}
                        </a>
                        <a href="{{ route('report.closing') }}" class="nav-link {{ request()->routeIs('report.closing') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                            {{ __('Daily Closing') }}
                        </a>
                        <a href="{{ route('report.gold') }}" class="nav-link {{ request()->routeIs('report.gold') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                            {{ __('Gold Report') }}
                        </a>
                    </div>
                    @endif

                    {{-- Dhiran (Gold Loans) is its own product on the dhiran.* subdomain
                         with its own x-dhiran-layout nav. It is intentionally NOT shown
                         in the ERP sidebar. --}}

                    {{-- ─── ACCOUNT ─── --}}
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Account') }}</div>
                        <a href="{{ route('export.index') }}" class="nav-link {{ request()->routeIs('export.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></span>
                            {{ __('Export Data') }}
                        </a>
                        @if(($hasRetailer || $hasManufacturer) && auth()->user()->can('imports.manage'))
                        <a href="{{ route('imports.index') }}" class="nav-link {{ request()->routeIs('imports.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
                            {{ __('Bulk Imports') }}
                        </a>
                        @endif
                    </div>
                </div>
                
                <div class="sidebar-footer">
                    <a href="{{ route('settings.edit', ['tab' => 'profile']) }}" class="sidebar-footer-link {{ (request()->routeIs('profile.*') || (request()->routeIs('settings.*') && request()->query('tab') === 'profile')) ? 'is-active' : '' }}">
                        <span class="nav-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></span>
                        {{ __('Profile') }}
                    </a>
                    <a href="{{ route($settingsRoute) }}" class="sidebar-footer-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}">
                        <span class="nav-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.573-1.066z"/><circle cx="12" cy="12" r="3"/></svg></span>
                        {{ __('Settings') }}
                        <span class="sidebar-footer-role">{{ $authUser?->role?->display_name ?? __('Guest') }}</span>
                    </a>
                    <div x-data="{ showLogout: false }">
                        <button @click="showLogout = true" type="button" class="sidebar-footer-link sidebar-footer-link--danger sidebar-button-reset">
                            <span class="nav-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg></span>
                            {{ __('Log out') }}
                        </button>

                        <!-- Logout Confirmation Modal -->
                        <div x-show="showLogout" x-cloak
                             class="fixed inset-0 z-[9999] flex items-center justify-center"
                             @keydown.escape.window="showLogout = false">
                            <div class="fixed inset-0 bg-black/40" @click="showLogout = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>
                            <div class="relative bg-white shadow-xl p-6 w-full max-w-sm mx-4 logout-confirm-card" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                                <div class="flex items-center gap-3 mb-4">
                                    <div class="w-10 h-10 bg-red-100 flex items-center justify-center logout-confirm-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-600"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900">{{ __('Log out?') }}</h3>
                                        <p class="text-sm text-gray-500">{{ __('Are you sure you want to log out?') }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-3 mt-5">
                                    <button @click="showLogout = false" type="button" class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors text-sm font-medium rounded-xl">{{ __('Cancel') }}</button>
                                    <form method="POST" action="{{ route('logout') }}" class="logout-confirm-form">
                                        @csrf
                                        <button type="submit" class="w-full px-4 py-2 bg-red-600 text-white hover:bg-red-700 transition-colors text-sm font-medium rounded-xl">{{ __('Log out') }}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content Area -->
            @php
                // Small inline notices only (info/warning/critical). Big "banner"
                // offers/deals and "cross_promo" toast overrides render via their
                // own surfaces (x-promo-banner / cross-promo toast), not here.
                $activeAnnouncements = \App\Models\Platform\PlatformAnnouncement::active()
                    ->whereIn('type', \App\Models\Platform\PlatformAnnouncement::SYSTEM_TYPES)
                    ->whereNotExists(function($q) {
                        $q->select(\DB::raw(1))->from('platform_announcement_dismissals')
                          ->where('user_id', auth()->id())
                          ->whereColumn('announcement_id', 'platform_announcements.id');
                    })->get();
            @endphp
            @foreach($activeAnnouncements as $ann)
            <div x-data="{ show: true }" x-show="show"
                 class="mx-4 mt-3 rounded-lg border px-4 py-3 text-sm flex items-start justify-between gap-3
                 {{ $ann->type === 'critical' ? 'bg-rose-950 border-rose-700 text-rose-200' : ($ann->type === 'warning' ? 'bg-amber-950 border-amber-700 text-amber-200' : 'bg-blue-950 border-blue-700 text-blue-200') }}">
                <div><strong>{{ $ann->title }}</strong> — {{ $ann->body }}</div>
                <form method="POST" action="{{ route('announcements.dismiss', $ann) }}" class="shrink-0">
                    @csrf
                    <button type="submit" @click="show=false" class="opacity-60 hover:opacity-100 text-xs">Dismiss</button>
                </form>
            </div>
            @endforeach
            <main id="main-content" class="content-area" role="main">
                @include('components.impersonation-banner')
                @isset($header)
                    <x-page-header>
                        {{ $header }}
                    </x-page-header>
                @endisset
                <div class="content-body">
                    {{-- M7: print-only letterhead so a printed report is CA-presentable. --}}
                    <div class="print-only" style="margin-bottom:12px;border-bottom:1px solid #111;padding-bottom:6px;overflow:hidden;">
                        <strong style="font-size:15px;">{{ auth()->user()?->shop?->name ?? config('app.name', 'JewelFlow') }}</strong>
                        <span style="float:right;font-size:12px;">Printed {{ now()->format('d M Y') }}</span>
                    </div>
                    {{ $slot ?? '' }}
                </div>
            </main>
        </div>
        @stack('scripts')

        @php
            $autoLogoutMinutes = auth()->user()?->shop?->preferences?->auto_logout_minutes ?? 0;
        @endphp
        @if($autoLogoutMinutes > 0)
        <form id="_auto-logout-form" method="POST" action="{{ route('logout') }}" class="app-hidden-form">
            @csrf
        </form>
        <script>
        (function () {
            var idleLimit = {{ (int) $autoLogoutMinutes }} * 60 * 1000;
            var timer;
            function resetTimer() {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    document.getElementById('_auto-logout-form').submit();
                }, idleLimit);
            }
            ['mousemove','mousedown','keydown','touchstart','scroll','click'].forEach(function(e) {
                document.addEventListener(e, resetTimer, { passive: true });
            });
            resetTimer();
        })();
        </script>
        @endif
        <script>
        // ── Browser default fixes ────────────────────────────────────────────

        // 1. Mouse wheel: don't increment number inputs
        document.addEventListener('wheel', function () {
            if (document.activeElement && document.activeElement.type === 'number') {
                document.activeElement.blur();
            }
        }, { passive: true });

        // 2. Autocomplete off + spellcheck off on all inputs (except password/login)
        function applyInputFixes(root) {
            root.querySelectorAll('input:not([type="password"]):not([type="email"])').forEach(function (el) {
                if (!el.hasAttribute('autocomplete')) el.setAttribute('autocomplete', 'off');
                el.setAttribute('spellcheck', 'false');
            });
            root.querySelectorAll('select').forEach(function (el) {
                el.setAttribute('spellcheck', 'false');
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            applyInputFixes(document);
        });

        // Handle Alpine.js / Turbo dynamically added inputs
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) applyInputFixes(node);
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });

        // 3. Prevent accidental text-drag from input fields
        document.addEventListener('dragstart', function (e) {
            if (e.target.matches('input, textarea')) e.preventDefault();
        });
        </script>
    </body>
</html>
