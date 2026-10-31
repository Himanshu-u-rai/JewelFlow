<x-app-layout>
    <div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:40px 20px;">
        <div style="text-align:center;max-width:460px;">
            <div style="font-size:72px;margin-bottom:16px;">
                <x-icon name="rotate-cw" size="72" style="display:inline-block;" stroke="#d97706" stroke-width="1.5" />
            </div>
            <h1 style="font-size:28px;font-weight:700;color:#101828;margin-bottom:8px;">Page Expired</h1>
            <p style="font-size:15px;color:#475569;line-height:1.6;margin-bottom:24px;">
                This page has been open too long and the security token has expired. Refresh the page and try again — your data is safe.
            </p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="javascript:window.location.reload();"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#14213d;color:#fff;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;">
                    <x-icon name="rotate-cw" size="16" />
                    Refresh Page
                </a>
                <a href="{{ route('dashboard') }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#f1f5f9;color:#334155;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;border:1px solid #e2e8f0;">
                    Dashboard
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
