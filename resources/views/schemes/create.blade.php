<x-app-layout>
    <style>
        .schemes-create-page {
            --schemes-create-border: #d8e1ef;
            --schemes-create-border-strong: #c8d5e7;
            --schemes-create-surface: #ffffff;
            --schemes-create-surface-soft: #f7f9fc;
            --schemes-create-text: #16213d;
            --schemes-create-text-soft: #64748b;
            --schemes-create-accent: #0d9488;
            --schemes-create-accent-soft: rgba(13, 148, 136, 0.1);
            --schemes-create-gold-soft: rgba(245, 158, 11, 0.1);
            --schemes-create-sale-soft: rgba(59, 130, 246, 0.09);
            --schemes-create-shadow: 0 18px 44px rgba(15, 23, 42, 0.06);
        }

        .schemes-create-page [x-cloak] {
            display: none !important;
        }

        .schemes-create-page .schemes-create-shell {
            max-width: 1240px;
            margin: 0 auto;
        }

        .schemes-create-page .schemes-create-intro,
        .schemes-create-page .schemes-create-card {
            border: 1px solid var(--schemes-create-border);
            border-radius: 24px;
            background: var(--schemes-create-surface);
            box-shadow: var(--schemes-create-shadow);
        }

        .schemes-create-page .schemes-create-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            margin-bottom: 16px;
        }

        .schemes-create-page .schemes-create-kicker {
            margin: 0 0 6px;
            color: var(--schemes-create-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .schemes-create-page .schemes-create-title {
            margin: 0;
            color: var(--schemes-create-text);
            font-size: 24px;
            font-weight: 700;
            line-height: 1.15;
        }

        .schemes-create-page .schemes-create-copy {
            margin: 6px 0 0;
            max-width: 760px;
            color: var(--schemes-create-text-soft);
            font-size: 14px;
            line-height: 1.5;
        }

        .schemes-create-page .schemes-create-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(13, 148, 136, 0.16);
            background: var(--schemes-create-accent-soft);
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .schemes-create-page .schemes-create-errors {
            margin-bottom: 18px;
            border: 1px solid #fecaca;
            border-radius: 18px;
            background: #fef2f2;
            padding: 14px 16px;
            color: #b91c1c;
        }

        .schemes-create-page .schemes-create-errors-title {
            margin: 0 0 6px;
            font-size: 14px;
            font-weight: 700;
        }

        .schemes-create-page .schemes-create-errors ul {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            line-height: 1.6;
        }

        .schemes-create-page .schemes-create-card {
            overflow: hidden;
        }

        .schemes-create-page .schemes-create-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            padding: 24px;
        }

        .schemes-create-page .schemes-create-section {
            min-width: 0;
            border: 1px solid #e7edf6;
            border-radius: 22px;
            background: #fbfcfe;
            padding: 22px;
        }

        .schemes-create-page .schemes-create-section--wide,
        .schemes-create-page .schemes-create-actions {
            grid-column: 1 / -1;
        }

        .schemes-create-page .schemes-create-section--gold {
            background: linear-gradient(180deg, #fffaf1 0%, #ffffff 100%);
            border-color: rgba(245, 158, 11, 0.22);
        }

        .schemes-create-page .schemes-create-section--offers {
            background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
            border-color: rgba(59, 130, 246, 0.18);
        }

        .schemes-create-page .schemes-create-section-head {
            margin-bottom: 14px;
        }

        .schemes-create-page .schemes-create-section-title {
            margin: 0;
            color: var(--schemes-create-text);
            font-size: 19px;
            font-weight: 700;
        }

        .schemes-create-page .schemes-create-section-copy {
            margin: 6px 0 0;
            color: var(--schemes-create-text-soft);
            font-size: 14px;
            line-height: 1.55;
        }

        .schemes-create-page .schemes-create-grid {
            display: grid;
            gap: 18px;
        }

        .schemes-create-page .schemes-create-grid--two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .schemes-create-page .schemes-create-grid--three {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .schemes-create-page .schemes-create-field {
            min-width: 0;
        }

        .schemes-create-page .schemes-create-field--full {
            grid-column: 1 / -1;
        }

        .schemes-create-page .schemes-create-label {
            display: block;
            margin-bottom: 8px;
            color: var(--schemes-create-text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
        }

        .schemes-create-page .schemes-create-label-meta {
            color: var(--schemes-create-text-soft);
            font-size: 12px;
            font-weight: 500;
        }

        .schemes-create-page .schemes-create-required {
            color: #dc2626;
        }

        .schemes-create-page .schemes-create-input,
        .schemes-create-page .schemes-create-select,
        .schemes-create-page .schemes-create-textarea {
            display: block;
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--schemes-create-border-strong);
            background: var(--schemes-create-surface-soft);
            color: var(--schemes-create-text);
            font-size: 14px;
            line-height: 1.4;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .schemes-create-page .schemes-create-input,
        .schemes-create-page .schemes-create-select {
            min-height: 48px;
            padding: 0 14px;
        }

        .schemes-create-page .schemes-create-textarea {
            min-height: 112px;
            resize: vertical;
            padding: 12px 14px;
        }

        .schemes-create-page .schemes-create-input::placeholder,
        .schemes-create-page .schemes-create-textarea::placeholder {
            color: #8a99b1;
        }

        .schemes-create-page .schemes-create-input:focus,
        .schemes-create-page .schemes-create-select:focus,
        .schemes-create-page .schemes-create-textarea:focus {
            outline: none;
            border-color: rgba(13, 148, 136, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1);
        }

        .schemes-create-page .schemes-create-input.is-error,
        .schemes-create-page .schemes-create-select.is-error,
        .schemes-create-page .schemes-create-textarea.is-error {
            border-color: #ef4444;
            background: #fff;
        }

        .schemes-create-page .ui-filter-select-host,
        .schemes-create-page .ui-filter-select {
            width: 100%;
        }

        .schemes-create-page .ui-filter-select-trigger {
            width: 100%;
            min-height: 48px;
            border-radius: 16px;
            border: 1px solid var(--schemes-create-border-strong);
            background: var(--schemes-create-surface-soft);
            color: var(--schemes-create-text);
            padding: 0 14px;
            font-size: 14px;
            line-height: 1.4;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .schemes-create-page .ui-filter-select-trigger:hover {
            background: #fff;
            border-color: #c2cfdf;
        }

        .schemes-create-page .ui-filter-select-trigger.is-open,
        .schemes-create-page .ui-filter-select-trigger:focus-visible {
            outline: none;
            border-color: rgba(13, 148, 136, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1);
        }

        .schemes-create-page .ui-filter-select-trigger-text {
            font-weight: 500;
        }

        .schemes-create-page .ui-filter-select-chevron {
            color: #70819e;
        }

        .schemes-create-page .ui-filter-select-menu {
            margin-top: 4px;
            border-radius: 16px;
            border-color: #dbe5f0;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
            padding: 6px;
        }

        .schemes-create-page .ui-filter-select-option {
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 13px;
        }

        .schemes-create-page .ui-filter-select-option:hover {
            background: #f8fbff;
        }

        .schemes-create-page .ui-filter-select-option.is-selected {
            background: rgba(13, 148, 136, 0.1);
            color: #0f766e;
        }

        @supports selector(:has(*)) {
            .schemes-create-page .ui-filter-select-host:has(.schemes-create-select.is-error) .ui-filter-select-trigger {
                border-color: #ef4444;
                background: #fff;
            }
        }

        .schemes-create-page .schemes-create-hint {
            margin: 6px 0 0;
            color: var(--schemes-create-text-soft);
            font-size: 12px;
            line-height: 1.5;
        }

        .schemes-create-page .schemes-create-error {
            margin: 6px 0 0;
            color: #dc2626;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.5;
        }

        .schemes-create-page .schemes-create-toggle-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .schemes-create-page .schemes-create-toggle {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px;
            border: 1px solid #e7edf6;
            border-radius: 18px;
            background: #fff;
            min-width: 0;
        }

        .schemes-create-page .schemes-create-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 1px;
            border-radius: 6px;
            border: 1px solid #b8c6da;
            color: var(--schemes-create-accent);
            box-shadow: none;
            flex-shrink: 0;
        }

        .schemes-create-page .schemes-create-toggle input[type="checkbox"]:focus {
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14);
        }

        .schemes-create-page .schemes-create-toggle-title {
            display: block;
            color: var(--schemes-create-text);
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
        }

        .schemes-create-page .schemes-create-toggle-copy {
            display: block;
            margin-top: 3px;
            color: var(--schemes-create-text-soft);
            font-size: 12px;
            line-height: 1.5;
        }

        .schemes-create-page .schemes-create-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: -2px;
        }

        .schemes-create-page .schemes-create-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 16px;
            border: 1px solid var(--schemes-create-border);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
            white-space: nowrap;
        }

        .schemes-create-page .schemes-create-btn:hover {
            transform: translateY(-1px);
        }

        .schemes-create-page .schemes-create-btn--ghost {
            background: #fff;
            color: var(--schemes-create-text);
        }

        .schemes-create-page .schemes-create-btn--ghost:hover {
            background: var(--schemes-create-surface-soft);
        }

        .schemes-create-page .schemes-create-btn--primary {
            border-color: var(--schemes-create-accent);
            background: var(--schemes-create-accent);
            color: #fff;
            box-shadow: 0 12px 24px rgba(13, 148, 136, 0.16);
        }

        .schemes-create-page .schemes-create-btn--primary:hover {
            background: #0f766e;
            border-color: #0f766e;
        }

        @media (max-width: 1100px) {
            .schemes-create-page .schemes-create-shell {
                max-width: 980px;
            }

            .schemes-create-page .schemes-create-form {
                grid-template-columns: 1fr;
            }

            .schemes-create-page .schemes-create-section,
            .schemes-create-page .schemes-create-section--wide,
            .schemes-create-page .schemes-create-actions {
                grid-column: auto;
            }
        }

        @media (max-width: 767px) {
            .schemes-create-page .schemes-create-intro {
                padding: 16px;
                border-radius: 20px;
                margin-bottom: 14px;
            }

            .schemes-create-page .schemes-create-title {
                font-size: 20px;
            }

            .schemes-create-page .schemes-create-copy {
                display: none;
            }

            .schemes-create-page .schemes-create-pill {
                min-height: 32px;
                padding: 0 10px;
                font-size: 11px;
            }

            .schemes-create-page .schemes-create-form {
                padding: 16px;
                gap: 16px;
            }

            .schemes-create-page .schemes-create-section {
                padding: 16px;
                border-radius: 18px;
            }

            .schemes-create-page .schemes-create-section-title {
                font-size: 17px;
            }

            .schemes-create-page .schemes-create-section-copy {
                font-size: 13px;
            }

            .schemes-create-page .schemes-create-grid--two,
            .schemes-create-page .schemes-create-grid--three,
            .schemes-create-page .schemes-create-toggle-row {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .schemes-create-page .schemes-create-label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            .schemes-create-page .schemes-create-label-meta {
                font-size: 11px;
            }

            .schemes-create-page .schemes-create-input,
            .schemes-create-page .schemes-create-select,
            .schemes-create-page .schemes-create-textarea {
                border-radius: 14px;
                font-size: 13px;
            }

            .schemes-create-page .ui-filter-select-trigger {
                min-height: 44px;
                border-radius: 14px;
                font-size: 13px;
                padding: 0 12px;
            }

            .schemes-create-page .ui-filter-select-menu {
                border-radius: 14px;
                padding: 5px;
            }

            .schemes-create-page .ui-filter-select-option {
                padding: 10px 11px;
                font-size: 12px;
            }

            .schemes-create-page .schemes-create-input,
            .schemes-create-page .schemes-create-select {
                min-height: 44px;
                padding: 0 12px;
            }

            .schemes-create-page .schemes-create-textarea {
                min-height: 96px;
                padding: 10px 12px;
            }

            .schemes-create-page .schemes-create-toggle {
                padding: 12px;
                border-radius: 16px;
            }

            .schemes-create-page .schemes-create-toggle-title {
                font-size: 13px;
            }

            .schemes-create-page .schemes-create-toggle-copy {
                font-size: 11px;
            }

            .schemes-create-page .schemes-create-actions {
                flex-direction: column-reverse;
                align-items: stretch;
                gap: 8px;
                margin-top: 0;
            }

            .schemes-create-page .schemes-create-btn {
                width: 100%;
                min-height: 44px;
                border-radius: 14px;
                font-size: 13px;
            }
        }
    </style>

    <x-page-header class="schemes-create-header ops-treatment-header">
        <h1 class="page-title">Create Scheme</h1>
        <div class="page-actions">
            <a href="{{ route('schemes.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium transition-colors schemes-create-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 19 5 12 12 5" />
                </svg>
                <span class="schemes-create-back-label-full">Back to Schemes</span>
                <span class="schemes-create-back-label-short">Back</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page schemes-create-page">
        <div class="schemes-create-shell">
            <section class="schemes-create-intro">
                <div>
                    <p class="schemes-create-kicker">Catalog</p>
                    <h2 class="schemes-create-title">Scheme Details</h2>
                    <p class="schemes-create-copy">Create a gold savings plan, a festival sale, or a discount offer in a layout that stays easy to manage on both desktop and mobile.</p>
                </div>
                <span class="schemes-create-pill">3 scheme types</span>
            </section>

            @if($errors->any())
                <div class="schemes-create-errors">
                    <p class="schemes-create-errors-title">Please review the highlighted fields.</p>
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="schemes-create-card">
                <form
                    method="POST"
                    action="{{ route('schemes.store') }}"
                    class="schemes-create-form"
                    x-data="{ type: '{{ old('type', 'gold_savings') }}', appliesTo: '{{ old('applies_to', 'all_items') }}' }"
                    data-enhance-selects="true"
                    data-enhance-selects-variant="standard"
                >
                    @csrf

                    <section class="schemes-create-section">
                        <div class="schemes-create-section-head">
                            <h3 class="schemes-create-section-title">Scheme Basics</h3>
                            <p class="schemes-create-section-copy">Define the scheme name, core type, and what customers should understand at a glance.</p>
                        </div>

                        <div class="schemes-create-grid schemes-create-grid--two">
                            <div class="schemes-create-field">
                                <label for="name" class="schemes-create-label">Scheme Name <span class="schemes-create-required">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name') }}" required class="schemes-create-input @error('name') is-error @enderror">
                                @error('name')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="type" class="schemes-create-label">Type <span class="schemes-create-required">*</span></label>
                                <select name="type" id="type" x-model="type" required class="schemes-create-select @error('type') is-error @enderror">
                                    <option value="gold_savings">Gold Savings Scheme</option>
                                    <option value="festival_sale">Festival Sale</option>
                                    <option value="discount_offer">Discount Offer</option>
                                </select>
                                @error('type')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field schemes-create-field--full">
                                <label for="description" class="schemes-create-label">Description</label>
                                <textarea name="description" id="description" rows="3" class="schemes-create-textarea @error('description') is-error @enderror">{{ old('description') }}</textarea>
                                @error('description')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="schemes-create-section">
                        <div class="schemes-create-section-head">
                            <h3 class="schemes-create-section-title">Schedule</h3>
                            <p class="schemes-create-section-copy">Control when the scheme starts and when it should stop being available.</p>
                        </div>

                        <div class="schemes-create-grid schemes-create-grid--two">
                            <div class="schemes-create-field">
                                <label for="start_date" class="schemes-create-label">Start Date <span class="schemes-create-required">*</span></label>
                                <input type="date" name="start_date" id="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required class="schemes-create-input @error('start_date') is-error @enderror">
                                @error('start_date')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="end_date" class="schemes-create-label">End Date <span class="schemes-create-label-meta">(Optional)</span></label>
                                <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}" class="schemes-create-input @error('end_date') is-error @enderror">
                                @error('end_date')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section x-show="type === 'gold_savings'" x-cloak class="schemes-create-section schemes-create-section--wide schemes-create-section--gold">
                        <div class="schemes-create-section-head">
                            <h3 class="schemes-create-section-title">Gold Savings Settings</h3>
                            <p class="schemes-create-section-copy">Configure the installment cycle and optional bonus amount for a savings plan.</p>
                        </div>

                        <div class="schemes-create-grid schemes-create-grid--two">
                            <div class="schemes-create-field">
                                <label for="total_installments" class="schemes-create-label">Total Installments</label>
                                <input type="number" name="total_installments" id="total_installments" value="{{ old('total_installments', 11) }}" min="1" max="36" class="schemes-create-input @error('total_installments') is-error @enderror">
                                <p class="schemes-create-hint">Typically 11 months when the shop covers the final month.</p>
                                @error('total_installments')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="bonus_month_value" class="schemes-create-label">Bonus Amount (₹)</label>
                                <input type="number" name="bonus_month_value" id="bonus_month_value" value="{{ old('bonus_month_value') }}" step="0.01" min="0" class="schemes-create-input @error('bonus_month_value') is-error @enderror">
                                <p class="schemes-create-hint">If left blank, the bonus can match a regular monthly payment.</p>
                                @error('bonus_month_value')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section x-show="type !== 'gold_savings'" x-cloak class="schemes-create-section schemes-create-section--wide schemes-create-section--offers">
                        <div class="schemes-create-section-head">
                            <h3 class="schemes-create-section-title">Discount Settings</h3>
                            <p class="schemes-create-section-copy">Define how the offer is calculated, when it applies, and how often it can be used.</p>
                        </div>

                        <div class="schemes-create-grid schemes-create-grid--three">
                            <div class="schemes-create-field">
                                <label for="discount_type" class="schemes-create-label">Discount Type</label>
                                <select name="discount_type" id="discount_type" class="schemes-create-select @error('discount_type') is-error @enderror">
                                    <option value="">None</option>
                                    <option value="percentage" {{ old('discount_type') === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                                    <option value="flat" {{ old('discount_type') === 'flat' ? 'selected' : '' }}>Flat (₹)</option>
                                </select>
                                @error('discount_type')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="discount_value" class="schemes-create-label">Discount Value</label>
                                <input type="number" name="discount_value" id="discount_value" value="{{ old('discount_value') }}" step="0.01" min="0" class="schemes-create-input @error('discount_value') is-error @enderror">
                                @error('discount_value')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="max_discount_amount" class="schemes-create-label">Max Discount (₹)</label>
                                <input type="number" name="max_discount_amount" id="max_discount_amount" value="{{ old('max_discount_amount') }}" step="0.01" min="0" class="schemes-create-input @error('max_discount_amount') is-error @enderror">
                                @error('max_discount_amount')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="min_purchase_amount" class="schemes-create-label">Min Purchase (₹)</label>
                                <input type="number" name="min_purchase_amount" id="min_purchase_amount" value="{{ old('min_purchase_amount') }}" step="0.01" min="0" class="schemes-create-input @error('min_purchase_amount') is-error @enderror">
                                @error('min_purchase_amount')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="priority" class="schemes-create-label">Priority <span class="schemes-create-label-meta">(Lower = higher)</span></label>
                                <input type="number" name="priority" id="priority" value="{{ old('priority', 100) }}" min="1" max="1000" class="schemes-create-input @error('priority') is-error @enderror">
                                @error('priority')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="max_uses_per_customer" class="schemes-create-label">Max Uses / Customer</label>
                                <input type="number" name="max_uses_per_customer" id="max_uses_per_customer" value="{{ old('max_uses_per_customer') }}" min="1" class="schemes-create-input @error('max_uses_per_customer') is-error @enderror">
                                @error('max_uses_per_customer')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-create-field">
                                <label for="applies_to" class="schemes-create-label">Applies To</label>
                                <select name="applies_to" id="applies_to" x-model="appliesTo" class="schemes-create-select @error('applies_to') is-error @enderror">
                                    <option value="all_items">All items</option>
                                    <option value="category">Specific category</option>
                                    <option value="sub_category">Specific sub-category</option>
                                </select>
                                @error('applies_to')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div x-show="appliesTo !== 'all_items'" x-cloak class="schemes-create-field">
                                <label for="applies_to_value" class="schemes-create-label">Target Value</label>
                                <input type="text" name="applies_to_value" id="applies_to_value" value="{{ old('applies_to_value') }}" placeholder="e.g. Rings" class="schemes-create-input @error('applies_to_value') is-error @enderror">
                                @error('applies_to_value')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="schemes-create-toggle-row">
                            <label class="schemes-create-toggle">
                                <input type="hidden" name="auto_apply" value="0">
                                <input type="checkbox" name="auto_apply" value="1" {{ old('auto_apply') ? 'checked' : '' }}>
                                <span>
                                    <span class="schemes-create-toggle-title">Auto apply when eligible</span>
                                    <span class="schemes-create-toggle-copy">Useful when the offer should activate automatically at checkout.</span>
                                </span>
                            </label>

                            <label class="schemes-create-toggle">
                                <input type="hidden" name="stackable" value="0">
                                <input type="checkbox" name="stackable" value="1" {{ old('stackable') ? 'checked' : '' }}>
                                <span>
                                    <span class="schemes-create-toggle-title">Allow stacking</span>
                                    <span class="schemes-create-toggle-copy">Keep this available if the shop plans to combine offers later.</span>
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="schemes-create-section schemes-create-section--wide">
                        <div class="schemes-create-section-head">
                            <h3 class="schemes-create-section-title">Terms & Conditions</h3>
                            <p class="schemes-create-section-copy">Add the key customer-facing terms or internal conditions for this scheme.</p>
                        </div>

                        <div class="schemes-create-grid">
                            <div class="schemes-create-field">
                                <label for="terms" class="schemes-create-label">Terms</label>
                                <textarea name="terms" id="terms" rows="3" class="schemes-create-textarea @error('terms') is-error @enderror">{{ old('terms') }}</textarea>
                                @error('terms')<p class="schemes-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <div class="schemes-create-actions">
                        <a href="{{ route('schemes.index') }}" class="schemes-create-btn schemes-create-btn--ghost">Cancel</a>
                        <button type="submit" class="schemes-create-btn schemes-create-btn--primary">Create Scheme</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
