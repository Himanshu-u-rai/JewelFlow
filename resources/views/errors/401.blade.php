<x-app-layout>
    <div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:40px 20px;">
        <div style="text-align:center;max-width:440px;">
            <div style="font-size:72px;margin-bottom:16px;">
                <x-icon name="log-in" size="72" style="display:inline-block;" stroke="#d97706" stroke-width="1.5" />
            </div>
            <h1 style="font-size:28px;font-weight:700;color:#101828;margin-bottom:8px;">Session Expired</h1>
            <p style="font-size:15px;color:#475569;line-height:1.6;margin-bottom:24px;">
                Your session has timed out. Please sign in again to continue where you left off.
            </p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="{{ route('login') }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%);color:#fff;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;box-shadow:0 2px 8px rgba(217,119,6,0.20);">
                    <x-icon name="log-in" size="16" />
                    Sign In
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
