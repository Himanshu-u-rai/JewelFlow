<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification Code</title>
    <style>
        body { margin: 0; padding: 0; background: #f5f7fb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .wrap { max-width: 520px; margin: 40px auto; background: #ffffff; border: 1px solid #e2e8f0; }
        .header { background: #14213d; padding: 28px 32px; }
        .logo { color: #ffffff; font-size: 20px; font-weight: 800; letter-spacing: 0.02em; }
        .logo span { color: #fca311; }
        .body { padding: 32px; }
        .greeting { font-size: 15px; color: #374151; margin: 0 0 16px; }
        .otp-box { background: #f8fafc; border: 2px solid #e2e8f0; padding: 24px; text-align: center; margin: 24px 0; }
        .otp-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 10px; }
        .otp-code { font-size: 42px; font-weight: 800; letter-spacing: 0.18em; color: #14213d; font-variant-numeric: tabular-nums; }
        .otp-expiry { margin-top: 10px; font-size: 12px; color: #94a3b8; }
        .note { font-size: 13px; color: #64748b; line-height: 1.7; margin: 0 0 16px; }
        .ignore { font-size: 12px; color: #94a3b8; padding-top: 20px; border-top: 1px solid #f1f5f9; }
        .footer { background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 32px; font-size: 11px; color: #94a3b8; text-align: center; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <div class="logo">Jewel<span>Flow</span></div>
        </div>
        <div class="body">
            <p class="greeting">Hi{{ $shopName ? ', ' . $shopName : '' }}!</p>
            <p class="note">Your email verification code for <strong>JewelFlow</strong> is:</p>

            <div class="otp-box">
                <div class="otp-label">One-Time Code</div>
                <div class="otp-code">{{ $otp }}</div>
                <div class="otp-expiry">⏱ Expires in 10 minutes</div>
            </div>

            <p class="note">
                Enter this code in the JewelFlow dashboard to verify your email address.
                Once verified, you can reset your password anytime using this email.
            </p>
            <p class="note">
                <strong>Do not share this code.</strong> JewelFlow will never ask for this via phone or chat.
            </p>
            <p class="ignore">If you didn't request this, you can safely ignore this email. Your account remains secure.</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} JewelFlow &mdash; Jewellery ERP &amp; POS Platform
        </div>
    </div>
</body>
</html>
