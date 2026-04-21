<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Edit Item</h1>
            <p class="text-sm text-gray-500 mt-1">Update item details, weight, and pricing</p>
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
                <!-- Left: Editable Fields -->
                <div class="xl:col-span-2 space-y-6">
                    
                    <!-- Item Preview -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 item-edit-mobile-preview-card">
                        <div class="grid grid-cols-12 items-start gap-3 sm:flex sm:flex-row sm:gap-6 item-edit-mobile-preview-layout">
                            <div class="flex-shrink-0 flex justify-center sm:justify-start item-edit-mobile-preview-media">
                                <div class="relative" id="imageContainer">
                                    @if($item->image)
                                        <img id="imagePreview" src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->design }}"
                                             class="rounded-xl object-cover bg-gray-100 shadow-sm w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36 item-edit-mobile-preview-image">
                                    @else
                                        <div id="imagePlaceholder" class="rounded-xl bg-gray-100 flex items-center justify-center shadow-sm w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36 item-edit-mobile-preview-image">
                                            <span class="text-5xl"></span>
                                        </div>
                                        <img id="imagePreview" src="" alt="Item image preview" class="rounded-xl object-cover bg-gray-100 shadow-sm hidden w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36 item-edit-mobile-preview-image">
                                    @endif
                                </div>
                            </div>
                            <div class="flex-1 min-w-0 item-edit-mobile-preview-details">
                                <div class="flex items-start justify-between gap-2 mb-2 sm:mb-3">
                                    <div class="min-w-0">
                                        <h2 class="text-base sm:text-2xl font-bold text-gray-900 truncate item-edit-mobile-title">{{ $item->design ?: 'No Design Name' }}</h2>
                                        <p class="text-gray-500 text-xs sm:text-sm truncate item-edit-mobile-subtitle">{{ $item->category }}{{ $item->sub_category ? ' / ' . $item->sub_category : '' }}</p>
                                    </div>
                                    <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-[10px] sm:text-sm font-medium bg-green-100 text-green-800 item-edit-mobile-status">
                                        In Stock
                                    </span>
                                </div>
                                <div class="font-mono text-sm sm:text-lg font-semibold text-amber-600 bg-amber-50 px-2.5 sm:px-4 py-1 sm:py-2 rounded-lg inline-block mb-2 sm:mb-3 item-edit-mobile-barcode">
                                    {{ $item->barcode }}
                                </div>
                                <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:gap-3 text-xs sm:text-sm">
                                    <span class="bg-yellow-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-yellow-700 font-semibold">{{ $item->purity }}K</span>
                                    <span class="bg-gray-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-gray-700 font-semibold">{{ number_format($item->gross_weight, 3) }}g</span>
                                    <span class="col-span-2 sm:col-span-1 bg-green-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-green-700 font-semibold">₹{{ number_format($item->selling_price, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Basic Details -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Item Details</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Barcode <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="barcode" required
                                       value="{{ old('barcode', $item->barcode) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 font-mono">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Design / Item Name</label>
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

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Change Item Image</label>
                                <div class="flex items-center gap-4">
                                    <label class="flex-1 flex items-center justify-center px-4 py-3 bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg cursor-pointer hover:bg-gray-100 hover:border-amber-400 transition-colors">
                                        <input type="file" name="image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" class="hidden" id="imageFileInput">
                                        <div class="text-center">
                                            <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                            </svg>
                                            <p class="mt-1 text-sm text-gray-600"><span class="font-medium text-amber-600">Click to upload</span> new image</p>
                                        </div>
                                    </label>
                                </div>
                                <div id="uploadPreviewWrap" class="mt-3 hidden">
                                    <p id="selectedFileName" class="text-sm text-amber-600 mb-2"></p>
                                    <img id="uploadPreview" src="" alt="Selected image preview" class="rounded-lg object-cover border border-gray-200 w-full max-w-[200px] max-h-52">
                                </div>
                                @if($item->image)
                                    <div class="mt-3 flex items-center gap-2">
                                        <input type="checkbox" name="remove_image" value="1" id="removeImage" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                        <label for="removeImage" class="text-sm text-red-600">Remove current image</label>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Weight & Purity -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Weight & Purity</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Gross Weight (g) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="gross_weight" id="gross_weight" required
                                       value="{{ old('gross_weight', $item->gross_weight) }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       oninput="calculateNet()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Weight (g)</label>
                                <input type="number" name="stone_weight" id="stone_weight"
                                       value="{{ old('stone_weight', $item->stone_weight ?? 0) }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       oninput="calculateNet()">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Net Metal (g)</label>
                                <input type="text" id="net_weight_display" readonly
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700"
                                       value="{{ number_format($item->net_metal_weight, 3) }}">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Purity (Karat) <span class="text-red-500">*</span>
                                </label>
                                <select name="purity" id="purity" required
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="24" {{ old('purity', $item->purity) == 24 ? 'selected' : '' }}>24K</option>
                                    <option value="22" {{ old('purity', $item->purity) == 22 ? 'selected' : '' }}>22K</option>
                                    <option value="18" {{ old('purity', $item->purity) == 18 ? 'selected' : '' }}>18K</option>
                                    <option value="14" {{ old('purity', $item->purity) == 14 ? 'selected' : '' }}>14K</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Pricing</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Cost Price (₹) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="cost_price" id="cost_price" required
                                       value="{{ old('cost_price', $item->cost_price) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       oninput="updateProfit()">
                                <p class="mt-1 text-xs text-gray-500">How much you paid for this item</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Selling Price / MRP (₹) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="selling_price" id="selling_price" required
                                       value="{{ old('selling_price', $item->selling_price) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       oninput="updateProfit()">
                                <p class="mt-1 text-xs text-gray-500">The price tag shown to customer</p>
                            </div>
                        </div>
                    </div>

                    <!-- Vendor & Hallmark Card -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Vendor & Hallmark</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Vendor / Supplier</label>
                                <select name="vendor_id" class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">— None —</option>
                                    @foreach($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id', $item->vendor_id) == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-gray-500">Who supplied this item</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">HUID Number</label>
                                <input type="text" name="huid" value="{{ old('huid', $item->huid) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., A1B2C3D4E5F6"
                                       maxlength="30">
                                <p class="mt-1 text-xs text-gray-500">BIS Hallmark Unique ID</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hallmark Date</label>
                                <input type="date" name="hallmark_date" value="{{ old('hallmark_date', $item->hallmark_date?->format('Y-m-d')) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                    </div>

                    <!-- Advanced: Cost Breakdown (collapsible) -->
                    <div x-data="{ open: {{ ($item->making_charges > 0 || $item->stone_charges > 0 || old('making_charges') || old('stone_charges')) ? 'true' : 'false' }} }" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <button type="button" @click="open = !open" class="flex items-center justify-between w-full text-left">
                            <h2 class="text-lg font-semibold text-gray-900">Cost Breakdown <span class="text-sm font-normal text-gray-400">(optional)</span></h2>
                            <svg :class="open ? 'rotate-180' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <p class="text-xs text-gray-500 mt-1">Break down the cost price into making & stone charges for your records</p>
                        
                        <div x-show="open" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Making Charges (₹)</label>
                                <input type="number" name="making_charges"
                                       value="{{ old('making_charges', $item->making_charges) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                                <p class="mt-1 text-xs text-gray-500">Included in cost price (for your reference)</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Charges (₹)</label>
                                <input type="number" name="stone_charges"
                                       value="{{ old('stone_charges', $item->stone_charges) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                                <p class="mt-1 text-xs text-gray-500">Included in cost price (for your reference)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Sidebar -->
                <div class="xl:col-span-1">
                    <div class="xl:sticky xl:top-6 space-y-4 sm:space-y-6">
                        <!-- Profit Summary -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Profit Summary</h2>
                            <dl class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Cost Price</dt>
                                    <dd class="font-medium text-gray-900" id="summaryCost">₹{{ number_format($item->cost_price, 2) }}</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">Selling Price</dt>
                                    <dd class="font-semibold text-amber-600 text-base" id="summarySelling">₹{{ number_format($item->selling_price, 2) }}</dd>
                                </div>
                                <hr class="border-gray-200">
                                <div class="flex justify-between items-center">
                                    <dt class="text-gray-500">Profit</dt>
                                    <dd id="summaryProfit" class="font-bold text-green-600">₹{{ number_format(($item->selling_price ?? 0) - $item->cost_price, 2) }}</dd>
                                </div>
                                <div class="flex justify-between items-center">
                                    <dt class="text-gray-500">Margin %</dt>
                                    <dd id="summaryMarginPct" class="text-green-600 font-medium">
                                        {{ $item->cost_price > 0 ? number_format((($item->selling_price - $item->cost_price) / $item->cost_price) * 100, 1) : 0 }}%
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-col gap-3">
                            <button type="submit" 
                                    class="w-full px-6 py-3 rounded-xl text-white font-semibold text-base shadow-lg transition-all hover:shadow-xl flex items-center justify-center gap-2"
                                    style="background: #0d9488;">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Save Changes
                            </button>
                            <a href="{{ route('inventory.items.show', $item) }}" 
                               class="w-full px-6 py-2.5 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-colors font-medium text-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Cancel
                            </a>
                        </div>

                        <p class="text-xs text-gray-400 text-center">
                            Last updated: {{ $item->updated_at->format('d M Y, h:i A') }}
                        </p>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        function calculateNet() {
            const gross = parseFloat(document.getElementById('gross_weight').value) || 0;
            const stone = parseFloat(document.getElementById('stone_weight').value) || 0;
            document.getElementById('net_weight_display').value = Math.max(0, gross - stone).toFixed(3);
        }

        function updateProfit() {
            const cost = parseFloat(document.getElementById('cost_price').value) || 0;
            const selling = parseFloat(document.getElementById('selling_price').value) || 0;
            const profit = selling - cost;
            const marginPct = cost > 0 ? ((profit / cost) * 100) : 0;

            document.getElementById('summaryCost').textContent = '₹' + cost.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summarySelling').textContent = '₹' + selling.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('summaryProfit').textContent = '₹' + profit.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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

        // File input preview
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
                reader.onload = function(ev) {
                    preview.src = ev.target.result;
                    preview.classList.remove('hidden');
                    if (placeholder) placeholder.classList.add('hidden');
                    uploadPreview.src = ev.target.result;
                    uploadWrap.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });

        const removeCheckbox = document.getElementById('removeImage');
        if (removeCheckbox) {
            removeCheckbox.addEventListener('change', function(e) {
                const preview = document.getElementById('imagePreview');
                const placeholder = document.getElementById('imagePlaceholder');
                if (e.target.checked) {
                    preview.classList.add('hidden');
                    if (placeholder) placeholder.classList.remove('hidden');
                } else {
                    if (preview.src) { preview.classList.remove('hidden'); if (placeholder) placeholder.classList.add('hidden'); }
                }
            });
        }
    </script>
</x-app-layout>
