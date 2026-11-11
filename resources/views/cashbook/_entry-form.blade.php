<form method="POST" action="{{ route('cashbook.store') }}" class="cbf-form" data-cashbook-entry-form data-turbo-frame="_top">
    @csrf
            <div class="cbf-body">
                <div class="cbf-entry-grid">
                    <div class="cbf-field" role="radiogroup" aria-labelledby="cbf-direction-label">
                        <span class="cbf-label" id="cbf-direction-label">Direction *</span>
                        <div class="cbf-type-grid">
                            <label class="cbf-type">
                                <input type="radio" name="type" value="in" {{ old('type', 'in') === 'in' ? 'checked' : '' }} onchange="handleTypeChange('in')">
                                <span class="cbf-type-box cbf-type-box--in">
                                    <span class="cbf-type-icon" aria-hidden="true">
                                        <x-icon name="arrow-up" size="17" />
                                    </span>
                                    <span class="cbf-type-copy">
                                        <span class="cbf-type-name">Money in</span>
                                        <span class="cbf-type-sub">Money received</span>
                                    </span>
                                </span>
                            </label>

                            <label class="cbf-type">
                                <input type="radio" name="type" value="out" {{ old('type') === 'out' ? 'checked' : '' }} onchange="handleTypeChange('out')">
                                <span class="cbf-type-box cbf-type-box--out">
                                    <span class="cbf-type-icon" aria-hidden="true">
                                        <x-icon name="arrow-down" size="17" />
                                    </span>
                                    <span class="cbf-type-copy">
                                        <span class="cbf-type-name">Money out</span>
                                        <span class="cbf-type-sub">Money paid out</span>
                                    </span>
                                </span>
                            </label>
                        </div>
                        @error('type') <p class="cbf-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field">
                        <label for="amount" class="cbf-label">Amount (₹) *</label>
                        <div class="cbf-amount">
                            <span class="cbf-amount-prefix">₹</span>
                            <input type="number" step="0.01" min="0.01" name="amount" id="amount" autofocus
                                   value="{{ old('amount') }}" placeholder="0.00" required
                                   class="cbf-input cbf-input--amount">
                        </div>
                        @error('amount') <p class="cbf-error" role="alert">{{ $message }}</p> @enderror
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
                        <input type="text" id="custom_source" aria-label="Custom reason"
                               class="cbf-input cbf-custom {{ $isCustom ? '' : 'cbf-hidden' }}"
                               placeholder="Type the reason" maxlength="100"
                               value="{{ $isCustom ? $oldSource : '' }}">
                        <p class="cbf-help" id="source_hint">Pick why money came in.</p>
                        @error('source_type') <p class="cbf-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field">
                        <label for="payment_mode" class="cbf-label">Payment mode *</label>
                        @php $oldMode = old('payment_mode', 'cash'); @endphp
                        <div class="cbf-select">
                            <select name="payment_mode" id="payment_mode" class="cbf-input" required onchange="handleModeChange()">
                                <option value="cash"   {{ $oldMode === 'cash'   ? 'selected' : '' }}>Cash (drawer)</option>
                                <option value="upi"    {{ $oldMode === 'upi'    ? 'selected' : '' }}>UPI</option>
                                <option value="bank"   {{ $oldMode === 'bank'   ? 'selected' : '' }}>Bank</option>
                                <option value="card"   {{ $oldMode === 'card'   ? 'selected' : '' }}>Card</option>
                                <option value="wallet" {{ $oldMode === 'wallet' ? 'selected' : '' }}>Wallet</option>
                                <option value="other"  {{ $oldMode === 'other'  ? 'selected' : '' }}>Other</option>
                            </select>
                        </div>
                        <p class="cbf-help">Cash affects drawer balance. Other modes stay tracked separately.</p>
                        @error('payment_mode') <p class="cbf-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    {{-- Which of the shop's accounts (as on POS): shown for UPI / bank / wallet when the shop has one. --}}
                    <div class="cbf-field" id="account_field">
                        <label for="payment_method_id" class="cbf-label">Which account? *</label>
                        <div class="cbf-select">
                            <select name="payment_method_id" id="payment_method_id" class="cbf-input">
                                <option value="">Choose the account</option>
                                @foreach($accounts as $kind => $list)
                                    @foreach($list as $account)
                                        <option value="{{ $account['id'] }}" data-kind="{{ $kind }}" {{ (int) old('payment_method_id') === $account['id'] ? 'selected' : '' }}>{{ $account['account_label'] }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                        @error('payment_method_id') <p class="cbf-error" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div class="cbf-field cbf-field--full">
                        <label for="description" class="cbf-label">Description</label>
                        <textarea name="description" id="description" rows="2" maxlength="500"
                                  placeholder="Add optional notes for this entry"
                                  class="cbf-input cbf-textarea">{{ old('description') }}</textarea>
                        @error('description') <p class="cbf-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>


    <div class="cbf-actions">
        @if($entryModal ?? false)
            <button type="button" class="btn btn-secondary btn-sm" @click="$refs.entry.close()">Cancel</button>
        @else
            <a href="{{ route('cashbook.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
        @endif
        <button type="submit" class="btn btn-primary btn-sm"><x-icon name="save" size="16" />Record transaction</button>
    </div>
</form>

@once
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

        // Show only the accounts of the chosen kind; hide the field when there are none.
        window.handleModeChange = function() {
            const mode = document.getElementById('payment_mode');
            const field = document.getElementById('account_field');
            const select = document.getElementById('payment_method_id');
            if (!mode || !field || !select) return;

            const matching = Array.from(select.options).filter((opt) => opt.dataset.kind === mode.value);
            Array.from(select.options).forEach((opt) => {
                if (!opt.dataset.kind) return;
                opt.hidden = opt.dataset.kind !== mode.value;
                opt.disabled = opt.hidden;
            });
            if (select.selectedOptions[0]?.disabled) select.value = '';
            if (matching.length === 1) select.value = matching[0].value;

            field.classList.toggle('cbf-hidden', matching.length === 0);
            select.required = matching.length > 0;
            select.disabled = matching.length === 0;
        };

        function initCashbookEntryForm() {
            const form = document.querySelector('[data-cashbook-entry-form]');
            if (!form) return;

            const checkedType = form.querySelector('input[name="type"]:checked');
            window.handleTypeChange(checkedType ? checkedType.value : 'in');
            window.handleModeChange();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initCashbookEntryForm, { once: true });
        } else {
            initCashbookEntryForm();
        }

        document.addEventListener('turbo:load', initCashbookEntryForm);
    </script>
@endonce
