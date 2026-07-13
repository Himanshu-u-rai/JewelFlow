<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell admin-auth-login-shell">
    <main class="admin-auth-login-panel">
        <section class="admin-auth-login-intro" aria-labelledby="admin-login-title">
            <div>
                <div class="admin-auth-brand-row">
                    <span class="admin-auth-logo" aria-hidden="true">JF</span>
                    <div>
                        <p class="admin-auth-eyebrow">JewelFlows</p>
                        <h1 id="admin-login-title" class="admin-auth-title">Control Tower</h1>
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
                Platform access is restricted to verified administrators.
            </div>
        </section>

        <section class="admin-auth-card admin-auth-login-card" aria-label="Super admin login form">
            <p class="admin-auth-eyebrow">Super Admin</p>
            <h2 class="admin-auth-title">Sign in</h2>
            <p class="admin-auth-copy">Use your platform admin credentials.</p>

            <form method="POST" action="{{ route('admin.login.store') }}" class="admin-auth-form">
            @csrf
            @error('mobile_number')
                <div role="alert" class="mt-4 rounded-md border border-rose-700 bg-rose-900/40 text-rose-200 text-sm px-3 py-2">{{ $message }}</div>
            @enderror
            <div>
                <label class="admin-auth-label">Mobile Number</label>
                <input type="text" name="mobile_number" value="{{ old('mobile_number') }}" maxlength="10" required
                       autocomplete="username" inputmode="numeric" class="admin-auth-input">
            </div>
            <div>
                <label class="admin-auth-label">Password</label>
                <input type="password" name="password" required autocomplete="current-password"
                       class="admin-auth-input">
            </div>
            <label class="admin-auth-checkbox">
                <input type="checkbox" name="remember" value="1">
                Remember me
            </label>
            <button class="admin-btn admin-btn-primary w-full">Login</button>
        </form>

        <a href="{{ route('admin.password.request') }}" class="admin-btn admin-btn-secondary w-full mt-4">Forgot password</a>

        <div class="admin-auth-separator">
            @if(!$hasSuperAdmin)
                <a href="{{ route('admin.register') }}" class="admin-btn admin-btn-secondary w-full">
                    Create Super Admin
                </a>
            @else
                <p class="admin-auth-copy text-center">Super Admin already configured.</p>
            @endif
        </div>
        </section>
    </main>
</body>
</html>
