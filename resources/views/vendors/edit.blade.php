<x-app-layout>
    <x-page-header class="vendors-edit-header ops-treatment-header">
        <h1 class="page-title">Edit Vendor</h1>
        <div class="page-actions">
            <a href="{{ route('vendors.show', $vendor) }}" class="inline-flex items-center px-4 py-2 text-sm font-medium transition-colors vendors-edit-back-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg><span class="vendors-edit-back-label-full">Back to Vendor</span><span class="vendors-edit-back-label-short">Back</span></a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Edit {{ $vendor->name }}</h2>
                </div>
                <form method="POST" action="{{ route('vendors.update', $vendor) }}" class="p-6">
                    @csrf @method('PUT')
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Business Name <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name', $vendor->name) }}" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-2">Contact Person</label>
                                <input type="text" name="contact_person" id="contact_person" value="{{ old('contact_person', $vendor->contact_person) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="mobile" class="block text-sm font-medium text-gray-700 mb-2">Mobile</label>
                                <input type="tel" name="mobile" id="mobile" value="{{ old('mobile', $vendor->mobile) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('mobile') border-red-500 @enderror">
                                @error('mobile')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" name="email" id="email" value="{{ old('email', $vendor->email) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('email') border-red-500 @enderror">
                                @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="city" class="block text-sm font-medium text-gray-700 mb-2">City</label>
                                <input type="text" name="city" id="city" value="{{ old('city', $vendor->city) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div>
                                <label for="state" class="block text-sm font-medium text-gray-700 mb-2">State</label>
                                <input type="text" name="state" id="state" value="{{ old('state', $vendor->state) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                        </div>
                        <div>
                            <label for="gst_number" class="block text-sm font-medium text-gray-700 mb-2">GST Number</label>
                            <input type="text" name="gst_number" id="gst_number" value="{{ old('gst_number', $vendor->gst_number) }}" maxlength="15" placeholder="e.g. 22AAAAA0000A1Z5" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 @error('gst_number') border-red-500 @enderror">
                            @error('gst_number')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-700 mb-2">Address</label>
                            <textarea name="address" id="address" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('address', $vendor->address) }}</textarea>
                        </div>
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <textarea name="notes" id="notes" rows="2" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('notes', $vendor->notes) }}</textarea>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $vendor->is_active) ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500">
                            <label for="is_active" class="text-sm font-medium text-gray-700">Active vendor</label>
                        </div>
                    </div>
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('vendors.show', $vendor) }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors font-medium">Cancel</a>
                        <button type="submit" class="px-6 py-2 rounded-md transition-colors font-medium" style="background: #0d9488; color: white;">Update Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
