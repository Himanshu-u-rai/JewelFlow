<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login Security Code</title>
    <style>
        body { margin: 0; padding: 0; background: #f5f7fb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrap { max-width: 520px; margin: 40px auto; background: #ffffff; border: 1px solid #e2e8f0; }
        .header { background: #0f172a; padding: 28px 32px; }
        .logo { color: #ffffff; font-size: 20px; font-weight: 800; letter-spacing: 0.02em; }
        .logo span { color: #f59e0b; }
        .badge { display: inline-block; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; padding: 3px 10px; border-radius: 99px; margin-top: 8px; }
        .body { padding: 32px; }
        .greeting { font-size: 15px; color: #374151; margin: 0 0 16px; }
        .otp-box { background: #f8fafc; border: 2px solid #e2e8f0; padding: 24px; text-align: center; margin: 24px 0; border-radius: 8px; }
        .otp-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 10px; }
        .otp-code { font-size: 44px; font-weight: 800; letter-spacing: 0.22em; color: #0f172a; font-variant-numeric: tabular-nums; }
        .otp-expiry { margin-top: 10px; font-size: 12px; color: #94a3b8; }
        .note { font-size: 13px; color: #64748b; line-height: 1.7; margin: 0 0 16px; }
        .warning { font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; padding: 12px 16px; border-radius: 6px; margin: 16px 0; }
        .ignore { font-size: 12px; color: #94a3b8; padding-top: 20px; border-top: 1px solid #f1f5f9; }
        .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 32px; font-size: 11px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div class="logo">Jewel<span>Flow</span></div>
            <div class="badge">Platform Admin Security Alert</div>
        </div>
        <div class="body">
            <p class="greeting">Hi {{ $adminName }},</p>
            <p class="note">A login attempt was made to the <strong>JewelFlow Platform Admin</strong> panel. Use the code below to complete your sign-in.</p>

            <div class="otp-box">
                <div class="otp-label">One-Time Login Code</div>
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-expiry">⏱ Expires in 10 minutes</div>
            </div>

            <div class="warning">
                <strong>Security Notice:</strong> Never share this code. JewelFlow staff will never ask for this code via phone, chat, or email.
            </div>

            <p class="note">If you did not attempt to log in, your admin password may be compromised. Change it immediately and contact your platform team.</p>

            <p class="ignore">If you initiated this login, simply enter the code above and proceed.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} JewelFlow &mdash; Platform Control Tower
        </div>
    </div>
</body>
</html>
