<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Super Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:520px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 style="font-size:24px;font-weight:700;margin:0;">Create Super Admin</h1>
        <p style="margin-top:6px;color:#94a3b8;font-size:14px;">Bootstrap platform control access</p>

        @if($errors->any())
            <div style="margin-top:14px;border:1px solid #fda4af;background:#fff1f2;color:#be123c;padding:10px;border-radius:8px;font-size:13px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.register.store') }}" style="margin-top:16px;display:grid;gap:12px;">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:13px;margin-bottom:4px;">First Name</label>
                    <input type="text" name="first_name" value="{{ old('first_name') }}" required
                           style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1224;color:#e2e8f0;">
                </div>
                <div>
                    <label style="display:block;font-size:13px;margin-bottom:4px;">Last Name</label>
                    <input type="text" name="last_name" value="{{ old('last_name') }}" required
                           style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1224;color:#e2e8f0;">
                </div>
            </div>
            <div>
                <label style="display:block;font-size:13px;margin-bottom:4px;">Mobile Number</label>
                <input type="text" name="mobile_number" value="{{ old('mobile_number') }}" maxlength="10" required
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1224;color:#e2e8f0;">
            </div>
            <div>
                <label style="display:block;font-size:13px;margin-bottom:4px;">Email (Optional)</label>
                <input type="email" name="email" value="{{ old('email') }}"
                       style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1224;color:#e2e8f0;">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="display:block;font-size:13px;margin-bottom:4px;">Password</label>
                    <input type="password" name="password" required minlength="8"
                           style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1224;color:#e2e8f0;">
                </div>
                <div>
                    <label style="display:block;font-size:13px;margin-bottom:4px;">Confirm Password</label>
                    <input type="password" name="password_confirmation" required minlength="8"
                           style="width:100%;padding:10px;border-radius:8px;border:1px solid #334155;background:#0b1224;color:#e2e8f0;">
                </div>
            </div>
            <button style="margin-top:4px;background:#0f766e;color:#fff;padding:10px;border-radius:8px;border:none;font-weight:700;cursor:pointer;">
                Create Super Admin
            </button>
            <a href="{{ route('admin.login') }}" style="font-size:13px;color:#67e8f9;text-align:center;text-decoration:none;">Back to login</a>
        </form>
    </div>
</body>
</html>

