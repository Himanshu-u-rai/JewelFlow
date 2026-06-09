<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Deactivate Account') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Once your account is deactivated, you will be logged out and will not be able to log in again. Your data will be kept safe. Contact your administrator to reactivate your account.') }}
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deactivation')"
    >{{ __('Deactivate Account') }}</x-danger-button>

    <x-modal name="confirm-user-deactivation" :show="$errors->userDeactivation->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.deactivate') }}" class="p-6" data-turbo-frame="_top">
            @csrf

            <h2 class="text-lg font-medium text-gray-900">
                {{ __('Are you sure you want to deactivate your account?') }}
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                {{ __('You will be logged out and will not be able to log in until an administrator reactivates your account. Your data will not be deleted. Please enter your password to confirm.') }}
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <x-text-input
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('Password') }}"
                />

                <x-input-error :messages="$errors->userDeactivation->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    {{ __('Deactivate Account') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
