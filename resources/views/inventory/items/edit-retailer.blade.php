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
                        <h2 class="text-lg font-semibold text-gray-900 mb-1">Pricing</h2>
                        <p class="text-xs text-gray-500 mb-4 sm:mb-6">Metal cost is recalculated from today&apos;s rates on save. Adjust charges — the total becomes the selling price / MRP.</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Metal Cost (₹)</label>
                                <input type="number" name="cost_price" id="cost_price" readonly
                                       value="{{ old('cost_price', $item->cost_price) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700">
                                <p class="mt-1 text-xs text-gray-500">Net weight × today&apos;s resolved rate.</p>
                            </div>

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

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Hallmark Charges (₹)</label>
                                <input type="number" name="hallmark_charges" id="hallmark_charges"
                                       value="{{ old('hallmark_charges', $item->hallmark_charges ?? 0) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Rhodium Charges (₹)</label>
                                <input type="number" name="rhodium_charges" id="rhodium_charges"
                                       value="{{ old('rhodium_charges', $item->rhodium_charges ?? 0) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Other Charges (₹)</label>
                                <input type="number" name="other_charges" id="other_charges"
                                       value="{{ old('other_charges', $item->other_charges ?? 0) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500"
                                       placeholder="0.00">
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Selling Price / MRP (₹)</label>
                                <input type="number" id="selling_price_display" readonly
                                       value="{{ old('selling_price', $item->selling_price) }}"
                                       step="0.01" min="0"
                                       class="w-full rounded-lg bg-amber-50 border-amber-200 text-amber-800 font-semibold text-base">
                                <input type="hidden" name="selling_price" id="selling_price" value="{{ old('selling_price', $item->selling_price) }}">
                                <p class="mt-1 text-xs text-gray-500">Auto-calculated: Metal Cost + Making + Stone + Hallmark + Rhodium + Other.</p>
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

                </div>

                <div class="xl:col-span-1">
                    <div class="xl:sticky xl:top-6 space-y-4 sm:space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Price Summary</h2>
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
                                    <dt class="text-gray-500">Rate / g</dt>
                                    <dd class="font-medium text-gray-900" id="summaryRate">—</dd>
                                </div>
                                <hr class="border-gray-200">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Metal Cost</dt>
                                    <dd class="font-medium text-gray-900" id="summaryCost">₹{{ number_format($item->cost_price, 2) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Making</dt>
                                    <dd class="text-gray-700" id="summaryMaking">₹{{ number_format($item->making_charges, 2) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Stone</dt>
                                    <dd class="text-gray-700" id="summaryStone">₹{{ number_format($item->stone_charges, 2) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Hallmark</dt>
                                    <dd class="text-gray-700" id="summaryHallmark">₹{{ number_format($item->hallmark_charges ?? 0, 2) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Rhodium</dt>
                                    <dd class="text-gray-700" id="summaryRhodium">₹{{ number_format($item->rhodium_charges ?? 0, 2) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500">Other</dt>
                                    <dd class="text-gray-700" id="summaryOther">₹{{ number_format($item->other_charges ?? 0, 2) }}</dd>
                                </div>
                                <hr class="border-gray-200">
                                <div class="flex justify-between items-center gap-4">
                                    <dt class="text-gray-600 font-medium">Selling Price / MRP</dt>
                                    <dd class="font-bold text-amber-600 text-base" id="summarySelling">₹{{ number_format($item->selling_price, 2) }}</dd>
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
            document.getElementById('summaryPurity').textContent = purityLabel;
            document.getElementById('summaryRate').textContent = rate > 0 ? formatCurrency(rate) : '—';
            document.getElementById('summaryCost').textContent = formatCurrency(metalCost);
            document.getElementById('summaryMaking').textContent = formatCurrency(making);
            document.getElementById('summaryStone').textContent = formatCurrency(stoneCharges);
            document.getElementById('summaryHallmark').textContent = formatCurrency(hallmark);
            document.getElementById('summaryRhodium').textContent = formatCurrency(rhodium);
            document.getElementById('summaryOther').textContent = formatCurrency(other);
            document.getElementById('summarySelling').textContent = formatCurrency(selling);
            document.getElementById('previewMetalChip').textContent = metalType ? metalType.charAt(0).toUpperCase() + metalType.slice(1) : 'Metal';
            document.getElementById('previewPurityChip').textContent = purityLabel;
            document.getElementById('previewSellingChip').textContent = formatCurrency(selling);
        }

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
                setRemoveImageState(false);
                if (placeholder) {
                    placeholder.classList.add('hidden');
                }
            };
            reader.readAsDataURL(file);
        });

        const removeButton = document.getElementById('removeImageButton');
        if (removeButton) {
            removeButton.addEventListener('click', function () {
                const shouldRemove = removeButton.dataset.removeImageState !== '1';
                setRemoveImageState(shouldRemove);
            });
        }

        function initializeRetailerEditPage() {
            const form = document.querySelector('form[action*="/inventory/items/"]');
            if (!form || form.dataset.retailerEditBooted === '1') {
                return;
            }
            form.dataset.retailerEditBooted = '1';

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
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeRetailerEditPage, { once: true });
        } else {
            initializeRetailerEditPage();
        }

        document.addEventListener('turbo:load', initializeRetailerEditPage);
    </script>
</x-app-layout>
