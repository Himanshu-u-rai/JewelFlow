<x-guest-layout>
    <div class="mb-4 text-sm text-gray-600">
        Enter the email address you verified in your JewelFlow account and we'll send you a password reset link.
    </div>

    <div class="mb-4 p-3 text-sm" style="background:#f0fdf4; border:1px solid #86efac; color:#166534;">
        💡 <strong>Tip:</strong> Make sure to enter the exact email you verified — it's case-sensitive.
        If you never added an email to your account, contact your shop administrator for help.
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                {{ __('Email Password Reset Link') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
