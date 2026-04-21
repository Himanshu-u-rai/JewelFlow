<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Product</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $product->design_code }}</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('products.show', $product) }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Cancel
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

        <form method="POST" action="{{ route('products.update', $product) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Image Upload -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Product Image</h2>
                        
                        <div class="space-y-4">
                            <!-- Current/Preview Image (smaller) -->
                            <div class="h-40 bg-gray-100 rounded-lg overflow-hidden relative" id="imagePreviewContainer">
                                @if($product->image)
                                    <img src="{{ asset('storage/' . $product->image) }}" 
                                         alt="{{ $product->name }}" 
                                         class="w-full h-full object-cover cursor-pointer" id="imagePreview"
                                         onclick="openImageModal('{{ asset('storage/' . $product->image) }}', '{{ $product->name }}')">
                                    <button type="button" 
                                            onclick="openImageModal('{{ asset('storage/' . $product->image) }}', '{{ $product->name }}')"
                                            class="absolute bottom-2 right-2 px-2 py-1 bg-black/60 rounded text-white text-xs hover:bg-black/80 transition-colors flex items-center gap-1" id="viewFullBtn">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/>
                                        </svg>
                                        View Full
                                    </button>
                                @else
                                    <div class="w-full h-full flex items-center justify-center" id="noImagePlaceholder">
                                        <div class="text-center">
                                            <svg class="w-10 h-10 text-gray-300 mx-auto mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            <p class="text-xs text-gray-400">No image</p>
                                        </div>
                                    </div>
                                    <img src="" alt="Product image preview" class="w-full h-full object-cover hidden" id="imagePreview">
                                @endif
                            </div>
                            
                            <!-- Upload Input -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Upload New Image</label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/jpg,image/webp" 
                                       class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100"
                                       onchange="previewImage(this)">
                                <p class="text-xs text-gray-500 mt-1">JPEG, PNG, JPG, WebP. Max 2MB.</p>
                            </div>
                            
                            @if($product->image)
                                <!-- Remove Image Option -->
                                <div class="flex items-center gap-2">
                                    <input type="checkbox" name="remove_image" value="1" id="remove_image" 
                                           class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                    <label for="remove_image" class="text-sm text-red-600">Remove current image</label>
                                </div>
                            @endif
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
                                <input type="text" name="name" value="{{ old('name', $product->name) }}" 
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Design Code <span class="text-red-500">*</span></label>
                                <input type="text" name="design_code" value="{{ old('design_code', $product->design_code) }}" 
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Category <span class="text-red-500">*</span></label>
                                <select name="category_id" id="category_id" 
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                                    <option value="">Select Category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}" @selected(old('category_id', $product->category_id) == $cat->id)>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Sub-category <span class="text-red-500">*</span></label>
                                <select name="sub_category_id" id="sub_category_id" 
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500" required>
                                    <option value="">Select Sub-category</option>
                                    @foreach($categories as $cat)
                                        @foreach($cat->subCategories as $sub)
                                            <option value="{{ $sub->id }}" 
                                                    data-category="{{ $cat->id }}"
                                                    @selected(old('sub_category_id', $product->sub_category_id) == $sub->id)>
                                                {{ $sub->name }}
                                            </option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Default Values <span class="text-sm font-normal text-gray-500">(Optional)</span></h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Default Purity</label>
                                <select name="default_purity" class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select</option>
                                    <option value="24" @selected(old('default_purity', $product->default_purity) == 24)>24K (999)</option>
                                    <option value="22" @selected(old('default_purity', $product->default_purity) == 22)>22K (916)</option>
                                    <option value="18" @selected(old('default_purity', $product->default_purity) == 18)>18K (750)</option>
                                    <option value="14" @selected(old('default_purity', $product->default_purity) == 14)>14K (585)</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Approx Weight (g)</label>
                                <input type="number" name="approx_weight" value="{{ old('approx_weight', $product->approx_weight) }}" 
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Default Making (₹)</label>
                                <input type="number" name="default_making" value="{{ old('default_making', $product->default_making) }}" 
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Default Stone (₹)</label>
                                <input type="number" name="default_stone" value="{{ old('default_stone', $product->default_stone) }}" 
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Notes</h2>
                        <textarea name="notes" rows="3" 
                                  class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                  placeholder="Any additional notes...">{{ old('notes', $product->notes) }}</textarea>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end gap-4">
                <a href="{{ route('products.show', $product) }}" 
                   class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-medium">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel
                </a>
                <button type="submit" 
                        class="px-6 py-3 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors font-medium shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>Update Product
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

        // Image preview
        function previewImage(input) {
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('noImagePlaceholder');
            const viewFullBtn = document.getElementById('viewFullBtn');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    preview.onclick = function() { openImageModal(e.target.result, 'Preview'); };
                    if (placeholder) placeholder.classList.add('hidden');
                    if (viewFullBtn) {
                        viewFullBtn.classList.remove('hidden');
                        viewFullBtn.classList.add('flex');
                        viewFullBtn.onclick = function() { openImageModal(e.target.result, 'Preview'); };
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Filter sub-categories based on category
        const categorySelect = document.getElementById('category_id');
        const subCategorySelect = document.getElementById('sub_category_id');
        const allSubOptions = [...subCategorySelect.querySelectorAll('option[data-category]')];
        
        function filterSubCategories() {
            const selectedCategory = categorySelect.value;
            const currentSubValue = subCategorySelect.value;
            
            // Clear and add placeholder
            subCategorySelect.innerHTML = '<option value="">Select Sub-category</option>';
            
            // Add matching sub-categories
            allSubOptions.forEach(option => {
                if (option.dataset.category === selectedCategory) {
                    const newOption = option.cloneNode(true);
                    if (newOption.value === currentSubValue) {
                        newOption.selected = true;
                    }
                    subCategorySelect.appendChild(newOption);
                }
            });
        }
        
        categorySelect.addEventListener('change', filterSubCategories);
        
        // Initial filter
        filterSubCategories();
    </script>
</x-app-layout>
