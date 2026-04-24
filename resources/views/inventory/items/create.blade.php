<x-app-layout>
    <x-page-header class="inventory-items-create-header">
        <div>
            <h1 class="page-title">Create New Item</h1>
            <p class="text-sm text-gray-500 mt-1">Add a jewellery item to stock from available gold</p>
        </div>
        <div class="page-actions">
                <a href="{{ route('inventory.items.index') }}" 
                    class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 transition-colors text-sm font-medium inventory-items-create-back-btn" style="border-radius:10px;">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Items
            </a>
        </div>
    </x-page-header>

    <div class="content-inner">
        @if($errors->any())
            <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 text-red-700 text-sm" style="border-radius:16px;">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('inventory.items.store') }}" class="space-y-6" id="createItemForm" enctype="multipart/form-data">
            @csrf

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Item Details -->
                <div class="lg:col-span-2 bg-white shadow-sm border border-gray-200 p-6" style="border-radius:16px;">
                    <h2 class="text-lg font-semibold text-gray-900 mb-6">Item Details</h2>
                    
                    <!-- Product Quick-Fill -->
                    @if(isset($products) && $products->count() > 0)
                    <div class="mb-6 p-4 bg-amber-50 border border-amber-100" style="border-radius:16px;">
                        <label class="block text-sm font-medium text-amber-700 mb-2">
                            Quick Fill from Product Catalog
                        </label>
                        <input type="hidden" name="product_id" id="product_id" value="{{ old('product_id', $selectedProductId ?? '') }}">
                        <select id="productSelect" onchange="fillFromProduct(this)"
                            class="w-full border-amber-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;">
                            <option value="">-- Select a product to auto-fill --</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" 
                                        data-category="{{ $product->category?->name }}"
                                        data-subcategory="{{ $product->subCategory?->name }}"
                                        data-design="{{ $product->name }}"
                                        data-purity="{{ $product->default_purity }}"
                                        data-making="{{ $product->default_making }}"
                                        data-stone="{{ $product->default_stone }}"
                                        data-wastage="{{ $product->wastage_percent ?? 0 }}"
                                        data-approx-weight="{{ $product->approx_weight }}"
                                        @selected(old('product_id', $selectedProductId ?? null) == $product->id)>
                                    {{ $product->name }} - {{ $product->category?->name ?? 'Uncategorized' }} ({{ $product->default_purity ?? '-' }}K)
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-amber-600">This auto-fills item details from catalog template, then you complete lot and weight.</p>
                    </div>
                    @endif
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Barcode <span class="text-red-500">*</span>
                            </label>
                            <div class="flex gap-2">
                                    <input type="text" name="barcode" id="barcode" required
                                        value="{{ old('barcode') }}"
                                        class="flex-1 border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;"
                                        placeholder="Enter or generate barcode">
                                <button type="button" onclick="generateBarcode()" 
                                    class="px-4 py-2 bg-gray-200 text-gray-700 hover:bg-gray-300 text-sm font-medium" style="border-radius:10px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Generate
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Design Name</label>
                            <input type="text" name="design" id="design"
                                value="{{ old('design') }}"
                                class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;"
                                placeholder="e.g., Flower Ring, Traditional Necklace">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Category <span class="text-red-500">*</span>
                            </label>
                                <select name="category" id="category" required onchange="handleCategoryChange()"
                                    class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;">
                                <option value="">Select Category</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat->name }}" data-category-id="{{ $cat->id }}" {{ old('category') == $cat->name ? 'selected' : '' }}>
                                        {{ $cat->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Sub Category</label>
                                <select name="sub_category" id="sub_category"
                                    class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;">
                                <option value="">Select sub category</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Sub categories are loaded from your saved category setup.</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Item Image</label>
                            <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                                class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;">
                            <p class="mt-1 text-xs text-gray-500">Optional. JPG, PNG, GIF, or WEBP up to 2MB.</p>
                            <div class="mt-3">
                                <div id="imagePreviewPlaceholder" class="bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-400 text-xs" style="width: 120px; height: 120px; border-radius:12px;">
                                    No image selected
                                </div>
                                <img id="imagePreview" src="" alt="Selected item image" class="object-cover bg-gray-100 border border-gray-200 hidden" style="width: 120px; height: 120px; border-radius:12px;">
                            </div>
                            @error('image')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Gold Lot Selection -->
                <div class="bg-white shadow-sm border border-gray-200 p-6" style="border-radius:16px;">
                    <h2 class="text-lg font-semibold text-gray-900 mb-6">Gold Source</h2>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Select Gold Lot <span class="text-red-500">*</span>
                        </label>
                        <select name="metal_lot_id" id="metal_lot_id" required
                            class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;"
                            onchange="updateLotInfo()">
                            <option value="">Select a lot</option>
                            @foreach($lots as $lot)
                                <option value="{{ $lot->id }}" 
                                        data-purity="{{ $lot->purity }}"
                                        data-remaining="{{ $lot->fine_weight_remaining }}"
                                        data-cost="{{ $lot->cost_per_fine_gram }}"
                                        {{ (old('metal_lot_id') ?? request('lot_id')) == $lot->id ? 'selected' : '' }}>
                                    Lot #{{ $lot->lot_number }} - {{ $lot->purity }}K - {{ number_format($lot->fine_weight_remaining, 3) }}g fine
                                </option>
                            @endforeach
                        </select>
                        
                        @if($lots->isEmpty())
                            <p class="mt-2 text-sm text-red-500">
                                No gold lots available. <a href="{{ route('inventory.gold.create') }}" class="underline">Add gold first</a>.
                            </p>
                        @endif
                    </div>
                    
                    <div id="lotInfo" class="mt-4 p-4 bg-yellow-50 hidden" style="border-radius:16px;">
                        <h4 class="text-sm font-medium text-yellow-800 mb-2">Selected Lot Info</h4>
                        <dl class="space-y-1 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-yellow-700">Purity:</dt>
                                <dd class="font-semibold text-yellow-900" id="lotPurity">-</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-yellow-700">Available Fine Gold:</dt>
                                <dd class="font-semibold text-yellow-900" id="lotRemaining">-</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-yellow-700">Cost/Fine Gram:</dt>
                                <dd class="font-semibold text-yellow-900" id="lotCost">-</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Weight & Purity -->
            <div class="bg-white shadow-sm border border-gray-200 p-6" style="border-radius:16px;">
                <h2 class="text-lg font-semibold text-gray-900 mb-6">Weight & Purity</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Gross Weight (g) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="gross_weight" id="gross_weight" required
                               value="{{ old('gross_weight') }}"
                               step="0.001" min="0"
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                               placeholder="0.000"
                               onchange="calculateWeights()">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Stone Weight (g)</label>
                        <input type="number" name="stone_weight" id="stone_weight" 
                               value="{{ old('stone_weight', 0) }}"
                               step="0.001" min="0"
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                               placeholder="0.000"
                               onchange="calculateWeights()">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Net Metal Weight (g)</label>
                        <input type="number" name="net_metal_weight" id="net_metal_weight" readonly
                               value="{{ old('net_metal_weight') }}"
                               step="0.001"
                               class="w-full rounded-lg border-gray-200 bg-gray-50 text-gray-700"
                               placeholder="Auto-calculated">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Purity <span class="text-red-500">*</span>
                        </label>
                        <select name="purity" id="purity" required
                                class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                onchange="calculateWeights()">
                            <option value="">Select</option>
                            <option value="24" {{ old('purity') == '24' ? 'selected' : '' }}>24K (999)</option>
                            <option value="22" {{ old('purity') == '22' ? 'selected' : '' }}>22K (916)</option>
                            <option value="18" {{ old('purity') == '18' ? 'selected' : '' }}>18K (750)</option>
                            <option value="14" {{ old('purity') == '14' ? 'selected' : '' }}>14K (585)</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Wastage %</label>
                        <input type="number" name="wastage_percent" id="wastage" 
                               value="{{ old('wastage_percent', 0) }}"
                               step="0.01" min="0" max="100"
                               class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                               placeholder="0.00"
                               onchange="calculateWeights()">
                    </div>
                </div>
                
                <!-- Calculation Display -->
                <div class="mt-6 p-4 bg-gray-50" style="border-radius:16px;">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">Gold Calculation</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500">Fine Gold Required</p>
                            <p class="text-xl font-bold text-yellow-600" id="fineGoldRequired">0.000 g</p>
                        </div>
                        <div>
                            <p class="text-gray-500">+ Wastage</p>
                            <p class="text-xl font-bold text-gray-600" id="wastageAmount">0.000 g</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Total Fine Gold Needed</p>
                            <p class="text-xl font-bold text-amber-600" id="totalFineNeeded">0.000 g</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Available in Lot</p>
                            <p class="text-xl font-bold" id="availableDisplay">
                                <span class="text-gray-400">Select a lot</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pricing -->
            <div class="bg-white shadow-sm border border-gray-200 p-6" style="border-radius:16px;">
                <h2 class="text-lg font-semibold text-gray-900 mb-6">Pricing</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Making Charges (₹)</label>
                           <input type="number" name="making_charges" id="making_charges" 
                               value="{{ old('making_charges', 0) }}"
                               step="0.01" min="0"
                               class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;"
                               placeholder="0.00"
                               onchange="calculateCostPrice()">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Stone Charges (₹)</label>
                           <input type="number" name="stone_charges" id="stone_charges" 
                               value="{{ old('stone_charges', 0) }}"
                               step="0.01" min="0"
                               class="w-full border-gray-300 focus:ring-amber-500 focus:border-amber-500" style="border-radius:12px;"
                               placeholder="0.00"
                               onchange="calculateCostPrice()">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Estimated Cost Price (₹)</label>
                           <input type="number" name="cost_price" id="cost_price" readonly
                               value="{{ old('cost_price') }}"
                               step="0.01"
                               class="w-full border-gray-200 bg-gray-50 text-gray-700 font-semibold" style="border-radius:12px;"
                               placeholder="Auto-calculated">
                        <p class="text-xs text-gray-500 mt-1">Gold cost + Making + Stone charges</p>
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="flex justify-end gap-4">
                     <a href="{{ route('inventory.items.index') }}" 
                         class="px-6 py-3 bg-gray-200 text-gray-700 hover:bg-gray-300 transition-colors font-medium" style="border-radius:10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel
                </a>
                <button type="submit" id="submitBtn"
                    class="px-6 py-3 bg-amber-600 text-white hover:bg-amber-700 transition-colors font-medium shadow-sm disabled:opacity-50 disabled:cursor-not-allowed" style="border-radius:10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>Create Item
                </button>
            </div>
        </form>
    </div>

    <script>
        let selectedLotPurity = 0;
        let selectedLotRemaining = 0;
        let selectedLotCost = 0;
        let pendingSubCategorySelection = @json(old('sub_category'));

        function generateBarcode() {
            const timestamp = Date.now().toString(36).toUpperCase();
            const random = Math.random().toString(36).substring(2, 6).toUpperCase();
            document.getElementById('barcode').value = `JF-${timestamp}-${random}`;
        }

        function updateLotInfo() {
            const select = document.getElementById('metal_lot_id');
            const option = select.options[select.selectedIndex];
            const lotInfo = document.getElementById('lotInfo');

            if (option.value) {
                selectedLotPurity = parseFloat(option.dataset.purity) || 0;
                selectedLotRemaining = parseFloat(option.dataset.remaining) || 0;
                selectedLotCost = parseFloat(option.dataset.cost) || 0;

                document.getElementById('lotPurity').textContent = selectedLotPurity + 'K';
                document.getElementById('lotRemaining').textContent = selectedLotRemaining.toFixed(3) + ' g';
                document.getElementById('lotCost').textContent = selectedLotCost > 0 ? '₹' + selectedLotCost.toFixed(2) : 'N/A';
                
                lotInfo.classList.remove('hidden');
                
                // Set purity to match lot purity
                const puritySelect = document.getElementById('purity');
                puritySelect.value = selectedLotPurity;
            } else {
                selectedLotPurity = 0;
                selectedLotRemaining = 0;
                selectedLotCost = 0;
                lotInfo.classList.add('hidden');
            }
            
            calculateWeights();
        }

        function calculateWeights() {
            const grossWeight = parseFloat(document.getElementById('gross_weight').value) || 0;
            const stoneWeight = parseFloat(document.getElementById('stone_weight').value) || 0;
            const purity = parseFloat(document.getElementById('purity').value) || 0;
            const wastagePercent = parseFloat(document.getElementById('wastage').value) || 0;

            const netMetalWeight = grossWeight - stoneWeight;
            document.getElementById('net_metal_weight').value = netMetalWeight.toFixed(3);

            const fineGold = netMetalWeight * (purity / 24);
            const wastageAmount = fineGold * (wastagePercent / 100);
            const totalFineNeeded = fineGold + wastageAmount;

            document.getElementById('fineGoldRequired').textContent = fineGold.toFixed(3) + ' g';
            document.getElementById('wastageAmount').textContent = wastageAmount.toFixed(3) + ' g';
            document.getElementById('totalFineNeeded').textContent = totalFineNeeded.toFixed(3) + ' g';

            // Check against available
            const availableDisplay = document.getElementById('availableDisplay');
            if (selectedLotRemaining > 0) {
                if (totalFineNeeded > selectedLotRemaining) {
                    availableDisplay.innerHTML = `<span class="text-red-600">${selectedLotRemaining.toFixed(3)} g (insufficient!)</span>`;
                    document.getElementById('submitBtn').disabled = true;
                } else {
                    availableDisplay.innerHTML = `<span class="text-green-600">${selectedLotRemaining.toFixed(3)} g ✓</span>`;
                    document.getElementById('submitBtn').disabled = false;
                }
            } else {
                availableDisplay.innerHTML = `<span class="text-gray-400">Select a lot</span>`;
            }

            calculateCostPrice();
        }

        function calculateCostPrice() {
            const grossWeight = parseFloat(document.getElementById('gross_weight').value) || 0;
            const stoneWeight = parseFloat(document.getElementById('stone_weight').value) || 0;
            const purity = parseFloat(document.getElementById('purity').value) || 0;
            const wastagePercent = parseFloat(document.getElementById('wastage').value) || 0;
            const makingCharges = parseFloat(document.getElementById('making_charges').value) || 0;
            const stoneCharges = parseFloat(document.getElementById('stone_charges').value) || 0;

            const netMetalWeight = grossWeight - stoneWeight;
            const fineGold = netMetalWeight * (purity / 24);
            const wastageAmount = fineGold * (wastagePercent / 100);
            const totalFineNeeded = fineGold + wastageAmount;

            const goldCost = totalFineNeeded * selectedLotCost;
            const totalCost = goldCost + makingCharges + stoneCharges;

            document.getElementById('cost_price').value = totalCost.toFixed(2);
        }

        function fillFromProduct(select) {
            const hiddenProductId = document.getElementById('product_id');
            if (!select.value) {
                if (hiddenProductId) {
                    hiddenProductId.value = '';
                }
                return;
            }
            if (hiddenProductId) {
                hiddenProductId.value = select.value;
            }
            
            const option = select.options[select.selectedIndex];
            
            // Fill category
            const category = option.dataset.category;
            const categorySelect = document.getElementById('category');
            let selectedCategoryId = '';
            for (let i = 0; i < categorySelect.options.length; i++) {
                if (categorySelect.options[i].value === category) {
                    categorySelect.selectedIndex = i;
                    selectedCategoryId = categorySelect.options[i].dataset.categoryId || '';
                    break;
                }
            }
            
            // Fill sub-category after loading list for selected category
            loadSubCategories(selectedCategoryId, option.dataset.subcategory || '');
            
            // Fill design name
            document.getElementById('design').value = option.dataset.design || '';
            
            // Fill purity
            const purity = option.dataset.purity;
            const puritySelect = document.getElementById('purity');
            for (let i = 0; i < puritySelect.options.length; i++) {
                if (puritySelect.options[i].value === purity) {
                    puritySelect.selectedIndex = i;
                    break;
                }
            }
            
            // Fill approximate weight if catalog has it
            const approxWeight = parseFloat(option.dataset.approxWeight || 0);
            if (approxWeight > 0) {
                document.getElementById('gross_weight').value = approxWeight.toFixed(3);
            }

            // Fill default charges
            const defaultMaking = parseFloat(option.dataset.making || 0);
            const defaultStone = parseFloat(option.dataset.stone || 0);
            document.getElementById('making_charges').value = defaultMaking.toFixed(2);
            document.getElementById('stone_charges').value = defaultStone.toFixed(2);

            // Fill wastage
            document.getElementById('wastage').value = option.dataset.wastage || 0;
            
            // Show notification
            const notification = document.createElement('div');
            notification.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 shadow-lg z-50';
            notification.style.borderRadius = '10px';
            notification.textContent = '✓ Product template applied. Select lot and adjust weights if needed.';
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 3000);
            
            // Recalculate
            calculateWeights();
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            const imageInput = document.getElementById('image');
            const imagePreview = document.getElementById('imagePreview');
            const imagePreviewPlaceholder = document.getElementById('imagePreviewPlaceholder');

            if (imageInput && imagePreview && imagePreviewPlaceholder) {
                imageInput.addEventListener('change', function(event) {
                    const file = event.target.files && event.target.files[0];

                    if (!file) {
                        imagePreview.src = '';
                        imagePreview.classList.add('hidden');
                        imagePreviewPlaceholder.classList.remove('hidden');
                        return;
                    }

                    const reader = new FileReader();
                    reader.onload = function(e) {
                        imagePreview.src = e.target.result;
                        imagePreview.classList.remove('hidden');
                        imagePreviewPlaceholder.classList.add('hidden');
                    };
                    reader.readAsDataURL(file);
                });
            }

            updateLotInfo();
            calculateWeights();
            handleCategoryChange();

            const preselectedProductId = @json(old('product_id', $selectedProductId ?? null));
            const hasOldInput = @json(!empty(session()->getOldInput()));
            if (preselectedProductId && !hasOldInput) {
                const productSelect = document.getElementById('productSelect');
                if (productSelect) {
                    productSelect.value = String(preselectedProductId);
                    fillFromProduct(productSelect);
                }
            }
        });

        function getSelectedCategoryId() {
            const categorySelect = document.getElementById('category');
            const selected = categorySelect.options[categorySelect.selectedIndex];
            return selected ? selected.dataset.categoryId : null;
        }

        function handleCategoryChange() {
            const categoryId = getSelectedCategoryId();
            loadSubCategories(categoryId, pendingSubCategorySelection);
            pendingSubCategorySelection = null;
        }

        async function loadSubCategories(categoryId, selectedSubName = null) {
            const subCategorySelect = document.getElementById('sub_category');
            subCategorySelect.innerHTML = '<option value="">Select sub category</option>';

            if (!categoryId) {
                subCategorySelect.disabled = true;
                return;
            }

            subCategorySelect.disabled = false;

            try {
                const response = await fetch(`/api/sub-categories?category_id=${encodeURIComponent(categoryId)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    }
                });

                if (!response.ok) return;

                const subCategories = await response.json();
                if (!Array.isArray(subCategories)) return;

                subCategories.forEach((sub) => {
                    const option = document.createElement('option');
                    option.value = sub.name;
                    option.textContent = sub.name;
                    if (selectedSubName && selectedSubName === sub.name) {
                        option.selected = true;
                    }
                    subCategorySelect.appendChild(option);
                });
            } catch (error) {
                // Silent fail: form remains usable with category only
            }
        }
    </script>
</x-app-layout>
