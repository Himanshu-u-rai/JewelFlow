<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if(request()->routeIs('dhiran.product-preferences.*'))
        <meta name="turbo-cache-control" content="no-cache">
    @endif
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
                    <x-icon name="x" />
                </button>
            </div>

            {{-- Dhiran-only navigation. No POS / inventory / job-order / ERP links. --}}
            <nav class="dh-nav">
                <a href="{{ route('dhiran.dashboard') }}" class="dh-nav-link {{ request()->routeIs('dhiran.dashboard') ? 'active' : '' }}" @click="open = false">
                    <x-icon name="house" />
                    <span class="dh-nav-text">Dashboard</span>
                </a>
                @if((bool) auth()->user()?->shop_id)
                    <a href="{{ route('dhiran.loans') }}" class="dh-nav-link {{ request()->routeIs('dhiran.loans') || request()->routeIs('dhiran.show') ? 'active' : '' }}" @click="open = false">
                        <x-icon name="credit-card" />
                        <span class="dh-nav-text">Loans</span>
                    </a>
                    <a href="{{ route('dhiran.borrowers.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.borrowers.*') ? 'active' : '' }}" @click="open = false">
                        <x-icon name="users" />
                        <span class="dh-nav-text">Borrowers</span>
                    </a>
                    <a href="{{ route('dhiran.create') }}" class="dh-nav-link {{ request()->routeIs('dhiran.create') ? 'active' : '' }}" @click="open = false">
                        <x-icon name="plus" />
                        <span class="dh-nav-text">New Loan</span>
                    </a>
                    <a href="{{ route('dhiran.reports.index') }}" class="dh-nav-link {{ request()->routeIs('dhiran.reports.*') ? 'active' : '' }}" @click="open = false">
                        <x-icon name="chart-no-axes-column" />
                        <span class="dh-nav-text">Reports</span>
                    </a>
                    <a href="{{ route('dhiran.settings') }}" class="dh-nav-link {{ request()->routeIs('dhiran.settings') ? 'active' : '' }}" @click="open = false">
                        <x-icon name="settings" />
                        <span class="dh-nav-text">Settings</span>
                    </a>
                @endif
            </nav>

            <div class="dh-foot">
                <div class="dh-foot-user">{{ auth()->user()->name ?? \App\Support\Mobile::forDisplay(auth()->user()?->mobile_number) }}</div>
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
                            <x-icon name="menu" />
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
