<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card">
        <h1 class="admin-auth-title">JewelFlow Super Admin</h1>
        <p class="admin-auth-copy">Platform control tower login</p>

<form method="POST" action="{{ route('admin.login.store') }}" class="admin-auth-form">
            @csrf
            @error('mobile_number')
                <div role="alert" class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
            @enderror
            <div>
                <label class="admin-auth-label">Mobile Number</label>
                <input type="text" name="mobile_number" value="{{ old('mobile_number') }}" maxlength="10" required
                       class="admin-auth-input">
            </div>
            <div>
                <label class="admin-auth-label">Password</label>
                <input type="password" name="password" required
                       class="admin-auth-input">
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-slate-300">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-600 bg-slate-800 text-amber-500">
                Remember me
            </label>
            <button class="admin-btn admin-btn-primary w-full">Login</button>
        </form>

        <a href="{{ route('admin.password.request') }}" class="admin-auth-link block mt-4 text-center">Forgot your password?</a>

        <div class="admin-auth-separator">
            @if(!$hasSuperAdmin)
                <a href="{{ route('admin.register') }}" class="admin-btn admin-btn-secondary w-full">
                    Create Super Admin
                </a>
            @else
                <p class="admin-auth-copy text-center">Super Admin already configured.</p>
            @endif
        </div>
    </div>
</body>
</html>
