<x-app-layout>
    <x-page-header class="schemes-create-header ops-treatment-header">
        <h1 class="page-title">Create Scheme</h1>
        <div class="page-actions">
            <a href="{{ route('schemes.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium transition-colors schemes-create-back-btn"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg><span class="schemes-create-back-label-full">Back to Schemes</span><span class="schemes-create-back-label-short">Back</span></a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Scheme Details</h2>
                    <p class="text-sm text-gray-500 mt-1">Create a gold savings scheme, festival sale, or discount offer</p>
                </div>

                <form method="POST" action="{{ route('schemes.store') }}" class="p-6" x-data="{ type: '{{ old('type', 'gold_savings') }}', appliesTo: '{{ old('applies_to', 'all_items') }}' }">
                    @csrf
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Scheme Name <span class="text-red-500">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name') }}" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="type" class="block text-sm font-medium text-gray-700 mb-2">Type <span class="text-red-500">*</span></label>
                                <select name="type" id="type" x-model="type" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="gold_savings">Gold Savings Scheme</option>
                                    <option value="festival_sale">Festival Sale</option>
                                    <option value="discount_offer">Discount Offer</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                            <textarea name="description" id="description" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('description') }}</textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">Start Date <span class="text-red-500">*</span></label>
                                <input type="date" name="start_date" id="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                                <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            </div>
                        </div>

                        {{-- Gold Savings specific --}}
                        <div x-show="type === 'gold_savings'" x-cloak class="bg-amber-50 border border-amber-200 rounded-lg p-4">
                            <h3 class="text-sm font-semibold text-amber-800 mb-3">Gold Savings Scheme Settings</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="total_installments" class="block text-sm font-medium text-gray-700 mb-1">Total Installments</label>
                                    <input type="number" name="total_installments" id="total_installments" value="{{ old('total_installments', 11) }}" min="1" max="36" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <p class="text-xs text-gray-500 mt-1">Typically 11 months (shop pays 12th)</p>
                                </div>
                                <div>
                                    <label for="bonus_month_value" class="block text-sm font-medium text-gray-700 mb-1">Bonus Amount (₹)</label>
                                    <input type="number" name="bonus_month_value" id="bonus_month_value" value="{{ old('bonus_month_value') }}" step="0.01" min="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <p class="text-xs text-gray-500 mt-1">If blank, equals one monthly payment</p>
                                </div>
                            </div>
                        </div>

                        {{-- Offer/Sale specific --}}
                        <div x-show="type !== 'gold_savings'" x-cloak class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h3 class="text-sm font-semibold text-blue-800 mb-3">Discount Settings</h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="discount_type" class="block text-sm font-medium text-gray-700 mb-1">Discount Type</label>
                                    <select name="discount_type" id="discount_type" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                        <option value="">None</option>
                                        <option value="percentage" {{ old('discount_type') === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                                        <option value="flat" {{ old('discount_type') === 'flat' ? 'selected' : '' }}>Flat (₹)</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="discount_value" class="block text-sm font-medium text-gray-700 mb-1">Discount Value</label>
                                    <input type="number" name="discount_value" id="discount_value" value="{{ old('discount_value') }}" step="0.01" min="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label for="max_discount_amount" class="block text-sm font-medium text-gray-700 mb-1">Max Discount (₹)</label>
                                    <input type="number" name="max_discount_amount" id="max_discount_amount" value="{{ old('max_discount_amount') }}" step="0.01" min="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label for="min_purchase_amount" class="block text-sm font-medium text-gray-700 mb-1">Min Purchase (₹)</label>
                                    <input type="number" name="min_purchase_amount" id="min_purchase_amount" value="{{ old('min_purchase_amount') }}" step="0.01" min="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label for="priority" class="block text-sm font-medium text-gray-700 mb-1">Priority (lower = higher)</label>
                                    <input type="number" name="priority" id="priority" value="{{ old('priority', 100) }}" min="1" max="1000" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label for="applies_to" class="block text-sm font-medium text-gray-700 mb-1">Applies To</label>
                                    <select name="applies_to" id="applies_to" x-model="appliesTo" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                        <option value="all_items">All items</option>
                                        <option value="category">Specific category</option>
                                        <option value="sub_category">Specific sub-category</option>
                                    </select>
                                </div>
                                <div x-show="appliesTo !== 'all_items'">
                                    <label for="applies_to_value" class="block text-sm font-medium text-gray-700 mb-1">Target Value</label>
                                    <input type="text" name="applies_to_value" id="applies_to_value" value="{{ old('applies_to_value') }}" placeholder="e.g. Rings" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                                <div>
                                    <label for="max_uses_per_customer" class="block text-sm font-medium text-gray-700 mb-1">Max Uses / Customer</label>
                                    <input type="number" name="max_uses_per_customer" id="max_uses_per_customer" value="{{ old('max_uses_per_customer') }}" min="1" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="hidden" name="auto_apply" value="0">
                                    <input type="checkbox" name="auto_apply" value="1" {{ old('auto_apply') ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500">
                                    Auto apply when eligible
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="hidden" name="stackable" value="0">
                                    <input type="checkbox" name="stackable" value="1" {{ old('stackable') ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500">
                                    Allow stacking (future-ready only)
                                </label>
                            </div>
                        </div>

                        <div>
                            <label for="terms" class="block text-sm font-medium text-gray-700 mb-2">Terms & Conditions</label>
                            <textarea name="terms" id="terms" rows="3" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">{{ old('terms') }}</textarea>
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('schemes.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors font-medium">Cancel</a>
                        <button type="submit" class="px-6 py-2 rounded-md transition-colors font-medium" style="background: #0d9488; color: white;">Create Scheme</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
