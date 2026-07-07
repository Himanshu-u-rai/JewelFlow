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

    <x-page-header class="inventory-items-create-header inventory-items-create-header--retailer">
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

    <div class="content-inner inventory-item-create-dropdowns inventory-item-create-page inventory-item-create-page--retailer">
        @if($errors->any())
            <div class="item-create-error-summary">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('inventory.items.store') }}" class="item-create-form" id="createItemForm" enctype="multipart/form-data" data-enhance-selects="true" data-enhance-selects-variant="standard">
            @csrf

            <div class="item-create-layout">
                <div class="item-create-main">
                    <section class="item-create-panel item-create-panel--identity">
                        <div class="item-create-panel-head">
                            <span class="item-create-panel-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 0 1 1-1h2M4 17v2a1 1 0 0 0 1 1h2M17 4h2a1 1 0 0 1 1 1v2M17 20h2a1 1 0 0 0 1-1v-2M7 12h10M7 9h10M7 15h6"/>
                                </svg>
                            </span>
                            <div>
                                <p class="item-create-kicker">Item Identity</p>
                                <h2>Barcode, category, and images</h2>
                            </div>
                        </div>

                        <div class="item-create-grid item-create-grid--identity">
                            <div class="item-create-field item-create-field--barcode">
                                <label class="item-create-label">
                                    Barcode <span class="text-red-500">*</span>
                                </label>
                                <div class="item-create-input-row">
                                    <input type="text" name="barcode" id="barcode" required
                                           value="{{ old('barcode') }}"
                                           class="item-create-input item-create-input--mono"
                                           placeholder="Enter or scan barcode">
                                    <button type="button" onclick="generateBarcode()"
                                            class="item-create-inline-action">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-1"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>Generate
                                    </button>
                                </div>
                            </div>

                            <div class="item-create-field item-create-field--design">
                                <label class="item-create-label">Design / Item Name</label>
                                <input type="text" name="design" id="design"
                                       value="{{ old('design') }}"
                                       class="item-create-input"
                                       placeholder="e.g., Flower Ring, Traditional Necklace">
                            </div>

                            <div class="item-create-field item-create-field--category">
                                <label class="item-create-label">
                                    Category <span class="text-red-500">*</span>
                                </label>
                                <select name="category" id="category" required onchange="handleCategoryChange()"
                                        class="item-create-input">
                                    <option value="">Select Category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->name }}" data-category-id="{{ $cat->id }}" {{ old('category') == $cat->name ? 'selected' : '' }}>
                                            {{ $cat->name }}
                                        </option>
                                    @endforeach
                                    <option value="__new_category__">＋ New category…</option>
                                </select>
                            </div>

                            <div class="item-create-field item-create-field--sub-category">
                                <label class="item-create-label">Sub Category</label>
                                @php
                                    $initialCategory = old('category', '');
                                    $initialSubCategory = old('sub_category', '');
                                    $initialCategoryObj = $categories->firstWhere('name', $initialCategory);
                                    $initialSubCategories = $initialCategoryObj
                                        ? $initialCategoryObj->subCategories->pluck('name')
                                        : collect();
                                @endphp
                                <select name="sub_category" id="sub_category"
                                        data-initial-value="{{ $initialSubCategory }}"
                                        class="item-create-input">
                                    <option value="">Select sub category</option>
                                    @foreach($initialSubCategories as $subName)
                                        <option value="{{ $subName }}" @selected($initialSubCategory === $subName)>{{ $subName }}</option>
                                    @endforeach
                                    @if($initialCategoryObj)
                                        <option value="__new_sub_category__">＋ New sub-category…</option>
                                    @endif
                                </select>
                            </div>

                            <div class="item-create-field item-create-field--metal">
                                <label class="item-create-label">
                                    Metal Type <span class="text-red-500">*</span>
                                </label>
                                @php
                                    $metalLabels = ['gold' => 'Gold', 'silver' => 'Silver', 'platinum' => 'Platinum', 'copper' => 'Copper'];
                                    $pickerMetals = $enabledMetals ?? ['gold', 'silver'];
                                    $defaultMetal = in_array('gold', $pickerMetals, true) ? 'gold' : ($pickerMetals[0] ?? '');
                                @endphp
                                <select name="metal_type" id="metal_type" required
                                        class="item-create-input">
                                    <option value="">Select metal</option>
                                    @foreach($pickerMetals as $metalOption)
                                        <option value="{{ $metalOption }}" @selected(old('metal_type', $defaultMetal) === $metalOption)>
                                            {{ $metalLabels[$metalOption] ?? ucfirst($metalOption) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="item-create-field item-create-field--upload">
                                <label class="item-create-label">Item Images</label>
                                <div class="item-create-upload-layout">
                                    <label for="images" class="item-create-upload-zone">
                                        <input type="file" name="images[]" id="images" accept="image/jpeg,image/png,image/jpg,image/gif,image/webp,image/avif,image/bmp"
                                               multiple
                                               class="sr-only">
                                        <span class="item-create-upload-icon" aria-hidden="true">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0 4 4m-4-4-4 4"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 16.5V19a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-2.5"/>
                                            </svg>
                                        </span>
                                        <span>
                                            <strong>Upload item photos</strong>
                                            <small>Up to 4 images, 5MB each</small>
                                        </span>
                                    </label>
                                    <div class="item-create-upload-preview">
                                        <div id="imagePreviewPlaceholder" class="item-create-image-placeholder">
                                            No images selected
                                        </div>
                                        <div id="imagePreviewGrid" class="hidden item-create-preview-grid"></div>
                                    </div>
                                </div>
                                @error('images')
                                    <p class="item-create-error">{{ $message }}</p>
                                @enderror
                                @error('images.*')
                                    <p class="item-create-error">{{ $message }}</p>
                                @enderror
                                @error('image')
                                    <p class="item-create-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>

                    <section class="item-create-panel item-create-panel--weights">
                        <div class="item-create-panel-head">
                            <span class="item-create-panel-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 7h14M6 7l-3 6h6L6 7Zm12 0-3 6h6l-3-6Z"/>
                                </svg>
                            </span>
                            <div>
                                <p class="item-create-kicker">Weight &amp; Rate</p>
                                <h2>Metal weight and purity</h2>
                            </div>
                        </div>

                        <div class="item-create-grid item-create-grid--weight">
                            <div class="item-create-field">
                                <label class="item-create-label">
                                    Gross Weight (g) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" name="gross_weight" id="gross_weight" required
                                       value="{{ old('gross_weight') }}"
                                       step="0.001" min="0"
                                       oninput="updateNetWeight(); refreshRetailerPricing();"
                                       class="item-create-input"
                                       placeholder="0.000">
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Stone Weight (g)</label>
                                <input type="number" name="stone_weight" id="stone_weight"
                                       value="{{ old('stone_weight', '0') }}"
                                       step="0.001" min="0"
                                       oninput="updateNetWeight(); refreshRetailerPricing();"
                                       class="item-create-input"
                                       placeholder="0.000">
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Net Metal Weight (g)</label>
                                <input type="text" id="net_weight_display" readonly
                                       class="item-create-input item-create-input--readonly"
                                       value="0.000">
                            </div>

                            <div class="item-create-field" id="purity_field_wrap">
                                <label class="item-create-label">
                                    <span id="purity_field_label">Purity Profile</span> <span id="purity_required_star" class="text-red-500">*</span>
                                </label>
                                @php
                                    $initialMetal = old('metal_type', 'gold');
                                    $initialPurity = old('purity', '');
                                    $initialProfiles = $profileOptions[$initialMetal] ?? collect();
                                @endphp
                                <select name="purity" id="purity" required data-initial-value="{{ $initialPurity }}"
                                        class="item-create-input">
                                    <option value="">Select purity</option>
                                    @foreach($initialProfiles as $profile)
                                        <option value="{{ $profile['value'] }}" @selected($initialPurity === $profile['value'])>{{ $profile['label'] }}</option>
                                    @endforeach
                                    @if($initialMetal)
                                        <option value="__custom__">＋ Custom purity…</option>
                                    @endif
                                </select>
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Live Rate / g</label>
                                <input type="text" id="resolved_rate_display" readonly
                                       class="item-create-input item-create-input--readonly item-create-input--money"
                                       value="Select purity">
                            </div>
                        </div>
                    </section>

                    <section class="item-create-panel item-create-panel--pricing">
                        <div class="item-create-panel-head">
                            <span class="item-create-panel-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/>
                                </svg>
                            </span>
                            <div>
                                <p class="item-create-kicker">Pricing</p>
                                <h2>Charges and selling price</h2>
                            </div>
                        </div>

                        <div class="item-create-grid item-create-grid--pricing">
                            <div class="item-create-field">
                                <label class="item-create-label">Metal Cost (₹)</label>
                                <input type="number" name="cost_price" id="cost_price" readonly
                                       value="{{ old('cost_price') }}"
                                       step="0.01" min="0"
                                       class="item-create-input item-create-input--readonly item-create-input--money"
                                       placeholder="Calculated from today&apos;s rates">
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label" id="making_charges_label">Making Charges (₹)</label>
                                @if(config('features.making_charge_modes'))
                                {{-- One cohesive control: mode picker + value side by side. --}}
                                <div class="item-create-split-control">
                                    <select id="making_charge_type" name="making_charge_type"
                                            class="item-create-input">
                                        <option value="fixed" @selected(old('making_charge_type', 'fixed') === 'fixed')>Fixed (₹)</option>
                                        <option value="percentage" @selected(old('making_charge_type') === 'percentage')>% of metal</option>
                                        <option value="per_gram" @selected(old('making_charge_type') === 'per_gram')>₹ / gram</option>
                                    </select>
                                    <input type="number" name="making_charges" id="making_charges"
                                           value="{{ old('making_charges', '0') }}"
                                           step="0.01" min="0"
                                           class="item-create-input"
                                           placeholder="0.00">
                                </div>
                                <input type="hidden" name="making_charge_value" id="making_charge_value" value="{{ old('making_charge_value', old('making_charges', '0')) }}">
                                <p class="item-create-hint" id="making_resolved_hint"></p>
                                @else
                                <input type="number" name="making_charges" id="making_charges"
                                       value="{{ old('making_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="item-create-input"
                                       placeholder="0.00">
                                @endif
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Stone Charges (₹)</label>
                                <input type="number" name="stone_charges" id="stone_charges"
                                       value="{{ old('stone_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="item-create-input"
                                       placeholder="0.00">
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Hallmark Charges (₹)</label>
                                <input type="number" name="hallmark_charges" id="hallmark_charges"
                                       value="{{ old('hallmark_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="item-create-input"
                                       placeholder="0.00">
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Rhodium Charges (₹)</label>
                                <input type="number" name="rhodium_charges" id="rhodium_charges"
                                       value="{{ old('rhodium_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="item-create-input"
                                       placeholder="0.00">
                            </div>

                            <div class="item-create-field">
                                <label class="item-create-label">Other Charges (₹)</label>
                                <input type="number" name="other_charges" id="other_charges"
                                       value="{{ old('other_charges', '0') }}"
                                       step="0.01" min="0"
                                       class="item-create-input"
                                       placeholder="0.00">
                            </div>

                            <div class="item-create-field item-create-field--total">
                                <label class="item-create-label">Selling Price / MRP (₹)</label>
                                <input type="number" id="selling_price_display" readonly
                                       step="0.01" min="0"
                                       class="item-create-input item-create-input--readonly item-create-input--total"
                                       placeholder="Sum of all charges above">
                                <input type="hidden" name="selling_price" id="selling_price" value="{{ old('selling_price', '0') }}">
                            </div>
                        </div>
                    </section>

                    <section class="item-create-panel item-create-panel--supplier">
                        <div class="item-create-panel-head">
                            <span class="item-create-panel-icon" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 11a4 4 0 1 0-8 0M3 21a7 7 0 0 1 18 0M18 8h3m-1.5-1.5v3"/>
                                </svg>
                            </span>
                            <div>
                                <p class="item-create-kicker">Source Details</p>
                                <h2>Vendor and hallmark</h2>
                            </div>
                        </div>

                        <div class="item-create-grid item-create-grid--supplier">
                            <div class="item-create-field">
                                <label class="item-create-label">Vendor / Karigar</label>
                                <select id="supplier_picker" class="item-create-input">
                                    <option value="">— None —</option>
                                    @if($vendors->isNotEmpty())
                                        <optgroup label="Vendors">
                                            @foreach($vendors as $vendor)
                                                <option value="vendor:{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    @if($karigars->isNotEmpty())
                                        <optgroup label="Karigars">
                                            @foreach($karigars as $karigar)
                                                <option value="karigar:{{ $karigar->id }}" {{ old('karigar_id') == $karigar->id ? 'selected' : '' }}>{{ $karigar->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                    <option value="__new_supplier__">＋ New vendor / karigar…</option>
                                </select>
                                <input type="hidden" name="vendor_id" id="vendor_id" value="{{ old('vendor_id') }}">
                                <input type="hidden" name="karigar_id" id="karigar_id" value="{{ old('karigar_id') }}">
                            </div>
                            <div class="item-create-field">
                                <label class="item-create-label">HUID Number</label>
                                <input type="text" name="huid" value="{{ old('huid') }}"
                                       class="item-create-input item-create-input--mono"
                                       placeholder="e.g., A1B2C3D4E5F6"
                                       maxlength="30">
                            </div>
                            <div class="item-create-field">
                                <label class="item-create-label">Hallmark Date</label>
                                <input type="date" name="hallmark_date" value="{{ old('hallmark_date') }}"
                                       class="item-create-input">
                            </div>
                        </div>
                    </section>

                </div>

                <aside class="item-create-aside">
                    <div class="item-create-summary-stack">
                        <div class="item-create-summary-card">
                            <div class="item-create-summary-head">
                                <div>
                                    <p class="item-create-kicker">Live Summary</p>
                                    <h2>Stock preview</h2>
                                </div>
                                <span class="item-create-summary-chip">Draft</span>
                            </div>

                            <dl class="item-create-summary-list">
                                <div>
                                    <dt>Metal</dt>
                                    <dd id="summaryMetal">—</dd>
                                </div>
                                <div>
                                    <dt>Gross / Net</dt>
                                    <dd id="summaryGross">—</dd>
                                </div>
                                <div>
                                    <dt>Purity</dt>
                                    <dd id="summaryPurity">—</dd>
                                </div>
                                <div>
                                    <dt>Rate / g</dt>
                                    <dd id="summaryRate">—</dd>
                                </div>

                                <hr>

                                <div>
                                    <dt>Metal Cost</dt>
                                    <dd id="summaryCost">₹ 0.00</dd>
                                </div>
                                <div>
                                    <dt>Making</dt>
                                    <dd id="summaryMaking">₹ 0.00</dd>
                                </div>
                                <div>
                                    <dt>Stone</dt>
                                    <dd id="summaryStone">₹ 0.00</dd>
                                </div>
                                <div>
                                    <dt>Hallmark</dt>
                                    <dd id="summaryHallmark">₹ 0.00</dd>
                                </div>
                                <div>
                                    <dt>Rhodium</dt>
                                    <dd id="summaryRhodium">₹ 0.00</dd>
                                </div>
                                <div>
                                    <dt>Other</dt>
                                    <dd id="summaryOther">₹ 0.00</dd>
                                </div>

                                <div class="item-create-summary-total">
                                    <dt>Selling Price / MRP</dt>
                                    <dd id="summarySelling">₹ 0.00</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="item-create-action-card">
                            <button type="submit" id="submitBtn"
                                    class="item-create-submit-btn">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                                Add Item to Stock
                            </button>
                            <a href="{{ route('inventory.items.index') }}" class="item-create-cancel-btn">
                                Cancel
                            </a>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>

    {{-- New supplier modal (vendor or karigar) --}}
    <div id="newSupplierModal" style="display:none;" class="item-create-modal fixed inset-0 z-50 flex items-center justify-center p-4" data-supplier-type="vendor">
        <div class="item-create-modal-backdrop absolute inset-0 bg-black/40 backdrop-blur-sm" id="newSupplierBackdrop"></div>
        <div class="item-create-modal-dialog relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="item-create-modal-head flex items-start gap-3">
                <div class="item-create-modal-icon flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h3 class="item-create-modal-title text-base font-semibold text-gray-900">Add New Vendor / Karigar</h3>
                    <p class="item-create-modal-copy text-sm text-gray-500 mt-1">Choose the type, enter a name, and it will be saved to your shop. Full details can be added later.</p>
                </div>
            </div>
            {{-- Type toggle --}}
            <div class="item-create-modal-tabs flex rounded-xl overflow-hidden border border-gray-200 text-sm font-medium">
                <button type="button" id="supplierTabVendor"
                        class="item-create-modal-tab is-active flex-1 px-4 py-2 transition-colors">
                    Vendor
                </button>
                <button type="button" id="supplierTabKarigar"
                        class="item-create-modal-tab flex-1 px-4 py-2 border-l border-gray-200 text-gray-600 transition-colors hover:bg-gray-50">
                    Karigar
                </button>
            </div>
            <div class="item-create-modal-body space-y-3">
                <div class="item-create-modal-field">
                    <label class="item-create-modal-label block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newSupplierInput" maxlength="255"
                           class="item-create-modal-input w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. Mehta Jewellers, Rajesh Traders">
                </div>
                <div id="newSupplierError" class="item-create-modal-error hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>
            <div class="item-create-modal-actions flex gap-3 pt-1">
                <button type="button" id="newSupplierCancel"
                        class="item-create-modal-btn item-create-modal-btn--secondary flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="newSupplierConfirm"
                        class="item-create-modal-btn item-create-modal-btn--primary flex-1 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-colors">
                    Add &amp; Use
                </button>
            </div>
        </div>
    </div>

    {{-- New category modal --}}
    <div id="newCategoryModal" style="display:none;" class="item-create-modal fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="item-create-modal-backdrop absolute inset-0 bg-black/40 backdrop-blur-sm" id="newCategoryBackdrop"></div>
        <div class="item-create-modal-dialog relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="item-create-modal-head flex items-start gap-3">
                <div class="item-create-modal-icon flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h3 class="item-create-modal-title text-base font-semibold text-gray-900">Add New Category</h3>
                    <p class="item-create-modal-copy text-sm text-gray-500 mt-1">This category will be saved to your shop and available for all future items.</p>
                </div>
            </div>
            <div class="item-create-modal-body space-y-3">
                <div class="item-create-modal-field">
                    <label class="item-create-modal-label block text-sm font-medium text-gray-700 mb-1">Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newCategoryInput" maxlength="255"
                           class="item-create-modal-input w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. Necklace, Bangles, Earrings">
                </div>
                <div id="newCategoryError" class="item-create-modal-error hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>
            <div class="item-create-modal-actions flex gap-3 pt-1">
                <button type="button" id="newCategoryCancel"
                        class="item-create-modal-btn item-create-modal-btn--secondary flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="newCategoryConfirm"
                        class="item-create-modal-btn item-create-modal-btn--primary flex-1 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-colors">
                    Add &amp; Use This Category
                </button>
            </div>
        </div>
    </div>

    {{-- New sub-category modal --}}
    <div id="newSubCategoryModal" style="display:none;" class="item-create-modal fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="item-create-modal-backdrop absolute inset-0 bg-black/40 backdrop-blur-sm" id="newSubCategoryBackdrop"></div>
        <div class="item-create-modal-dialog relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="item-create-modal-head flex items-start gap-3">
                <div class="item-create-modal-icon flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                </div>
                <div>
                    <h3 class="item-create-modal-title text-base font-semibold text-gray-900">Add New Sub-Category</h3>
                    <p class="item-create-modal-copy text-sm text-gray-500 mt-1">This sub-category will be added under the selected category.</p>
                </div>
            </div>
            <div class="item-create-modal-body space-y-3">
                <div class="item-create-modal-field">
                    <label class="item-create-modal-label block text-sm font-medium text-gray-700 mb-1">Parent Category</label>
                    <input type="text" id="newSubCategoryParentDisplay" readonly
                           class="item-create-modal-input item-create-modal-input--readonly w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700 text-sm px-3 py-2">
                </div>
                <div class="item-create-modal-field">
                    <label class="item-create-modal-label block text-sm font-medium text-gray-700 mb-1">Sub-Category Name <span class="text-red-500">*</span></label>
                    <input type="text" id="newSubCategoryInput" maxlength="255"
                           class="item-create-modal-input w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. Studs, Jhumkas, Hoops">
                </div>
                <div id="newSubCategoryError" class="item-create-modal-error hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>
            <div class="item-create-modal-actions flex gap-3 pt-1">
                <button type="button" id="newSubCategoryCancel"
                        class="item-create-modal-btn item-create-modal-btn--secondary flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="newSubCategoryConfirm"
                        class="item-create-modal-btn item-create-modal-btn--primary flex-1 px-4 py-2 rounded-xl text-white text-sm font-semibold transition-colors">
                    Add &amp; Use This Sub-Category
                </button>
            </div>
        </div>
    </div>

    {{-- Custom purity modal --}}
    <div id="customPurityModal" style="display:none;" class="item-create-modal fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="item-create-modal-backdrop absolute inset-0 bg-black/40 backdrop-blur-sm" id="customPurityBackdrop"></div>
        <div class="item-create-modal-dialog relative bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto p-6 space-y-4">
            <div class="item-create-modal-head flex items-start gap-3">
                <div class="item-create-modal-icon flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="item-create-modal-title text-base font-semibold text-gray-900">Add New Purity</h3>
                    <p class="item-create-modal-copy text-sm text-gray-500 mt-1">This purity is not in your pricing table. Adding it will create a new profile and auto-calculate today's rate from your saved base rates.</p>
                </div>
            </div>

            <div class="item-create-modal-note bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
                New purity profiles added here will appear in <strong>Settings → Pricing</strong> and will be included in all future daily reprice runs.
            </div>

            <div class="item-create-modal-body space-y-3">
                <div class="item-create-modal-field">
                    <label class="item-create-modal-label block text-sm font-medium text-gray-700 mb-1">Metal Type</label>
                    <input type="text" id="customPurityMetalDisplay" readonly
                           class="item-create-modal-input item-create-modal-input--readonly w-full rounded-lg bg-gray-50 border-gray-300 text-gray-700 text-sm px-3 py-2">
                </div>
                <div class="item-create-modal-field">
                    <label class="item-create-modal-label block text-sm font-medium text-gray-700 mb-1">
                        Purity Value <span class="text-red-500">*</span>
                        <span class="text-xs font-normal text-gray-400" id="customPurityHint"></span>
                    </label>
                    <input type="number" id="customPurityInput" step="0.001" min="0.001"
                           class="item-create-modal-input w-full rounded-lg border-gray-300 focus:ring-amber-500 focus:border-amber-500 text-sm px-3 py-2"
                           placeholder="e.g. 20">
                    <p class="mt-1 text-xs text-gray-400" id="customPuritySubhint"></p>
                </div>
                <div id="customPurityError" class="item-create-modal-error hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></div>
            </div>

            <div class="item-create-modal-actions flex gap-3 pt-1">
                <button type="button" id="customPurityCancel"
                        class="item-create-modal-btn item-create-modal-btn--secondary flex-1 px-4 py-2 rounded-xl border border-gray-300 text-gray-700 text-sm font-medium hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="customPurityConfirm"
                        class="item-create-modal-btn item-create-modal-btn--primary flex-1 px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition-colors">
                    Add &amp; Use This Purity
                </button>
            </div>
        </div>
    </div>

    <script>
        var purityProfiles = @json($profileOptions);
        var resolvedRates = @json($resolvedRates);
        var categoriesData = @json($categoriesData);
        var initialSubCategory = @json(old('sub_category'));

        function updateNetWeight() {
            const gross = Number.parseFloat(document.getElementById('gross_weight').value) || 0;
            const stone = Number.parseFloat(document.getElementById('stone_weight').value) || 0;
            const net = Math.max(0, gross - stone);
            document.getElementById('net_weight_display').value = net.toFixed(3);
            document.getElementById('summaryGross').textContent = gross > 0
                ? gross.toFixed(3) + 'g / ' + net.toFixed(3) + 'g'
                : '—';
        }

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

            // Always append the custom purity option at the bottom
            if (metalType) {
                const customOption = document.createElement('option');
                customOption.value = '__custom__';
                customOption.textContent = '＋ Custom purity…';
                customOption.dataset.isCustom = '1';
                puritySelect.appendChild(customOption);
            }

            puritySelect.dataset.initialValue = '';
            refreshCreateDropdown(puritySelect);
        }

        function handleCategoryChange() {
            const categorySelect = document.getElementById('category');

            // Intercept "new category" sentinel
            if (categorySelect.value === '__new_category__') {
                categorySelect.value = '';
                refreshCreateDropdown(categorySelect);
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
            refreshCreateDropdown(subCategorySelect);
        }

        function refreshRetailerPricing() {
            updateNetWeight();

            const metalType = document.getElementById('metal_type').value;
            const purity = normalizePurityValue(document.getElementById('purity').value);
            const net = Number.parseFloat(document.getElementById('net_weight_display').value) || 0;
            const makingInput = Number.parseFloat(document.getElementById('making_charges').value) || 0;
            const stoneCharges = Number.parseFloat(document.getElementById('stone_charges').value) || 0;
            const hallmark = Number.parseFloat(document.getElementById('hallmark_charges').value) || 0;
            const rhodium = Number.parseFloat(document.getElementById('rhodium_charges').value) || 0;
            const other = Number.parseFloat(document.getElementById('other_charges').value) || 0;

            const rateEntry = resolvedRates[metalType] ? resolvedRates[metalType][purity] : null;
            const rate = rateEntry ? Number.parseFloat(rateEntry.rate_per_gram) || 0 : 0;
            const purityLabel = rateEntry ? rateEntry.label : (purity ? (metalType === 'gold' ? purity + 'K' : purity) : '—');
            const metalCost = rate > 0 ? Math.round(net * rate * 100) / 100 : 0;

            // MC: resolve making per mode (preview only — server re-resolves on save).
            // Percentage = of metal cost; per-gram = of net weight; fixed = the amount.
            const makingType = document.getElementById('making_charge_type')?.value || 'fixed';
            let making;
            if (makingType === 'percentage') {
                making = Math.round(metalCost * (makingInput / 100) * 100) / 100;
            } else if (makingType === 'per_gram') {
                making = Math.round(net * makingInput * 100) / 100;
            } else {
                making = makingInput;
            }
            const mcv = document.getElementById('making_charge_value');
            if (mcv) mcv.value = makingInput;
            const mcLabel = document.getElementById('making_charges_label');
            if (mcLabel) mcLabel.textContent = makingType === 'percentage' ? 'Making (% of metal value)'
                : (makingType === 'per_gram' ? 'Making (₹ per gram, net)' : 'Making Charges (₹)');
            const mcHint = document.getElementById('making_resolved_hint');
            if (mcHint) mcHint.textContent = makingType === 'percentage'
                ? '= ₹' + making.toFixed(2) + ' (' + makingInput + '% of metal value)'
                : (makingType === 'per_gram' ? '= ₹' + making.toFixed(2) + ' (₹' + makingInput + '/g × ' + net.toFixed(3) + 'g)' : '');

            const selling = rate > 0 ? Math.round((metalCost + making + stoneCharges + hallmark + rhodium + other) * 100) / 100 : 0;

            document.getElementById('resolved_rate_display').value = rate > 0 ? formatCurrency(rate) : (purity ? 'Rate unavailable' : 'Select purity');
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
        }

        function initializeRetailerCreatePage() {
            const form = document.getElementById('createItemForm');
            if (!form || form.dataset.retailerCreateBooted === '1') {
                return;
            }
            form.dataset.retailerCreateBooted = '1';

            const imageInput = document.getElementById('images');
            if (imageInput && imageInput.dataset.galleryPreviewBound !== '1') {
                let selectedImageFiles = [];
                let imagePreviewRenderToken = 0;
                const previewGrid = document.getElementById('imagePreviewGrid');
                const placeholder = document.getElementById('imagePreviewPlaceholder');

                const syncImageInputFiles = function () {
                    const dataTransfer = new DataTransfer();
                    selectedImageFiles.forEach(function (file) {
                        dataTransfer.items.add(file);
                    });
                    imageInput.files = dataTransfer.files;
                };

                const renderImagePreviews = function () {
                    const renderToken = ++imagePreviewRenderToken;
                    previewGrid.innerHTML = '';

                    if (!selectedImageFiles.length) {
                        previewGrid.classList.add('hidden');
                        placeholder.classList.remove('hidden');
                        return;
                    }

                    selectedImageFiles.forEach(function (file, index) {
                        const reader = new FileReader();
                        reader.onload = function (loadEvent) {
                            if (renderToken !== imagePreviewRenderToken) {
                                return;
                            }

                            const tile = document.createElement('div');
                            tile.className = 'item-gallery-preview-tile';

                            const image = document.createElement('img');
                            image.src = loadEvent.target.result;
                            image.alt = 'Selected item image ' + (index + 1);

                            const badge = document.createElement('span');
                            badge.textContent = index === 0 ? 'Primary' : 'Image ' + (index + 1);

                            const removeButton = document.createElement('button');
                            removeButton.type = 'button';
                            removeButton.className = 'item-gallery-remove-btn';
                            removeButton.dataset.removeImageIndex = String(index);
                            removeButton.setAttribute('aria-label', 'Remove selected image ' + (index + 1));
                            removeButton.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" d="M18 6 6 18M6 6l12 12"/></svg>';

                            tile.appendChild(image);
                            tile.appendChild(badge);
                            tile.appendChild(removeButton);
                            previewGrid.appendChild(tile);
                        };
                        reader.readAsDataURL(file);
                    });

                    previewGrid.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };

                imageInput.addEventListener('change', function (event) {
                    const MAX_FILES = 4;
                    const MAX_BYTES = 5 * 1024 * 1024; // 5 MB per image — matches the server limit
                    const allFiles = Array.from(event.target.files || []);
                    const tooBig = allFiles.filter(function (f) { return f.size > MAX_BYTES; });
                    if (tooBig.length) {
                        alert('Some images are larger than 5 MB and can\'t be uploaded:\n• '
                            + tooBig.map(function (f) { return f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)'; }).join('\n• ')
                            + '\n\nPlease choose images under 5 MB each.');
                        selectedImageFiles = [];
                        event.target.value = '';
                        renderImagePreviews();
                        return;
                    }
                    if (allFiles.length > MAX_FILES) {
                        alert('You can add up to ' + MAX_FILES + ' images. Only the first ' + MAX_FILES + ' will be used.');
                    }
                    selectedImageFiles = allFiles.slice(0, MAX_FILES);
                    syncImageInputFiles();
                    renderImagePreviews();
                });

                previewGrid.addEventListener('click', function (event) {
                    const removeButton = event.target.closest('[data-remove-image-index]');
                    if (!removeButton) {
                        return;
                    }

                    const removeIndex = Number.parseInt(removeButton.dataset.removeImageIndex, 10);
                    if (!Number.isInteger(removeIndex)) {
                        return;
                    }

                    selectedImageFiles.splice(removeIndex, 1);
                    syncImageInputFiles();
                    renderImagePreviews();
                });
                imageInput.dataset.galleryPreviewBound = '1';
            }

            const watchedIds = [
                'metal_type',
                'purity',
                'gross_weight',
                'stone_weight',
                'making_charge_type',
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
                    refreshCreateDropdown(this);
                    openCustomPurityModal();
                } else {
                    refreshRetailerPricing();
                }
            });

            // Intercept sub-category "new" sentinel
            document.getElementById('sub_category')?.addEventListener('change', function () {
                if (this.value === '__new_sub_category__') {
                    this.value = '';
                    refreshCreateDropdown(this);
                    openNewSubCategoryModal();
                }
            });

            // Supplier picker — sync hidden vendor_id / karigar_id fields
            document.getElementById('supplier_picker')?.addEventListener('change', function () {
                if (this.value === '__new_supplier__') {
                    this.value = '';
                    refreshCreateDropdown(this);
                    openNewSupplierModal();
                    return;
                }
                syncSupplierHiddenFields(this.value);
            });

            watchedIds.forEach((id) => {
                if (id === 'purity') return; // handled above
                document.getElementById(id)?.addEventListener('input', refreshRetailerPricing);
                document.getElementById(id)?.addEventListener('change', refreshRetailerPricing);
            });

            document.getElementById('category')?.addEventListener('change', handleCategoryChange);

            handleCategoryChange();
            populatePurityOptions();
            updateNetWeight();
            refreshRetailerPricing();

            // Restore supplier hidden fields from old() on validation failure
            const supplierPicker = document.getElementById('supplier_picker');
            if (supplierPicker && supplierPicker.value && supplierPicker.value !== '__new_supplier__') {
                syncSupplierHiddenFields(supplierPicker.value);
            }

            // Defer one frame so app.js's enhanced-select widget (which also
            // attaches on DOMContentLoaded) has finished wrapping all selects
            // before we call refresh — prevents dropdowns showing empty
            // without a page reload.
            requestAnimationFrame(() => {
                ['purity', 'sub_category', 'supplier_picker'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) refreshCreateDropdown(el);
                });
            });
        }

        // Run immediately for current render.
        initializeRetailerCreatePage();

        // Keep a single live Turbo handler so stale page closures do not
        // re-initialize this form with old inlined data.
        if (window.__retailerCreateTurboInitHandler) {
            document.removeEventListener('turbo:load', window.__retailerCreateTurboInitHandler);
        }
        window.__retailerCreateTurboInitHandler = initializeRetailerCreatePage;
        document.addEventListener('turbo:load', window.__retailerCreateTurboInitHandler);

        function cleanupRetailerCreatePageBeforeCache() {
            const form = document.getElementById('createItemForm');
            if (!form) {
                return;
            }

            delete form.dataset.retailerCreateBooted;

            const imageInput = document.getElementById('images');
            if (imageInput) {
                delete imageInput.dataset.galleryPreviewBound;
            }

            form.querySelectorAll('.ui-filter-select-host').forEach((host) => {
                const select = host.querySelector('select');
                if (!select || !host.parentNode) {
                    return;
                }

                const cleanSelect = select.cloneNode(true);
                cleanSelect.value = select.value;
                Array.from(cleanSelect.options).forEach((option) => {
                    option.selected = option.value === cleanSelect.value;
                    if (option.selected) {
                        option.setAttribute('selected', 'selected');
                    } else {
                        option.removeAttribute('selected');
                    }
                });
                cleanSelect.classList.remove('ui-filter-native-select');

                host.parentNode.insertBefore(cleanSelect, host);
                host.remove();
            });

            document.querySelectorAll('.item-create-modal').forEach((modal) => {
                modal.style.display = 'none';
            });
        }

        if (window.__retailerCreateBeforeCacheHandler) {
            document.removeEventListener('turbo:before-cache', window.__retailerCreateBeforeCacheHandler);
        }
        window.__retailerCreateBeforeCacheHandler = cleanupRetailerCreatePageBeforeCache;
        document.addEventListener('turbo:before-cache', window.__retailerCreateBeforeCacheHandler);

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
            // Default to vendor tab
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
            vendorTab.classList.toggle('is-active', type === 'vendor');
            karigarTab.classList.toggle('is-active', type === 'karigar');
            if (type === 'vendor') {
                document.getElementById('newSupplierInput').placeholder = 'e.g. Mehta Jewellers, Rajesh Traders';
            } else {
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

                // Find or create the right optgroup
                let group = Array.from(picker.querySelectorAll('optgroup')).find(g =>
                    g.label.toLowerCase() === (type === 'vendor' ? 'vendors' : 'karigars')
                );
                if (!group) {
                    group = document.createElement('optgroup');
                    group.label = type === 'vendor' ? 'Vendors' : 'Karigars';
                    const sentinel = picker.querySelector('option[value="__new_supplier__"]');
                    picker.insertBefore(group, sentinel);
                }
                const sentinel = picker.querySelector('option[value="__new_supplier__"]');
                group.insertBefore(opt, null);
                picker.value = optValue;
                refreshCreateDropdown(picker);
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
                // Insert before the sentinel option
                const sentinel = Array.from(categorySelect.options).find(o => o.value === '__new_category__');
                categorySelect.insertBefore(opt, sentinel);
                categorySelect.value = data.name;
                refreshCreateDropdown(categorySelect);

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
                refreshCreateDropdown(subSelect);

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
                refreshCreateDropdown(puritySelect);

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

    @include('inventory.items._metal_aware_pricing')
</x-app-layout>
