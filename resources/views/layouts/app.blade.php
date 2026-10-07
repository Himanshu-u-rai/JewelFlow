<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="turbo-cache-control" content="{{ request()->routeIs('product-preferences.*') ? 'no-cache' : 'no-preview' }}">
        @if(session('success'))
            <meta name="flash-success" content="{{ session('success') }}">
        @endif
        @if(session('error'))
            <meta name="flash-error" content="{{ session('error') }}">
        @endif
        @if(session('warning'))
            <meta name="flash-warning" content="{{ session('warning') }}">
        @endif

        <title>{{ config('app.name', 'JewelFlows') }}</title>
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
            .rate-modal__title { margin-top: 12px; font-size: 21px; font-weight: 700; color: var(--rm-ink); letter-spacing: 0; }
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
            /* Selected by wrapper, NOT by input[type="number"]. These fields were
               type="number" until the grouped-rate fix ("1,00,000") had to make
               them type="text" — which silently dropped every rule below,
               including the 28px left padding that clears the ₹ above, so the
               currency glyph landed on top of the placeholder's first digit and
               the modal read "₹.00". Styling keyed to a type attribute breaks the
               moment the type is the thing you need to change. */
            .rate-modal .rate-input-wrap input {
                width: 100%; padding: 11px 13px 11px 28px;
                border: 1px solid #d3d8e0; border-radius: 11px; background: #fdfbf7;
                font: inherit; font-size: 16px; font-weight: 600; color: var(--rm-ink);
                transition: border-color .16s ease, box-shadow .18s var(--rm-ease), background .16s ease;
            }
            .rate-modal .rate-input-wrap input::placeholder { color: #b3a892; font-weight: 400; }
            @media (hover: hover) and (pointer: fine) {
                .rate-modal .rate-input-wrap input:hover { border-color: #b8c0cc; }
            }
            .rate-modal .rate-input-wrap input:focus {
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
                .rate-modal__cta, .rate-modal .rate-input-wrap input { transition: none; }
            }
        </style>
    </head>
    <body class="app-shell">

        @php
            // ERP app shell. Dhiran is a separate customer-facing product served by
            // its own x-dhiran-layout on the dhiran.* subdomain, so this layout is
            // always the ERP (JewelFlows) chrome — it never re-skins itself as Dhiran.
            $authUser = auth()->user();
            $authShop = $authUser?->shop;
            $hasRetailer = (bool) $authShop?->isRetailer();
            $hasManufacturer = (bool) $authShop?->isManufacturer();
            $hasDhiran = (bool) $authShop?->hasDhiran();
            $homeRoute = 'dashboard';
            $brandName = 'JewelFlows';
            $brandSubtitle = __('Enterprise System');
            $settingsRoute = 'settings.edit';

            // Pages that left this sidebar for the page they belong to: reports
            // (Close Day among them) open from the reports hub, Historical Sales
            // and Returns / Exchange from Invoices, Installments from the
            // Customers page's own tab, Download Reports and Import Data from
            // Settings. Each of those homes has a permission of its own, so a
            // shortcut stays here for the one user the move would strand: who
            // may open the page but not the page it moved to. (Each check is a
            // query, so the home is asked first: an owner or manager stops
            // there.)
            //
            // Categories, Tag Printing and Reorder Alerts (Stock page), Vendors
            // and Product Catalog (Masters hub) need no such shortcut: their
            // home asks for nothing they do not.
            // Cash Book is not a moved page but an everyday ledger, and an
            // ordinary entry for whoever may open it.
            $navCan = fn (string $ability): bool => (bool) $authUser?->can($ability);
            $canOpenReportsHub = $navCan('reports.view');
            $canOpenSettings = $navCan('settings.view');
            $canOpenInvoices = $navCan('sales.view');
            $canOpenCashBook = $navCan('cash.view');
            $strandedCloseDay = ! $canOpenReportsHub && $navCan('reports.daily_closing');
            $strandedHistorical = $hasRetailer && ! $canOpenInvoices && $navCan('historical.view');
            $strandedReturns = ! $canOpenInvoices && $navCan('returns.view');
            $strandedInstallments = $hasRetailer && $canOpenInvoices && ! $navCan('customers.view');
            $strandedExport = ! $canOpenSettings && $navCan('reports.export');
            $strandedImport = ($hasRetailer || $hasManufacturer) && ! $canOpenSettings && $navCan('imports.manage');
        @endphp

        <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>

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
                                    {{-- text, not number: a number input silently discards any value the
                                         browser cannot parse, so "1,00,000" reaches value="" and `required`
                                         blocks the save with "Please fill in this field" — the grouped rate
                                         never reaches normalizeRateInput(). step/min are dropped with it;
                                         the controller already enforces min/max/format, and two copies of
                                         one rule that disagree is how this got missed. --}}
                                    <input
                                        id="rm-gold"
                                        type="text"
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
                                        type="text"
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
        @elseif($pricingShellState['read_only_notice'] ?? null)
            <div role="status" style="margin:12px 16px 0;padding:13px 16px;border:1px solid rgba(190,24,93,0.35);border-left:4px solid #be123c;background:#fff1f2;color:#881337;border-radius:8px;font-size:13px;font-weight:600;line-height:1.45;">
                {{ $pricingShellState['read_only_notice'] }}
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
                        {{-- Vendors and Product Catalog open from their Masters cards, so their pages light this link. --}}
                        <a href="{{ route('masters.index') }}" class="nav-link {{ request()->routeIs('masters.*', 'vendors.*', 'products.*') ? 'active' : '' }}" data-nav-match="/vendors,/products">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg></span>
                            {{ __('Masters') }}
                        </a>
                        <a href="{{ route('pos.index') }}" class="nav-link {{ request()->routeIs('pos.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                            {{ __('Sales Counter') }}
                        </a>
                        @if($authShop?->preferences?->quick_bill_enabled ?? true)
                        <a href="{{ route('quick-bills.index') }}" class="nav-link {{ request()->routeIs('quick-bills.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 2h8l4 4v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg></span>
                            {{ __('Quick Bills') }}
                        </a>
                        @endif
                        @endif
                    </div>

                    {{-- ─── SALES ─── --}}
                    @if($hasRetailer || $hasManufacturer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Sales') }}</div>
                        {{-- Installments is the Customers page's "EMI / Installments" tab, so its pages light this link. --}}
                        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') || (! $strandedInstallments && request()->routeIs('installments.*')) ? 'active' : '' }}" @unless($strandedInstallments) data-nav-match="/installments" @endunless>
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            {{ __('Customers') }}
                        </a>
                        {{-- Historical Sales and Returns / Exchange open from the Invoices page, so their pages
                             light this link too (data-nav-match: see syncActiveNavLink in app.js). Operations
                             still lights itself on /returns/control-center: the longer address wins. --}}
                        <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') || (! $strandedHistorical && request()->routeIs('historical.*')) || (! $strandedReturns && request()->routeIs('returns.index', 'returns.show', 'exchanges.*')) ? 'active' : '' }}" data-nav-match="{{ implode(',', array_filter([$strandedHistorical ? '' : '/historical', $strandedReturns ? '' : '/returns,/exchanges'])) }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                            {{ __('Invoices') }}
                        </a>
                        {{-- Kept for a role with returns.view but not sales.view, who cannot open Invoices.
                             The exchange pages are part of it (they live at /exchanges, not under /returns). --}}
                        @if($strandedReturns)
                        <a href="{{ route('returns.index') }}" class="nav-link {{ request()->routeIs('returns.index') || request()->routeIs('returns.show') || request()->routeIs('exchanges.*') ? 'active' : '' }}" data-nav-match="/exchanges">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg></span>
                            {{ __('Returns / Exchange') }}
                        </a>
                        @endif
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
                        @if($strandedInstallments)
                        <a href="{{ route('installments.index') }}" class="nav-link {{ request()->routeIs('installments.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></span>
                            {{ __('Installments') }}
                        </a>
                        @endif
                        <a href="{{ route('catalog.index') }}" class="nav-link {{ request()->routeIs('catalog.*') && ! request()->routeIs('catalog.website.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>
                            {{ __('Catalog') }}
                        </a>
                        @endif
                        @if($strandedHistorical)
                        <a href="{{ route('historical.index') }}" class="nav-link {{ request()->routeIs('historical.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg></span>
                            {{ __('Historical Sales') }}
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
                        {{-- Categories, Tag Printing and Reorder Alerts open from the Stock page's navigation row,
                             so their pages light this link. The reorder alert count came with them: it stays in
                             sight from every page here, on the way to the link that carries it. --}}
                        <a href="{{ route('inventory.items.index') }}" class="nav-link {{ request()->routeIs('inventory.items.*', 'categories.*', 'sub-categories.*', 'tags.*', 'reorder.*') ? 'active' : '' }}" data-nav-match="/categories,/sub-categories,/tags,/reorder">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                            {{ __('Jewellery Stock') }}
                            @if(($reorderAlertCount ?? 0) > 0)
                                <span class="sidebar-alert-pill" title="{{ __('Reorder alerts') }}">{{ $reorderAlertCount }}</span>
                            @endif
                        </a>
                        @if($hasRetailer)
                        <a href="{{ route('inventory.purchases.index') }}" class="nav-link {{ request()->routeIs('inventory.purchases.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg></span>
                            {{ __('Stock Purchases') }}
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
                            {{ __('Metal Vault') }}
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
                            {{ __('Karigar Bills') }}
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

                    {{-- ─── REPORTS ─── every report opens from the hub; the sidebar keeps the one way in.
                         The hub link answers for the report pages too (data-nav-match: see
                         syncActiveNavLink in app.js). Cash Book is a ledger, not a report:
                         its own entry, for whoever may open it. --}}
                    @if($hasRetailer || $hasManufacturer)
                    @php
                        $reportsHubActive = request()->routeIs('report.*', 'reporting.*')
                            && ! ($strandedCloseDay && request()->routeIs('report.closing'));
                    @endphp
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Reports') }}</div>
                        <a href="{{ route('report.hub') }}" class="nav-link {{ $reportsHubActive ? 'active' : '' }}" data-nav-match="/report,/reporting">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>
                            {{ __('All Reports') }}
                        </a>
                        @if($canOpenCashBook)
                        <a href="{{ route('cashbook.index') }}" class="nav-link {{ request()->routeIs('cashbook.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></span>
                            {{ __('Cash Book') }}
                        </a>
                        @endif
                        @if($strandedCloseDay)
                        <a href="{{ route('report.closing') }}" class="nav-link {{ request()->routeIs('report.closing') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                            {{ __('Close Day') }}
                        </a>
                        @endif
                    </div>
                    @endif

                    {{-- Dhiran (Gold Loans) is its own product on the dhiran.* subdomain
                         with its own x-dhiran-layout nav. It is intentionally NOT shown
                         in the ERP sidebar. --}}

                    {{-- ─── ACCOUNT ─── Download Reports and Import Data live in Settings now. --}}
                    @if($strandedExport || $strandedImport)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Account') }}</div>
                        @if($strandedExport)
                        <a href="{{ route('export.index') }}" class="nav-link {{ request()->routeIs('export.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></span>
                            {{ __('Download Reports') }}
                        </a>
                        @endif
                        @if($strandedImport)
                        <a href="{{ route('imports.index') }}" class="nav-link {{ request()->routeIs('imports.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg></span>
                            {{ __('Import Data') }}
                        </a>
                        @endif
                    </div>
                    @endif
                </div>
                
                <div class="sidebar-footer">
                    <a href="{{ route('settings.edit', ['tab' => 'profile']) }}" class="sidebar-footer-link {{ (request()->routeIs('profile.*') || (request()->routeIs('settings.*') && request()->query('tab') === 'profile')) ? 'is-active' : '' }}" data-nav-match="/profile">
                        <span class="nav-icon"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg></span>
                        {{ __('Profile') }}
                    </a>
                    <a href="{{ route($settingsRoute) }}" class="sidebar-footer-link {{ request()->routeIs('settings.*') || ($canOpenSettings && request()->routeIs('export.*', 'imports.*')) ? 'is-active' : '' }}" @if($canOpenSettings) data-nav-match="/export,/imports" @endif>
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
                        <strong style="font-size:15px;">{{ auth()->user()?->shop?->name ?? config('app.name', 'JewelFlows') }}</strong>
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
        // ── Logout hygiene: clear POS drafts from localStorage ──────────────
        // POS cart/customer/scan drafts are keyed per user+shop, but they live
        // in the browser. On shared counter terminals a later login to the
        // same account would resurrect a stale cart, so wipe them the moment
        // any logout form (sidebar modal or idle auto-logout) submits.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || ((form.getAttribute('action') || '').indexOf('logout') === -1)) return;
            [window.sessionStorage, window.localStorage].forEach(function (store) {
                try {
                    Object.keys(store)
                        .filter(function (k) {
                            return k.indexOf('pos_cart_') === 0
                                || k.indexOf('pos_customer_') === 0
                                || k.indexOf('pos_scan_state_') === 0;
                        })
                        .forEach(function (k) { store.removeItem(k); });
                } catch (_) {}
            });
        }, true);

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
