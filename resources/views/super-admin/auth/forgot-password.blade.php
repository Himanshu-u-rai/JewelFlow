<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Forgot Password</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:420px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">Reset your password</h1>
        <p class="text-sm text-slate-400 mt-1">Enter your verified admin email. If it matches an active account, we'll email a reset link.</p>

        @if (session('status'))
            <div class="mt-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-3 py-2">{{ session('status') }}</div>
        @endif
        @error('email')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.password.email') }}" class="mt-5 space-y-4">
            @csrf
            <div>
                <label class="block text-sm mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Send reset link</button>
        </form>

        <a href="{{ route('admin.login') }}" class="block mt-4 text-sm text-slate-400 hover:text-slate-200">← Back to login</a>
    </div>
</body>
</html>
