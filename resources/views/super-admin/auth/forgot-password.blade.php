<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Forgot Password</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card">
        <h1 class="admin-auth-title">Reset your password</h1>
        <p class="admin-auth-copy">Enter your verified admin email. If it matches an active account, we'll email a reset link.</p>

        @if (session('status'))
            <div class="mt-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-3 py-2">{{ session('status') }}</div>
        @endif
        @error('email')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.password.email') }}" class="admin-auth-form">
            @csrf
            <div>
                <label class="admin-auth-label">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus
                       class="admin-auth-input">
            </div>
            <button class="admin-btn admin-btn-primary w-full">Send reset link</button>
        </form>

        <a href="{{ route('admin.login') }}" class="admin-auth-link block mt-4">← Back to login</a>
    </div>
</body>
</html>
