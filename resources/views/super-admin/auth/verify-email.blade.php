<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Verify Email</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:420px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">Verify your email</h1>
        <p class="text-sm text-slate-400 mt-1">
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
                <button class="w-full rounded-md bg-slate-700 hover:bg-slate-600 text-white font-medium py-2">Send verification code</button>
            </form>

            <form method="POST" action="{{ route('admin.verify-email.verify') }}" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="block text-sm mb-1">6-digit code</label>
                    <input type="text" name="otp" inputmode="numeric" maxlength="6" required
                           class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 tracking-widest focus:outline-none focus:ring-2 focus:ring-amber-500">
                </div>
                <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Verify email</button>
            </form>
        @endunless

        <a href="{{ route('admin.dashboard') }}" class="block mt-5 text-sm text-slate-400 hover:text-slate-200">← Back to dashboard</a>
    </div>
</body>
</html>
