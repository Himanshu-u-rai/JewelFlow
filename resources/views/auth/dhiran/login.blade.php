{{-- Dhiran-branded login. Posts to the shared route('login') (the LoginRequest
     scopes auth to the dhiran realm by host) + the realm-aware guest layout. --}}
<x-guest-layout>
    <h2 class="text-xl font-bold text-center text-slate-900 mb-1">Welcome back</h2>
    <p class="text-center text-sm text-slate-500 mb-6">Sign in to manage your gold-loan business.</p>

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
                <a class="text-sm font-medium text-amber-700 hover:text-amber-800 transition" href="{{ route('password.request') }}">
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
            <a class="font-semibold text-amber-700 hover:text-amber-800 transition" href="{{ route('register') }}">
                {{ __('Register') }}
            </a>
        </p>
    </form>
</x-guest-layout>
