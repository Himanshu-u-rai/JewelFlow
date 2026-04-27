<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Item</h1>
            <p class="text-sm text-gray-500 mt-1">Update item details and image</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('inventory.items.show', $item) }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-sm font-medium">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Item
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

        <form method="POST" action="{{ route('inventory.items.update', $item) }}" class="space-y-6" enctype="multipart/form-data">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 sm:gap-6">
                <!-- Left Column - Image & Main Details -->
                <div class="xl:col-span-2 space-y-6">
                    
                    <!-- Item Preview Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 item-edit-mobile-preview-card">
                        <div class="grid grid-cols-12 items-start gap-3 sm:flex sm:flex-row sm:gap-6 item-edit-mobile-preview-layout">
                            <!-- Image Section -->
                            <div class="flex-shrink-0 flex justify-center sm:justify-start item-edit-mobile-preview-media">
                                <div class="relative group" id="imageContainer">
                                    @if($item->image)
                                        <img id="imagePreview" src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->design }}" 
                                             class="rounded-xl object-cover bg-gray-100 shadow-sm w-28 h-28 sm:w-32 sm:h-32 md:w-36 md:h-36 item-edit-mobile-preview-image">
                                    @else
                                        <div id="imagePlaceholder" class="rounded-xl bg-gray-100 flex items-center justify-center shadow-sm w-28 h-28 sm:w-32 sm:h-32 md:w-36 md:h-36 item-edit-mobile-preview-image">
                                            <span class="text-5xl"></span>
                                        </div>
                                        <img id="imagePreview" src="" alt="Item image preview" class="rounded-xl object-cover bg-gray-100 shadow-sm hidden w-28 h-28 sm:w-32 sm:h-32 md:w-36 md:h-36 item-edit-mobile-preview-image">
                                    @endif
                                </div>
                                <p class="hidden sm:block text-xs text-gray-500 mt-2 text-center">Item image</p>
                            </div>
                            
                            <!-- Quick Info -->
                            <div class="flex-1 min-w-0 item-edit-mobile-preview-details">
                                <div class="flex items-start justify-between gap-2 mb-2 sm:mb-4">
                                    <div>
                                        <h2 class="text-base sm:text-2xl font-bold text-gray-900 truncate item-edit-mobile-title">{{ $item->design ?: 'No Design Name' }}</h2>
                                        <p class="text-xs sm:text-base text-gray-500 truncate item-edit-mobile-subtitle">{{ $item->category }}{{ $item->sub_category ? ' / ' . $item->sub_category : '' }}</p>
                                    </div>
                                    <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-[10px] sm:text-sm font-medium bg-green-100 text-green-800 item-edit-mobile-status">
                                        In Stock
                                    </span>
                                </div>
                                
                                <div class="font-mono text-sm sm:text-lg font-semibold text-amber-600 bg-amber-50 px-2.5 sm:px-4 py-1 sm:py-2 rounded-lg inline-block mb-2 sm:mb-4 item-edit-mobile-barcode">
                                    {{ $item->barcode }}
                                </div>
                                
                                <div class="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:gap-4 text-xs sm:text-sm">
                                    <div class="bg-yellow-50 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-lg">
                                        <span class="text-yellow-600 font-semibold">{{ $item->purity }}K</span>
                                        <span class="text-yellow-700">Gold</span>
                                    </div>
                                    <div class="bg-gray-50 px-2.5 sm:px-3 py-1.5 sm:py-2 rounded-lg">
                                        <span class="font-semibold text-gray-700">{{ number_format($item->net_metal_weight, 3) }}g</span>
                                        <span class="text-gray-500">Net</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Editable Fields -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            Item Details
                        </h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Barcode <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="barcode" 
                                       value="{{ old('barcode', $item->barcode) }}"
                                       required
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 font-mono">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Design Name</label>
                                <input type="text" name="design" 
                                       value="{{ old('design', $item->design) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., Flower Ring, Traditional Necklace">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Category <span class="text-red-500">*</span>
                                </label>
                                <select name="category" required
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select Category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->name }}" {{ old('category', $item->category) == $cat->name ? 'selected' : '' }}>
                                            {{ $cat->name }}
                                        </option>
                                    @endforeach
                                    @if(!$categories->contains('name', $item->category))
                                        <option value="{{ $item->category }}" selected>{{ $item->category }}</option>
                                    @endif
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Sub Category</label>
                                <input type="text" name="sub_category" 
                                       value="{{ old('sub_category', $item->sub_category) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., Daily Wear, Bridal">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <span class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        Change Item Image
                                    </span>
                                </label>
                                <div class="flex items-center gap-4">
                                    <label class="flex-1 flex items-center justify-center px-4 py-3 bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:bg-gray-100 hover:border-amber-400 transition-colors">
                                        <input type="file" name="image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" class="hidden" id="imageFileInput">
                                        <div class="text-center">
                                            <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                            </svg>
                                            <p class="mt-1 text-sm text-gray-600"><span class="font-medium text-amber-600">Click to upload</span> or drag and drop</p>
                                            <p class="text-xs text-gray-500">PNG, JPG, GIF, WebP up to 2MB</p>
                                        </div>
                                    </label>
                                </div>
                                <div id="uploadPreviewWrap" class="mt-3 hidden">
                                    <p id="selectedFileName" class="text-sm text-amber-600 mb-2"></p>
                                    <img id="uploadPreview" src="" alt="Selected image preview" class="rounded-lg object-cover border border-gray-200 w-full max-w-[200px] max-h-52">
                                </div>
                                @if($item->image)
                                    <div class="mt-3">
                                        <input type="hidden" name="remove_image" value="0" id="removeImage">
                                        <button
                                            type="button"
                                            id="removeImageButton"
                                            data-remove-image-state="0"
                                            class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-300"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-8 0l1 12h8l1-12"/>
                                            </svg>
                                            <span id="removeImageButtonLabel">Delete current image</span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Charges -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Charges
                        </h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Making Charges</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">₹</span>
                                    <input type="number" name="making_charges" 
                                           value="{{ old('making_charges', $item->making_charges) }}"
                                           step="0.01" min="0"
                                           class="w-full pl-8 rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                           placeholder="0.00">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Charges</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">₹</span>
                                    <input type="number" name="stone_charges" 
                                           value="{{ old('stone_charges', $item->stone_charges) }}"
                                           step="0.01" min="0"
                                           class="w-full pl-8 rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                           placeholder="0.00">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-700 flex items-start gap-2">
                            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span>Cost price will be recalculated automatically: Gold Cost + Making + Stone Charges</span>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Read-only Info -->
                <div class="space-y-6">
                    <!-- Weight & Purity (Read-only) -->
                    <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-2 flex items-center gap-2">
                            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            Weight & Purity
                        </h2>
                        <p class="text-xs text-gray-500 mb-4">Cannot be changed (affects gold accounting)</p>
                        
                        <dl class="space-y-3">
                            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                <dt class="text-sm text-gray-600">Gross Weight</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ number_format($item->gross_weight, 3) }} g</dd>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                <dt class="text-sm text-gray-600">Stone Weight</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ number_format($item->stone_weight ?? 0, 3) }} g</dd>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                <dt class="text-sm text-gray-600">Net Metal</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ number_format($item->net_metal_weight, 3) }} g</dd>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-200">
                                <dt class="text-sm text-gray-600">Purity</dt>
                                <dd class="text-sm font-semibold text-yellow-600">{{ $item->purity }}K</dd>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <dt class="text-sm text-gray-600">Wastage</dt>
                                <dd class="text-sm font-semibold text-gray-900">{{ number_format($item->wastage, 4) }} g</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Current Cost -->
                    <div class="bg-gray-50 rounded-xl border border-yellow-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-yellow-900 mb-2">Current Cost Price</h2>
                        <p class="text-3xl font-bold text-yellow-700">₹{{ number_format($item->cost_price, 2) }}</p>
                        <div class="mt-4 space-y-1 text-sm text-yellow-700">
                            <div class="flex justify-between">
                                <span>Gold Cost</span>
                                <span class="font-medium">₹{{ number_format($item->cost_price - $item->making_charges - $item->stone_charges, 2) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Making</span>
                                <span class="font-medium">₹{{ number_format($item->making_charges, 2) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Stone</span>
                                <span class="font-medium">₹{{ number_format($item->stone_charges, 2) }}</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Source Lot -->
                    @if($item->metalLot)
                    <div class="bg-white rounded-xl border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-sm font-semibold text-gray-900 mb-3">Source Gold Lot</h2>
                        <a href="{{ route('inventory.gold.show', $item->metalLot) }}" 
                           class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="w-10 h-10 rounded-full bg-yellow-100 flex items-center justify-center">
                                <span class="text-lg"></span>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-900">Lot #{{ $item->metalLot->lot_number }}</div>
                                <div class="text-xs text-gray-500">{{ $item->metalLot->type ?? 'Gold' }} • {{ $item->metalLot->purity }}K</div>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 ml-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                    </div>
                    @endif
                </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-col gap-3 pt-6 border-t border-gray-200 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-gray-500">
                    Last updated: {{ $item->updated_at->format('d M Y, h:i A') }}
                </p>
                <div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                    <a href="{{ route('inventory.items.show', $item) }}" 
                       class="w-full sm:w-auto px-6 py-2.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-medium text-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel
                    </a>
                    <button type="submit" 
                            class="w-full sm:w-auto px-6 py-2.5 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors font-medium flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        function setRemoveImageState(shouldRemove) {
            const removeInput = document.getElementById('removeImage');
            const removeButton = document.getElementById('removeImageButton');
            const removeButtonLabel = document.getElementById('removeImageButtonLabel');
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('imagePlaceholder');

            if (!removeInput || !removeButton || !removeButtonLabel) {
                return;
            }

            removeInput.value = shouldRemove ? '1' : '0';
            removeButton.dataset.removeImageState = shouldRemove ? '1' : '0';
            removeButtonLabel.textContent = shouldRemove ? 'Undo image removal' : 'Delete current image';
            removeButton.classList.toggle('bg-red-600', shouldRemove);
            removeButton.classList.toggle('border-red-600', shouldRemove);
            removeButton.classList.toggle('text-white', shouldRemove);
            removeButton.classList.toggle('hover:bg-red-700', shouldRemove);
            removeButton.classList.toggle('border-red-200', !shouldRemove);
            removeButton.classList.toggle('text-red-600', !shouldRemove);
            removeButton.classList.toggle('hover:bg-red-50', !shouldRemove);

            if (!preview) {
                return;
            }

            if (shouldRemove) {
                preview.classList.add('hidden');
                placeholder?.classList.remove('hidden');
                return;
            }

            if (preview.src) {
                preview.classList.remove('hidden');
                placeholder?.classList.add('hidden');
            }
        }

        // Update preview when file is selected
        document.getElementById('imageFileInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const preview = document.getElementById('imagePreview');
                const placeholder = document.getElementById('imagePlaceholder');
                const fileName = document.getElementById('selectedFileName');
                const uploadWrap = document.getElementById('uploadPreviewWrap');
                const uploadPreview = document.getElementById('uploadPreview');

                fileName.textContent = 'Selected: ' + file.name;

                const reader = new FileReader();
                reader.onload = function(event) {
                    preview.src = event.target.result;
                    preview.classList.remove('hidden');
                    if (placeholder) placeholder.classList.add('hidden');
                    uploadPreview.src = event.target.result;
                    uploadWrap.classList.remove('hidden');
                    setRemoveImageState(false);
                };
                reader.readAsDataURL(file);
            }
        });
        
        const removeButton = document.getElementById('removeImageButton');
        if (removeButton) {
            removeButton.addEventListener('click', function () {
                const shouldRemove = removeButton.dataset.removeImageState !== '1';
                setRemoveImageState(shouldRemove);
            });
        }
    </script>
</x-app-layout>
