<x-guest-layout>
    <h2 class="text-xl font-bold text-center text-slate-900 mb-1">Create your shop</h2>
    <p class="text-center text-sm text-slate-500 mb-6">Set up your Jewelflow account in a minute.</p>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('register') }}" class="space-y-4">
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
                autocomplete="new-password"
                placeholder="At least 8 characters" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
            <x-text-input id="password_confirmation"
                class="block mt-1 w-full"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                placeholder="Re-enter your password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-6">
            <x-primary-button>
                {{ __('Create my shop') }}
            </x-primary-button>
        </div>

        <p class="text-center text-sm text-slate-500 mt-6">
            {{ __('Already have a shop?') }}
            <a class="font-semibold text-amber-700 hover:text-amber-800 transition" href="{{ route('login') }}">
                {{ __('Sign in') }}
            </a>
        </p>
    </form>
</x-guest-layout>
