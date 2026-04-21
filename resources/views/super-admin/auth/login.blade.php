<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:420px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">JewelFlow Super Admin</h1>
        <p class="text-sm text-slate-400 mt-1">Platform control tower login</p>

        @if($errors->any())
            <div class="mt-4 rounded-md border border-rose-300 bg-rose-50 text-rose-700 px-3 py-2 text-sm">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.login.store') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">Mobile Number</label>
                <input type="text" name="mobile_number" value="{{ old('mobile_number') }}" maxlength="10" required
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-sm mb-1">Password</label>
                <input type="password" name="password" required
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-600 bg-slate-800 text-amber-500">
                Remember me
            </label>
            <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Login</button>
        </form>

        <div style="margin-top:16px;padding-top:12px;border-top:1px solid #1e293b;">
            @if(!$hasSuperAdmin)
                <a href="{{ route('admin.register') }}"
                   style="display:block;text-align:center;background:#334155;color:#fff;padding:10px;border-radius:8px;text-decoration:none;font-weight:600;">
                    Create Super Admin
                </a>
            @else
                <p style="font-size:12px;color:#94a3b8;text-align:center;">Super Admin already configured.</p>
            @endif
        </div>
    </div>
</body>
</html>
