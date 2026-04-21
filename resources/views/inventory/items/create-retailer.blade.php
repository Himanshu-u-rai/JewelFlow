<x-app-layout>
    <x-page-header class="inventory-items-create-header">
        <div>
            <h1 class="page-title">Add New Item</h1>
            <p class="text-sm text-gray-500 mt-1">Add a jewellery item to your stock</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('inventory.items.index') }}" 
               class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors text-sm font-medium inventory-items-create-back-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Items
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

        <form method="POST" action="{{ route('inventory.items.store') }}" class="space-y-6" id="createItemForm" enctype="multipart/form-data">
            @csrf

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left: Item Details -->
                <div class="lg:col-span-2 space-y-6">
                    
                    <!-- Basic Info Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Item Details</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Barcode <span class="text-red-500">*</span>
                                </label>
                                <div class="flex gap-2">
                                    <input type="text" name="barcode" id="barcode" required
                                           value="{{ old('barcode') }}"
                                           class="flex-1 rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                           placeholder="Enter or scan barcode">
                                    <button type="button" onclick="generateBarcode()" 
                                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm font-medium whitespace-nowrap">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Generate
                                    </button>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Design / Item Name</label>
                                <input type="text" name="design" id="design"
                                       value="{{ old('design') }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., Flower Ring, Traditional Necklace">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Category <span class="text-red-500">*</span>
                                </label>
                                <select name="category" id="category" required onchange="handleCategoryChange()"
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
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
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select sub category</option>
                                </select>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Item Image</label>
                                <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                <p class="mt-1 text-xs text-gray-500">Optional. JPG, PNG, GIF, or WEBP up to 2MB.</p>
                                <div class="mt-3">
                                    <div id="imagePreviewPlaceholder" class="rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-400 text-xs" style="width: 120px; height: 120px;">
                                        No image selected
                                    </div>
                                    <img id="imagePreview" src="" alt="Selected item image" class="rounded-lg object-cover bg-gray-100 border border-gray-200 hidden" style="width: 120px; height: 120px;">
                                </div>
                                @error('image')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Weight & Purity Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Weight & Purity</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Gross Weight (g) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="gross_weight" id="gross_weight" required
                                       value="{{ old('gross_weight') }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.000"
                                       oninput="calculateNet()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Stone Weight (g)
                                </label>
                                <input type="number" name="stone_weight" id="stone_weight"
                                       value="{{ old('stone_weight', '0') }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.000"
                                       oninput="calculateNet()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Net Metal Weight (g)
                                </label>
                                <input type="text" id="net_weight_display" readonly
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700"
                                       value="0.000">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Purity (Karat) <span class="text-red-500">*</span>
                                </label>
                                <select name="purity" id="purity" required
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select</option>
                                    <option value="24" {{ old('purity') == '24' ? 'selected' : '' }}>24K</option>
                                    <option value="22" {{ old('purity', '22') == '22' ? 'selected' : '' }}>22K</option>
                                    <option value="18" {{ old('purity') == '18' ? 'selected' : '' }}>18K</option>
                                    <option value="14" {{ old('purity') == '14' ? 'selected' : '' }}>14K</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Pricing</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Cost Price (₹) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="cost_price" id="cost_price" required
                                       value="{{ old('cost_price') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="Purchase cost"
                                       oninput="updateProfit()">
                                <p class="mt-1 text-xs text-gray-500">How much you paid for this item</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Selling Price / MRP (₹) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="selling_price" id="selling_price" required
                                       value="{{ old('selling_price') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="Tag price / MRP"
                                       oninput="updateProfit()">
                                <p class="mt-1 text-xs text-gray-500">The price tag shown to customer</p>
                            </div>
                        </div>
                    </div>

                    <!-- Vendor & Hallmark Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Vendor & Hallmark</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Vendor / Supplier</label>
                                <select name="vendor_id" class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">— None —</option>
                                    @foreach($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Who supplied this item</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">HUID Number</label>
                                <input type="text" name="huid" value="{{ old('huid') }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., A1B2C3D4E5F6"
                                       maxlength="30">
                                <p class="mt-1 text-xs text-gray-500">BIS Hallmark Unique ID</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hallmark Date</label>
                                <input type="date" name="hallmark_date" value="{{ old('hallmark_date') }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                    </div>

                    <!-- Advanced: Cost Breakdown (collapsible) -->
                    <div x-data="{ open: {{ old('making_charges') || old('stone_charges') ? 'true' : 'false' }} }" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <button type="button" @click="open = !open" class="flex items-center justify-between w-full text-left">
                            <h2 class="text-lg font-semibold text-gray-900">Cost Breakdown <span class="text-sm font-normal text-gray-400">(optional)</span></h2>
                            <svg :class="open ? 'rotate-180' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <p class="text-xs text-gray-500 mt-1">Break down the cost price into making & stone charges for your records</p>
                        
                        <div x-show="open" x-cloak class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Making Charges (₹)</label>
                                <input type="number" name="making_charges" id="making_charges"
                                       value="{{ old('making_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                                <p class="mt-1 text-xs text-gray-500">Included in cost price (for your reference)</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Charges (₹)</label>
                                <input type="number" name="stone_charges" id="stone_charges"
                                       value="{{ old('stone_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                                <p class="mt-1 text-xs text-gray-500">Included in cost price (for your reference)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar: Summary -->
                <div class="lg:col-span-1">
                    <div class="sticky top-6 space-y-6">
                        <!-- Price Summary Card -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Summary</h2>
                            
                            <dl class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Gross Weight</dt>
                                    <dd class="font-medium text-gray-900" id="summaryGross">—</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Net Metal</dt>
                                    <dd class="font-medium text-gray-900" id="summaryNet">—</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Purity</dt>
                                    <dd class="font-medium text-gray-900" id="summaryPurity">—</dd>
                                </div>
                                
                                <hr class="border-gray-200">
                                
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Cost Price</dt>
                                    <dd class="font-medium text-gray-900" id="summaryCost">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Selling Price</dt>
                                    <dd class="font-semibold text-amber-600 text-base" id="summarySelling">₹ 0.00</dd>
                                </div>
                                
                                <hr class="border-gray-200">
                                
                                <div class="flex justify-between items-center">
                                    <dt class="text-gray-500">Profit Margin</dt>
                                    <dd id="summaryProfit" class="font-bold text-green-600">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between items-center">
                                    <dt class="text-gray-500">Margin %</dt>
                                    <dd id="summaryMarginPct" class="text-green-600 font-medium">0%</dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" id="submitBtn"
                                class="w-full flex items-center justify-center gap-2 px-6 py-3 rounded-xl text-white font-semibold text-base shadow-lg transition-all hover:shadow-xl"
                                style="background: #0d9488;">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            Add Item to Stock
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script>
        // Generate barcode
        function generateBarcode() {
            const now = Date.now().toString().slice(-8);
            const rand = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            document.getElementById('barcode').value = 'JF-' + now + rand;
        }

        // Calculate net weight
        function calculateNet() {
            const gross = parseFloat(document.getElementById('gross_weight').value) || 0;
            const stone = parseFloat(document.getElementById('stone_weight').value) || 0;
            const net = Math.max(0, gross - stone);
            document.getElementById('net_weight_display').value = net.toFixed(3);

            // Update summary
            document.getElementById('summaryGross').textContent = gross > 0 ? gross.toFixed(3) + 'g' : '—';
            document.getElementById('summaryNet').textContent = net > 0 ? net.toFixed(3) + 'g' : '—';
        }

        // Update profit calculations
        function updateProfit() {
            const cost = parseFloat(document.getElementById('cost_price').value) || 0;
            const selling = parseFloat(document.getElementById('selling_price').value) || 0;
            const profit = selling - cost;
            const marginPct = cost > 0 ? ((profit / cost) * 100) : 0;

            document.getElementById('summaryCost').textContent = '₹ ' + cost.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summarySelling').textContent = '₹ ' + selling.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summaryProfit').textContent = '₹ ' + profit.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summaryMarginPct').textContent = marginPct.toFixed(1) + '%';

            const profitEl = document.getElementById('summaryProfit');
            const pctEl = document.getElementById('summaryMarginPct');
            if (profit < 0) {
                profitEl.className = 'font-bold text-red-600';
                pctEl.className = 'text-red-600 font-medium';
            } else {
                profitEl.className = 'font-bold text-green-600';
                pctEl.className = 'text-green-600 font-medium';
            }
        }

        // Purity summary
        document.getElementById('purity')?.addEventListener('change', function() {
            document.getElementById('summaryPurity').textContent = this.value ? this.value + 'K' : '—';
        });

        // Image preview
        document.getElementById('image')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('imagePreviewPlaceholder');
            if (file) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    preview.src = ev.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                preview.classList.add('hidden');
                placeholder.classList.remove('hidden');
            }
        });

        // Category → Sub-category loading
        const categoriesData = @json($categories->mapWithKeys(fn($c) => [$c->id => $c->subCategories->pluck('name')]));

        function handleCategoryChange() {
            const sel = document.getElementById('category');
            const opt = sel.options[sel.selectedIndex];
            const catId = opt?.getAttribute('data-category-id');
            const subSel = document.getElementById('sub_category');
            subSel.innerHTML = '<option value="">Select sub category</option>';
            if (catId && categoriesData[catId]) {
                categoriesData[catId].forEach(function(name) {
                    const o = document.createElement('option');
                    o.value = name;
                    o.textContent = name;
                    subSel.appendChild(o);
                });
            }
        }

        // Init on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateNet();
            updateProfit();
            if (document.getElementById('category').value) handleCategoryChange();
            if (document.getElementById('purity').value) {
                document.getElementById('summaryPurity').textContent = document.getElementById('purity').value + 'K';
            }
        });
    </script>
</x-app-layout>
