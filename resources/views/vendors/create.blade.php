<x-app-layout>
    <x-page-header class="vendors-create-header ops-treatment-header">
        <h1 class="page-title">Add Vendor</h1>
        <div class="page-actions">
            <a href="{{ route('vendors.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium transition-colors vendors-create-back-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="vendors-create-back-label-full">Back to Vendors</span>
                <span class="vendors-create-back-label-short">Back</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Vendor Information</h2>
                    <p class="text-sm text-gray-500 mt-1">Enter the details of the new vendor/supplier</p>
                </div>

                <form method="POST" action="{{ route('vendors.store') }}" class="p-6">
                    @csrf
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Business Name <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name') }}" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('name') border-red-500 @enderror">
                                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-2">Contact Person <span class="text-gray-400 text-xs">(Optional)</span></label>
                                <input type="text" name="contact_person" id="contact_person" value="{{ old('contact_person') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="mobile" class="block text-sm font-medium text-gray-700 mb-2">Mobile</label>
                                <input type="tel" name="mobile" id="mobile" value="{{ old('mobile') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('mobile') border-red-500 @enderror">
                                @error('mobile')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" name="email" id="email" value="{{ old('email') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('email') border-red-500 @enderror">
                                @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="city" class="block text-sm font-medium text-gray-700 mb-2">City</label>
                                <input type="text" name="city" id="city" value="{{ old('city') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div>
                                <label for="state" class="block text-sm font-medium text-gray-700 mb-2">State</label>
                                <input type="text" name="state" id="state" value="{{ old('state') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                        </div>

                        <div>
                            <label for="gst_number" class="block text-sm font-medium text-gray-700 mb-2">GST Number</label>
                            <input type="text" name="gst_number" id="gst_number" value="{{ old('gst_number') }}" maxlength="15" placeholder="e.g. 22AAAAA0000A1Z5" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('gst_number') border-red-500 @enderror">
                            @error('gst_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <textarea name="address" id="address" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('address') }}</textarea>
                        </div>

                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <textarea name="notes" id="notes" rows="2" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('notes') }}</textarea>
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('vendors.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors font-medium">Cancel</a>
                        <button type="submit" class="px-6 py-2 rounded-md transition-colors font-medium" style="background: #0d9488; color: white;">Save Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
