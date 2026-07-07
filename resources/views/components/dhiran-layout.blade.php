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
    @vite(['resources/css/app.css', 'resources/css/dhiran.css', 'resources/js/app.js'])

    {{-- Dhiran shell — a dedicated, Dhiran-only navigation surface. NONE of the
         ERP chrome (POS, inventory, job orders, returns, ERP reports) appears
         here: the markup simply does not include those links. --}}
</head>
<body class="dh-app">
    <div class="dh-shell"
         x-data="{ open: false }"
         :class="{ 'is-open': open }"
         @keydown.escape.window="open = false"
         @turbo:before-cache.window="open = false"
         @dhiran-menu.window="open = true">

        {{-- Scrim: tap to close the drawer. --}}
        <div class="dh-scrim" @click="open = false" aria-hidden="true"></div>

        <aside class="dh-sidebar" id="dh-sidebar">
            <div class="dh-sidebar-head">
                <a href="{{ route('dhiran.dashboard') }}" class="dh-brand" @click="open = false">
                    <span class="dh-brand-mark">D</span>
                    <span class="dh-brand-copy">
                        <span class="dh-brand-name">Dhiran</span>
                        <span class="dh-brand-sub">Pledge Loan Manager</span>
                    </span>
                </a>
                <button type="button" class="dh-sidebar-close"
                        @click="open = false"
                        aria-label="Close menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            {{-- Dhiran-only navigation. No POS / inventory / job-order / ERP links. --}}
            <nav class="dh-nav">
                <a href="{{ route('dhiran.dashboard') }}" class="dh-nav-link {{ request()->routeIs('dhiran.dashboard') ? 'active' : '' }}" @click="open = false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span class="dh-nav-text">Dashboard</span>
                </a>
                @if((bool) auth()->user()?->shop_id)
                    <a href="{{ route('dhiran.loans') }}" class="dh-nav-link {{ request()->routeIs('dhiran.loans') || request()->routeIs('dhiran.show') ? 'active' : '' }}" @click="open = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span class="dh-nav-text">Loans</span>
                    </a>
                    <a href="{{ route('dhiran.borrowers.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.borrowers.*') ? 'active' : '' }}" @click="open = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span class="dh-nav-text">Borrowers</span>
                    </a>
                    <a href="{{ route('dhiran.create') }}" class="dh-nav-link {{ request()->routeIs('dhiran.create') ? 'active' : '' }}" @click="open = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span class="dh-nav-text">New Loan</span>
                    </a>
                    <a href="{{ route('dhiran.reports.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.reports.*') ? 'active' : '' }}" @click="open = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                        <span class="dh-nav-text">Reports</span>
                    </a>
                    <a href="{{ route('dhiran.settings') }}" class="dh-nav-link {{ request()->routeIs('dhiran.settings') ? 'active' : '' }}" @click="open = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span class="dh-nav-text">Settings</span>
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
            @php
                $slotMarkup = (string) $slot;
                $hasPageHeader = str_contains($slotMarkup, 'dh-page-header');
            @endphp

            @if(session('success'))
                <div class="dh-flash dh-flash-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="dh-flash dh-flash-error">{{ session('error') }}</div>
            @endif

            @unless($hasPageHeader)
                <div class="content-header dh-page-header dh-page-header-fallback">
                    <div class="dh-page-header-content">
                        <button type="button" class="dh-header-toggle"
                                onclick="window.dispatchEvent(new CustomEvent('dhiran-menu'))"
                                aria-label="Open menu" aria-controls="dh-sidebar">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                        </button>
                        <div class="min-w-0">
                            <h1 class="page-title">{{ $title ?? 'Dhiran' }}</h1>
                        </div>
                    </div>
                </div>
            @endunless

            {!! $slotMarkup !!}
        </main>
    </div>
    @stack('scripts')
</body>
</html>
