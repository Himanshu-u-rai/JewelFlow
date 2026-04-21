<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <h1 class="page-title">Enroll Customer — {{ $scheme->name }}</h1>
        <div class="page-actions">
            <a href="{{ route('schemes.show', $scheme) }}" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Enrollment Details</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ $scheme->total_installments ?? 11 }} installments · Bonus on maturity</p>
                </div>
                <form method="POST" action="{{ route('schemes.enroll', $scheme) }}" class="p-6">
                    @csrf
                    <div class="space-y-6">
                        <div>
                            <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-2">Customer <span class="text-red-500">*</span></label>
                            <select name="customer_id" id="customer_id" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                <option value="">Select customer...</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}" {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                                        {{ $customer->name }} — {{ $customer->mobile }}
                                    </option>
                                @endforeach
                            </select>
                            @error('customer_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="monthly_amount" class="block text-sm font-medium text-gray-700 mb-2">Monthly Amount (₹) <span class="text-red-500">*</span></label>
                            <input type="number" name="monthly_amount" id="monthly_amount" value="{{ old('monthly_amount') }}" step="0.01" min="100" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @error('monthly_amount')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                            <textarea name="notes" id="notes" rows="2" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('notes') }}</textarea>
                        </div>

                        @if($scheme->terms)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                                <p class="font-semibold mb-1">Scheme Terms</p>
                                <p class="whitespace-pre-line">{{ $scheme->terms }}</p>
                            </div>
                        @endif

                        <label class="inline-flex items-start gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }} required class="mt-0.5 rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500">
                            <span>I confirm the customer has accepted the scheme terms and conditions.</span>
                        </label>
                        @error('accept_terms')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('schemes.show', $scheme) }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                        <button type="submit" class="px-6 py-2 rounded-md font-medium" style="background: #0d9488; color: white;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>Enroll Customer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
