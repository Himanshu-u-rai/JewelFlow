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
            .rate-modal__head { padding: 22px 24px 18px; border-bottom: 1px solid var(--rm-line); }
            .rate-modal__title { margin: 0; font-size: 21px; font-weight: 700; color: var(--rm-ink); letter-spacing: 0; }
            .rate-modal__desc { margin-top: 7px; font-size: 13.5px; line-height: 1.5; color: var(--rm-muted); }
            .rate-modal__titlerow { display: flex; align-items: center; justify-content: space-between; gap: 8px 12px; flex-wrap: wrap; }
            .rate-modal__date {
                display: inline-flex; align-items: center; gap: 7px; flex: 0 0 auto; white-space: nowrap;
                font-size: 13px; font-weight: 600; color: #4a4334;
                background: #f7f8fa; border: 1px solid var(--rm-line); border-radius: 9px; padding: 7px 11px;
            }
            .rate-modal__date svg { width: 14px; height: 14px; color: var(--rm-gold); flex: 0 0 auto; }

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
            @media (hover: hover) and (pointer: fine) {
                .rate-modal .rate-input-wrap input:hover { border-color: #b8c0cc; }
            }
            .rate-modal .rate-input-wrap input:focus {
                outline: none; border-color: #d97706; background: #fff;
                box-shadow: 0 0 0 3px rgba(217,119,6,0.32);
            }
            .rate-modal .rate-input-wrap input[aria-invalid="true"] { border-color: #d92d20; background: #fffbfa; }
            .rate-field__label { display: flex; align-items: center; gap: 4px; margin-bottom: 7px; }
            .rate-field__label label { margin-bottom: 0; }
            .rate-tip { position: relative; display: inline-flex; }
            .rate-tip__btn {
                display: inline-flex; align-items: center; justify-content: center;
                width: 32px; height: 32px; margin: -8px 0; border: 0; border-radius: 999px;
                background: transparent; color: var(--rm-muted); cursor: help;
            }
            .rate-tip__btn:hover, .rate-tip__btn[aria-expanded="true"] { color: var(--rm-gold-deep); background: #fdf6ec; }
            .rate-tip__btn:focus-visible { outline: 2px solid #f59e0b; outline-offset: 1px; }
            .rate-tip__bubble {
                /* Above the icon and click-through, so it never covers the input below. */
                position: absolute; z-index: 2; bottom: calc(100% + 6px); right: -8px; width: max-content; max-width: 220px;
                pointer-events: none;
                padding: 8px 11px; border-radius: 9px; background: var(--rm-ink); color: #fff;
                font-size: 13px; font-weight: 500; line-height: 1.4;
                box-shadow: 0 8px 20px -8px rgba(16,24,40,0.45);
                opacity: 0; visibility: hidden; transform: translateY(3px);
                transition: opacity .14s ease, transform .14s var(--rm-ease), visibility 0s linear .14s;
            }
            .rate-tip:hover .rate-tip__bubble, .rate-tip__btn:focus-visible + .rate-tip__bubble, .rate-tip__btn[aria-expanded="true"] + .rate-tip__bubble {
                opacity: 1; visibility: visible; transform: none; transition-delay: 0s;
            }
            @media (max-width: 560px) { .rate-tip__bubble { right: auto; left: -110px; } }
            .rate-field .hint { margin-top: 7px; font-size: 13px; line-height: 1.45; color: var(--rm-muted); }
            .rate-field__error { margin-top: 7px; font-size: 13px; line-height: 1.45; font-weight: 600; color: #b42318; }
            .rate-field__warn {
                margin-top: 8px; font-size: 13px; line-height: 1.45; font-weight: 600; color: #92400e;
                background: #fffbeb; border: 1px solid #fcd34d; border-radius: 9px; padding: 8px 10px;
            }
            .rate-field__warn[hidden] { display: none; }

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
            .rate-modal__cta:focus-visible { outline: 3px solid #f59e0b; outline-offset: 3px; }
            .rate-modal__cta[disabled] { opacity: .75; cursor: progress; }
            .rate-modal__cta svg { width: 16px; height: 16px; }
            .rate-modal__logout {
                display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
                margin: 0; padding: 12px 24px 18px; border-top: 1px solid #efe7d6;
                font-size: 13px; color: var(--rm-muted);
            }
            .rate-modal__logout button {
                display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; min-height: 44px;
                border: 1px solid #d3d8e0; border-radius: 10px; background: #fff; cursor: pointer;
                font: inherit; font-size: 13px; font-weight: 600; color: var(--rm-ink);
            }
            .rate-modal__logout button:hover { border-color: #b8c0cc; background: #f8f9fb; }
            .rate-modal__logout button:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(245,158,11,0.25); }
            .rate-modal__logout svg { width: 15px; height: 15px; }

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
                .rate-modal__title { font-size: 18px; }
                .rate-modal__titlerow { gap: 6px 10px; }
                .rate-modal__date { padding: 5px 9px; }
                .rate-modal__date svg, .rate-modal__year { display: none; }
                .rate-modal__body { padding: 18px 18px 22px; }
                .rate-modal__logout { padding: 12px 18px calc(16px + env(safe-area-inset-bottom)); }
                .rate-modal__grid { grid-template-columns: 1fr; gap: 16px; }
            }
            @keyframes rm-sheet { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }

            @media (prefers-reduced-motion: reduce) {
                .rate-modal__backdrop, .rate-modal__panel { animation: none; opacity: 1; transform: none; }
                .rate-modal__cta, .rate-modal .rate-input-wrap input, .rate-tip__bubble { transition: none; }
            }
        </style>
    </head>
    <body class="app-shell">

        @php
            // ERP app shell.
            $authUser = auth()->user();
            $authShop = $authUser?->shop;
            $hasRetailer = (bool) $authShop?->isRetailer();
            $hasManufacturer = (bool) $authShop?->isManufacturer();
            $homeRoute = 'dashboard';
            $brandName = 'JewelFlows';
            $brandSubtitle = __('By Factual Technologies');
            $settingsRoute = 'settings.edit';

            // Pages that left this sidebar for the page they belong to: reports
            // (Close Day among them) open from the reports hub, Historical Sales
            // from the Invoices header, Installments from the
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
            // Cash Book and Returns / Exchange are ordinary sidebar entries
            // for whoever may open them.
            $navCan = fn (string $ability): bool => (bool) $authUser?->can($ability);
            // Can this user open the page behind a route? (its own can:/role: middleware — see RouteAccess)
            $navOpen = fn (string $route): bool => \App\Support\RouteAccess::allows($route);
            $canOpenReportsHub = $navCan('reports.view');
            $canOpenSettings = $navCan('settings.view');
            $canOpenInvoices = $navCan('sales.view');
            $canOpenCashBook = $navCan('cash.view');
            $strandedCloseDay = ! $canOpenReportsHub && $navCan('reports.daily_closing');
            $strandedHistorical = $hasRetailer && ! $canOpenInvoices && $navCan('historical.view');
            $strandedInstallments = $hasRetailer && $navCan('installments.view') && ! $navCan('customers.view');
            $strandedExport = ! $canOpenReportsHub && $navCan('reports.export');
            $strandedImport = ($hasRetailer || $hasManufacturer) && ! $canOpenSettings && $navCan('imports.manage');
        @endphp

        <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>

        @php
            $viewErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
            $pricingModalErrors = $viewErrors->getBag('pricingModal');
            $pricingTodayRate = $pricingShellState['today_rate'] ?? null;
        @endphp
        @if(($pricingShellState['show_owner_modal'] ?? false) === true)
            @php
                $pricingPreviousRate = $pricingShellState['previous_rate'] ?? null;
                $pricingPrevGold = $pricingPreviousRate ? round((float) $pricingPreviousRate->gold_24k_rate_per_gram, 4) : null;
                $pricingPrevSilverKg = $pricingPreviousRate ? round((float) $pricingPreviousRate->silver_999_rate_per_gram * 1000, 4) : null;
                $pricingPrevDate = $pricingPreviousRate ? \Illuminate\Support\Carbon::parse($pricingPreviousRate->business_date)->format('j M') : null;
                // Indian grouping (7,250 / 1,00,000) without needing the intl extension.
                $rupees = function (float $v): string {
                    [$int, $dec] = array_pad(explode('.', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.')), 2, null);
                    $rest = substr($int, 0, -3);

                    return '₹'.($rest !== '' ? preg_replace('/\B(?=(\d{2})+$)/', ',', $rest).',' : '').substr($int, -3).($dec ? '.'.$dec : '');
                };
                $goldError = $pricingModalErrors->first('gold_24k_rate_per_gram');
                $silverError = $pricingModalErrors->first('silver_999_rate_per_kg');
                $otherErrors = collect($pricingModalErrors->getMessages())->except(['gold_24k_rate_per_gram', 'silver_999_rate_per_kg'])->flatten();
            @endphp
            <div class="pricing-shell-modal rate-modal" role="dialog" aria-modal="true" aria-labelledby="rate-modal-title" aria-describedby="rate-modal-desc">
                <div class="rate-modal__backdrop"></div>
                <div class="pricing-shell-modal__panel rate-modal__panel">
                    <div class="rate-modal__head">
                        <div class="rate-modal__titlerow">
                            <h2 id="rate-modal-title" class="rate-modal__title">{{ __('Enter Today\'s Metal Rates') }}</h2>
                            <span class="rate-modal__date">
                                <x-icon name="calendar" stroke-width="1.9" />
                                @isset($pricingShellState['business_date'])
                                    @php $pricingDay = \Illuminate\Support\Carbon::parse($pricingShellState['business_date']); @endphp
                                    {{ $pricingDay->format('D, j M') }}<span class="rate-modal__year"> {{ $pricingDay->format('Y') }}</span>
                                @else
                                    -
                                @endisset
                            </span>
                        </div>
                        <p id="rate-modal-desc" class="rate-modal__desc">
                            {{ __('Saving updates your stock prices and gets the counter ready for billing.') }}
                        </p>
                    </div>
                    <form method="POST" action="{{ route('settings.pricing.save-rates') }}" class="rate-modal__body" data-rate-form>
                        @csrf
                        <input type="hidden" name="context" value="modal">

                        @if($otherErrors->isNotEmpty())
                            <div class="rate-modal__errors" role="alert">
                                <ul>
                                    @foreach($otherErrors as $error)
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
                                        name="gold_24k_rate_per_gram"
                                        value="{{ old('gold_24k_rate_per_gram', $pricingTodayRate ? (float) $pricingTodayRate->gold_24k_rate_per_gram : null) }}"
                                        @if($pricingPrevGold !== null) data-previous="{{ $pricingPrevGold }}" data-previous-label="{{ $rupees($pricingPrevGold) }}" @endif
                                        @if($goldError) aria-invalid="true" aria-describedby="rm-gold-error" @elseif($pricingPrevGold !== null) aria-describedby="rm-gold-hint" @endif
                                        @if($goldError || ! $silverError) autofocus @endif
                                        required
                                    >
                                </div>
                                @if($goldError)
                                    <p id="rm-gold-error" class="rate-field__error">{{ $goldError }}</p>
                                @endif
                                @if($pricingPrevGold !== null)
                                    <p id="rm-gold-hint" class="hint">{{ __('Last saved :date: :rate', ['date' => $pricingPrevDate, 'rate' => $rupees($pricingPrevGold)]) }}</p>
                                @endif
                                <p class="rate-field__warn" data-rate-warn hidden></p>
                            </div>
                            <div class="rate-field">
                                <div class="rate-field__label">
                                    <label for="rm-silver">{{ __('Silver 999 Price / Kg') }}</label>
                                    {{-- Hover or keyboard focus shows it; a tap toggles it (touch has no hover). --}}
                                    <span class="rate-tip">
                                        <button type="button" class="rate-tip__btn" aria-label="{{ __('About the silver rate') }}" aria-describedby="rm-silver-tip" aria-expanded="false" data-rate-tip>
                                            <x-icon name="info" size="16" stroke-width="2" />
                                        </button>
                                        <span id="rm-silver-tip" role="tooltip" class="rate-tip__bubble">{{ __('We convert silver to a per-gram rate for you.') }}</span>
                                    </span>
                                </div>
                                <div class="rate-input-wrap">
                                    <span class="cur">₹</span>
                                    <input
                                        id="rm-silver"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        name="silver_999_rate_per_kg"
                                        value="{{ old('silver_999_rate_per_kg', $pricingTodayRate ? round((float) $pricingTodayRate->silver_999_rate_per_gram * 1000, 4) : null) }}"
                                        @if($pricingPrevSilverKg !== null) data-previous="{{ $pricingPrevSilverKg }}" data-previous-label="{{ $rupees($pricingPrevSilverKg) }}" @endif
                                        @if($silverError) aria-invalid="true" aria-describedby="rm-silver-error" @elseif($pricingPrevSilverKg !== null) aria-describedby="rm-silver-hint" @endif
                                        @if($silverError && ! $goldError) autofocus @endif
                                        required
                                    >
                                </div>
                                @if($silverError)
                                    <p id="rm-silver-error" class="rate-field__error">{{ $silverError }}</p>
                                @endif
                                @if($pricingPrevSilverKg !== null)
                                    <p id="rm-silver-hint" class="hint">{{ __('Last saved :date: :rate per kg', ['date' => $pricingPrevDate, 'rate' => $rupees($pricingPrevSilverKg)]) }}</p>
                                @endif
                                <p class="rate-field__warn" data-rate-warn hidden></p>
                            </div>
                        </div>

                        <button type="submit" class="rate-modal__cta" data-turbo-submits-with="{{ __('Saving…') }}">
                            {{ __('Save Today\'s Rates') }}
                            <x-icon name="arrow-right" stroke-width="2.2" />
                        </button>
                    </form>
                    {{-- The gate covers the sidebar, so sign-out (e.g. to switch account) lives here too. --}}
                    <form class="rate-modal__logout" method="POST" action="{{ route('logout') }}" data-turbo="false">
                        @csrf
                        <span>{{ __('Switching account?') }}</span>
                        <button type="submit">
                            <x-icon name="log-out" stroke-width="2" />
                            {{ __('Log Out') }}
                        </button>
                    </form>
                </div>
            </div>
            <script>
                (() => {
                    const modal = document.currentScript.previousElementSibling;
                    // Keyboard focus stays in the gate: the page around it is inert until the next page renders
                    // (restored before Turbo caches or swaps the page, so the permanent sidebar comes back live).
                    const inerted = [];
                    const hold = () => {
                        for (let el = modal; el.parentElement && el !== document.body; el = el.parentElement) {
                            for (const sib of el.parentElement.children) {
                                if (sib !== el && sib.tagName !== 'SCRIPT' && sib.id !== 'global-toast' && !sib.inert) { sib.inert = true; inerted.push(sib); }
                            }
                        }
                    };
                    // On a full load this runs before the page behind is parsed; Turbo renders have it already.
                    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', hold, { once: true }) : hold();
                    const restore = () => inerted.forEach((sib) => { sib.inert = false; });
                    document.addEventListener('turbo:before-cache', restore, { once: true });
                    document.addEventListener('turbo:before-render', restore, { once: true });

                    // Typo check, not a rule: a rate more than 10% away from the last saved one needs a second Save.
                    modal.querySelectorAll('[data-rate-tip]').forEach((btn) => btn.addEventListener('click', () => {
                        btn.setAttribute('aria-expanded', btn.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
                    }));
                    modal.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape') modal.querySelectorAll('[data-rate-tip]').forEach((btn) => btn.setAttribute('aria-expanded', 'false'));
                    });

                    const form = modal.querySelector('[data-rate-form]');
                    form.addEventListener('submit', (event) => {
                        let first = null;
                        form.querySelectorAll('input[data-previous]').forEach((input) => {
                            const warn = input.closest('.rate-field').querySelector('[data-rate-warn]');
                            const prev = parseFloat(input.dataset.previous);
                            const now = parseFloat(input.value.replace(/[,\s]/g, ''));
                            const pct = prev > 0 && now > 0 ? Math.round(Math.abs(now - prev) / prev * 100) : 0;
                            const show = pct > 10 && input.value !== input.dataset.checked;
                            warn.hidden = ! show;
                            if (show) {
                                warn.textContent = `${pct}% ${now > prev ? 'higher' : 'lower'} than last saved (${input.dataset.previousLabel}). Check it, then press Save again.`;
                                input.dataset.checked = input.value;
                                first ??= input;
                            }
                        });
                        if (first) { event.preventDefault(); first.focus(); }
                    });
                })();
            </script>
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
                            <x-icon name="menu" size="18" stroke-width="2.2" />
                        </span>
                        <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                            <x-icon name="x" size="18" stroke-width="2.2" />
                        </span>
                    </button>
                </div>
                
                <div class="sidebar-nav" id="sidebar-nav">
                    {{-- ─── WORKSPACE ─── --}}
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Workspace') }}</div>
                        <a href="{{ route($homeRoute) }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="house" size="16" /></span>
                            {{ __('Dashboard') }}
                        </a>
                        @if($hasRetailer || $hasManufacturer)
                        {{-- Vendors, Karigars and Product Catalog open from Masters, so their pages light this link. --}}
                        <a href="{{ route('masters.index') }}" class="nav-link {{ request()->routeIs('masters.*', 'vendors.*', 'karigars.*', 'products.*') ? 'active' : '' }}" data-nav-match="/vendors,/karigars,/products">
                            <span class="nav-icon"><x-icon name="layers" size="16" /></span>
                            {{ __('Masters') }}
                        </a>
                        {{-- @canopen: each link shows only when its page would let this user in (RouteAccess). --}}
                        @canopen('pos.index')
                        <a href="{{ route('pos.index') }}" class="nav-link {{ request()->routeIs('pos.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="shopping-cart" size="16" /></span>
                            {{ __('Sales Counter') }}
                        </a>
                        @endcanopen
                        @if(($authShop?->preferences?->quick_bill_enabled ?? true) && ($navOpen('quick-bills.create') || $navOpen('quick-bills.index')))
                        <a href="{{ route(auth()->user()->can('quick_bills.create') ? 'quick-bills.create' : 'quick-bills.index') }}" class="nav-link {{ request()->routeIs('quick-bills.*') ? 'active' : '' }}" data-nav-match="/quick-bills">
                            <span class="nav-icon"><x-icon name="file-text" size="16" /></span>
                            {{ __('Quick Bills') }}
                        </a>
                        @endif
                        @endif
                    </div>

                    {{-- ─── SALES ─── --}}
                    @php
                        $salesNav = [
                            'customers' => $navOpen('customers.index'),
                            'invoices' => $navOpen('invoices.index'),
                            'schemes' => $hasRetailer && $navOpen('schemes.index'),
                            'catalog' => $hasRetailer && $navOpen('catalog.index'),
                        ];
                        $salesNavAny = in_array(true, $salesNav, true) || $navCan('returns.view') || $navCan('returns.approve')
                            || ($hasRetailer && $strandedInstallments) || $strandedHistorical;
                    @endphp
                    @if(($hasRetailer || $hasManufacturer) && $salesNavAny)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Sales') }}</div>
                        {{-- Installments is the Customers page's "EMI / Installments" tab, so its pages light this link. --}}
                        @if($salesNav['customers'])
                        <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') || (! $strandedInstallments && request()->routeIs('installments.*')) ? 'active' : '' }}" @unless($strandedInstallments) data-nav-match="/installments" @endunless>
                            <span class="nav-icon"><x-icon name="users" size="16" /></span>
                            {{ __('Customers') }}
                        </a>
                        @endif
                        {{-- Historical Sales opens from the Invoices header, so its pages light this link too. --}}
                        @if($salesNav['invoices'])
                        <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') || (! $strandedHistorical && request()->routeIs('historical.*')) ? 'active' : '' }}" @unless($strandedHistorical) data-nav-match="/historical" @endunless>
                            <span class="nav-icon"><x-icon name="file-text" size="16" /></span>
                            {{ __('Invoices') }}
                        </a>
                        @endif
                        {{-- Operations still wins on /returns/control-center: the longer address wins. --}}
                        @can('returns.view')
                        <a href="{{ route('returns.index') }}" class="nav-link {{ request()->routeIs('returns.index') || request()->routeIs('returns.show') || request()->routeIs('exchanges.*') ? 'active' : '' }}" data-nav-match="/exchanges">
                            <span class="nav-icon"><x-icon name="corner-up-left" size="16" /></span>
                            {{ __('Returns / Exchange') }}
                        </a>
                        @endcan
                        @can('returns.approve')
                        <a href="{{ route('returns.control-center') }}" class="nav-link {{ request()->routeIs('returns.control-center') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="activity" size="16" /></span>
                            {{ __('Operations') }}
                        </a>
                        @endcan
                        @if($hasRetailer)
                        @if($salesNav['schemes'])
                        <a href="{{ route('schemes.index') }}" class="nav-link {{ request()->routeIs('schemes.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="gift" size="16" /></span>
                            {{ __('Schemes') }}
                        </a>
                        @endif
                        @if($strandedInstallments)
                        <a href="{{ route('installments.index') }}" class="nav-link {{ request()->routeIs('installments.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="credit-card" size="16" /></span>
                            {{ __('Installments') }}
                        </a>
                        @endif
                        @if($salesNav['catalog'])
                        <a href="{{ route('catalog.index') }}" class="nav-link {{ request()->routeIs('catalog.*') && ! request()->routeIs('catalog.website.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="message-circle" size="16" /></span>
                            {{ __('Catalog') }}
                        </a>
                        @endif
                        @endif
                        @if($strandedHistorical)
                        <a href="{{ route('historical.index') }}" class="nav-link {{ request()->routeIs('historical.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="history" size="16" /></span>
                            {{ __('Historical Sales') }}
                        </a>
                        @endif
                    </div>
                    @endif

                    {{-- ─── INVENTORY ─── --}}
                    @php
                        $inventoryNav = [
                            'gold' => $hasManufacturer && $navOpen('inventory.gold.index'),
                            'stock' => $navOpen('inventory.items.index'),
                            'purchases' => $hasRetailer && $navOpen('inventory.purchases.index'),
                        ];
                    @endphp
                    @if(($hasRetailer || $hasManufacturer) && in_array(true, $inventoryNav, true))
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Inventory') }}</div>
                        @if($inventoryNav['gold'])
                        <a href="{{ route('inventory.gold.index') }}" class="nav-link {{ request()->routeIs('inventory.gold.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="database" size="16" /></span>
                            {{ __('Gold Inventory') }}
                        </a>
                        @endif
                        {{-- Categories, Tag Printing and Reorder Alerts open from the Stock page's navigation row,
                             so their pages light this link. The reorder alert count came with them: it stays in
                             sight from every page here, on the way to the link that carries it. --}}
                        @if($inventoryNav['stock'])
                        <a href="{{ route('inventory.items.index') }}" class="nav-link {{ request()->routeIs('inventory.items.*', 'inventory.stock-aging', 'inventory.sell-trend', 'categories.*', 'sub-categories.*', 'tags.*', 'reorder.*') ? 'active' : '' }}" data-nav-match="/categories,/sub-categories,/tags,/reorder,/inventory/stock-aging,/inventory/sell-trend">
                            <span class="nav-icon"><x-icon name="package" size="16" /></span>
                            {{ __('Jewellery Stock') }}
                            {{-- Always present: the sidebar survives Turbo visits, so app.js re-reads the count from each page's bell. --}}
                            <span class="sidebar-alert-pill" title="{{ __('Reorder alerts') }}" data-reorder-pill @if(($reorderAlertCount ?? 0) === 0) hidden @endif>{{ $reorderAlertCount ?? 0 }}</span>
                        </a>
                        @endif
                        @if($inventoryNav['purchases'])
                        <a href="{{ route('inventory.purchases.index') }}" class="nav-link {{ request()->routeIs('inventory.purchases.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="shopping-bag" size="16" /></span>
                            {{ __('Stock Purchases') }}
                        </a>
                        @endif
                    </div>
                    @endif

                    {{-- ─── JOB WORK (retailer edition — bullion vault + karigar workflow) ─── --}}
                    @php
                        $jobWorkNav = $hasRetailer ? [
                            'vault' => $navOpen('vault.index'),
                            'jobOrders' => $navOpen('job-orders.index'),
                            'karigarBills' => $navOpen('karigar-invoices.index'),
                        ] : [];
                    @endphp
                    @if($hasRetailer && in_array(true, $jobWorkNav, true))
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Job Work') }}</div>
                        @if($jobWorkNav['vault'])
                        <a href="{{ route('vault.index') }}" class="nav-link {{ request()->routeIs('vault.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="lock" size="16" /></span>
                            {{ __('Metal Vault') }}
                        </a>
                        @endif
                        @if($jobWorkNav['jobOrders'])
                        <a href="{{ route('job-orders.index') }}" class="nav-link {{ request()->routeIs('job-orders.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="clipboard-list" size="16" /></span>
                            {{ __('Job Orders') }}
                        </a>
                        @endif
                        @if($jobWorkNav['karigarBills'])
                        <a href="{{ route('karigar-invoices.index') }}" class="nav-link {{ request()->routeIs('karigar-invoices.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="file-text" size="16" /></span>
                            {{ __('Karigar Bills') }}
                        </a>
                        @endif
                    </div>
                    @endif

                    {{-- ─── SERVICES ─── --}}
                    @if(($hasRetailer || $hasManufacturer) && $navOpen('repairs.index'))
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Services') }}</div>
                        <a href="{{ route('repairs.index') }}" class="nav-link {{ request()->routeIs('repairs.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="wrench" size="16" /></span>
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
                        $reportsHubActive = request()->routeIs('report.*', 'reporting.*', 'export.*')
                            && ! ($strandedCloseDay && request()->routeIs('report.closing'));
                    @endphp
                    @if($canOpenReportsHub || $canOpenCashBook || $strandedCloseDay)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Reports') }}</div>
                        @if($canOpenReportsHub)
                        <a href="{{ route('report.hub') }}" class="nav-link {{ $reportsHubActive ? 'active' : '' }}" data-nav-match="/report,/reporting,/export">
                            <span class="nav-icon"><x-icon name="layout-grid" size="16" /></span>
                            {{ __('All Reports') }}
                        </a>
                        @endif
                        @if($canOpenCashBook)
                        <a href="{{ route('cashbook.index') }}" class="nav-link {{ request()->routeIs('cashbook.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="book-open" size="16" /></span>
                            {{ __('Cash Book') }}
                        </a>
                        @endif
                        @if($strandedCloseDay)
                        <a href="{{ route('report.closing') }}" class="nav-link {{ request()->routeIs('report.closing') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="square-check-big" size="16" /></span>
                            {{ __('Close Day') }}
                        </a>
                        @endif
                    </div>
                    @endif
                    @endif

                    {{-- Fallbacks when a permitted export/import has no accessible hub. --}}
                    @if($strandedExport || $strandedImport)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Account') }}</div>
                        @if($strandedExport)
                        <a href="{{ route('export.index') }}" class="nav-link {{ request()->routeIs('export.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="download" size="16" /></span>
                            {{ __('Download Reports') }}
                        </a>
                        @endif
                        @if($strandedImport)
                        <a href="{{ route('imports.index') }}" class="nav-link {{ request()->routeIs('imports.*') ? 'active' : '' }}">
                            <span class="nav-icon"><x-icon name="upload" size="16" /></span>
                            {{ __('Import Data') }}
                        </a>
                        @endif
                    </div>
                    @endif
                </div>
                
                <div class="sidebar-footer">
                    {{-- Cashiers/staff never edit their own details (owner decision 2026-11-07) --}}
                    @can('owner-or-manager')
                    <a href="{{ route('settings.edit', ['tab' => 'profile']) }}" class="sidebar-footer-link {{ (request()->routeIs('profile.*') || (request()->routeIs('settings.*') && request()->query('tab') === 'profile')) ? 'is-active' : '' }}" data-nav-match="/profile">
                        <span class="nav-icon"><x-icon name="user" size="16" /></span>
                        {{ __('Profile') }}
                    </a>
                    @endcan
                    <a href="{{ route($settingsRoute) }}" class="sidebar-footer-link {{ request()->routeIs('settings.*') || ($canOpenSettings && request()->routeIs('imports.*')) ? 'is-active' : '' }}" @if($canOpenSettings) data-nav-match="/imports" @endif>
                        <span class="nav-icon"><x-icon name="settings" size="16" /></span>
                        {{ __('Settings') }}
                        <span class="sidebar-footer-role">{{ $authUser?->role?->display_name ?? __('Guest') }}</span>
                    </a>
                    <div x-data="{ showLogout: false }">
                        <button @click="showLogout = true" type="button" class="sidebar-footer-link sidebar-footer-link--danger sidebar-button-reset">
                            <span class="nav-icon"><x-icon name="log-out" size="16" /></span>
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
                                        <x-icon name="log-out" size="20" class="text-red-600" />
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
                // offers/deals render via their own surface (x-promo-banner), not here.
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
