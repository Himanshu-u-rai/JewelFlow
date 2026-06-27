<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Verification</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:420px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">Two-step verification</h1>
        @if ($method === 'totp')
            <p class="text-sm text-slate-400 mt-1">Enter the 6-digit code from your authenticator app.</p>
        @else
            <p class="text-sm text-slate-400 mt-1">We sent a 6-digit code to <span class="text-slate-200">{{ $email }}</span>.</p>
        @endif

        @if (session('status'))
            <div class="mt-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-3 py-2">{{ session('status') }}</div>
        @endif
        @error('otp')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.mfa.verify') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">Verification code</label>
                <input type="text" name="otp" inputmode="numeric" maxlength="6" autofocus autocomplete="one-time-code"
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 tracking-widest focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Verify</button>
        </form>

        @if ($method === 'email')
            <form method="POST" action="{{ route('admin.mfa.resend') }}" class="mt-3">
                @csrf
                <button class="text-sm text-slate-400 hover:text-slate-200">Resend code</button>
            </form>
        @endif

        <details class="mt-5">
            <summary class="text-sm text-slate-400 hover:text-slate-200 cursor-pointer">Use a recovery code instead</summary>
            <form method="POST" action="{{ route('admin.mfa.verify') }}" class="mt-3 space-y-3">
                @csrf
                <input type="text" name="recovery_code" placeholder="XXXXX-XXXXX" autocomplete="off"
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
                <button class="w-full rounded-md bg-slate-700 hover:bg-slate-600 text-white font-medium py-2">Use recovery code</button>
            </form>
        </details>

        <form method="POST" action="{{ route('admin.logout') }}" class="mt-5">
            @csrf
            <button class="text-sm text-slate-500 hover:text-slate-300">← Cancel and sign out</button>
        </form>
    </div>
</body>
</html>
