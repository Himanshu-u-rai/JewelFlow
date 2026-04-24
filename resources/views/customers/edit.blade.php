<x-app-layout>
    <x-page-header class="customers-create-header">
        <h1 class="page-title">Edit Customer</h1>
        <div class="page-actions">
            <a href="{{ route('customers.show', $customer) }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 transition-colors text-sm font-medium customers-create-back-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Profile
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Customer Information</h2>
                    <p class="text-sm text-gray-500 mt-1">Update the details of this customer</p>
                </div>

                @if(session('success'))
                    <div class="mx-6 mt-6 px-4 py-3 bg-green-50 border border-green-200 text-green-700 text-sm">
                        {{ session('success') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('customers.update', $customer) }}" class="p-6">
                    @csrf
                    @method('PUT')

                    <div class="space-y-6">
                        <!-- Name Fields -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" 
                                       name="first_name" 
                                       id="first_name" 
                                       value="{{ old('first_name', $customer->first_name) }}"
                                       required
                                       class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('first_name') border-red-500 @enderror">
                                @error('first_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" 
                                       name="last_name" 
                                       id="last_name" 
                                       value="{{ old('last_name', $customer->last_name) }}"
                                       required
                                       class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('last_name') border-red-500 @enderror">
                                @error('last_name')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Contact Fields -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="mobile" class="block text-sm font-medium text-gray-700 mb-2">
                                    Mobile Number <span class="text-gray-400 text-xs font-normal">(optional)</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                        </svg>
                                    </div>
                                    <input type="tel"
                                           name="mobile"
                                           id="mobile"
                                           value="{{ old('mobile', $customer->mobile) }}"
                                           pattern="[0-9]{10}"
                                           maxlength="10"
                                           placeholder="10-digit number (optional)"
                                           class="w-full pl-10 border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('mobile') border-red-500 @enderror">
                                </div>
                                @error('mobile')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                    Email <span class="text-gray-400 text-xs">(Optional)</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                        </svg>
                                    </div>
                                    <input type="email" 
                                           name="email" 
                                           id="email" 
                                           value="{{ old('email', $customer->email) }}"
                                           placeholder="customer@example.com"
                                           class="w-full pl-10 border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('email') border-red-500 @enderror">
                                </div>
                                @error('email')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Address Field -->
                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
                                Address <span class="text-gray-400 text-xs">(Optional)</span>
                            </label>
                            <textarea name="address" 
                                      id="address" 
                                      rows="3"
                                      placeholder="Enter customer's address"
                                      class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('address') border-red-500 @enderror">{{ old('address', $customer->address) }}</textarea>
                            @error('address')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                        <!-- Occasion & Notes Fields -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="date_of_birth" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date of Birth <span class="text-gray-400 text-xs">(Optional)</span>
                                </label>
                                <input type="date" name="date_of_birth" id="date_of_birth"
                                       value="{{ old('date_of_birth', $customer->date_of_birth?->toDateString()) }}"
                                       max="{{ now()->subDay()->toDateString() }}"
                                       class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('date_of_birth') border-red-500 @enderror">
                                @error('date_of_birth')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="anniversary_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    Anniversary <span class="text-gray-400 text-xs">(Optional)</span>
                                </label>
                                <input type="date" name="anniversary_date" id="anniversary_date"
                                       value="{{ old('anniversary_date', $customer->anniversary_date?->toDateString()) }}"
                                       class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('anniversary_date') border-red-500 @enderror">
                                @error('anniversary_date')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="wedding_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    Wedding Anniversary <span class="text-gray-400 text-xs">(Optional)</span>
                                </label>
                                <input type="date" name="wedding_date" id="wedding_date"
                                       value="{{ old('wedding_date', $customer->wedding_date?->toDateString()) }}"
                                       class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('wedding_date') border-red-500 @enderror">
                                @error('wedding_date')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                                Notes <span class="text-gray-400 text-xs">(Optional)</span>
                            </label>
                            <textarea name="notes" id="notes" rows="2"
                                      placeholder="Any internal notes about this customer"
                                      class="w-full border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('notes') border-red-500 @enderror">{{ old('notes', $customer->notes) }}</textarea>
                            @error('notes')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                    <!-- Form Actions -->
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('customers.show', $customer) }}" 
                           class="px-6 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 transition-colors font-medium inline-flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Cancel
                        </a>
                        <button type="submit" 
                                class="px-6 py-2 transition-colors font-medium inline-flex items-center" style="background: #0d9488; color: white;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            Update Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
