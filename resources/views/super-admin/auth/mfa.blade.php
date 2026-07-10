<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Verification</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card">
        <h1 class="admin-auth-title">Two-step verification</h1>
        @if ($method === 'totp')
            <p class="admin-auth-copy">Enter the 6-digit code from your authenticator app.</p>
        @else
            <p class="admin-auth-copy">We sent a 6-digit code to <span class="text-slate-200">{{ $email }}</span>.</p>
        @endif

        @if (session('status'))
            <div class="mt-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-3 py-2">{{ session('status') }}</div>
        @endif
        @error('otp')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.mfa.verify') }}" class="admin-auth-form">
            @csrf
            <div>
                <label class="admin-auth-label">Verification code</label>
                <input type="text" name="otp" inputmode="numeric" maxlength="6" autofocus autocomplete="one-time-code"
                       class="admin-auth-input tracking-widest">
            </div>
            <button class="admin-btn admin-btn-primary w-full">Verify</button>
        </form>

        @if ($method === 'email')
            <form method="POST" action="{{ route('admin.mfa.resend') }}" class="mt-3">
                @csrf
                <button class="admin-auth-link">Resend code</button>
            </form>
        @endif

        <details class="mt-5">
            <summary class="admin-auth-link cursor-pointer">Use a recovery code instead</summary>
            {{-- Native submit (data-turbo="false"): the form sits inside <details> and Turbo was
                 swallowing the click, so the POST never reached the controller. --}}
            <form method="POST" action="{{ route('admin.mfa.verify') }}" class="mt-3 space-y-3" data-turbo="false">
                @csrf
                <input type="text" name="recovery_code" placeholder="XXXXX-XXXXX" autocomplete="off"
                       class="admin-auth-input">
                <button type="submit" class="admin-btn admin-btn-secondary w-full">Use recovery code</button>
            </form>
        </details>

        <form method="POST" action="{{ route('admin.logout') }}" class="mt-5">
            @csrf
            <button class="admin-auth-link">← Cancel and sign out</button>
        </form>
    </div>
</body>
</html>
