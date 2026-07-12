<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Super Admin</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card admin-auth-card-lg">
        <h1 class="admin-auth-title">Create Super Admin</h1>
        <p class="admin-auth-copy">Bootstrap platform control access</p>

<form method="POST" action="{{ route('admin.register.store') }}" class="admin-auth-form">
            @csrf
            <div class="admin-auth-grid">
                <div>
                    <label class="admin-auth-label">First Name</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           class="admin-auth-input">
                </div>
                <div>
                    <label class="admin-auth-label">Last Name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           class="admin-auth-input">
                </div>
            </div>
            <div>
                <label class="admin-auth-label">Mobile Number</label>
                <input type="text" name="mobile_number" value="{{ old('mobile_number') }}" maxlength="10" required
                       class="admin-auth-input">
            </div>
            <div>
                <label class="admin-auth-label">Email (Optional)</label>
                <input type="email" name="email" value="{{ old('email') }}"
                       class="admin-auth-input">
            </div>
            <div class="admin-auth-grid">
                <div>
                    <label class="admin-auth-label">Password</label>
                    <input type="password" name="password" required minlength="8"
                           class="admin-auth-input">
                </div>
                <div>
                    <label class="admin-auth-label">Confirm Password</label>
                    <input type="password" name="password_confirmation" required minlength="8"
                           class="admin-auth-input">
                </div>
            </div>
            <button class="admin-btn admin-btn-primary w-full">
                Create Super Admin
            </button>
            <a href="{{ route('admin.login') }}" class="admin-btn admin-btn-secondary w-full">Back to login</a>
        </form>
    </div>
</body>
</html>
