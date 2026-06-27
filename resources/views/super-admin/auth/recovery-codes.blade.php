<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Recovery Codes</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="min-height:100vh;background:#0b1020;color:#e2e8f0;display:flex;align-items:center;justify-content:center;padding:16px;">
    <div style="width:100%;max-width:480px;border:1px solid #1e293b;background:#0f172a;border-radius:12px;padding:24px;">
        <h1 class="text-xl font-semibold">Recovery codes</h1>
        <p class="text-sm text-slate-400 mt-1">Each code works once if you ever lose access to your email/authenticator.</p>

        @if (session('status'))
            <div class="mt-4 rounded-md border border-emerald-700 bg-emerald-900/40 text-emerald-200 text-sm px-3 py-2">{{ session('status') }}</div>
        @endif

        @if (!empty($plainCodes))
            <div class="mt-4 rounded-md border border-amber-700 bg-amber-900/30 text-amber-200 text-sm px-3 py-2">
                Save these now — they will <strong>not</strong> be shown again.
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2 font-mono text-slate-100 text-sm">
                @foreach ($plainCodes as $code)
                    <div class="rounded bg-slate-800 border border-slate-700 px-3 py-2 text-center tracking-wider">{{ $code }}</div>
                @endforeach
            </div>
            <a href="{{ route('admin.dashboard') }}" class="block mt-5 w-full text-center rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">I've saved them — continue</a>
        @else
            <p class="mt-4 text-sm text-slate-300">You have <strong>{{ $remaining }}</strong> unused recovery code{{ $remaining === 1 ? '' : 's' }}.</p>
            {{-- Turbo intercepts the submit, so a native onsubmit confirm silently cancels it.
                 Use Turbo's own confirm, and only for the destructive regenerate (no codes = nothing to confirm). --}}
            <form method="POST" action="{{ route('admin.recovery-codes.regenerate') }}" class="mt-4"
                  @if ($remaining > 0) data-turbo-confirm="Regenerating invalidates all current recovery codes. Continue?" @endif>
                @csrf
                <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2">
                    {{ $remaining > 0 ? 'Regenerate codes' : 'Generate recovery codes' }}
                </button>
            </form>
            <p class="mt-2 text-xs text-slate-500">Regenerating immediately invalidates any old codes.</p>
            @if ($remaining > 0)
                <a href="{{ route('admin.dashboard') }}" class="block mt-4 text-sm text-slate-400 hover:text-slate-200 text-center">← Back to dashboard</a>
            @endif
        @endif
    </div>
</body>
</html>
