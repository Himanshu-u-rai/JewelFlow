@extends('layouts.app')

@section('title', __('Change Login Mobile'))

@section('content')
<div class="p-6 max-w-2xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('Change Login Mobile Number') }}</h1>
        <p class="text-sm text-gray-500 mt-1">
            {{ __('Your mobile number is how you log in. Changing it requires email verification.') }}
        </p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="flex items-start justify-between gap-4 mb-5 pb-5 border-b border-gray-100">
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Current mobile') }}</div>
                <div class="mt-1 text-lg font-semibold text-gray-900">{{ $user->mobile_number }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-gray-500">{{ __('Verified email') }}</div>
                <div class="mt-1 text-sm text-gray-700">
                    {{ $user->email ?? __('Not set') }}
                    @if($emailVerified)
                        <span class="inline-block ml-1 text-emerald-600 text-xs">✓ {{ __('Verified') }}</span>
                    @else
                        <span class="inline-block ml-1 text-rose-600 text-xs">✗ {{ __('Not verified') }}</span>
                    @endif
                </div>
            </div>
        </div>

        @if(! $emailVerified)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ __('You must verify your email address before you can change your login mobile.') }}
                <a href="{{ route('profile.edit') }}" class="font-semibold underline ml-1">{{ __('Verify email') }}</a>
            </div>
        @elseif($pendingRequest)
            {{-- Step 2: OTP verification --}}
            <div class="mb-5">
                <h2 class="text-base font-semibold text-gray-900">{{ __('Enter the 6-digit code') }}</h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('We emailed it to') }} <strong>{{ $user->email }}</strong>.
                    {{ __('It expires at') }} {{ $pendingRequest->expires_at->format('H:i') }}.
                    {{ __('You have') }} {{ max(0, 5 - $pendingRequest->attempts) }} {{ __('attempt(s) remaining.') }}
                </p>
            </div>

            <form method="POST" action="{{ route('profile.mobile.verify') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('6-digit code') }}</label>
                    <input type="text" name="otp" inputmode="numeric" autocomplete="one-time-code"
                           pattern="[0-9]{6}" maxlength="6" required
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-lg font-mono tracking-[0.5em] focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                           placeholder="______">
                </div>
                <div class="flex items-center justify-between">
                    <button type="submit" class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                        {{ __('Verify & Change') }}
                    </button>
                    <div class="text-xs text-gray-500">
                        {{ __('Pending change to') }}: <strong>{{ $pendingRequest->new_mobile_number }}</strong>
                    </div>
                </div>
            </form>
        @else
            {{-- Step 1: initiate change --}}
            <form method="POST" action="{{ route('profile.mobile.request') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('New mobile number') }}</label>
                    <input type="tel" name="new_mobile_number" inputmode="numeric" pattern="[0-9]{10}" maxlength="10"
                           required value="{{ old('new_mobile_number') }}"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                           placeholder="{{ __('10-digit mobile') }}">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Confirm your current password') }}</label>
                    <input type="password" name="current_password" required autocomplete="current-password"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-amber-500 focus:ring-1 focus:ring-amber-500">
                    <p class="mt-1 text-xs text-gray-500">{{ __('We ask for your password so a stolen session alone cannot change your login.') }}</p>
                </div>

                <div class="rounded-md bg-gray-50 border border-gray-200 p-3 text-xs text-gray-600">
                    {{ __('After you submit, we will email a 6-digit code to') }} <strong>{{ $user->email }}</strong>.
                    {{ __('Enter it on the next screen to complete the change. All other devices will be signed out.') }}
                </div>

                <button type="submit" class="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                    {{ __('Send verification code') }}
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
