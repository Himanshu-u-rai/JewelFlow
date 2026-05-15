<x-app-layout>
    <div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:40px 20px;">
        <div style="text-align:center;max-width:440px;">
            <div style="font-size:72px;margin-bottom:16px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <h1 style="font-size:28px;font-weight:700;color:#101828;margin-bottom:8px;">Something Went Wrong</h1>
            <p style="font-size:15px;color:#475569;line-height:1.6;margin-bottom:24px;">
                An unexpected error occurred. Our team has been notified and is looking into it. Please try again in a few minutes.
            </p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#14213d;color:#fff;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m0 0l7 7m-7-7l7-7"/></svg>
                    Go Back
                </a>
                <a href="{{ route('dashboard') }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#f1f5f9;color:#334155;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;border:1px solid #e2e8f0;">
                    Dashboard
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
