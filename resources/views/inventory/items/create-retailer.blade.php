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
            (string) $category->id => $category->subCategories->pluck('name')->values(),
        ]);
    @endphp

    <x-page-header class="inventory-items-create-header">
        <div>
            <h1 class="page-title">Add New Item</h1>
            <p class="text-sm text-gray-500 mt-1">Create retailer stock using today&apos;s saved metal rates</p>
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

    <div class="content-inner inventory-item-create-dropdowns">
        @if($errors->any())
            <div class="mb-6 px-4 py-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('inventory.items.store') }}" class="space-y-6" id="createItemForm" enctype="multipart/form-data" data-enhance-selects="true" data-enhance-selects-variant="standard">
            @csrf

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 space-y-6">
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

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Metal Type <span class="text-red-500">*</span>
                                </label>
                                <select name="metal_type" id="metal_type" required
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select metal</option>
                                    <option value="gold" @selected(old('metal_type', 'gold') === 'gold')>Gold</option>
                                    <option value="silver" @selected(old('metal_type') === 'silver')>Silver</option>
                                </select>
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Item Image</label>
                                <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                <p class="mt-1 text-xs text-gray-500">Optional. JPG, PNG, GIF, or WEBP up to 5MB.</p>
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

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Weight &amp; Purity</h2>

                        <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Gross Weight (g) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="gross_weight" id="gross_weight" required
                                       value="{{ old('gross_weight') }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.000">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Weight (g)</label>
                                <input type="number" name="stone_weight" id="stone_weight"
                                       value="{{ old('stone_weight', '0') }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.000">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Net Metal Weight (g)</label>
                                <input type="text" id="net_weight_display" readonly
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700"
                                       value="0.000">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Purity Profile <span class="text-red-500">*</span>
                                </label>
                                <select name="purity" id="purity" required data-initial-value="{{ old('purity') }}"
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select purity</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Today&apos;s Rate / g</label>
                                <input type="text" id="resolved_rate_display" readonly
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700"
                                       value="Unavailable">
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-1">Pricing</h2>
                        <p class="text-xs text-gray-500 mb-6">Metal cost is calculated from today&apos;s rates. Enter the applicable charges — the total becomes the selling price / MRP.</p>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Metal Cost (₹)</label>
                                <input type="number" name="cost_price" id="cost_price" readonly
                                       value="{{ old('cost_price') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700"
                                       placeholder="Calculated from today&apos;s rates">
                                <p class="mt-1 text-xs text-gray-500">Net weight × today&apos;s resolved rate. Auto-updates daily.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Making Charges (₹)</label>
                                <input type="number" name="making_charges" id="making_charges"
                                       value="{{ old('making_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Charges (₹)</label>
                                <input type="number" name="stone_charges" id="stone_charges"
                                       value="{{ old('stone_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hallmark Charges (₹)</label>
                                <input type="number" name="hallmark_charges" id="hallmark_charges"
                                       value="{{ old('hallmark_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Rhodium Charges (₹)</label>
                                <input type="number" name="rhodium_charges" id="rhodium_charges"
                                       value="{{ old('rhodium_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Other Charges (₹)</label>
                                <input type="number" name="other_charges" id="other_charges"
                                       value="{{ old('other_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Selling Price / MRP (₹)</label>
                                <input type="number" id="selling_price_display" readonly
                                       step="0.01" min="0"
                                       class="w-full rounded-lg bg-amber-50 border-amber-200 text-amber-800 font-semibold text-base"
                                       placeholder="Sum of all charges above">
                                <input type="hidden" name="selling_price" id="selling_price" value="{{ old('selling_price', '0') }}">
                                <p class="mt-1 text-xs text-gray-500">Auto-calculated: Metal Cost + Making + Stone + Hallmark + Rhodium + Other.</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-6">Vendor &amp; Hallmark</h2>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Vendor / Supplier</label>
                                <select name="vendor_id" class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">— None —</option>
                                    @foreach($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">HUID Number</label>
                                <input type="text" name="huid" value="{{ old('huid') }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., A1B2C3D4E5F6"
                                       maxlength="30">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hallmark Date</label>
                                <input type="date" name="hallmark_date" value="{{ old('hallmark_date') }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                    </div>

                </div>

                <div class="lg:col-span-1">
                    <div class="sticky top-6 space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Summary</h2>

                            <dl class="space-y-3 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Metal</dt>
                                    <dd class="font-medium text-gray-900" id="summaryMetal">—</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Gross / Net</dt>
                                    <dd class="font-medium text-gray-900" id="summaryGross">—</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Purity</dt>
                                    <dd class="font-medium text-gray-900" id="summaryPurity">—</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Rate / g</dt>
                                    <dd class="font-medium text-gray-900" id="summaryRate">—</dd>
                                </div>

                                <hr class="border-gray-200">

                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Metal Cost</dt>
                                    <dd class="font-medium text-gray-900" id="summaryCost">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Making</dt>
                                    <dd class="text-gray-700" id="summaryMaking">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Stone</dt>
                                    <dd class="text-gray-700" id="summaryStone">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Hallmark</dt>
                                    <dd class="text-gray-700" id="summaryHallmark">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Rhodium</dt>
                                    <dd class="text-gray-700" id="summaryRhodium">₹ 0.00</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Other</dt>
                                    <dd class="text-gray-700" id="summaryOther">₹ 0.00</dd>
                                </div>

                                <hr class="border-gray-200">

                                <div class="flex justify-between items-center gap-4">
                                    <dt class="text-gray-600 font-medium">Selling Price / MRP</dt>
                                    <dd class="font-bold text-amber-600 text-base" id="summarySelling">₹ 0.00</dd>
                                </div>
                            </dl>
                        </div>

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
        const purityProfiles = @json($profileOptions);
        const resolvedRates = @json($resolvedRates);
        const categoriesData = @json($categoriesData);
        const initialSubCategory = @json(old('sub_category'));

        function generateBarcode() {
            const now = Date.now().toString().slice(-8);
            const rand = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
            document.getElementById('barcode').value = 'JF-' + now + rand;
        }

        function normalizePurityValue(value) {
            const number = Number.parseFloat(value);
            if (!Number.isFinite(number)) {
                return '';
            }

            return number.toFixed(3).replace(/\.?0+$/, '');
        }

        function formatCurrency(value) {
            return '₹ ' + Number(value || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }

        function refreshCreateDropdown(select) {
            if (window.refreshEnhancedFilterSelect) {
                window.refreshEnhancedFilterSelect(select);
            }
        }

        function populatePurityOptions() {
            const metalType = document.getElementById('metal_type').value;
            const puritySelect = document.getElementById('purity');
            const previousValue = normalizePurityValue(puritySelect.dataset.initialValue || puritySelect.value);
            const profiles = purityProfiles[metalType] || [];

            puritySelect.innerHTML = '<option value="">Select purity</option>';

            profiles.forEach((profile) => {
                const option = document.createElement('option');
                option.value = profile.value;
                option.textContent = profile.label;
                option.selected = profile.value === previousValue;
                puritySelect.appendChild(option);
            });

            puritySelect.dataset.initialValue = '';
            refreshCreateDropdown(puritySelect);
        }

        function handleCategoryChange() {
            const categorySelect = document.getElementById('category');
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const categoryId = selectedOption ? selectedOption.getAttribute('data-category-id') : null;
            const subCategorySelect = document.getElementById('sub_category');
            const currentValue = subCategorySelect.dataset.initialValue || subCategorySelect.value || '';

            subCategorySelect.innerHTML = '<option value="">Select sub category</option>';

            if (categoryId && categoriesData[categoryId]) {
                categoriesData[categoryId].forEach((name) => {
                    const option = document.createElement('option');
                    option.value = name;
                    option.textContent = name;
                    option.selected = name === currentValue;
                    subCategorySelect.appendChild(option);
                });
            }

            subCategorySelect.dataset.initialValue = '';
            refreshCreateDropdown(subCategorySelect);
        }

        function refreshRetailerPricing() {
            const metalType = document.getElementById('metal_type').value;
            const purity = normalizePurityValue(document.getElementById('purity').value);
            const gross = Number.parseFloat(document.getElementById('gross_weight').value) || 0;
            const stoneWt = Number.parseFloat(document.getElementById('stone_weight').value) || 0;
            const making = Number.parseFloat(document.getElementById('making_charges').value) || 0;
            const stoneCharges = Number.parseFloat(document.getElementById('stone_charges').value) || 0;
            const hallmark = Number.parseFloat(document.getElementById('hallmark_charges').value) || 0;
            const rhodium = Number.parseFloat(document.getElementById('rhodium_charges').value) || 0;
            const other = Number.parseFloat(document.getElementById('other_charges').value) || 0;
            const net = Math.max(0, gross - stoneWt);

            const rateEntry = resolvedRates[metalType] ? resolvedRates[metalType][purity] : null;
            const rate = rateEntry ? Number.parseFloat(rateEntry.rate_per_gram) || 0 : 0;
            const purityLabel = rateEntry ? rateEntry.label : (purity ? (metalType === 'gold' ? purity + 'K' : purity) : '—');
            const metalCost = rate > 0 ? (net * rate) : 0;
            const selling = rate > 0 ? (metalCost + making + stoneCharges + hallmark + rhodium + other) : 0;

            document.getElementById('net_weight_display').value = net.toFixed(3);
            document.getElementById('resolved_rate_display').value = rate > 0 ? formatCurrency(rate) : 'Unavailable';
            document.getElementById('cost_price').value = rate > 0 ? metalCost.toFixed(2) : '';
            document.getElementById('selling_price').value = selling.toFixed(2);
            document.getElementById('selling_price_display').value = selling > 0 ? selling.toFixed(2) : '';

            document.getElementById('summaryMetal').textContent = metalType ? metalType.charAt(0).toUpperCase() + metalType.slice(1) : '—';
            document.getElementById('summaryGross').textContent = gross > 0 ? gross.toFixed(3) + 'g / ' + net.toFixed(3) + 'g' : '—';
            document.getElementById('summaryPurity').textContent = purityLabel;
            document.getElementById('summaryRate').textContent = rate > 0 ? formatCurrency(rate) : '—';
            document.getElementById('summaryCost').textContent = formatCurrency(metalCost);
            document.getElementById('summaryMaking').textContent = formatCurrency(making);
            document.getElementById('summaryStone').textContent = formatCurrency(stoneCharges);
            document.getElementById('summaryHallmark').textContent = formatCurrency(hallmark);
            document.getElementById('summaryRhodium').textContent = formatCurrency(rhodium);
            document.getElementById('summaryOther').textContent = formatCurrency(other);
            document.getElementById('summarySelling').textContent = formatCurrency(selling);
        }

        document.getElementById('image')?.addEventListener('change', function (event) {
            const file = event.target.files[0];
            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('imagePreviewPlaceholder');

            if (!file) {
                preview.classList.add('hidden');
                placeholder.classList.remove('hidden');
                return;
            }

            const reader = new FileReader();
            reader.onload = function (loadEvent) {
                preview.src = loadEvent.target.result;
                preview.classList.remove('hidden');
                placeholder.classList.add('hidden');
            };
            reader.readAsDataURL(file);
        });

        document.addEventListener('DOMContentLoaded', function () {
            const watchedIds = [
                'metal_type',
                'purity',
                'gross_weight',
                'stone_weight',
                'making_charges',
                'stone_charges',
                'hallmark_charges',
                'rhodium_charges',
                'other_charges',
            ];

            document.getElementById('metal_type')?.addEventListener('change', function () {
                populatePurityOptions();
                refreshRetailerPricing();
            });

            watchedIds.forEach((id) => {
                document.getElementById(id)?.addEventListener('input', refreshRetailerPricing);
                document.getElementById(id)?.addEventListener('change', refreshRetailerPricing);
            });

            document.getElementById('category')?.addEventListener('change', handleCategoryChange);

            handleCategoryChange();
            populatePurityOptions();
            refreshRetailerPricing();
        });
    </script>
</x-app-layout>
