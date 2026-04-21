<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JewelFlow Super Admin</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased admin-shell">
    @php
        $title = 'Platform Dashboard';
        $subtitle = 'Monitor global tenant health and control access.';
        if (request()->routeIs('admin.shops.*')) {
            $title = 'Shop Management';
            $subtitle = 'Search, inspect, activate, and deactivate tenant shops.';
        } elseif (request()->routeIs('admin.users.*')) {
            $title = 'User Management';
            $subtitle = 'Control user status, scope, and password recovery.';
        } elseif (request()->routeIs('admin.tenant-activity.*')) {
            $title = 'Tenant Activity';
            $subtitle = 'Operational metrics, spikes, and activity trends.';
        } elseif (request()->routeIs('admin.security.*')) {
            $title = 'Platform Security';
            $subtitle = 'Failed logins, impersonation, and enforcement signals.';
        } elseif (request()->routeIs('admin.system.jobs.*')) {
            $title = 'System Jobs';
            $subtitle = 'Queue health, failures, and retries.';
        } elseif (request()->routeIs('admin.platform-admins.*')) {
            $title = 'Platform Admins';
            $subtitle = 'Manage platform administrator access.';
        } elseif (request()->routeIs('admin.plans.*')) {
            $title = 'Plan Management';
            $subtitle = 'Configure subscription products, billing cycles, and feature limits.';
        } elseif (request()->routeIs('admin.subscriptions.*')) {
            $title = 'Subscriptions';
            $subtitle = 'Monitor tenant subscriptions, billing state, and renewal health.';
        } elseif (request()->routeIs('admin.settings.*')) {
            $title = 'Platform Settings';
            $subtitle = 'Control platform-wide behaviour and availability for all tenants.';
        }
    @endphp

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
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="admin-btn admin-btn-danger w-full">Logout</button>
                </form>
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
            </header>

            <div class="px-5 py-6 lg:px-8">
                @if(session('success'))
                    <div class="admin-alert admin-alert-success mb-4">
                        {{ session('success') }}
                    </div>
                @endif

                @if($errors->any())
                    <div class="admin-alert admin-alert-error mb-4">
                        {{ $errors->first() }}
                    </div>
                @endif

                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
