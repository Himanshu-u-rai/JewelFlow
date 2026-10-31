<x-app-layout>
    <div style="display:flex;align-items:center;justify-content:center;min-height:70vh;padding:40px 20px;">
        <div style="text-align:center;max-width:440px;">
            <div style="font-size:72px;margin-bottom:16px;">
                <x-icon name="triangle-alert" size="72" style="display:inline-block;" stroke="#dc2626" stroke-width="1.5" />
            </div>
            <h1 style="font-size:28px;font-weight:700;color:#101828;margin-bottom:8px;">Something Went Wrong</h1>
            <p style="font-size:15px;color:#475569;line-height:1.6;margin-bottom:24px;">
                An unexpected error occurred. Our team has been notified and is looking into it. Please try again in a few minutes.
            </p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}"
                   style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#14213d;color:#fff;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;">
                    <x-icon name="arrow-left" size="16" />
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
