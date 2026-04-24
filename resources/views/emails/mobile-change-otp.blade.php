<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Confirm your new login mobile</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 24px; color: #0f172a;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
        <h2 style="margin: 0 0 8px; font-size: 20px; color: #0f172a;">Confirm your new login mobile</h2>
        <p style="color: #475569; font-size: 14px; line-height: 1.6;">
            Hi {{ $userName }}, use the code below to confirm changing your {{ $appName }} login mobile to <strong>{{ $newMobileMasked }}</strong>.
        </p>
        <div style="margin: 24px 0; padding: 20px; background: #f1f5f9; border-radius: 10px; text-align: center;">
            <div style="font-size: 32px; font-weight: 700; letter-spacing: 6px; color: #0f172a;">{{ $otp }}</div>
            <div style="margin-top: 6px; font-size: 12px; color: #64748b;">Expires in 10 minutes</div>
        </div>
        <p style="color: #475569; font-size: 13px; line-height: 1.6;">
            If you didn't start this change, ignore this email and your login mobile stays the same.
            Consider changing your password if you suspect your account is compromised.
        </p>
        <p style="color: #94a3b8; font-size: 12px; margin-top: 24px;">— {{ $appName }}</p>
    </div>
</body>
</html>
