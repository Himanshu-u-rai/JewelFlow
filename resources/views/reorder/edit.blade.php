<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <h1 class="page-title">Edit Reorder Rule</h1>
        <div class="page-actions">
            <a href="{{ route('reorder.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 text-sm font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Edit Rule</h2>
                </div>
                <form method="POST" action="{{ route('reorder.update', $rule) }}" class="p-6">
                    @csrf @method('PUT')
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                                <select name="category" id="category" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="">All categories</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->name }}" {{ old('category', $rule->category) === $cat->name ? 'selected' : '' }}>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('category')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="sub_category" class="block text-sm font-medium text-gray-700 mb-2">Sub-Category</label>
                                <select name="sub_category" id="sub_category" data-old-sub="{{ old('sub_category', $rule->sub_category) }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="">All sub-categories</option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Optional. This narrows alerting to a specific sub-category.</p>
                                @error('sub_category')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <div>
                            <label for="min_stock_threshold" class="block text-sm font-medium text-gray-700 mb-2">Minimum Stock Threshold <span class="text-red-500">*</span></label>
                            <input type="number" name="min_stock_threshold" id="min_stock_threshold" value="{{ old('min_stock_threshold', $rule->min_stock_threshold) }}" min="1" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            @error('min_stock_threshold')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="vendor_id" class="block text-sm font-medium text-gray-700 mb-2">Preferred Vendor</label>
                            <select name="vendor_id" id="vendor_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                <option value="">None</option>
                                @foreach($vendors as $vendor)
                                    <option value="{{ $vendor->id }}" {{ old('vendor_id', $rule->vendor_id) == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                            @error('vendor_id')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $rule->is_active) ? 'checked' : '' }} class="rounded border-gray-300 text-amber-600 shadow-sm focus:ring-amber-500">
                            <label for="is_active" class="text-sm font-medium text-gray-700">Active</label>
                        </div>
                    </div>
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('reorder.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                        <button type="submit" class="px-6 py-2 rounded-md font-medium" style="background: #0d9488; color: white;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Update Rule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
    (() => {
        const init = () => {
        const categorySelect = document.getElementById('category');
        const subCategorySelect = document.getElementById('sub_category');
        if (!categorySelect || !subCategorySelect) return;

        const subCategoryMap = @json($subCategoryMap);
        const oldSubCategory = (subCategorySelect.dataset.oldSub || '').trim();

        const buildSubCategoryOptions = (preserveOld = false) => {
            const selectedCategory = (categorySelect.value || '').trim();
            const options = selectedCategory && subCategoryMap[selectedCategory]
                ? subCategoryMap[selectedCategory]
                : [];

            subCategorySelect.innerHTML = '';

            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'All sub-categories';
            subCategorySelect.appendChild(defaultOption);

            options.forEach((name) => {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name;
                if (preserveOld && oldSubCategory !== '' && oldSubCategory === name) {
                    option.selected = true;
                }
                subCategorySelect.appendChild(option);
            });

            subCategorySelect.disabled = options.length === 0;
        };

        categorySelect.addEventListener('change', () => buildSubCategoryOptions(false));
        buildSubCategoryOptions(true);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init, { once: true });
        } else {
            init();
        }
    })();
</script>
</x-app-layout>
