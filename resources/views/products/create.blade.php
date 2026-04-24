<x-app-layout>
    @php
        $profileOptions = $purityProfiles->mapWithKeys(function ($profiles, $metalType) {
            return [
                $metalType => $profiles->map(function ($profile) {
                    $value = rtrim(rtrim(number_format((float) $profile->purity_value, 3, '.', ''), '0'), '.');

                    return [
                        'value' => $value,
                        'label' => $profile->label,
                    ];
                })->values(),
            ];
        });

        $categoriesData = $categories->mapWithKeys(fn ($category) => [
            (string) $category->id => $category->subCategories->map(fn ($subCategory) => [
                'id' => $subCategory->id,
                'name' => $subCategory->name,
            ])->values(),
        ]);
    @endphp

    <x-page-header>
        <div>
            <h1 class="page-title">Add New Product</h1>
            <p class="text-sm text-gray-500 mt-1">Create a product template in your catalog</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('products.index') }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Products
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        @if($errors->any())
            <div class="mb-6 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Image Upload -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Product Image</h2>
                        
                        <div class="space-y-4">
                            <!-- Preview (smaller) -->
                            <div class="h-40 bg-gray-100 rounded-lg overflow-hidden relative">
                                <div class="w-full h-full flex items-center justify-center" id="noImagePlaceholder">
                                    <div class="text-center">
                                        <svg class="w-10 h-10 text-gray-300 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <p class="text-xs text-gray-400">No image</p>
                                    </div>
                                </div>
                                <img src="" alt="Product image preview" class="w-full h-full object-cover hidden cursor-pointer" id="imagePreview" onclick="openImageModal(this.src, 'Preview')">
                                <button type="button" id="viewFullBtn" class="absolute bottom-2 right-2 px-2 py-1 bg-black/60 rounded text-white text-xs hover:bg-black/80 transition-colors hidden items-center gap-1" onclick="openImageModal(document.getElementById('imagePreview').src, 'Preview')">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/>
                                    </svg>
                                    View Full
                                </button>
                            </div>
                            
                            <!-- Upload Input -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Upload Image</label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/jpg,image/webp" 
                                       class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100"
                                       onchange="previewImage(this)">
                                <p class="text-xs text-gray-500 mt-1">JPEG, PNG, JPG, WebP. Max 2MB.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Details -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Basic Information</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Product Name <span class="text-red-500">*</span></label>
                                <input type="text" name="name" value="{{ old('name') }}" 
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" 
                                       placeholder="e.g., Traditional Gold Chain" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Design Code</label>
                                <div class="flex gap-2">
                                    <input type="text" name="design_code" id="design_code" value="{{ old('design_code') }}" 
                                           class="flex-1 rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" 
                                           placeholder="Auto-generated if empty">
                                    <button type="button" onclick="generateDesignCode()" 
                                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm font-medium">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Generate
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Unique code to identify this product design</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Category <span class="text-red-500">*</span></label>
                                <select name="category_id" id="category_id" 
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                                    <option value="">Select Category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}" @selected(old('category_id') == $cat->id)>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Sub-category <span class="text-red-500">*</span></label>
                                <select name="sub_category_id" id="sub_category_id" 
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                                    <option value="">Select category first</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Metal Type <span class="text-red-500">*</span></label>
                                <select name="metal_type" id="metal_type"
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                                    <option value="">Select metal</option>
                                    <option value="gold" @selected(old('metal_type', 'gold') === 'gold')>Gold</option>
                                    <option value="silver" @selected(old('metal_type') === 'silver')>Silver</option>
                                </select>
                            </div>
                        </div>
                    </div>
            
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Default Values <span class="text-sm font-normal text-gray-500">(Optional)</span></h2>
                        <p class="text-sm text-gray-500 mb-4">These values will be pre-filled when creating items from this product</p>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Default Purity</label>
                                <select name="default_purity" id="default_purity" data-initial-value="{{ old('default_purity') }}"
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select metal type first</option>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Defaults come from your shared shop purity catalog.</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Approx Weight (g)</label>
                                <input type="number" name="approx_weight" value="{{ old('approx_weight') }}" 
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" 
                                       placeholder="0.000">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Default Making (₹)</label>
                                <input type="number" name="default_making" value="{{ old('default_making') }}" 
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" 
                                       placeholder="0.00">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Default Stone (₹)</label>
                                <input type="number" name="default_stone" value="{{ old('default_stone') }}" 
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" 
                                       placeholder="0.00">
                            </div>
                        </div>
                    </div>
            
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Notes</h2>
                        <textarea name="notes" rows="3" 
                                  class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                  placeholder="Any additional notes about this product...">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end gap-4">
                <a href="{{ route('products.index') }}" 
                   class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-medium">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel
                </a>
                <button type="submit" 
                        class="px-6 py-3 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors font-medium shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Create Product
                </button>
            </div>
        </form>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="fixed inset-0 z-50 hidden" onclick="closeImageModal()">
        <div class="absolute inset-0 bg-black/80"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="relative max-w-4xl max-h-[90vh]" onclick="event.stopPropagation()">
                <img id="modalImage" src="" alt="Product preview" class="max-w-full max-h-[85vh] object-contain rounded-lg shadow-2xl">
                <button onclick="closeImageModal()" 
                        class="absolute -top-3 -right-3 p-2 bg-white rounded-full shadow-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
                <p id="modalCaption" class="text-white text-center mt-3 text-sm"></p>
            </div>
        </div>
    </div>

    <script>
        const categoryOptions = @json($categoriesData);
        const purityProfiles = @json($profileOptions);

        // Image modal
        function openImageModal(src, caption) {
            document.getElementById('modalImage').src = src;
            document.getElementById('modalImage').alt = caption ? (caption + ' preview') : 'Product preview';
            document.getElementById('modalCaption').textContent = caption;
            document.getElementById('imageModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeImageModal() {
            document.getElementById('imageModal').classList.add('hidden');
            document.body.style.overflow = '';
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeImageModal();
        });

        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('noImagePlaceholder');
            const viewFullBtn = document.getElementById('viewFullBtn');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                    if (viewFullBtn) {
                        viewFullBtn.classList.remove('hidden');
                        viewFullBtn.classList.add('flex');
                    }
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.classList.add('hidden');
                placeholder.classList.remove('hidden');
                if (viewFullBtn) viewFullBtn.classList.add('hidden');
            }
        }
        
        function generateDesignCode() {
            const timestamp = Date.now().toString(36).toUpperCase();
            const random = Math.random().toString(36).substring(2, 6).toUpperCase();
            document.getElementById('design_code').value = `PRD-${timestamp}-${random}`;
        }

        function normalizePurityValue(value) {
            const number = Number.parseFloat(value);
            if (!Number.isFinite(number)) {
                return '';
            }

            return number.toFixed(3).replace(/\.?0+$/, '');
        }

        function populateSubCategories() {
            const categoryId = document.getElementById('category_id').value;
            const subCatSelect = document.getElementById('sub_category_id');
            const currentValue = subCatSelect.dataset.initialValue || subCatSelect.value || @json(old('sub_category_id'));

            if (!categoryId || !categoryOptions[categoryId]) {
                subCatSelect.innerHTML = '<option value="">Select category first</option>';
                return;
            }

            subCatSelect.innerHTML = '<option value="">Select Sub-category</option>';
            categoryOptions[categoryId].forEach((subCategory) => {
                const option = document.createElement('option');
                option.value = subCategory.id;
                option.textContent = subCategory.name;
                option.selected = String(subCategory.id) === String(currentValue);
                subCatSelect.appendChild(option);
            });

            subCatSelect.dataset.initialValue = '';
        }

        function populatePurityOptions() {
            const metalType = document.getElementById('metal_type').value;
            const puritySelect = document.getElementById('default_purity');
            const currentValue = normalizePurityValue(puritySelect.dataset.initialValue || puritySelect.value);
            const profiles = purityProfiles[metalType] || [];

            puritySelect.innerHTML = '<option value="">Select</option>';

            profiles.forEach((profile) => {
                const option = document.createElement('option');
                option.value = profile.value;
                option.textContent = profile.label;
                option.selected = profile.value === currentValue;
                puritySelect.appendChild(option);
            });

            puritySelect.dataset.initialValue = '';
        }

        document.getElementById('category_id').addEventListener('change', populateSubCategories);
        document.getElementById('metal_type').addEventListener('change', populatePurityOptions);

        document.addEventListener('DOMContentLoaded', function() {
            populateSubCategories();
            populatePurityOptions();
        });
    </script>
</x-app-layout>
