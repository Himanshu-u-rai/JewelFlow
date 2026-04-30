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
                                    <option value="__new_category__">＋ New category…</option>
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
                                       oninput="updateNetWeight(); refreshRetailerPricing();"
                                       class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stone Weight (g)</label>
                                <input type="number" name="stone_weight" id="stone_weight"
                                       value="{{ old('stone_weight', $item->stone_weight ?? 0) }}"
                                       step="0.001" min="0"
                                       oninput="updateNetWeight(); refreshRetailerPricing();"
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
                                <label class="block text-sm font-medium text-gray-700 mb-2">Vendor / Karigar</label>
                                @php
                                    $initialSupplierValue = '';
                                    if (old('karigar_id', $item->karigar_id)) $initialSupplierValue = 'karigar:' . old('karigar_id', $item->karigar_id);
                                    elseif (old('vendor_id', $item->vendor_id)) $initialSupplierValue = 'vendor:' . old('vendor_id', $item->vendor_id);
                                @endphp
                                <select id="supplier_picker" class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500">
                                    <option value="">— None —</option>
                                    @if($vendors->isNotEmpty())
                                        <optgroup label="Vendors">
                                            @foreach($vendors as $vendor)
                                                <option value="vendor:{{ $vendor->id }}" {{ $initialSupplierValue === 'vendor:'.$vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if($karigars->isNotEmpty())
                                        <optgroup label="Karigars">
                                            @foreach($karigars as $karigar)
                                                <option value="karigar:{{ $karigar->id }}" {{ $initialSupplierValue === 'karigar:'.$karigar->id ? 'selected' : '' }}>{{ $karigar->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    <option value="__new_supplier__">＋ New vendor / karigar…</option>
                                </select>
                                <input type="hidden" name="vendor_id" id="vendor_id" value="{{ old('vendor_id', $item->vendor_id) }}">
                                <input type="hidden" name="karigar_id" id="karigar_id" value="{{ old('karigar_id', $item->karigar_id) }}">
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

            if (metalType) {
                const customOption = document.createElement('option');
                customOption.value = '__custom__';
                customOption.textContent = '＋ Custom purity…';
                customOption.dataset.isCustom = '1';
                puritySelect.appendChild(customOption);
            }

            puritySelect.dataset.initialValue = '';
            refreshEditDropdown(puritySelect);
        }

        function handleCategoryChange() {
            const categorySelect = document.getElementById('category');

            // Intercept "new category" sentinel
            if (categorySelect.value === '__new_category__') {
                categorySelect.value = '';
                refreshEditDropdown(categorySelect);
                openNewCategoryModal();
                return;
            }

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

                // Append "new sub-category" option whenever a category is selected
                const newSubOpt = document.createElement('option');
                newSubOpt.value = '__new_sub_category__';
                newSubOpt.textContent = '＋ New sub-category…';
                subCategorySelect.appendChild(newSubOpt);
            }

            subCategorySelect.dataset.initialValue = '';
            refreshEditDropdown(subCategorySelect);
        }

        function updateNetWeight() {
            const gross = Number.parseFloat(document.getElementById('gross_weight').value) || 0;
            const stone = Number.parseFloat(document.getElementById('stone_weight').value) || 0;
            const net = Math.max(0, gross - stone);
            document.getElementById('net_weight_display').value = net.toFixed(3);
            document.getElementById('summaryGross').textContent = gross > 0
                ? gross.toFixed(3) + 'g / ' + net.toFixed(3) + 'g'
                : '—';
        }

        function refreshRetailerPricing() {
            updateNetWeight();

            const metalType = document.getElementById('metal_type').value;
            const purity = normalizePurityValue(document.getElementById('purity').value);
            const net = Number.parseFloat(document.getElementById('net_weight_display').value) || 0;
            const making = Number.parseFloat(document.getElementById('making_charges').value) || 0;
            const stoneCharges = Number.parseFloat(document.getElementById('stone_charges').value) || 0;
            const hallmark = Number.parseFloat(document.getElementById('hallmark_charges').value) || 0;
            const rhodium = Number.parseFloat(document.getElementById('rhodium_charges').value) || 0;
            const other = Number.parseFloat(document.getElementById('other_charges').value) || 0;

            const rateEntry = resolvedRates[metalType] ? resolvedRates[metalType][purity] : null;
            const rate = rateEntry ? Number.parseFloat(rateEntry.rate_per_gram) || 0 : 0;
            const purityLabel = rateEntry ? rateEntry.label : (purity ? (metalType === 'gold' ? purity + 'K' : purity) : '—');
            const metalCost = rate > 0 ? Math.round(net * rate * 100) / 100 : 0;
            const selling = rate > 0 ? Math.round((metalCost + making + stoneCharges + hallmark + rhodium + other) * 100) / 100 : 0;

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

        function refreshEditDropdown(select) {
            if (window.refreshEnhancedFilterSelect) {
                window.refreshEnhancedFilterSelect(select);
            }
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

            // Intercept purity selection — open modal if custom is chosen
            document.getElementById('purity')?.addEventListener('change', function () {
                if (this.value === '__custom__') {
                    this.value = '';
                    refreshEditDropdown(this);
                    openCustomPurityModal();
                } else {
                    refreshRetailerPricing();
                }
            });

            watchedIds.forEach((id) => {
                if (id === 'purity') return; // handled above
                document.getElementById(id)?.addEventListener('input', refreshRetailerPricing);
                document.getElementById(id)?.addEventListener('change', refreshRetailerPricing);
            });

            document.getElementById('category')?.addEventListener('change', handleCategoryChange);

            document.getElementById('sub_category')?.addEventListener('change', function () {
                if (this.value === '__new_sub_category__') {
                    this.value = '';
                    refreshEditDropdown(this);
                    openNewSubCategoryModal();
                }
            });

            document.getElementById('supplier_picker')?.addEventListener('change', function () {
                if (this.value === '__new_supplier__') {
                    this.value = '';
                    refreshEditDropdown(this);
                    openNewSupplierModal();
                    return;
                }
                syncSupplierHiddenFields(this.value);
            });

            handleCategoryChange();
            populatePurityOptions();
            updateNetWeight();
            refreshRetailerPricing();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeRetailerEditPage, { once: true });
        } else {
            initializeRetailerEditPage();
        }

        document.addEventListener('turbo:load', initializeRetailerEditPage);

        // ── Supplier picker helpers ────────────────────────────────────────────

        function syncSupplierHiddenFields(value) {
            const vendorInput = document.getElementById('vendor_id');
            const karigarInput = document.getElementById('karigar_id');
            if (value.startsWith('vendor:')) {
                vendorInput.value = value.slice(7);
                karigarInput.value = '';
            } else if (value.startsWith('karigar:')) {
                karigarInput.value = value.slice(8);
                vendorInput.value = '';
            } else {
                vendorInput.value = '';
                karigarInput.value = '';
            }
        }

        // ── New supplier modal ─────────────────────────────────────────────────

        function openNewSupplierModal() {
            document.getElementById('newSupplierInput').value = '';
            document.getElementById('newSupplierError').classList.add('hidden');
            document.getElementById('newSupplierError').textContent = '';
            document.getElementById('newSupplierConfirm').disabled = false;
            document.getElementById('newSupplierConfirm').textContent = 'Add & Use';
            selectSupplierType('vendor');
            document.getElementById('newSupplierModal').style.display = 'flex';
            document.getElementById('newSupplierInput').focus();
        }

        function closeNewSupplierModal() {
            document.getElementById('newSupplierModal').style.display = 'none';
        }

        function selectSupplierType(type) {
            document.getElementById('newSupplierModal').dataset.supplierType = type;
            const vendorTab = document.getElementById('supplierTabVendor');
            const karigarTab = document.getElementById('supplierTabKarigar');
            if (type === 'vendor') {
                vendorTab.style.background = '#0d9488'; vendorTab.style.color = '#fff';
                karigarTab.style.background = ''; karigarTab.style.color = '#4b5563';
                document.getElementById('newSupplierInput').placeholder = 'e.g. Mehta Jewellers, Rajesh Traders';
            } else {
                karigarTab.style.background = '#0d9488'; karigarTab.style.color = '#fff';
                vendorTab.style.background = ''; vendorTab.style.color = '#4b5563';
                document.getElementById('newSupplierInput').placeholder = 'e.g. Ramesh Kumar, Krishna Ornaments';
            }
        }

        document.getElementById('supplierTabVendor')?.addEventListener('click', () => selectSupplierType('vendor'));
        document.getElementById('supplierTabKarigar')?.addEventListener('click', () => selectSupplierType('karigar'));
        document.getElementById('newSupplierBackdrop')?.addEventListener('click', closeNewSupplierModal);
        document.getElementById('newSupplierCancel')?.addEventListener('click', closeNewSupplierModal);

        document.getElementById('newSupplierInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('newSupplierConfirm').click(); }
            if (e.key === 'Escape') closeNewSupplierModal();
        });

        document.getElementById('newSupplierConfirm')?.addEventListener('click', function () {
            const type = document.getElementById('newSupplierModal').dataset.supplierType || 'vendor';
            const input = document.getElementById('newSupplierInput');
            const errorEl = document.getElementById('newSupplierError');
            const name = input.value.trim();

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!name) {
                errorEl.textContent = 'Please enter a name.';
                errorEl.classList.remove('hidden');
                input.focus();
                return;
            }

            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            const url = type === 'vendor'
                ? '{{ route('vendors.store') }}'
                : '{{ route('inventory.items.quick-add-karigar') }}';

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ name }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.errors?.name?.[0] || data.error || 'Failed to add. Please try again.';
                    errorEl.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Add & Use';
                    return;
                }

                const picker = document.getElementById('supplier_picker');
                const optValue = type + ':' + data.id;
                const opt = document.createElement('option');
                opt.value = optValue;
                opt.textContent = data.name;

                let group = Array.from(picker.querySelectorAll('optgroup')).find(g =>
                    g.label.toLowerCase() === (type === 'vendor' ? 'vendors' : 'karigars')
                );
                if (!group) {
                    group = document.createElement('optgroup');
                    group.label = type === 'vendor' ? 'Vendors' : 'Karigars';
                    const sentinel = picker.querySelector('option[value="__new_supplier__"]');
                    picker.insertBefore(group, sentinel);
                }
                group.appendChild(opt);
                picker.value = optValue;
                refreshEditDropdown(picker);
                syncSupplierHiddenFields(optValue);

                closeNewSupplierModal();
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'Add & Use';
            });
        });

        // ── New category modal ─────────────────────────────────────────────────

        function openNewCategoryModal() {
            document.getElementById('newCategoryInput').value = '';
            document.getElementById('newCategoryError').classList.add('hidden');
            document.getElementById('newCategoryError').textContent = '';
            document.getElementById('newCategoryConfirm').disabled = false;
            document.getElementById('newCategoryConfirm').textContent = 'Add & Use This Category';
            document.getElementById('newCategoryModal').style.display = 'flex';
            document.getElementById('newCategoryInput').focus();
        }

        function closeNewCategoryModal() {
            document.getElementById('newCategoryModal').style.display = 'none';
        }

        document.getElementById('newCategoryBackdrop')?.addEventListener('click', closeNewCategoryModal);
        document.getElementById('newCategoryCancel')?.addEventListener('click', closeNewCategoryModal);

        document.getElementById('newCategoryInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('newCategoryConfirm').click(); }
            if (e.key === 'Escape') closeNewCategoryModal();
        });

        document.getElementById('newCategoryConfirm')?.addEventListener('click', function () {
            const input = document.getElementById('newCategoryInput');
            const errorEl = document.getElementById('newCategoryError');
            const name = input.value.trim();

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!name) {
                errorEl.textContent = 'Please enter a category name.';
                errorEl.classList.remove('hidden');
                input.focus();
                return;
            }

            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            fetch('{{ route('inventory.items.quick-add-category') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ name }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.errors?.name?.[0] || data.error || 'Failed to add category.';
                    errorEl.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Add & Use This Category';
                    return;
                }

                // Inject into JS data map
                categoriesData[String(data.id)] = [];

                // Append option to category select and select it
                const categorySelect = document.getElementById('category');
                const opt = document.createElement('option');
                opt.value = data.name;
                opt.textContent = data.name;
                opt.setAttribute('data-category-id', String(data.id));
                const sentinel = Array.from(categorySelect.options).find(o => o.value === '__new_category__');
                categorySelect.insertBefore(opt, sentinel);
                categorySelect.value = data.name;
                refreshEditDropdown(categorySelect);

                closeNewCategoryModal();
                handleCategoryChange();
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'Add & Use This Category';
            });
        });

        // ── New sub-category modal ──────────────────────────────────────────────

        function openNewSubCategoryModal() {
            const categorySelect = document.getElementById('category');
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const categoryName = selectedOption?.textContent?.trim() || '';
            document.getElementById('newSubCategoryParentDisplay').value = categoryName || '(none selected)';
            document.getElementById('newSubCategoryInput').value = '';
            document.getElementById('newSubCategoryError').classList.add('hidden');
            document.getElementById('newSubCategoryError').textContent = '';
            document.getElementById('newSubCategoryConfirm').disabled = false;
            document.getElementById('newSubCategoryConfirm').textContent = 'Add & Use This Sub-Category';
            document.getElementById('newSubCategoryModal').style.display = 'flex';
            document.getElementById('newSubCategoryInput').focus();
        }

        function closeNewSubCategoryModal() {
            document.getElementById('newSubCategoryModal').style.display = 'none';
        }

        document.getElementById('newSubCategoryBackdrop')?.addEventListener('click', closeNewSubCategoryModal);
        document.getElementById('newSubCategoryCancel')?.addEventListener('click', closeNewSubCategoryModal);

        document.getElementById('newSubCategoryInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('newSubCategoryConfirm').click(); }
            if (e.key === 'Escape') closeNewSubCategoryModal();
        });

        document.getElementById('newSubCategoryConfirm')?.addEventListener('click', function () {
            const categorySelect = document.getElementById('category');
            const selectedOption = categorySelect.options[categorySelect.selectedIndex];
            const categoryId = selectedOption?.getAttribute('data-category-id');
            const input = document.getElementById('newSubCategoryInput');
            const errorEl = document.getElementById('newSubCategoryError');
            const name = input.value.trim();

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!categoryId) {
                errorEl.textContent = 'Please select a category first.';
                errorEl.classList.remove('hidden');
                return;
            }
            if (!name) {
                errorEl.textContent = 'Please enter a sub-category name.';
                errorEl.classList.remove('hidden');
                input.focus();
                return;
            }

            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Adding…';

            fetch('{{ route('inventory.items.quick-add-sub-category') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ category_id: Number(categoryId), name }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.errors?.name?.[0] || data.error || 'Failed to add sub-category.';
                    errorEl.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Add & Use This Sub-Category';
                    return;
                }

                // Inject into JS data map
                if (!categoriesData[categoryId]) categoriesData[categoryId] = [];
                if (!categoriesData[categoryId].includes(data.name)) {
                    categoriesData[categoryId].push(data.name);
                }

                // Rebuild sub-category dropdown and select the new entry
                const subSelect = document.getElementById('sub_category');
                subSelect.dataset.initialValue = data.name;
                handleCategoryChange();
                subSelect.value = data.name;
                refreshEditDropdown(subSelect);

                closeNewSubCategoryModal();
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'Add & Use This Sub-Category';
            });
        });

        // ── Custom purity modal ────────────────────────────────────────────────

        function openCustomPurityModal() {
            const metalType = document.getElementById('metal_type').value;
            const isGold = metalType === 'gold';

            document.getElementById('customPurityMetalDisplay').value =
                metalType ? metalType.charAt(0).toUpperCase() + metalType.slice(1) : '';
            document.getElementById('customPurityHint').textContent =
                isGold ? '(0.001 – 24)' : '(0.001 – 1000)';
            document.getElementById('customPuritySubhint').textContent =
                isGold
                    ? 'Enter karat value, e.g. 20 for 20K or 916 for BIS hallmark.'
                    : 'Enter millesimal value, e.g. 958 for Britannia silver.';
            document.getElementById('customPurityInput').value = '';
            document.getElementById('customPurityInput').max = isGold ? 24 : 1000;
            document.getElementById('customPurityError').classList.add('hidden');
            document.getElementById('customPurityError').textContent = '';
            document.getElementById('customPurityModal').style.display = 'flex';
            document.getElementById('customPurityInput').focus();
        }

        function closeCustomPurityModal() {
            document.getElementById('customPurityModal').style.display = 'none';
        }

        document.getElementById('customPurityBackdrop')?.addEventListener('click', closeCustomPurityModal);
        document.getElementById('customPurityCancel')?.addEventListener('click', closeCustomPurityModal);

        document.getElementById('customPurityInput')?.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('customPurityConfirm').click();
            }
            if (e.key === 'Escape') closeCustomPurityModal();
        });

        document.getElementById('customPurityConfirm')?.addEventListener('click', function () {
            const metalType = document.getElementById('metal_type').value;
            const purityInput = document.getElementById('customPurityInput');
            const errorEl = document.getElementById('customPurityError');
            const purityValue = Number.parseFloat(purityInput.value);

            errorEl.classList.add('hidden');
            errorEl.textContent = '';

            if (!Number.isFinite(purityValue) || purityValue <= 0) {
                errorEl.textContent = 'Please enter a valid purity value greater than zero.';
                errorEl.classList.remove('hidden');
                purityInput.focus();
                return;
            }
            if (metalType === 'gold' && purityValue > 24) {
                errorEl.textContent = 'Gold purity cannot exceed 24K.';
                errorEl.classList.remove('hidden');
                purityInput.focus();
                return;
            }
            if (metalType === 'silver' && purityValue > 1000) {
                errorEl.textContent = 'Silver purity cannot exceed 1000.';
                errorEl.classList.remove('hidden');
                purityInput.focus();
                return;
            }

            const btn = document.getElementById('customPurityConfirm');
            btn.disabled = true;
            btn.textContent = 'Adding…';

            fetch('{{ route('inventory.items.quick-add-purity') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ metal_type: metalType, purity_value: purityValue }),
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    errorEl.textContent = data.error || 'Failed to add purity. Please try again.';
                    errorEl.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'Add & Use This Purity';
                    return;
                }

                // Inject into the local profiles map so future metal-type switches keep it
                if (!purityProfiles[metalType]) purityProfiles[metalType] = [];
                const alreadyExists = purityProfiles[metalType].some(p => p.value === data.value);
                if (!alreadyExists) {
                    purityProfiles[metalType].push({ value: data.value, label: data.label });
                }

                // Inject into the resolved rates map
                if (!resolvedRates[metalType]) resolvedRates[metalType] = {};
                resolvedRates[metalType][data.value] = {
                    label: data.label,
                    rate_per_gram: data.rate_per_gram,
                };

                // Rebuild the dropdown and select the new purity
                const puritySelect = document.getElementById('purity');
                puritySelect.dataset.initialValue = data.value;
                populatePurityOptions();
                puritySelect.value = data.value;
                refreshEditDropdown(puritySelect);

                closeCustomPurityModal();
                refreshRetailerPricing();
            })
            .catch(() => {
                errorEl.textContent = 'Network error. Please check your connection and try again.';
                errorEl.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'Add & Use This Purity';
            });
        });
    </script>

    {{-- New supplier modal (vendor or karigar) --}}
    <div id="newSupplierModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-supplier-type="vendor">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" id="newSupplierBackdrop"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add New Vendor / Karigar</h3>
                    <p class="text-sm text-gray-500 mt-1">Choose the type, enter a name, and it will be saved to your shop. Full details can be added later.</p>
                </div>
            </div>
            <div class="flex rounded-xl overflow-hidden border border-gray-200 text-sm font-medium">
                <button type="button" id="supplierTabVendor"
                        class="flex-1 px-4 py-2 transition-colors"
                        style="background:#0d9488; color:#fff;">
                    Vendor
                </button>
                <button type="button" id="supplierTabKarigar"
                        class="flex-1 px-4 py-2 border-l border-gray-200 transition-colors hover:bg-gray-50"
                        style="color:#4b5563;">
                    Karigar
                </button>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newSupplierInput" maxlength="255"
                           class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. Mehta Jewellers, Rajesh Traders">
                </div>
                <div id="newSupplierError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" id="newSupplierCancel"
                        class="flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="newSupplierConfirm"
                        class="flex-1 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-colors"
                        style="background:#0d9488;" onmouseover="this.style.background='#0f766e'" onmouseout="this.style.background='#0d9488'">
                    Add &amp; Use
                </button>
            </div>
        </div>
    </div>

    {{-- New category modal --}}
    <div id="newCategoryModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" id="newCategoryBackdrop"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add New Category</h3>
                    <p class="text-sm text-gray-500 mt-1">This category will be saved to your shop and available for all future items.</p>
                </div>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newCategoryInput" maxlength="255"
                           class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. Necklace, Bangles, Earrings">
                </div>
                <div id="newCategoryError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" id="newCategoryCancel"
                        class="flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="newCategoryConfirm"
                        class="flex-1 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-colors" style="background:#0d9488;" onmouseover="this.style.background='#0f766e'" onmouseout="this.style.background='#0d9488'">
                    Add &amp; Use This Category
                </button>
            </div>
        </div>
    </div>

    {{-- New sub-category modal --}}
    <div id="newSubCategoryModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" id="newSubCategoryBackdrop"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add New Sub-Category</h3>
                    <p class="text-sm text-gray-500 mt-1">This sub-category will be added under the selected category.</p>
                </div>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Parent Category</label>
                    <input type="text" id="newSubCategoryParentDisplay" readonly
                           class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700 text-sm px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sub-Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newSubCategoryInput" maxlength="255"
                           class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. Studs, Jhumkas, Hoops">
                </div>
                <div id="newSubCategoryError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>
            <div class="flex gap-3 pt-1">
                <button type="button" id="newSubCategoryCancel"
                        class="flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="newSubCategoryConfirm"
                        class="flex-1 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-colors" style="background:#0d9488;" onmouseover="this.style.background='#0f766e'" onmouseout="this.style.background='#0d9488'">
                    Add &amp; Use This Sub-Category
                </button>
            </div>
        </div>
    </div>

    {{-- Custom purity modal --}}
    <div id="customPurityModal" style="display:none;" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" id="customPurityBackdrop"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Add New Purity</h3>
                    <p class="text-sm text-gray-500 mt-1">This purity is not in your pricing table. Adding it will create a new profile and auto-calculate today's rate from your saved base rates.</p>
                </div>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
                New purity profiles added here will appear in <strong>Settings → Pricing</strong> and will be included in all future daily reprice runs.
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Metal Type</label>
                    <input type="text" id="customPurityMetalDisplay" readonly
                           class="w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700 text-sm px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Purity Value <span class="text-red-500">*</span>
                        <span class="text-xs font-normal text-gray-400" id="customPurityHint"></span>
                    </label>
                    <input type="number" id="customPurityInput" step="0.001" min="0.001"
                           class="w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. 20">
                    <p class="mt-1 text-xs text-gray-400" id="customPuritySubhint"></p>
                </div>
                <div id="customPurityError" class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>

            <div class="flex gap-3 pt-1">
                <button type="button" id="customPurityCancel"
                        class="flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="customPurityConfirm"
                        class="flex-1 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition-colors">
                    Add &amp; Use This Purity
                </button>
            </div>
        </div>
    </div>
</x-app-layout>
