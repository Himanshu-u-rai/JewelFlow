<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Your login mobile number was changed</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 24px; color: #0f172a;">
    <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
        <h2 style="margin: 0 0 8px; font-size: 20px; color: #0f172a;">Your login mobile was changed</h2>
        <p style="color: #475569; font-size: 14px; line-height: 1.6;">
            Hi {{ $userName }},
        </p>
        <p style="color: #475569; font-size: 14px; line-height: 1.6;">
            The login mobile for your {{ $appName }} account was changed from <strong>{{ $oldMobileMasked }}</strong> to <strong>{{ $newMobileMasked }}</strong>.
        </p>

        <div style="margin: 20px 0; padding: 16px 20px; background: #f1f5f9; border-radius: 10px; font-size: 13px; color: #334155;">
            <div><strong>Changed by:</strong> {{ $changedBy }}</div>
            <div><strong>When:</strong> {{ now()->format('d M Y, H:i T') }}</div>
            <div><strong>IP address:</strong> {{ $ipAddress }}</div>
            @if($reason)
                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e2e8f0;">
                    <strong>Reason:</strong> {{ $reason }}
                </div>
            @endif
        </div>

        <p style="color: #475569; font-size: 14px; line-height: 1.6;">
            <strong>If this wasn't you:</strong> contact support immediately — someone else may have access to your account.
        </p>

        <p style="color: #94a3b8; font-size: 12px; margin-top: 24px;">— {{ $appName }}</p>
    </div>
</body>
</html>
