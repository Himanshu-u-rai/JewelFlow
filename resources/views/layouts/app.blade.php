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

        <title>{{ config('app.name', 'JewelFlow') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="app-shell">
        <x-app-alerts floating :show-success="false" :show-validation="false" message-only />

        @php
            $authUser = auth()->user();
            $authShop = $authUser?->shop;
            $hasRetailer = (bool) $authShop?->isRetailer();
            $hasManufacturer = (bool) $authShop?->isManufacturer();
            $hasDhiran = (bool) $authShop?->hasDhiran();
            $dhiranOnly = $hasDhiran && ! $hasRetailer && ! $hasManufacturer;
            $onDhiranHost = str_starts_with(request()->getHost(), 'dhiran.');
            $onDhiranRoute = request()->routeIs('dhiran.*');
            $dhiranChrome = $dhiranOnly || $onDhiranHost || $onDhiranRoute;
            $homeRoute = $dhiranChrome ? 'dhiran.dashboard' : 'dashboard';
            $brandName = $dhiranChrome ? __('Dhiran') : 'JewelFlow';
            $brandSubtitle = $dhiranChrome ? __('Gold Loan Suite') : __('Enterprise System');
            $settingsRoute = $dhiranChrome ? 'dhiran.settings' : 'settings.edit';
        @endphp
        @php
            if ($dhiranChrome) {
                $hasRetailer = false;
                $hasManufacturer = false;
            }
        @endphp

        <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>
        <div id="turbo-stream-toasts" style="display:none"></div>

        @php
            $viewErrors = $errors ?? new \Illuminate\Support\ViewErrorBag();
            $pricingModalErrors = $viewErrors->getBag('pricingModal');
            $pricingTodayRate = $pricingShellState['today_rate'] ?? null;
        @endphp
        @if(($pricingShellState['show_owner_modal'] ?? false) === true)
            <div class="pricing-shell-modal">
                <div class="pricing-shell-modal__backdrop"></div>
                <div class="pricing-shell-modal__panel relative w-full max-w-2xl rounded-3xl border border-slate-200 bg-white shadow-2xl">
                    <div class="px-8 py-7 border-b border-slate-200">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">{{ __('Retailer Pricing Required') }}</div>
                        <h2 class="mt-2 text-2xl font-bold text-slate-900">{{ __('Enter Today\'s Metal Rates') }}</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            {{ __('The owner must save today\'s retailer pricing before the rest of the team can continue with pricing-sensitive stock and POS actions.') }}
                        </p>
                        <p class="mt-3 text-sm font-medium text-slate-800">
                            {{ __('Business date:') }} {{ $pricingShellState['business_date'] ?? '—' }}
                            <span class="text-slate-500">({{ $pricingShellState['timezone'] ?? config('app.timezone', 'UTC') }})</span>
                        </p>
                    </div>
                    <form method="POST" action="{{ route('settings.pricing.save-rates') }}" class="px-8 py-7">
                        @csrf
                        <input type="hidden" name="context" value="modal">
                        <input type="hidden" name="redirect_to" value="{{ url()->full() }}">

                        @if($pricingModalErrors->any())
                            <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                                <ul class="list-disc list-inside space-y-1">
                                    @foreach($pricingModalErrors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">{{ __('24K Gold Price / Gram') }}</label>
                                <input
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    name="gold_24k_rate_per_gram"
                                    value="{{ old('gold_24k_rate_per_gram', $pricingTodayRate ? (float) $pricingTodayRate->gold_24k_rate_per_gram : null) }}"
                                    class="w-full rounded-2xl border-slate-300 focus:border-amber-500 focus:ring-amber-500"
                                    required
                                >
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">{{ __('Silver 999 Price / Kg') }}</label>
                                <input
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    name="silver_999_rate_per_kg"
                                    value="{{ old('silver_999_rate_per_kg', $pricingTodayRate ? round((float) $pricingTodayRate->silver_999_rate_per_gram * 1000, 4) : null) }}"
                                    class="w-full rounded-2xl border-slate-300 focus:border-amber-500 focus:ring-amber-500"
                                    required
                                >
                                <p class="mt-2 text-xs text-slate-500">{{ __('We convert silver to a per-gram internal rate automatically.') }}</p>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-between gap-4">
                            <div class="text-xs text-slate-500">
                                {{ __('Saving today\'s rates will refresh current per-purity pricing and queue an in-stock retailer repricing job.') }}
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-amber-600 px-5 py-3 text-sm font-semibold text-white hover:bg-amber-700">
                                {{ __('Save Today\'s Rates') }}
                            </button>
                        </div>
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
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Main') }}</div>
                        <a href="{{ route($homeRoute) }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            {{ __('Dashboard') }}
                        </a>
                        @if($hasRetailer || $hasManufacturer)
                        <a href="{{ route('pos.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            {{ __('Point of Sale') }}
                        </a>
                        @endif
                    </div>

                    @if($hasRetailer || $hasManufacturer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Inventory') }}</div>
                        @if($hasManufacturer)
                        <a href="{{ route('inventory.gold.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                            {{ __('Gold Inventory') }}
                        </a>
                        @endif
                        <a href="{{ route('inventory.items.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                            {{ __('Stock / Items') }}
                        </a>
                        @if($hasRetailer)
                        <a href="{{ route('inventory.purchases.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                            {{ __('Stock Purchases') }}
                        </a>
                        @endif
                        <a href="{{ route('categories.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                            {{ __('Categories') }}
                        </a>
                        @if($hasManufacturer)
                        <a href="{{ route('products.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                            {{ __('Product Catalog') }}
                        </a>
                        @endif
                        <a href="{{ route('customers.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            {{ __('Customers') }}
                        </a>
                        <a href="{{ route('repairs.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            {{ __('Repairs') }}
                        </a>
                        <a href="{{ route('invoices.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            {{ __('Invoices') }}
                        </a>
                    </div>
                    @endif

                    @if($hasRetailer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Job Work') }}</div>
                        <a href="{{ route('vault.index') }}" class="nav-link {{ request()->routeIs('vault.*') ? 'active' : '' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16.5" r="1"/></svg>
                            {{ __('Bullion Vault') }}
                        </a>
                        <a href="{{ route('karigars.index') }}" class="nav-link {{ request()->routeIs('karigars.*') ? 'active' : '' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            {{ __('Karigars') }}
                        </a>
                        <a href="{{ route('job-orders.index') }}" class="nav-link {{ request()->routeIs('job-orders.*') ? 'active' : '' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11H3v10h6V11z"/><path d="M21 3h-6v18h6V3z"/><path d="M15 11H9V3h6v8z"/></svg>
                            {{ __('Job Orders') }}
                        </a>
                        <a href="{{ route('karigar-invoices.index') }}" class="nav-link {{ request()->routeIs('karigar-invoices.*') ? 'active' : '' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            {{ __('Karigar Invoices') }}
                        </a>
                    </div>
                    @endif

                    @if($hasRetailer)
                    {{-- Retailers: simplified "Reports" section --}}
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Reports') }}</div>
                        <a href="{{ route('cashbook.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            {{ __('Cash Ledger') }}
                        </a>
                        <a href="{{ route('report.closing') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            {{ __('Daily Closing') }}
                        </a>
                        <a href="{{ route('report.gst') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
                            {{ __('GST Reports') }}
                        </a>
                        <a href="{{ route('report.metal-exchange') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            {{ __('Metal Exchange') }}
                        </a>
                    </div>
                    @elseif($hasManufacturer)
                    {{-- Manufacturers: full Finance + Operations --}}
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Finance') }}</div>
                        <a href="{{ route('cashbook.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            {{ __('Cash Ledger') }}
                        </a>
                        <a href="{{ route('report.cash') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                            {{ __('Cash Flow Dashboard') }}
                        </a>
                        <a href="{{ route('report.pnl') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            {{ __('Profit & Loss') }}
                        </a>
                        <a href="{{ route('report.gst') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
                            {{ __('GST Reports') }}
                        </a>
                    </div>
                    
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Operations') }}</div>
                        <a href="{{ route('report.daily') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
                            {{ __('Daily Reports') }}
                        </a>
                        <a href="{{ route('report.closing') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                            {{ __('Daily Closing') }}
                        </a>
                        <a href="{{ route('report.gold') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            {{ __('Gold Report') }}
                        </a>
                        @if($hasRetailer)
                        <a href="{{ route('report.metal-exchange') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            {{ __('Metal Exchange') }}
                        </a>
                        @endif
                    </div>
                    @endif
                    
                    @if($hasRetailer)
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Retail') }}</div>
                        <a href="{{ route('vendors.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                            {{ __('Vendors') }}
                        </a>
                        <a href="{{ route('schemes.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                            {{ __('Schemes') }}
                        </a>
                        <a href="{{ route('reorder.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            {{ __('Reorder Alerts') }}
                            @if(($reorderAlertCount ?? 0) > 0)
                                <span class="sidebar-alert-pill">{{ $reorderAlertCount }}</span>
                            @endif
                        </a>
                        <a href="{{ route('tags.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            {{ __('Tag Printing') }}
                        </a>
                        <a href="{{ route('catalog.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                            {{ __('WhatsApp Catalog') }}
                        </a>
                    </div>
                    @endif

                    @if($onDhiranHost)
                    @can('dhiran.view')
                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('Gold Loans') }}</div>
                        <a href="{{ route('dhiran.dashboard') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v4"/><path d="M12 14v4"/><path d="M16 14v4"/></svg>
                            {{ __('Dashboard') }}
                        </a>
                        <a href="{{ route('dhiran.loans') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            {{ __('Loans') }}
                        </a>
                        <a href="{{ route('dhiran.settings') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.573-1.066z"/><circle cx="12" cy="12" r="3"/></svg>
                            {{ __('Settings') }}
                        </a>
                        <a href="{{ route('dhiran.reports.active') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            {{ __('Reports') }}
                        </a>
                    </div>
                    @endcan
                    @endif

                    <div class="nav-section">
                        <div class="nav-section-title">{{ __('System') }}</div>
                        @if($authUser?->role === 'owner')
                        <a href="{{ route('staff.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                            {{ __('Staff') }}
                        </a>
                        @endif
                        <a href="{{ route('export.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            {{ __('Export Data') }}
                        </a>
                        @if($hasRetailer || $hasManufacturer)
                        <a href="{{ route('imports.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            {{ __('Bulk Imports') }}
                        </a>
                        <a href="{{ route('quick-bills.index') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 2h8l4 4v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M9 9h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
                            {{ __('Quick Bills') }}
                        </a>
                        @endif
                        <a href="{{ route('subscription.status') }}" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v4Z"/><path d="M4 6v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/><path d="M12 12v4h4"/>
                            </svg>
                            {{ __('Subscription') }}
                        </a>
                    </div>
                </div>
                
                <div class="sidebar-footer">
                    <a href="{{ route($settingsRoute) }}" class="sidebar-footer-link">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.573-1.066z"/><circle cx="12" cy="12" r="3"/></svg>
                        {{ __('Settings') }}
                        <span class="sidebar-footer-role">{{ $authUser?->isOwner() ? __('Owner') : ($authUser ? __('Cashier') : __('Guest')) }}</span>
                    </a>
                    <div x-data="{ showLogout: false }">
                        <button @click="showLogout = true" type="button" class="sidebar-footer-link sidebar-button-reset">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
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
            <main id="main-content" class="content-area" role="main">
                @include('components.impersonation-banner')
                @isset($header)
                    <x-page-header>
                        {{ $header }}
                    </x-page-header>
                @endisset
                <div class="content-body">
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
        <footer class="app-footer">
            &copy; {{ date('Y') }} JewelFlow. All rights reserved.
        </footer>
    </body>
</html>
