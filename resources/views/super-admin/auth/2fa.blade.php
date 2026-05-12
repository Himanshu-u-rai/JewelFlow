<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication — JewelFlow Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:420px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">Two-Factor Verification</h1>
        <p class="text-sm text-slate-400 mt-1">Enter the 6-digit code from your authenticator app.</p>

<form method="POST" action="{{ route('admin.2fa.verify') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">Authentication Code</label>
                <input type="text" name="otp" autofocus autocomplete="one-time-code"
                       inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
                       placeholder="000000"
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 text-center text-2xl tracking-widest font-mono focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">
                Verify &amp; Login
            </button>
        </form>

        <p class="text-xs text-slate-500 mt-4 text-center">
            Open your authenticator app (Google Authenticator, Authy, etc.) and enter the current 6-digit code.
        </p>
        <div style="margin-top:16px;padding-top:12px;border-top:1px solid #1e293b;">
            <a href="{{ route('admin.login') }}" class="text-xs text-slate-400 hover:text-amber-400">← Back to login</a>
        </div>
    </div>
</body>
</html>
