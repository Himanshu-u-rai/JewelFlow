<x-app-layout>
    <style>
        .reorder-create-page {
            --reorder-create-border: #d8e1ef;
            --reorder-create-border-strong: #c8d5e7;
            --reorder-create-surface: #ffffff;
            --reorder-create-surface-soft: #f7f9fc;
            --reorder-create-text: #16213d;
            --reorder-create-text-soft: #64748b;
            --reorder-create-accent: #0d9488;
            --reorder-create-accent-soft: rgba(13, 148, 136, 0.1);
            --reorder-create-shadow: 0 18px 44px rgba(15, 23, 42, 0.06);
        }

        .reorder-create-page .reorder-create-shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .reorder-create-page .reorder-create-intro,
        .reorder-create-page .reorder-create-card {
            border: 1px solid var(--reorder-create-border);
            border-radius: 24px;
            background: var(--reorder-create-surface);
            box-shadow: var(--reorder-create-shadow);
        }

        .reorder-create-page .reorder-create-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            margin-bottom: 16px;
        }

        .reorder-create-page .reorder-create-kicker {
            margin: 0 0 6px;
            color: var(--reorder-create-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .reorder-create-page .reorder-create-title {
            margin: 0;
            color: var(--reorder-create-text);
            font-size: 24px;
            font-weight: 700;
            line-height: 1.15;
        }

        .reorder-create-page .reorder-create-copy {
            margin: 6px 0 0;
            max-width: 760px;
            color: var(--reorder-create-text-soft);
            font-size: 14px;
            line-height: 1.5;
        }

        .reorder-create-page .reorder-create-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(13, 148, 136, 0.16);
            background: var(--reorder-create-accent-soft);
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .reorder-create-page .reorder-create-errors {
            margin-bottom: 18px;
            border: 1px solid #fecaca;
            border-radius: 18px;
            background: #fef2f2;
            padding: 14px 16px;
            color: #b91c1c;
        }

        .reorder-create-page .reorder-create-errors-title {
            margin: 0 0 6px;
            font-size: 14px;
            font-weight: 700;
        }

        .reorder-create-page .reorder-create-errors ul {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            line-height: 1.6;
        }

        .reorder-create-page .reorder-create-card {
            overflow: visible;
        }

        .reorder-create-page .reorder-create-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            padding: 24px;
        }

        .reorder-create-page .reorder-create-section {
            min-width: 0;
            border: 1px solid #e7edf6;
            border-radius: 22px;
            background: #fbfcfe;
            padding: 22px;
        }

        .reorder-create-page .reorder-create-section-head {
            margin-bottom: 14px;
        }

        .reorder-create-page .reorder-create-section-title {
            margin: 0;
            color: var(--reorder-create-text);
            font-size: 19px;
            font-weight: 700;
        }

        .reorder-create-page .reorder-create-section-copy {
            margin: 6px 0 0;
            color: var(--reorder-create-text-soft);
            font-size: 14px;
            line-height: 1.55;
        }

        .reorder-create-page .reorder-create-grid {
            display: grid;
            gap: 18px;
        }

        .reorder-create-page .reorder-create-grid--two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .reorder-create-page .reorder-create-actions {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: -2px;
        }

        .reorder-create-page .reorder-create-field {
            min-width: 0;
        }

        .reorder-create-page .reorder-create-field--full {
            grid-column: 1 / -1;
        }

        .reorder-create-page .reorder-create-label {
            display: block;
            margin-bottom: 8px;
            color: var(--reorder-create-text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
        }

        .reorder-create-page .reorder-create-required {
            color: #dc2626;
        }

        .reorder-create-page .reorder-create-label-meta {
            color: var(--reorder-create-text-soft);
            font-size: 12px;
            font-weight: 500;
        }

        .reorder-create-page .reorder-create-input,
        .reorder-create-page .reorder-create-select {
            display: block;
            width: 100%;
            min-height: 48px;
            border-radius: 16px;
            border: 1px solid var(--reorder-create-border-strong);
            background: var(--reorder-create-surface-soft);
            color: var(--reorder-create-text);
            padding: 0 14px;
            font-size: 14px;
            line-height: 1.4;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .reorder-create-page .reorder-create-input::placeholder {
            color: #8a99b1;
        }

        .reorder-create-page .reorder-create-input:focus,
        .reorder-create-page .reorder-create-select:focus {
            outline: none;
            border-color: rgba(13, 148, 136, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1);
        }

        .reorder-create-page .reorder-create-input.is-error,
        .reorder-create-page .reorder-create-select.is-error {
            border-color: #ef4444;
            background: #fff;
        }

        .reorder-create-page .ui-filter-select-host,
        .reorder-create-page .ui-filter-select {
            width: 100%;
        }

        .reorder-create-page .ui-filter-select-trigger {
            width: 100%;
            min-height: 48px;
            border-radius: 16px;
            border: 1px solid var(--reorder-create-border-strong);
            background: var(--reorder-create-surface-soft);
            color: var(--reorder-create-text);
            padding: 0 14px;
            font-size: 14px;
            line-height: 1.4;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .reorder-create-page .ui-filter-select-trigger:hover {
            background: #fff;
            border-color: #c2cfdf;
        }

        .reorder-create-page .ui-filter-select-trigger.is-open,
        .reorder-create-page .ui-filter-select-trigger:focus-visible {
            outline: none;
            border-color: rgba(13, 148, 136, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1);
        }

        .reorder-create-page .ui-filter-select-trigger-text {
            font-weight: 500;
        }

        .reorder-create-page .ui-filter-select-chevron {
            color: #70819e;
        }

        .reorder-create-page .ui-filter-select-menu {
            margin-top: 4px;
            border-radius: 16px;
            border-color: #dbe5f0;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
            padding: 6px;
        }

        .reorder-create-page .ui-filter-select-option {
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 13px;
        }

        .reorder-create-page .ui-filter-select-option:hover {
            background: #f8fbff;
        }

        .reorder-create-page .ui-filter-select-option.is-selected {
            background: rgba(13, 148, 136, 0.1);
            color: #0f766e;
        }

        @supports selector(:has(*)) {
            .reorder-create-page .ui-filter-select-host:has(.reorder-create-select.is-error) .ui-filter-select-trigger {
                border-color: #ef4444;
                background: #fff;
            }
        }

        .reorder-create-page .reorder-create-hint {
            margin: 6px 0 0;
            color: var(--reorder-create-text-soft);
            font-size: 12px;
            line-height: 1.5;
        }

        .reorder-create-page .reorder-create-error {
            margin: 6px 0 0;
            color: #dc2626;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.5;
        }

        .reorder-create-page .reorder-create-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 16px;
            border: 1px solid var(--reorder-create-border);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }

        .reorder-create-page .reorder-create-btn:hover {
            transform: translateY(-1px);
        }

        .reorder-create-page .reorder-create-btn--ghost {
            background: #fff;
            color: var(--reorder-create-text);
        }

        .reorder-create-page .reorder-create-btn--ghost:hover {
            background: var(--reorder-create-surface-soft);
        }

        .reorder-create-page .reorder-create-btn--primary {
            border-color: var(--reorder-create-accent);
            background: var(--reorder-create-accent);
            color: #fff;
            box-shadow: 0 12px 24px rgba(13, 148, 136, 0.16);
        }

        .reorder-create-page .reorder-create-btn--primary:hover {
            background: #0f766e;
            border-color: #0f766e;
        }

        @media (max-width: 1100px) {
            .reorder-create-page .reorder-create-shell {
                max-width: 980px;
            }

            .reorder-create-page .reorder-create-form {
                grid-template-columns: 1fr;
            }

            .reorder-create-page .reorder-create-actions {
                grid-column: auto;
            }
        }

        @media (max-width: 767px) {
            .reorder-create-page .reorder-create-intro {
                padding: 16px;
                border-radius: 20px;
                margin-bottom: 14px;
            }

            .reorder-create-page .reorder-create-title {
                font-size: 20px;
            }

            .reorder-create-page .reorder-create-copy {
                display: none;
            }

            .reorder-create-page .reorder-create-pill {
                min-height: 32px;
                padding: 0 10px;
                font-size: 11px;
            }

            .reorder-create-page .reorder-create-form {
                padding: 16px;
                gap: 16px;
            }

            .reorder-create-page .reorder-create-section {
                padding: 16px;
                border-radius: 18px;
            }

            .reorder-create-page .reorder-create-section-title {
                font-size: 17px;
            }

            .reorder-create-page .reorder-create-section-copy {
                font-size: 13px;
            }

            .reorder-create-page .reorder-create-grid--two {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .reorder-create-page .reorder-create-label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            .reorder-create-page .reorder-create-input,
            .reorder-create-page .reorder-create-select,
            .reorder-create-page .ui-filter-select-trigger {
                min-height: 44px;
                border-radius: 14px;
                font-size: 13px;
            }

            .reorder-create-page .ui-filter-select-trigger {
                padding: 0 12px;
            }

            .reorder-create-page .ui-filter-select-menu {
                border-radius: 14px;
                padding: 5px;
            }

            .reorder-create-page .ui-filter-select-option {
                padding: 10px 11px;
                font-size: 12px;
            }

            .reorder-create-page .reorder-create-actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .reorder-create-page .reorder-create-btn {
                width: 100%;
                min-height: 44px;
                border-radius: 14px;
                font-size: 13px;
            }
        }
    </style>

    <x-page-header class="ops-treatment-header">
        <h1 class="page-title">Create Reorder Rule</h1>
        <div class="page-actions">
            <a href="{{ route('reorder.index') }}" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition-colors text-sm font-medium">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Back
            </a>
        </div>
    </x-page-header>

    <div class="content-inner reorder-create-page">
        <div class="reorder-create-shell">
            <section class="reorder-create-intro">
                <div>
                    <p class="reorder-create-kicker">Inventory</p>
                    <h2 class="reorder-create-title">Rule details</h2>
                    <p class="reorder-create-copy">Create a lightweight reorder rule for the stock area you want to monitor, then attach a preferred vendor if you already know where it should be restocked from.</p>
                </div>
                <span class="reorder-create-pill">1 required field</span>
            </section>

            @if ($errors->any())
                <div class="reorder-create-errors">
                    <p class="reorder-create-errors-title">Please review the highlighted fields.</p>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="reorder-create-card">
                <form method="POST"
                      action="{{ route('reorder.store') }}"
                      class="reorder-create-form inventory-item-create-dropdowns"
                      data-enhance-selects="true"
                      data-enhance-selects-variant="standard">
                    @csrf

                    <section class="reorder-create-section">
                        <div class="reorder-create-section-head">
                            <h3 class="reorder-create-section-title">Rule scope</h3>
                            <p class="reorder-create-section-copy">Choose whether the threshold should watch all categories or only a narrower stock segment.</p>
                        </div>

                        <div class="reorder-create-grid reorder-create-grid--two">
                            <div class="reorder-create-field">
                                <label for="category" class="reorder-create-label">Category</label>
                                <select name="category" id="category" class="reorder-create-select {{ $errors->has('category') ? 'is-error' : '' }}">
                                    <option value="">All categories</option>
                                    @foreach ($categories as $cat)
                                        <option value="{{ $cat->name }}" {{ old('category') === $cat->name ? 'selected' : '' }}>{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('category')
                                    <p class="reorder-create-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="reorder-create-field">
                                <label for="sub_category" class="reorder-create-label">
                                    Sub-Category
                                    <span class="reorder-create-label-meta">Optional</span>
                                </label>
                                <select name="sub_category"
                                        id="sub_category"
                                        data-old-sub="{{ old('sub_category') }}"
                                        class="reorder-create-select {{ $errors->has('sub_category') ? 'is-error' : '' }}">
                                    <option value="">All sub-categories</option>
                                </select>
                                <p class="reorder-create-hint">Leave this open if the threshold should cover every sub-category inside the chosen category.</p>
                                @error('sub_category')
                                    <p class="reorder-create-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>

                    <section class="reorder-create-section">
                        <div class="reorder-create-section-head">
                            <h3 class="reorder-create-section-title">Threshold and vendor</h3>
                            <p class="reorder-create-section-copy">Set the alert floor and optionally link the supplier you usually reorder from.</p>
                        </div>

                        <div class="reorder-create-grid reorder-create-grid--two">
                            <div class="reorder-create-field">
                                <label for="min_stock_threshold" class="reorder-create-label">
                                    Minimum Stock Threshold <span class="reorder-create-required">*</span>
                                </label>
                                <input type="number"
                                       name="min_stock_threshold"
                                       id="min_stock_threshold"
                                       value="{{ old('min_stock_threshold', 5) }}"
                                       min="1"
                                       required
                                       class="reorder-create-input {{ $errors->has('min_stock_threshold') ? 'is-error' : '' }}">
                                <p class="reorder-create-hint">An alert appears once the in-stock count falls below this number.</p>
                                @error('min_stock_threshold')
                                    <p class="reorder-create-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="reorder-create-field">
                                <label for="vendor_id" class="reorder-create-label">
                                    Preferred Vendor
                                    <span class="reorder-create-label-meta">Optional</span>
                                </label>
                                <select name="vendor_id" id="vendor_id" class="reorder-create-select {{ $errors->has('vendor_id') ? 'is-error' : '' }}">
                                    <option value="">None</option>
                                    @foreach ($vendors as $vendor)
                                        <option value="{{ $vendor->id }}" {{ old('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                                @error('vendor_id')
                                    <p class="reorder-create-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </section>

                    <div class="reorder-create-actions">
                        <a href="{{ route('reorder.index') }}" class="reorder-create-btn reorder-create-btn--ghost">Cancel</a>
                        <button type="submit" class="reorder-create-btn reorder-create-btn--primary">Create Rule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const init = () => {
                const categorySelect = document.getElementById('category');
                const subCategorySelect = document.getElementById('sub_category');

                if (!categorySelect || !subCategorySelect || categorySelect.dataset.reorderBound === 'true') {
                    return;
                }

                categorySelect.dataset.reorderBound = 'true';

                const subCategoryMap = @json($subCategoryMap);
                const oldSubCategory = (subCategorySelect.dataset.oldSub || '').trim();

                const buildSubCategoryOptions = (preserveOld = false) => {
                    const selectedCategory = (categorySelect.value || '').trim();
                    const options = selectedCategory && subCategoryMap[selectedCategory]
                        ? subCategoryMap[selectedCategory]
                        : [];

                    subCategorySelect.innerHTML = '';

                    const defaultOption = document.createElement('option');
                    defaultOption.value = '';
                    defaultOption.textContent = 'All sub-categories';
                    subCategorySelect.appendChild(defaultOption);

                    options.forEach((name) => {
                        const option = document.createElement('option');
                        option.value = name;
                        option.textContent = name;

                        if (preserveOld && oldSubCategory !== '' && oldSubCategory === name) {
                            option.selected = true;
                        }

                        subCategorySelect.appendChild(option);
                    });

                    subCategorySelect.disabled = false;

                    if (window.refreshEnhancedFilterSelect) {
                        window.refreshEnhancedFilterSelect(subCategorySelect);
                    }
                };

                categorySelect.addEventListener('change', () => buildSubCategoryOptions(false));
                buildSubCategoryOptions(true);
            };

            document.addEventListener('turbo:load', init);

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init, { once: true });
            } else {
                init();
            }
        })();
    </script>
</x-app-layout>
