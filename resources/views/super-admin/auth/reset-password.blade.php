<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Set New Password</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card">
        <h1 class="admin-auth-title">Set a new password</h1>
        <p class="admin-auth-copy">Minimum 12 characters with upper &amp; lower case, a number, and a symbol.</p>

        @error('email')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror
        @error('password')
            <div class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('admin.password.update') }}" class="admin-auth-form">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label class="admin-auth-label">Email</label>
                <input type="email" name="email" value="{{ old('email', $email) }}" required
                       class="admin-auth-input">
            </div>
            <div>
                <label class="admin-auth-label">New password</label>
                <input type="password" name="password" required autocomplete="new-password"
                       class="admin-auth-input">
            </div>
            <div>
                <label class="admin-auth-label">Confirm new password</label>
                <input type="password" name="password_confirmation" required autocomplete="new-password"
                       class="admin-auth-input">
            </div>
            <button class="admin-btn admin-btn-primary w-full">Update password</button>
        </form>
    </div>
</body>
</html>
