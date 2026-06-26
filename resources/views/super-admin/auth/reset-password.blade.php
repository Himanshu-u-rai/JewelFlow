<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Set New Password</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:420px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">Set a new password</h1>
        <p class="text-sm text-slate-400 mt-1">Minimum 12 characters with upper &amp; lower case, a number, and a symbol.</p>

        @error('email')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror
        @error('password')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.password.update') }}" class="mt-5 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label class="block text-sm mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $email) }}" required
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-sm mb-1">New password</label>
                <input type="password" name="password" required autocomplete="new-password"
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <div>
                <label class="block text-sm mb-1">Confirm new password</label>
                <input type="password" name="password_confirmation" required autocomplete="new-password"
                       class="w-full rounded-md border border-slate-700 bg-slate-800 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">Update password</button>
        </form>
    </div>
</body>
</html>
