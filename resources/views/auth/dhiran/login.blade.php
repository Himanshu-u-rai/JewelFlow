{{-- Dhiran-branded login. Posts to the shared route('login') (the LoginRequest
     scopes auth to the dhiran realm by host) + the realm-aware guest layout. --}}
<x-guest-layout>
    @once
        @vite(['resources/css/dhiran.css'])
    @endonce

    <div class="dh-auth-panel">
    <h2 class="dh-auth-title">Welcome back</h2>
    <p class="dh-auth-copy">Sign in to manage your Dhiran pledge-loan service.</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" class="space-y-4">
        @csrf

        <div>
            <x-input-label for="mobile_number" :value="__('Mobile Number')" />
            <x-text-input id="mobile_number"
                class="block mt-1 w-full"
                type="tel"
                name="mobile_number"
                :value="old('mobile_number')"
                required
                autofocus
                autocomplete="tel"
                pattern="[0-9]{10}"
                minlength="10"
                maxlength="10"
                placeholder="Enter 10-digit mobile number" />
            <x-input-error :messages="$errors->get('mobile_number')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password"
                class="block mt-1 w-full"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                placeholder="Enter your password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>

            @if (Route::has('password.request'))
                <a class="dh-auth-link text-sm" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif
        </div>

        <div class="mt-6">
            <x-primary-button>
                {{ __('Sign in') }}
            </x-primary-button>
        </div>

        <p class="text-center text-sm text-slate-500 mt-6">
            {{ __("Don't have a Dhiran account?") }}
            <a class="dh-auth-link" href="{{ route('register') }}">
                {{ __('Register') }}
            </a>
        </p>
    </form>
    </div>
</x-guest-layout>
