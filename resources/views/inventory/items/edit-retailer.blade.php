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

    <x-page-header>
        <div>
            <h1 class="page-title">Edit Item</h1>
            <p class="text-sm text-gray-500 mt-1">Update retailer stock details using today&apos;s pricing rules</p>
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
                <div class="xl:col-span-2 space-y-6">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <div class="grid grid-cols-12 items-start gap-3 sm:flex sm:flex-row sm:gap-6">
                            <div class="flex-shrink-0 flex justify-center sm:justify-start">
                                <div class="relative" id="imageContainer">
                                    @if($item->image)
                                        <img id="imagePreview" src="{{ asset('storage/' . $item->image) }}" alt="{{ $item->design }}"
                                             class="rounded-xl object-cover bg-gray-100 shadow-sm w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36">
                                        <div id="imagePlaceholder" class="rounded-xl bg-gray-100 flex items-center justify-center shadow-sm w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36 hidden">
                                            <span class="text-gray-400 text-xs">No image</span>
                                        </div>
                                    @else
                                        <div id="imagePlaceholder" class="rounded-xl bg-gray-100 flex items-center justify-center shadow-sm w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36">
                                            <span class="text-gray-400 text-xs">No image</span>
                                        </div>
                                        <img id="imagePreview" src="" alt="Item image preview" class="rounded-xl object-cover bg-gray-100 shadow-sm hidden w-28 h-28 sm:w-32 sm:h-32 lg:w-36 lg:h-36">
                                    @endif
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2 mb-3">
                                    <div class="min-w-0">
                                        <h2 class="text-base sm:text-2xl font-bold text-gray-900 truncate">{{ $item->design ?: 'No Design Name' }}</h2>
                                        <p class="text-gray-500 text-xs sm:text-sm truncate">{{ $item->category }}{{ $item->sub_category ? ' / ' . $item->sub_category : '' }}</p>
                                    </div>
                                    <span class="inline-flex items-center px-2 sm:px-3 py-1 rounded-full text-[10px] sm:text-sm font-medium bg-green-100 text-green-800">
                                        In Stock
                                    </span>
                                </div>
                                <div class="font-mono text-sm sm:text-lg font-semibold text-amber-600 bg-amber-50 px-2.5 sm:px-4 py-1 sm:py-2 rounded-lg inline-block mb-3">
                                    {{ $item->barcode }}
                                </div>
                                <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:gap-3 text-xs sm:text-sm">
                                    <span class="bg-slate-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-slate-700 font-semibold" id="previewMetalChip">{{ ucfirst($item->metal_type ?? 'gold') }}</span>
                                    <span class="bg-yellow-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-yellow-700 font-semibold" id="previewPurityChip">{{ $item->purity_label }}</span>
                                    <span class="bg-gray-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-gray-700 font-semibold">{{ number_format($item->gross_weight, 3) }}g</span>
                                    <span class="col-span-2 sm:col-span-1 bg-green-50 px-2.5 sm:px-3 py-1 sm:py-1.5 rounded-lg text-green-700 font-semibold" id="previewSellingChip">₹{{ number_format($item->selling_price, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                <input type="text" name="design" value="{{ old('design', $item->design) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., Flower Ring, Traditional Necklace">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Category <span class="text-red-500">*</span>
                                </label>
                                <select name="category" id="category" required
                                        class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">Select Category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->name }}" data-category-id="{{ $cat->id }}" @selected(old('category', $item->category) == $cat->name)>
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
                                <select name="sub_category" id="sub_category" data-initial-value="{{ old('sub_category', $item->sub_category) }}"
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
                                    <option value="gold" @selected(old('metal_type', $item->metal_type ?? 'gold') === 'gold')>Gold</option>
                                    <option value="silver" @selected(old('metal_type', $item->metal_type) === 'silver')>Silver</option>
                                </select>
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

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Weight &amp; Purity</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Gross Weight (g) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="gross_weight" id="gross_weight" required
                                       value="{{ old('gross_weight', $item->gross_weight) }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Weight (g)</label>
                                <input type="number" name="stone_weight" id="stone_weight"
                                       value="{{ old('stone_weight', $item->stone_weight ?? 0) }}"
                                       step="0.001" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Net Metal (g)</label>
                                <input type="text" id="net_weight_display" readonly
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700"
                                       value="{{ number_format($item->net_metal_weight, 3) }}">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Purity Profile <span class="text-red-500">*</span>
                                </label>
                                <select name="purity" id="purity" required data-initial-value="{{ old('purity', rtrim(rtrim(number_format((float) $item->purity, 3, '.', ''), '0'), '.')) }}"
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

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Pricing</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Cost Price (₹) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="cost_price" id="cost_price" readonly required
                                       value="{{ old('cost_price', $item->cost_price) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700">
                                <p class="mt-1 text-xs text-gray-500">Calculated from today&apos;s saved rates. Manual cost input is ignored for retailer stock.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Selling Price / MRP (₹) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="selling_price" id="selling_price" required
                                       value="{{ old('selling_price', $item->selling_price) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                <p class="mt-1 text-xs text-gray-500">Selling price remains manual.</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Vendor &amp; Hallmark</h2>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Vendor / Supplier</label>
                                <select name="vendor_id" class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">— None —</option>
                                    @foreach($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id', $item->vendor_id) == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">HUID Number</label>
                                <input type="text" name="huid" value="{{ old('huid', $item->huid) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="e.g., A1B2C3D4E5F6"
                                       maxlength="30">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hallmark Date</label>
                                <input type="date" name="hallmark_date" value="{{ old('hallmark_date', $item->hallmark_date?->format('Y-m-d')) }}"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                    </div>

                    <div x-data="{ open: {{ ($item->making_charges > 0 || $item->stone_charges > 0 || old('making_charges') || old('stone_charges')) ? 'true' : 'false' }} }" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                        <button type="button" @click="open = !open" class="flex items-center justify-between w-full text-left">
                            <h2 class="text-lg font-semibold text-gray-900">Cost Breakdown <span class="text-sm font-normal text-gray-400">(optional)</span></h2>
                            <svg :class="open ? 'rotate-180' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <p class="text-xs text-gray-500 mt-1">These charges are added on top of the metal cost resolved for today.</p>

                        <div x-show="open" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 mt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Making Charges (₹)</label>
                                <input type="number" name="making_charges" id="making_charges"
                                       value="{{ old('making_charges', $item->making_charges) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Charges (₹)</label>
                                <input type="number" name="stone_charges" id="stone_charges"
                                       value="{{ old('stone_charges', $item->stone_charges) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-1">
                    <div class="xl:sticky xl:top-6 space-y-4 sm:space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Profit Summary</h2>
                            <dl class="space-y-3 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Metal</dt>
                                    <dd class="font-medium text-gray-900" id="summaryMetal">{{ ucfirst($item->metal_type ?? 'gold') }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Purity</dt>
                                    <dd class="font-medium text-gray-900" id="summaryPurity">{{ $item->purity_label }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Resolved Rate</dt>
                                    <dd class="font-medium text-gray-900" id="summaryRate">—</dd>
                                </div>
                                <hr class="border-gray-200">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Cost Price</dt>
                                    <dd class="font-medium text-gray-900" id="summaryCost">₹{{ number_format($item->cost_price, 2) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Selling Price</dt>
                                    <dd class="font-semibold text-amber-600 text-base" id="summarySelling">₹{{ number_format($item->selling_price, 2) }}</dd>
                                </div>
                                <hr class="border-gray-200">
                                <div class="flex justify-between items-center gap-4">
                                    <dt class="text-gray-500">Profit</dt>
                                    <dd id="summaryProfit" class="font-bold text-green-600">₹{{ number_format(($item->selling_price ?? 0) - $item->cost_price, 2) }}</dd>
                                </div>
                                <div class="flex justify-between items-center gap-4">
                                    <dt class="text-gray-500">Margin %</dt>
                                    <dd id="summaryMarginPct" class="text-green-600 font-medium">
                                        {{ $item->cost_price > 0 ? number_format((($item->selling_price - $item->cost_price) / $item->cost_price) * 100, 1) : 0 }}%
                                    </dd>
                                </div>
                            </dl>
                        </div>

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
        const purityProfiles = @json($profileOptions);
        const resolvedRates = @json($resolvedRates);
        const categoriesData = @json($categoriesData);

        function normalizePurityValue(value) {
            const number = Number.parseFloat(value);
            if (!Number.isFinite(number)) {
                return '';
            }

            return number.toFixed(3).replace(/\.?0+$/, '');
        }

        function formatCurrency(value) {
            return '₹' + Number(value || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
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
        }

        function refreshRetailerPricing() {
            const metalType = document.getElementById('metal_type').value;
            const purity = normalizePurityValue(document.getElementById('purity').value);
            const gross = Number.parseFloat(document.getElementById('gross_weight').value) || 0;
            const stone = Number.parseFloat(document.getElementById('stone_weight').value) || 0;
            const making = Number.parseFloat(document.getElementById('making_charges').value) || 0;
            const stoneCharges = Number.parseFloat(document.getElementById('stone_charges').value) || 0;
            const selling = Number.parseFloat(document.getElementById('selling_price').value) || 0;
            const net = Math.max(0, gross - stone);

            const rateEntry = resolvedRates[metalType] ? resolvedRates[metalType][purity] : null;
            const rate = rateEntry ? Number.parseFloat(rateEntry.rate_per_gram) || 0 : 0;
            const purityLabel = rateEntry ? rateEntry.label : (purity ? (metalType === 'gold' ? purity + 'K' : purity) : '—');
            const cost = rate > 0 ? ((net * rate) + making + stoneCharges) : 0;
            const profit = selling - cost;
            const marginPct = cost > 0 ? ((profit / cost) * 100) : 0;

            document.getElementById('net_weight_display').value = net.toFixed(3);
            document.getElementById('resolved_rate_display').value = rate > 0 ? formatCurrency(rate) : 'Unavailable';
            document.getElementById('cost_price').value = rate > 0 ? cost.toFixed(2) : '';

            document.getElementById('summaryMetal').textContent = metalType ? metalType.charAt(0).toUpperCase() + metalType.slice(1) : '—';
            document.getElementById('summaryPurity').textContent = purityLabel;
            document.getElementById('summaryRate').textContent = rate > 0 ? formatCurrency(rate) : '—';
            document.getElementById('summaryCost').textContent = formatCurrency(cost);
            document.getElementById('summarySelling').textContent = formatCurrency(selling);
            document.getElementById('summaryProfit').textContent = formatCurrency(profit);
            document.getElementById('summaryMarginPct').textContent = marginPct.toFixed(1) + '%';
            document.getElementById('previewMetalChip').textContent = metalType ? metalType.charAt(0).toUpperCase() + metalType.slice(1) : 'Metal';
            document.getElementById('previewPurityChip').textContent = purityLabel;
            document.getElementById('previewSellingChip').textContent = formatCurrency(selling);

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

        document.getElementById('imageFileInput')?.addEventListener('change', function (event) {
            const file = event.target.files[0];
            if (!file) {
                return;
            }

            const preview = document.getElementById('imagePreview');
            const placeholder = document.getElementById('imagePlaceholder');
            const fileName = document.getElementById('selectedFileName');
            const uploadWrap = document.getElementById('uploadPreviewWrap');
            const uploadPreview = document.getElementById('uploadPreview');

            fileName.textContent = 'Selected: ' + file.name;

            const reader = new FileReader();
            reader.onload = function (loadEvent) {
                preview.src = loadEvent.target.result;
                preview.classList.remove('hidden');
                uploadPreview.src = loadEvent.target.result;
                uploadWrap.classList.remove('hidden');
                if (placeholder) {
                    placeholder.classList.add('hidden');
                }
            };
            reader.readAsDataURL(file);
        });

        const removeCheckbox = document.getElementById('removeImage');
        if (removeCheckbox) {
            removeCheckbox.addEventListener('change', function (event) {
                const preview = document.getElementById('imagePreview');
                const placeholder = document.getElementById('imagePlaceholder');

                if (event.target.checked) {
                    preview.classList.add('hidden');
                    if (placeholder) {
                        placeholder.classList.remove('hidden');
                    }
                    return;
                }

                if (preview.src) {
                    preview.classList.remove('hidden');
                    if (placeholder) {
                        placeholder.classList.add('hidden');
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const watchedIds = [
                'metal_type',
                'purity',
                'gross_weight',
                'stone_weight',
                'making_charges',
                'stone_charges',
                'selling_price',
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
