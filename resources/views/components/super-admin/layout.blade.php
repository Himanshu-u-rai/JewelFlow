@props([
    'title' => 'Platform Dashboard',
    'subtitle' => 'Monitor global tenant health and control access.',
])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>JewelFlow Super Admin</title>
    @include('partials.favicon')
    @if(session('success'))
        <meta name="flash-success" content="{{ session('success') }}">
    @endif
    @if(session('error'))
        <meta name="flash-error" content="{{ session('error') }}">
    @endif
    @if(session('warning'))
        <meta name="flash-warning" content="{{ session('warning') }}">
    @endif
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased admin-shell">
    <div class="admin-shell-frame">
        <aside id="admin-sidebar" class="admin-sidebar border-b backdrop-blur-lg lg:border-b-0 lg:border-r" data-mobile-drawer="admin">
            <div class="admin-sidebar-header p-5">
                <div class="admin-sidebar-header-main">
                    <p class="admin-brand-kicker">JewelFlow</p>
                    <h1 class="admin-brand-title">Control Tower</h1>
                    <p class="admin-brand-sub">Super Admin Console</p>
                </div>
                <button type="button" class="admin-sidebar-close" data-mobile-drawer-toggle="admin" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Close admin navigation">
                    <span class="drawer-toggle-icon drawer-toggle-icon-menu" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </span>
                    <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </span>
                </button>
            </div>
            <nav class="px-3 pb-5 space-y-1">
                <a href="{{ route('admin.dashboard') }}" class="admin-nav-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">
                    <span>Dashboard</span>
                    <span class="admin-nav-suffix">Ctrl</span>
                </a>
                <a href="{{ route('admin.shops.index') }}" class="admin-nav-link {{ request()->routeIs('admin.shops.*') ? 'is-active' : '' }}">
                    <span>Shops</span>
                    <span class="admin-nav-suffix">Tenants</span>
                </a>
                <a href="{{ route('admin.users.index') }}" class="admin-nav-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
                    <span>Users</span>
                    <span class="admin-nav-suffix">Identity</span>
                </a>

                <div class="pt-2">
                    <div class="admin-section-label">Billing</div>
                </div>
                <a href="{{ route('admin.plans.index') }}" class="admin-nav-link {{ request()->routeIs('admin.plans.*') ? 'is-active' : '' }}">
                    <span>Plans</span>
                    <span class="admin-nav-suffix">Products</span>
                </a>
                <a href="{{ route('admin.subscriptions.index') }}" class="admin-nav-link {{ request()->routeIs('admin.subscriptions.*') ? 'is-active' : '' }}">
                    <span>Subscriptions</span>
                    <span class="admin-nav-suffix">Tenants</span>
                </a>
                <a href="{{ route('admin.invoices.index') }}" class="admin-nav-link {{ request()->routeIs('admin.invoices.*') ? 'is-active' : '' }}">
                    <span>Invoices</span>
                    <span class="admin-nav-suffix">Billing</span>
                </a>

                <a href="{{ route('admin.settings.index') }}" class="admin-nav-link {{ request()->routeIs('admin.settings.*') ? 'is-active' : '' }}">
                    <span>Settings</span>
                    <span class="admin-nav-suffix">Config</span>
                </a>

                <div class="pt-2">
                    <div class="admin-section-label">Operations</div>
                </div>
                <a href="{{ route('admin.tenant-activity.index') }}" class="admin-nav-link {{ request()->routeIs('admin.tenant-activity.*') ? 'is-active' : '' }}">
                    <span>Tenant Activity</span>
                    <span class="admin-nav-suffix">Metrics</span>
                </a>
                <a href="{{ route('admin.security.index') }}" class="admin-nav-link {{ request()->routeIs('admin.security.*') ? 'is-active' : '' }}">
                    <span>Security</span>
                    <span class="admin-nav-suffix">Signals</span>
                </a>
                <a href="{{ route('admin.fraud-flags.index') }}" class="admin-nav-link {{ request()->routeIs('admin.fraud-flags.*') ? 'is-active' : '' }}">
                    <span>Fraud Flags</span>
                    <span class="admin-nav-suffix">Detect</span>
                </a>
                <a href="{{ route('admin.system.jobs.index') }}" class="admin-nav-link {{ request()->routeIs('admin.system.jobs.*') ? 'is-active' : '' }}">
                    <span>System Jobs</span>
                    <span class="admin-nav-suffix">Queues</span>
                </a>
                @if(auth('platform_admin')->user()?->isSuperAdmin())
                    <a href="{{ route('admin.platform-admins.index') }}" class="admin-nav-link {{ request()->routeIs('admin.platform-admins.*') ? 'is-active' : '' }}">
                        <span>Platform Admins</span>
                        <span class="admin-nav-suffix">Control</span>
                    </a>
                @endif
            </nav>
            <div class="px-4 pb-5">
                <button type="button" class="admin-btn admin-btn-danger w-full" onclick="document.getElementById('admin-logout-dialog')?.showModal()">
                    Logout
                </button>
            </div>
        </aside>
        <div class="admin-sidebar-overlay" data-mobile-drawer-overlay="admin"></div>

        <main class="admin-main">
            <header class="admin-topbar border-b px-5 py-5 backdrop-blur-lg lg:px-8">
                <button type="button" class="admin-mobile-toggle" data-mobile-drawer-toggle="admin" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Open admin navigation">
                    <span class="drawer-toggle-icon drawer-toggle-icon-menu" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </span>
                    <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </span>
                </button>
                <div class="admin-topbar-inner">
                    <h2 class="admin-title">{{ $title }}</h2>
                    <p class="admin-subtitle">{{ $subtitle }}</p>
                </div>
                @if(request()->routeIs('admin.dashboard'))
                    <nav class="admin-topbar-actions" aria-label="Quick controls">
                        <a href="{{ route('admin.shops.index') }}" class="admin-btn admin-btn-secondary admin-btn-sm">Shops</a>
                        <a href="{{ route('admin.users.index') }}" class="admin-btn admin-btn-secondary admin-btn-sm">Users</a>
                        <a href="{{ route('admin.security.index') }}" class="admin-btn admin-btn-secondary admin-btn-sm">Security</a>
                        <a href="{{ route('admin.system.jobs.index') }}" class="admin-btn admin-btn-primary admin-btn-sm">Jobs</a>
                    </nav>
                @endif
            </header>

            <div class="px-5 py-6 lg:px-8">
                {{ $slot }}
            </div>
        </main>
    </div>

    <dialog id="admin-logout-dialog" class="admin-modal">
        <form method="dialog" class="admin-modal-card">
            <div class="admin-modal-header">
                <div>
                    <h3 class="admin-modal-title">Sign out?</h3>
                    <p class="admin-modal-copy">You will leave the Super Admin console.</p>
                </div>
                <button type="submit" class="admin-modal-close" aria-label="Close logout confirmation">&times;</button>
            </div>
            <div class="admin-modal-actions">
                <button type="submit" class="admin-btn admin-btn-secondary">Cancel</button>
                <button type="submit"
                        class="admin-btn admin-btn-danger"
                        form="admin-logout-form">
                    Logout
                </button>
            </div>
        </form>
    </dialog>
    <form id="admin-logout-form" method="POST" action="{{ route('admin.logout') }}" class="hidden">
        @csrf
    </form>

    <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>
</body>
</html>
