<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dhiran' }}</title>
    @include('partials.favicon')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- Dhiran shell — a dedicated, Dhiran-only navigation surface. NONE of the
         ERP chrome (POS, inventory, job orders, returns, ERP reports) appears
         here: the markup simply does not include those links. --}}
    <style>
        :root {
            --dh-gold: #f4a300;
            --dh-gold-deep: #d98b00;
            --dh-ink: #0f172a;
            --dh-muted: #64748b;
            --dh-line: #e2e8f0;
            --dh-bg: #f7f8fb;
            --dh-ease: cubic-bezier(0.23, 1, 0.32, 1);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background: var(--dh-bg);
            color: var(--dh-ink);
        }
        .dh-shell { display: flex; min-height: 100vh; }
        .dh-sidebar {
            width: 248px;
            flex-shrink: 0;
            background: #fff;
            border-right: 1px solid var(--dh-line);
            display: flex;
            flex-direction: column;
            padding: 20px 14px;
            /* Pin the sidebar: it stays put while only the main content scrolls. */
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .dh-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 8px 18px;
            border-bottom: 1px solid var(--dh-line);
            margin-bottom: 14px;
        }
        .dh-brand-mark {
            width: 34px; height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--dh-gold), var(--dh-gold-deep));
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 17px;
        }
        .dh-brand-name { font-weight: 800; font-size: 17px; letter-spacing: -0.01em; }
        .dh-brand-sub { font-size: 11px; color: var(--dh-muted); margin-top: 1px; }
        .dh-nav { display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .dh-nav-link {
            display: flex; align-items: center; gap: 11px;
            padding: 10px 12px;
            border-radius: 10px;
            color: #334155;
            text-decoration: none;
            font-size: 14px; font-weight: 600;
            transition: background 160ms var(--dh-ease), color 160ms var(--dh-ease);
        }
        .dh-nav-link svg { width: 18px; height: 18px; flex-shrink: 0; }
        @media (hover: hover) and (pointer: fine) {
            .dh-nav-link:hover { background: #fff7ea; color: var(--dh-gold-deep); }
        }
        .dh-nav-link.active { background: #fff3df; color: var(--dh-gold-deep); }
        .dh-foot { border-top: 1px solid var(--dh-line); padding-top: 12px; }
        .dh-foot-user { font-size: 13px; font-weight: 700; }
        .dh-foot-shop { font-size: 11px; color: var(--dh-muted); margin-bottom: 8px; }
        .dh-logout {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--dh-line);
            border-radius: 9px;
            background: #fff;
            color: #475569;
            font-size: 13px; font-weight: 600;
            cursor: pointer;
            transition: transform 140ms var(--dh-ease), background 140ms var(--dh-ease);
        }
        .dh-logout:active { transform: scale(0.97); }
        @media (hover: hover) and (pointer: fine) {
            .dh-logout:hover { background: #f1f5f9; }
        }
        .dh-main { flex: 1; min-width: 0; padding: 26px 30px; }
        .dh-flash {
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 18px;
            font-size: 14px; font-weight: 600;
        }
        .dh-flash-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .dh-flash-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* iOS-style drawer easing — slides in fast, settles soft. */
        :root { --dh-ease-drawer: cubic-bezier(0.32, 0.72, 0, 1); }

        /* Mobile nav toggle: a self-contained floating control (no top bar),
           so it never reads as an empty/duplicate header. Hidden ≥720px. */
        .dh-menu-toggle { display: none; }
        .dh-menu-toggle {
            align-items: center; justify-content: center;
            width: 42px; height: 42px; border-radius: 12px;
            border: 1px solid var(--dh-line); background: #fff; color: var(--dh-ink);
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.10);
            cursor: pointer;
            transition: transform 140ms var(--dh-ease), background 140ms var(--dh-ease), opacity 160ms var(--dh-ease);
        }
        .dh-menu-toggle:active { transform: scale(0.94); }
        @media (hover: hover) and (pointer: fine) {
            .dh-menu-toggle:hover { background: #f1f5f9; }
        }
        .dh-menu-toggle svg { width: 21px; height: 21px; }
        /* Scrim sits behind the drawer; fades with opacity only. */
        .dh-scrim {
            position: fixed; inset: 0; z-index: 40;
            background: rgba(15, 23, 42, 0.45);
            opacity: 0; pointer-events: none;
            transition: opacity 240ms var(--dh-ease-drawer);
        }

        @media (max-width: 720px) {
            .dh-shell { flex-direction: column; }
            /* Float the toggle top-left, above content. */
            .dh-menu-toggle {
                display: inline-flex;
                position: fixed; top: 14px; left: 14px; z-index: 45;
            }
            /* Hide the toggle while the drawer is open (drawer + scrim take over). */
            .dh-shell.is-open .dh-menu-toggle { opacity: 0; pointer-events: none; }

            /* Sidebar becomes an off-canvas left drawer. */
            .dh-sidebar {
                position: fixed; top: 0; left: 0; bottom: 0;
                width: min(82vw, 300px); height: 100dvh; z-index: 50;
                box-shadow: 0 12px 40px rgba(15, 23, 42, 0.18);
                transform: translateX(-100%);
                transition: transform 260ms var(--dh-ease-drawer);
                will-change: transform;
            }
            /* Leave room for the floating toggle at the top of the content. */
            .dh-main { padding: 18px; padding-top: 70px; }

            /* Open state, driven by Alpine on .dh-shell. */
            .dh-shell.is-open .dh-sidebar { transform: translateX(0); }
            .dh-shell.is-open .dh-scrim { opacity: 1; pointer-events: auto; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dh-sidebar, .dh-scrim, .dh-menu-toggle { transition-duration: 0ms; }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="dh-shell"
         x-data="{ open: false }"
         :class="{ 'is-open': open }"
         @keydown.escape.window="open = false"
         @turbo:before-cache.window="open = false">

        {{-- Mobile-only floating nav toggle. No top bar: the brand and links live
             inside the drawer. Hidden ≥720px. --}}
        <button type="button" class="dh-menu-toggle"
                @click="open = true"
                :aria-expanded="open"
                aria-label="Open menu" aria-controls="dh-sidebar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>

        {{-- Scrim: tap to close the drawer. --}}
        <div class="dh-scrim" @click="open = false" aria-hidden="true"></div>

        <aside class="dh-sidebar" id="dh-sidebar" @click="open = false">
            <a href="{{ route('dhiran.dashboard') }}" class="dh-brand" style="text-decoration:none;color:inherit;">
                <span class="dh-brand-mark">D</span>
                <span>
                    <span class="dh-brand-name">Dhiran</span>
                    <span class="dh-brand-sub">Pledge Loan Manager</span>
                </span>
            </a>

            {{-- Dhiran-only navigation. No POS / inventory / job-order / ERP links. --}}
            <nav class="dh-nav">
                <a href="{{ route('dhiran.dashboard') }}" class="dh-nav-link {{ request()->routeIs('dhiran.dashboard') ? 'active' : '' }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Dashboard
                </a>
                @if((bool) auth()->user()?->shop_id)
                    <a href="{{ route('dhiran.loans') }}" class="dh-nav-link {{ request()->routeIs('dhiran.loans') || request()->routeIs('dhiran.show') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Loans
                    </a>
                    <a href="{{ route('dhiran.create') }}" class="dh-nav-link {{ request()->routeIs('dhiran.create') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        New Loan
                    </a>
                    <a href="{{ route('dhiran.reports.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.reports.*') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        Reports
                    </a>
                    <a href="{{ route('dhiran.settings') }}" class="dh-nav-link {{ request()->routeIs('dhiran.settings') ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Settings
                    </a>
                @endif
            </nav>

            <div class="dh-foot">
                <div class="dh-foot-user">{{ auth()->user()->name ?? auth()->user()->mobile_number }}</div>
                <div class="dh-foot-shop">{{ auth()->user()->shop?->name ?? 'Dhiran account' }}</div>
                <form method="POST" action="{{ route('logout') }}" data-turbo-frame="_top">
                    @csrf
                    <button type="submit" class="dh-logout">Sign out</button>
                </form>
            </div>
        </aside>

        <main class="dh-main">
            @if(session('success'))
                <div class="dh-flash dh-flash-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="dh-flash dh-flash-error">{{ session('error') }}</div>
            @endif

            {{ $slot }}
        </main>
    </div>
    @stack('scripts')
</body>
</html>
