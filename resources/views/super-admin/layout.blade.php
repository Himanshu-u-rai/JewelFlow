<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JewelFlow Super Admin</title>
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
                <a href="{{ route('admin.settings.index') }}" class="px-3 py-1.5 rounded-md text-sm {{ request()->routeIs('admin.settings.*') ? 'bg-amber-700 text-white' : 'bg-slate-800 hover:bg-slate-700 text-slate-300' }}">
                    ⚙ Settings
                </a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="px-3 py-1.5 rounded-md text-sm bg-rose-600 hover:bg-rose-700 text-white">Logout</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        @if(session('success'))
            <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 text-emerald-700 px-3 py-2 text-sm">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-4 rounded-md border border-rose-200 bg-rose-50 text-rose-700 px-3 py-2 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>

