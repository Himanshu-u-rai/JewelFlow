@extends('super-admin.layout')

@section('title', '2FA Setup')

@section('content')
<div class="max-w-lg mx-auto mt-8">
    <h2 class="text-lg font-semibold text-white mb-1">Set Up Two-Factor Authentication</h2>
    <p class="text-sm text-slate-400 mb-6">Protect your account with a time-based one-time password (TOTP).</p>

<div class="admin-card p-5 space-y-5">
        <div>
            <p class="text-sm font-medium text-slate-200 mb-2">Step 1 — Open your authenticator app</p>
            <p class="text-xs text-slate-400">Use Google Authenticator, Authy, Microsoft Authenticator, or any TOTP-compatible app.</p>
        </div>

        <div>
            <p class="text-sm font-medium text-slate-200 mb-2">Step 2 — Scan QR code or enter key manually</p>
            {{-- QR code via a self-hosted SVG generator URL or just show the manual key --}}
            <div class="bg-slate-800 rounded-lg p-4 text-center">
                <p class="text-xs text-slate-400 mb-3">Scan with your app, or enter the key below manually:</p>
                <p class="font-mono text-amber-400 text-lg tracking-widest break-all select-all">{{ $secret }}</p>
                <p class="text-xs text-slate-500 mt-2">Account: JewelFlow Admin · Type: Time-based</p>
            </div>
        </div>

        <div>
            <p class="text-sm font-medium text-slate-200 mb-2">Step 3 — Verify your code</p>
            <form method="POST" action="{{ route('admin.2fa.enroll.confirm') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Enter the 6-digit code from your app</label>
                    <input type="text" name="otp" autofocus inputmode="numeric"
                           pattern="[0-9]{6}" maxlength="6" required placeholder="000000"
                           class="w-full rounded-md border border-slate-700 bg-slate-900 text-slate-100 px-3 py-2 text-center text-xl font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-amber-500">
                </div>
                <button class="w-full rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium py-2 text-sm">
                    Activate Two-Factor Authentication
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
