<x-app-layout>
    <x-page-header class="ops-treatment-header">
        <h1 class="page-title">Create Reorder Rule</h1>
        <div class="page-actions">
            <a href="{{ route('reorder.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>Back</a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        <div class="max-w-2xl mx-auto">
            <div class="bg-white rounded-lg shadow-sm">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Rule Details</h2>
                    <p class="text-sm text-gray-500 mt-1">Set minimum stock level for a category. You'll see alerts when stock falls below.</p>
                </div>
                <form method="POST" action="{{ route('reorder.store') }}" class="p-6">
                    @csrf
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                                <select name="category" id="category" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                    <option value="">All categories</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->name }}" {{ old('category') === $cat->name ? 'selected' : '' }}>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('category')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <label for="sub_category" class="block text-sm font-medium text-gray-700 mb-2">Sub-Category</label>
                                <select name="sub_category" id="sub_category" data-old-sub="{{ old('sub_category') }}" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
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
                            <input type="number" name="min_stock_threshold" id="min_stock_threshold" value="{{ old('min_stock_threshold', 5) }}" min="1" required class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                            <p class="text-xs text-gray-500 mt-1">Alert when stock count drops below this number</p>
                            @error('min_stock_threshold')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="vendor_id" class="block text-sm font-medium text-gray-700 mb-2">Preferred Vendor</label>
                            <select name="vendor_id" id="vendor_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                                <option value="">None</option>
                                @foreach($vendors as $vendor)
                                    <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                            @error('vendor_id')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="mt-8 flex items-center justify-end gap-4 pt-6 border-t border-gray-200">
                        <a href="{{ route('reorder.index') }}" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 font-medium"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel</a>
                        <button type="submit" class="px-6 py-2 rounded-md font-medium" style="background: #0d9488; color: white;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Create Rule</button>
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
