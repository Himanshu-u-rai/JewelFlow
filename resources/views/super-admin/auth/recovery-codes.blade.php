<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Recovery Codes</title>
    @vite(['resources/css/app.css', 'resources/css/super-admin.css', 'resources/js/app.js'])
</head>
<body class="admin-auth-shell">
    <div class="admin-auth-card admin-auth-card-lg">
        <h1 class="admin-auth-title">Recovery codes</h1>
        <p class="admin-auth-copy">Each code works once if you ever lose access to your email/authenticator.</p>

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
            <a href="{{ route('admin.dashboard') }}" class="admin-btn admin-btn-primary w-full mt-5">I've saved them — continue</a>
        @else
            <p class="mt-4 text-sm text-slate-300">You have <strong>{{ $remaining }}</strong> unused recovery code{{ $remaining === 1 ? '' : 's' }}.</p>
            {{-- Confirm only the destructive regenerate. Emit the attribute as ONE expression so
                 the form tag's closing ">" is always present (an inline @if…@endif here dropped it). --}}
            <form method="POST" action="{{ route('admin.recovery-codes.regenerate') }}" class="mt-4" {!! $remaining > 0 ? 'data-turbo-confirm="Regenerating replaces your current recovery codes. Continue?"' : '' !!}>
                @csrf
                <button type="submit" class="admin-btn admin-btn-primary w-full">
                    {{ $remaining > 0 ? 'Regenerate codes' : 'Generate recovery codes' }}
                </button>
            </form>
            <p class="mt-2 text-xs text-slate-500">Regenerating immediately invalidates any old codes.</p>
            @if ($remaining > 0)
                <a href="{{ route('admin.dashboard') }}" class="admin-btn admin-btn-secondary w-full mt-4">Back to dashboard</a>
            @endif
        @endif
    </div>
</body>
</html>
