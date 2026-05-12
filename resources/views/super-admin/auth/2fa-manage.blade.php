@extends('super-admin.layout')

@section('title', '2FA Settings')

@section('content')
<div class="max-w-lg mx-auto mt-8">
    <h2 class="text-lg font-semibold text-white mb-1">Two-Factor Authentication</h2>
    <p class="text-sm text-slate-400 mb-6">Your account is protected with 2FA.</p>

<div class="admin-card p-5">
        <div class="flex items-center gap-3 mb-5">
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-900 text-green-300 border border-green-700">
                Enabled
            </span>
            <span class="text-sm text-slate-300">Two-factor authentication is active on your account.</span>
        </div>

        <p class="text-sm text-slate-400 mb-4">
            To disable 2FA, confirm your password below. You will be asked to re-enroll on next login.
        </p>

        <form method="POST" action="{{ route('admin.2fa.disable') }}" class="space-y-3"
              onsubmit="return confirm('Disable two-factor authentication? Your account will be less secure.')">
            @csrf
            <div>
                <label class="block text-xs text-slate-400 mb-1">Current Password</label>
                <input type="password" name="password" required
                       class="w-full rounded-md border border-slate-700 bg-slate-900 text-slate-100 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-500">
            </div>
            <button class="rounded-md bg-rose-700 hover:bg-rose-800 text-white font-medium px-4 py-2 text-sm">
                Disable 2FA
            </button>
        </form>
    </div>
</div>
@endsection
