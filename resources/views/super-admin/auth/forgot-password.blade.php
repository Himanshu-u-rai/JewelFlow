<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Forgot Password</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell admin-auth-login-shell">
    <main class="admin-auth-login-panel">
        <section class="admin-auth-login-intro" aria-labelledby="admin-reset-title">
            <div>
                <div class="admin-auth-brand-row">
                    <span class="admin-auth-logo" aria-hidden="true">JF</span>
                    <div>
                        <p class="admin-auth-eyebrow">JewelFlows</p>
                        <h1 id="admin-reset-title" class="admin-auth-title">Account Access</h1>
                    </div>
                </div>
                <p class="admin-auth-copy">Super Admin Console</p>
            </div>

            <div class="admin-auth-jewel" aria-hidden="true">
                <span class="admin-auth-jewel-ring"></span>
                <span class="admin-auth-jewel-gem"></span>
                <span class="admin-auth-jewel-shine"></span>
                <span class="admin-auth-jewel-chain admin-auth-jewel-chain-a"></span>
                <span class="admin-auth-jewel-chain admin-auth-jewel-chain-b"></span>
                <span class="admin-auth-jewel-spark admin-auth-jewel-spark-a"></span>
                <span class="admin-auth-jewel-spark admin-auth-jewel-spark-b"></span>
            </div>

            <div class="admin-auth-access-note">
                Password recovery is available only for verified platform administrators.
            </div>
        </section>

        <section class="admin-auth-card admin-auth-login-card" aria-label="Password reset request form">
            <p class="admin-auth-eyebrow">Recovery</p>
            <h2 class="admin-auth-title">Reset your password</h2>
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
                       autocomplete="email" class="admin-auth-input">
            </div>
            <button class="admin-btn admin-btn-primary w-full">Send reset link</button>
        </form>

            <a href="{{ route('admin.login') }}" class="admin-btn admin-btn-secondary w-full mt-4">Back to login</a>
        </section>
    </main>
</body>
</html>
