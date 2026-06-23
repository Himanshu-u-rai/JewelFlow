<x-app-layout>
    <x-page-header class="cashbook-create-header" title="Add entry" subtitle="Record a manual cash movement">
        <x-slot:actions>
            <a href="{{ route('cashbook.index') }}" class="cbf-header-back" aria-label="Back to Cash book">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                <span>Back to Cash book</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner cbf-page">
        <form method="POST" action="{{ route('cashbook.store') }}" class="cbf-card" data-cashbook-entry-form>
            @csrf

            <div class="cbf-head">
                <div>
                    <h2 class="cbf-title">Entry details</h2>
                    <p class="cbf-copy">Use this for manual cash or payment-mode adjustments.</p>
                </div>
                <span class="cbf-required">Required fields marked *</span>
            </div>

            <div class="cbf-body">
                <div class="cbf-entry-grid">
                    <div class="cbf-field">
                        <span class="cbf-label">Transaction type *</span>
                        <div class="cbf-type-grid">
                            <label class="cbf-type">
                                <input type="radio" name="type" value="in" {{ old('type', 'in') === 'in' ? 'checked' : '' }} onchange="handleTypeChange('in')">
                                <span class="cbf-type-box cbf-type-box--in">
                                    <span class="cbf-type-icon" aria-hidden="true">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path d="M12 19V5M5 12l7-7 7 7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <span class="cbf-type-copy">
                                        <span class="cbf-type-name">Cash in</span>
                                        <span class="cbf-type-sub">Money received</span>
                                    </span>
                                </span>
                            </label>

                            <label class="cbf-type">
                                <input type="radio" name="type" value="out" {{ old('type') === 'out' ? 'checked' : '' }} onchange="handleTypeChange('out')">
                                <span class="cbf-type-box cbf-type-box--out">
                                    <span class="cbf-type-icon" aria-hidden="true">
                                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                            <path d="M12 5v14M5 12l7 7 7-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </span>
                                    <span class="cbf-type-copy">
                                        <span class="cbf-type-name">Cash out</span>
                                        <span class="cbf-type-sub">Money paid out</span>
                                    </span>
                                </span>
                            </label>
                        </div>
                        @error('type') <p class="cbf-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field">
                        <label for="amount" class="cbf-label">Amount (₹) *</label>
                        <div class="cbf-amount">
                            <span class="cbf-amount-prefix">₹</span>
                            <input type="number" step="0.01" min="0.01" name="amount" id="amount"
                                   value="{{ old('amount') }}" placeholder="0.00" required
                                   class="cbf-input cbf-input--amount">
                        </div>
                        @error('amount') <p class="cbf-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field">
                        <label for="source_type" class="cbf-label">Reason *</label>
                        @php
                            $inSources  = \App\Models\CashTransaction::IN_SOURCES;
                            $outSources = \App\Models\CashTransaction::OUT_SOURCES;
                            $oldSource = old('source_type', '');
                            $knownSources = array_merge(array_keys($inSources), array_keys($outSources));
                            $isCustom  = $oldSource !== '' && !in_array($oldSource, $knownSources);
                        @endphp
                        <div class="cbf-select">
                            <select name="source_type" id="source_type" class="cbf-input" required onchange="handleSourceChange(this)">
                                <option value="">Select a reason</option>
                                @foreach($inSources as $val => $label)
                                    <option value="{{ $val }}" data-dir="in" {{ $oldSource === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                                @foreach($outSources as $val => $label)
                                    <option value="{{ $val }}" data-dir="out" {{ $oldSource === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                                <option value="custom" data-dir="both" {{ $isCustom ? 'selected' : '' }}>Something else (type it)</option>
                            </select>
                        </div>
                        <input type="text" id="custom_source"
                               class="cbf-input cbf-custom {{ $isCustom ? '' : 'cbf-hidden' }}"
                               placeholder="Type the reason" maxlength="100"
                               value="{{ $isCustom ? $oldSource : '' }}">
                        <p class="cbf-help" id="source_hint">Pick why money came in.</p>
                        @error('source_type') <p class="cbf-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field">
                        <label for="payment_mode" class="cbf-label">Payment mode *</label>
                        @php $oldMode = old('payment_mode', 'cash'); @endphp
                        <div class="cbf-select">
                            <select name="payment_mode" id="payment_mode" class="cbf-input" required>
                                <option value="cash"   {{ $oldMode === 'cash'   ? 'selected' : '' }}>Cash (drawer)</option>
                                <option value="upi"    {{ $oldMode === 'upi'    ? 'selected' : '' }}>UPI</option>
                                <option value="bank"   {{ $oldMode === 'bank'   ? 'selected' : '' }}>Bank</option>
                                <option value="card"   {{ $oldMode === 'card'   ? 'selected' : '' }}>Card</option>
                                <option value="wallet" {{ $oldMode === 'wallet' ? 'selected' : '' }}>Wallet</option>
                                <option value="other"  {{ $oldMode === 'other'  ? 'selected' : '' }}>Other</option>
                            </select>
                        </div>
                        <p class="cbf-help">Cash affects drawer balance. Other modes stay tracked separately.</p>
                        @error('payment_mode') <p class="cbf-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field cbf-field--full">
                        <label for="description" class="cbf-label">Description</label>
                        <textarea name="description" id="description" rows="3"
                                  placeholder="Add optional notes for this entry"
                                  class="cbf-input cbf-textarea">{{ old('description') }}</textarea>
                        @error('description') <p class="cbf-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="cbf-actions">
                <a href="{{ route('cashbook.index') }}" class="cbf-cancel">Cancel</a>
                <button type="submit" class="cbf-submit">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <path d="M17 21v-8H7v8M7 3v5h8"/>
                    </svg>
                    Record Transaction
                </button>
            </div>
        </form>
    </div>

    <style>
        .cbf-page,
        .cashbook-create-header {
            --cbf-border: #cbd5e1;
            --cbf-border-soft: #e2e8f0;
            --cbf-surface: #ffffff;
            --cbf-surface-muted: #f8fafc;
            --cbf-surface-nested: #f3f5f8;
            --cbf-ink: #1f2430;
            --cbf-ink-2: #4a4334;
            --cbf-muted: #64748b;
            --cbf-gold: #b45309;
            --cbf-gold-hover: #92400e;
            --cbf-pos: #047857;
            --cbf-neg: #b42318;
            --cbf-focus: rgba(245, 158, 11, .2);
            --cbf-ease: cubic-bezier(0.23, 1, 0.32, 1);
        }

        .cashbook-create-header {
            flex-wrap: nowrap;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--cbf-border-soft);
            background: #ffffff;
            box-shadow: none !important;
        }
        .cashbook-create-header > .min-w-0 {
            min-width: 0;
        }
        .cashbook-create-header .page-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin-left: auto;
        }
        .cbf-header-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--cbf-border);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cbf-ink-2);
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: none !important;
            transition: background-color 140ms var(--cbf-ease), border-color 140ms var(--cbf-ease), transform 120ms var(--cbf-ease);
        }
        .cbf-header-back:hover {
            border-color: #94a3b8;
            background: var(--cbf-surface-muted);
            color: var(--cbf-ink);
        }
        .cbf-header-back svg {
            display: block;
            flex: 0 0 auto;
            color: currentColor;
            stroke: currentColor;
        }
        .cbf-header-back:active,
        .cbf-submit:active,
        .cbf-cancel:active,
        .cbf-type-box:active {
            transform: scale(.98);
        }

        .cbf-page {
            width: 100%;
            max-width: none;
            color: var(--cbf-ink);
        }
        .cbf-card {
            width: 100%;
            border: 1px solid var(--cbf-border-soft);
            border-radius: 14px;
            background: var(--cbf-surface);
            box-shadow: none;
            overflow: hidden;
        }
        .cbf-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--cbf-border-soft);
            background: var(--cbf-surface);
        }
        .cbf-title {
            margin: 0;
            color: var(--cbf-ink);
            font-size: 18px;
            font-weight: 650;
            line-height: 1.2;
            letter-spacing: -0.15px;
        }
        .cbf-copy {
            margin: 4px 0 0;
            color: var(--cbf-muted);
            font-size: 13px;
            line-height: 1.4;
        }
        .cbf-required {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border: 1px solid #f3dcb6;
            border-radius: 999px;
            background: #fdf6ec;
            color: var(--cbf-gold);
            font-size: 11.5px;
            font-weight: 600;
            white-space: nowrap;
        }
        .cbf-body {
            padding: 20px;
        }
        .cbf-entry-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .cbf-field {
            min-width: 0;
        }
        .cbf-field--full {
            grid-column: 1 / -1;
        }
        .cbf-label {
            display: block;
            margin-bottom: 8px;
            color: var(--cbf-ink-2);
            font-size: 12.5px;
            font-weight: 600;
            line-height: 1.25;
        }

        .cbf-page .cbf-input {
            width: 100%;
            min-height: 44px;
            padding: 0 13px;
            border: 1px solid var(--cbf-border) !important;
            border-radius: 10px;
            background: #ffffff !important;
            color: var(--cbf-ink) !important;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.25;
            box-shadow: none !important;
            transition: border-color 140ms var(--cbf-ease), box-shadow 140ms var(--cbf-ease), background-color 140ms var(--cbf-ease);
        }
        .cbf-page .cbf-input::placeholder {
            color: #94a3b8;
        }
        .cbf-page .cbf-input:focus {
            border-color: var(--cbf-gold) !important;
            background: #ffffff !important;
            box-shadow: 0 0 0 3px var(--cbf-focus) !important;
            outline: none;
        }
        .cbf-select {
            position: relative;
        }
        .cbf-select::after {
            content: "";
            position: absolute;
            top: 50%;
            right: 15px;
            width: 8px;
            height: 8px;
            border-right: 2px solid #64748b;
            border-bottom: 2px solid #64748b;
            transform: translateY(-65%) rotate(45deg);
            pointer-events: none;
        }
        .cbf-select > select.cbf-input {
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            background-image: none !important;
            padding-right: 40px;
        }
        .cbf-select > select.cbf-input::-ms-expand {
            display: none;
        }
        .cbf-textarea,
        textarea.cbf-input {
            min-height: 92px;
            padding: 12px 13px;
            resize: vertical;
            line-height: 1.5;
        }
        .cbf-custom {
            margin-top: 10px;
        }
        .cbf-hidden {
            display: none;
        }
        .cbf-help {
            margin: 7px 0 0;
            color: var(--cbf-muted);
            font-size: 12px;
            line-height: 1.4;
        }
        .cbf-error {
            margin: 8px 0 0;
            color: var(--cbf-neg);
            font-size: 12px;
            line-height: 1.4;
        }

        .cbf-amount {
            position: relative;
        }
        .cbf-amount-prefix {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--cbf-muted);
            font-size: 14px;
            pointer-events: none;
        }
        .cbf-input--amount {
            padding-left: 30px;
            font-variant-numeric: tabular-nums;
        }

        .cbf-type-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .cbf-type {
            display: block;
            min-width: 0;
            cursor: pointer;
        }
        .cbf-type input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }
        .cbf-type-box {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 62px;
            padding: 11px 12px;
            border: 1px solid var(--cbf-border);
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none;
            overflow: hidden;
            transition: background-color 140ms var(--cbf-ease), border-color 140ms var(--cbf-ease), transform 120ms var(--cbf-ease);
        }
        .cbf-type-box::before {
            content: "";
            position: absolute;
            top: 10px;
            bottom: 10px;
            left: 0;
            width: 4px;
            border-radius: 0 999px 999px 0;
            background: currentColor;
            opacity: 0;
            transition: opacity 140ms var(--cbf-ease);
        }
        .cbf-type-icon {
            display: inline-flex;
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
        }
        .cbf-type-box--in .cbf-type-icon {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: var(--cbf-pos);
        }
        .cbf-type-box--out .cbf-type-icon {
            border: 1px solid #fecdca;
            background: #fef2f2;
            color: var(--cbf-neg);
        }
        .cbf-type-copy {
            min-width: 0;
        }
        .cbf-type-name {
            display: block;
            color: var(--cbf-ink);
            font-size: 14px;
            font-weight: 650;
            line-height: 1.15;
        }
        .cbf-type-sub {
            display: block;
            margin-top: 3px;
            color: var(--cbf-muted);
            font-size: 12px;
            line-height: 1.25;
        }
        @media (hover: hover) and (pointer: fine) {
            .cbf-type:hover .cbf-type-box {
                border-color: #94a3b8;
                background: var(--cbf-surface-muted);
            }
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--in {
            border-color: var(--cbf-pos) !important;
            background: #ecfdf5 !important;
            color: var(--cbf-pos) !important;
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--in .cbf-type-name {
            color: var(--cbf-pos);
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--in .cbf-type-sub {
            color: #047857;
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--in .cbf-type-icon {
            border-color: var(--cbf-pos);
            background: var(--cbf-pos);
            color: #ffffff;
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--out {
            border-color: var(--cbf-neg) !important;
            background: #fef2f2 !important;
            color: var(--cbf-neg) !important;
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--out .cbf-type-name {
            color: var(--cbf-neg);
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--out .cbf-type-sub {
            color: #b42318;
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box.cbf-type-box--out .cbf-type-icon {
            border-color: var(--cbf-neg);
            background: var(--cbf-neg);
            color: #ffffff;
        }
        .cbf-page .cbf-type input:checked + .cbf-type-box::before {
            opacity: 1;
        }
        .cbf-type input:focus-visible + .cbf-type-box {
            border-color: var(--cbf-gold);
            box-shadow: 0 0 0 3px var(--cbf-focus);
        }

        .cbf-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 16px 20px;
            border-top: 1px solid var(--cbf-border-soft);
            background: var(--cbf-surface-muted);
        }
        .cbf-submit,
        .cbf-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 16px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            box-shadow: none;
            transition: background-color 140ms var(--cbf-ease), border-color 140ms var(--cbf-ease), transform 120ms var(--cbf-ease);
        }
        .cbf-submit {
            border: 1px solid var(--cbf-gold);
            background: var(--cbf-gold);
            color: #ffffff;
        }
        .cbf-submit:hover {
            border-color: var(--cbf-gold-hover);
            background: var(--cbf-gold-hover);
            color: #ffffff;
        }
        .cbf-cancel {
            border: 1px solid var(--cbf-border);
            background: #ffffff;
            color: var(--cbf-ink-2);
        }
        .cbf-cancel:hover {
            border-color: #94a3b8;
            background: #ffffff;
            color: var(--cbf-ink);
        }

        @media (max-width: 767px) {
            .content-header.cashbook-create-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 8px;
            }
            .cashbook-create-header .content-header-nav {
                margin-right: 0;
            }
            .cashbook-create-header .page-title {
                font-size: 17px;
                line-height: 1.15;
            }
            .cashbook-create-header .page-subtitle {
                display: none;
            }
            .cashbook-create-header .page-actions {
                width: auto;
                flex: 0 0 auto;
            }
            .cbf-header-back {
                width: 36px;
                min-width: 36px;
                min-height: 36px;
                padding: 0;
                border-radius: 10px;
                border-color: #cbd5e1 !important;
                background: #ffffff !important;
                color: #1f2430 !important;
            }
            .cbf-header-back svg {
                width: 18px;
                height: 18px;
                stroke-width: 2.25;
            }
            .cbf-header-back span {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
            }
            .cbf-head {
                padding: 14px;
            }
            .cbf-required {
                display: none;
            }
            .cbf-title {
                font-size: 16px;
            }
            .cbf-body {
                padding: 14px;
            }
            .cbf-entry-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .cbf-field--full {
                grid-column: auto;
            }
            .cbf-type-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .cbf-type-box {
                min-height: 66px;
                padding: 10px;
                gap: 8px;
            }
            .cbf-type-icon {
                width: 30px;
                height: 30px;
                flex-basis: 30px;
            }
            .cbf-type-name {
                font-size: 13px;
            }
            .cbf-type-sub {
                font-size: 11px;
            }
            .cbf-actions {
                display: grid;
                grid-template-columns: 1fr;
                gap: 8px;
                padding: 14px;
            }
            .cbf-submit {
                order: 1;
                width: 100%;
            }
            .cbf-cancel {
                order: 2;
                width: 100%;
            }
        }

        @media (max-width: 380px) {
            .cbf-type-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <script>
        window.handleSourceChange = function(select) {
            const customInput = document.getElementById('custom_source');
            if (!customInput || !select) return;

            if (select.value === 'custom') {
                customInput.classList.remove('cbf-hidden');
                customInput.required = true;
                customInput.name = 'source_type';
                select.name = '';
            } else {
                customInput.classList.add('cbf-hidden');
                customInput.required = false;
                customInput.name = '';
                select.name = 'source_type';
            }
        };

        window.handleTypeChange = function(dir) {
            const select = document.getElementById('source_type');
            if (!select) return;

            let clearedSelection = false;
            Array.from(select.options).forEach((opt) => {
                const optDir = opt.getAttribute('data-dir');
                const visible = !optDir || optDir === 'both' || optDir === dir || opt.value === '';
                opt.hidden = !visible;
                opt.disabled = !visible;

                if (!visible && opt.selected) {
                    clearedSelection = true;
                }
            });

            if (clearedSelection) {
                select.value = '';
            }

            window.handleSourceChange(select);

            const hint = document.getElementById('source_hint');
            if (hint) {
                hint.textContent = dir === 'in'
                    ? 'Pick why money came in.'
                    : 'Pick why money went out.';
            }
        };

        function initCashbookEntryForm() {
            const form = document.querySelector('[data-cashbook-entry-form]');
            if (!form) return;

            const checkedType = form.querySelector('input[name="type"]:checked');
            window.handleTypeChange(checkedType ? checkedType.value : 'in');
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initCashbookEntryForm, { once: true });
        } else {
            initCashbookEntryForm();
        }

        document.addEventListener('turbo:load', initCashbookEntryForm);
    </script>
</x-app-layout>
