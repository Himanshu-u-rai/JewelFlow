<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Verify Email</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card">
        <h1 class="admin-auth-title">Verify your email</h1>
        <p class="admin-auth-copy">
            A verified email lets you recover your account if you forget your password.
        </p>

        @if (session('status'))
            <div class="mt-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-3 py-2">{{ session('status') }}</div>
        @endif
        @error('otp')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror
        @error('email')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <div class="mt-4 text-sm">
            <span class="text-slate-400">Email:</span>
            <span class="text-slate-100">{{ $admin->email ?? '— not set —' }}</span>
            @if ($admin->hasVerifiedEmail())
                <span class="ml-2 rounded bg-emerald-700/50 text-emerald-200 px-2 py-0.5 text-xs">verified</span>
            @else
                <span class="ml-2 rounded bg-amber-700/50 text-amber-200 px-2 py-0.5 text-xs">unverified</span>
            @endif
        </div>

        @unless ($admin->hasVerifiedEmail())
            <form method="POST" action="{{ route('admin.verify-email.send') }}" class="mt-5">
                @csrf
                <button class="admin-btn admin-btn-secondary w-full">Send verification code</button>
            </form>

            <form method="POST" action="{{ route('admin.verify-email.verify') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="admin-auth-label">6-digit code</label>
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" required
                           class="admin-auth-input tracking-widest">
                </div>
                <button class="admin-btn admin-btn-primary w-full">Verify email</button>
            </form>
        @endunless

        <a href="{{ route('admin.dashboard') }}" class="admin-btn admin-btn-secondary w-full mt-5">Back to dashboard</a>
    </div>
</body>
</html>
