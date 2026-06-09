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
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">{{ __('Mobile Number') }}</h2>
                            <p class="mt-1 text-sm text-gray-600">{{ __('Your registered mobile number used to log in.') }}</p>
                        </header>
                        <div class="mt-6 flex items-center justify-between gap-4">
                            <span class="text-sm font-medium text-gray-800">{{ auth()->user()->mobile_number ?? '—' }}</span>
                            <a href="{{ route('profile.mobile.change') }}"
                               data-turbo-frame="_top"
                               class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition">
                                Change mobile number
                            </a>
                        </div>
                    </section>
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
