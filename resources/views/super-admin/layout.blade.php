<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>JewelFlow Super Admin</title>
    @if(session('success'))
        <meta name="flash-success" content="{{ session('success') }}">
    @endif
    @if(session('error'))
        <meta name="flash-error" content="{{ session('error') }}">
    @endif
    @if(session('warning'))
        <meta name="flash-warning" content="{{ session('warning') }}">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    <header class="bg-slate-900 text-white border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="font-semibold text-lg">JewelFlow Control Tower</h1>
                <p class="text-xs text-slate-300">Super Admin Panel</p>
            </div>
            <nav class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.dashboard') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.dashboard') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Dashboard</a>
                <a href="{{ route('admin.shops.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.shops.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Shops</a>
                <a href="{{ route('admin.users.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.users.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Users</a>
                <a href="{{ route('admin.plans.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.plans.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Plans</a>
                <a href="{{ route('admin.announcements.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.announcements.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Announcements</a>
                <a href="{{ route('admin.backup.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.backup.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Backup</a>
                <a href="{{ route('admin.settings.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.settings.*') ? 'bg-amber-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">
                    ⚙ Settings
                </a>
                <a href="{{ route('admin.feature-flags.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.feature-flags.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Feature Flags</a>
                <a href="{{ route('admin.compliance-alerts.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.compliance-alerts.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Compliance</a>
                <a href="{{ route('admin.revenue.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.revenue.*') ? 'bg-slate-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">Revenue</a>
                <a href="{{ route('admin.2fa.enroll') }}"
                   class="px-3 py-1.5 rounded-md text-sm {{ auth('platform_admin')->user()?->two_factor_enabled ? 'bg-slate-800 hover:bg-slate-700 text-green-400' : 'bg-amber-900 hover:bg-amber-800 text-amber-300' }}"
                   title="{{ auth('platform_admin')->user()?->two_factor_enabled ? '2FA enabled' : 'Enable 2FA (recommended)' }}">
                    🔐 2FA
                </a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="px-3 py-1.5 rounded-md text-sm bg-rose-600 hover:bg-rose-700 text-white">Logout</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        {{ $slot }}
    </main>

    <div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>
</body>
</html>

