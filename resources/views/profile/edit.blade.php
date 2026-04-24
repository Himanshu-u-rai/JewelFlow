<x-app-layout>
    <x-slot name="header">
        <h1 class="page-title">{{ __('Profile') }}</h1>
    </x-slot>

    <div class="content-inner">
        <div class="max-w-5xl mx-auto space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow ">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow ">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow ">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
