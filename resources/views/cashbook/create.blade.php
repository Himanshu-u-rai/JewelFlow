<x-app-layout>
    <x-page-header title="Add Ledger Entry" subtitle="Record a cash inflow or outflow in the ledger" />

    <div class="content-inner cbf-page">
        <a href="{{ route('cashbook.index') }}" class="cbf-back">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Cash Ledger
        </a>

        <form method="POST" action="{{ route('cashbook.store') }}" class="cbf-card">
            @csrf
            <div class="cbf-head">
                <h2 class="cbf-title">New Transaction</h2>
                <p class="cbf-copy">Use this form for manual cash entries.</p>
            </div>

            <div class="cbf-body">
                {{-- Transaction type --}}
                <div class="cbf-field">
                    <span class="cbf-label">Transaction Type *</span>
                    <div class="cbf-type-grid">
                        <label class="cbf-type">
                            <input type="radio" name="type" value="in" {{ old('type', 'in') === 'in' ? 'checked' : '' }}>
                            <span class="cbf-type-box cbf-type-box--in">
                                <span class="cbf-type-name">Cash In</span>
                                <span class="cbf-type-sub">Money received</span>
                            </span>
                        </label>
                        <label class="cbf-type">
                            <input type="radio" name="type" value="out" {{ old('type') === 'out' ? 'checked' : '' }}>
                            <span class="cbf-type-box cbf-type-box--out">
                                <span class="cbf-type-name">Cash Out</span>
                                <span class="cbf-type-sub">Money paid out</span>
                            </span>
                        </label>
                    </div>
                    @error('type') <p class="cbf-error">{{ $message }}</p> @enderror
                </div>

                {{-- Amount --}}
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

                {{-- Source / reason --}}
                <div class="cbf-field">
                    <label for="source_type" class="cbf-label">Source / Reason *</label>
                    @php
                        $knownSources = ['opening_balance','loan_received','owner_investment','other_income','salary','rent','utility_bills','gold_purchase','supplier_payment','loan_repayment','owner_withdrawal','other_expense'];
                        $oldSource = old('source_type', '');
                        $isCustom = $oldSource !== '' && !in_array($oldSource, $knownSources);
                    @endphp
                    <select name="source_type" id="source_type" class="cbf-input" required onchange="handleSourceChange(this)">
                        <option value="">Select source</option>
                        <optgroup label="Cash In Sources">
                            <option value="opening_balance" {{ $oldSource === 'opening_balance' ? 'selected' : '' }}>Opening Balance</option>
                            <option value="loan_received" {{ $oldSource === 'loan_received' ? 'selected' : '' }}>Loan Received</option>
                            <option value="owner_investment" {{ $oldSource === 'owner_investment' ? 'selected' : '' }}>Owner Investment</option>
                            <option value="other_income" {{ $oldSource === 'other_income' ? 'selected' : '' }}>Other Income</option>
                        </optgroup>
                        <optgroup label="Cash Out Sources">
                            <option value="salary" {{ $oldSource === 'salary' ? 'selected' : '' }}>Salary Payment</option>
                            <option value="rent" {{ $oldSource === 'rent' ? 'selected' : '' }}>Rent</option>
                            <option value="utility_bills" {{ $oldSource === 'utility_bills' ? 'selected' : '' }}>Utility Bills</option>
                            <option value="gold_purchase" {{ $oldSource === 'gold_purchase' ? 'selected' : '' }}>Gold Purchase (Supplier)</option>
                            <option value="supplier_payment" {{ $oldSource === 'supplier_payment' ? 'selected' : '' }}>Supplier Payment</option>
                            <option value="loan_repayment" {{ $oldSource === 'loan_repayment' ? 'selected' : '' }}>Loan Repayment</option>
                            <option value="owner_withdrawal" {{ $oldSource === 'owner_withdrawal' ? 'selected' : '' }}>Owner Withdrawal</option>
                            <option value="other_expense" {{ $oldSource === 'other_expense' ? 'selected' : '' }}>Other Expense</option>
                        </optgroup>
                        <option value="custom" {{ $isCustom ? 'selected' : '' }}>Custom (Enter below)</option>
                    </select>
                    <input type="text" id="custom_source"
                           class="cbf-input cbf-custom {{ $isCustom ? '' : 'cbf-hidden' }}"
                           placeholder="Enter custom source/reason" maxlength="100"
                           value="{{ $isCustom ? $oldSource : '' }}">
                    @error('source_type') <p class="cbf-error">{{ $message }}</p> @enderror
                </div>

                {{-- Description --}}
                <div class="cbf-field">
                    <label for="description" class="cbf-label">Description (optional)</label>
                    <textarea name="description" id="description" rows="3"
                              placeholder="Add any additional notes..."
                              class="cbf-input cbf-textarea">{{ old('description') }}</textarea>
                    @error('description') <p class="cbf-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="cbf-actions">
                <a href="{{ route('cashbook.index') }}" class="cbf-cancel">Cancel</a>
                <button type="submit" class="cbf-submit">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Record Transaction
                </button>
            </div>
        </form>
    </div>

    <style>
        /* ── Add Ledger Entry — calm teal/hairline form (matches the system) ── */
        .cbf-page {
            --cbf-border:        #e7ebf1;
            --cbf-border-soft:   #eef1f6;
            --cbf-border-strong: #d9dfe8;
            --cbf-ink:           #0f172a;
            --cbf-ink-2:         #3d4861;
            --cbf-muted:         #6a7588;
            --cbf-accent:        #0d9488;
            --cbf-accent-deep:   #0f766e;
            --cbf-neg:           #b42318;
            --cbf-shadow:        0 1px 2px rgba(16,24,40,.04), 0 12px 28px -16px rgba(16,24,40,.16);
            --cbf-ease:          cubic-bezier(0.23,1,0.32,1);
            max-width: 680px;
        }

        /* Keep the header title beside the menu button on mobile (don't wrap below). */
        @media (max-width: 767px) {
            .content-header { flex-wrap: nowrap; align-items: center; }
            .content-header > :nth-child(2) { min-width: 0; }
        }

        .cbf-back {
            display: inline-flex; align-items: center; gap: 7px;
            margin-bottom: 16px;
            color: var(--cbf-muted); font-size: 13px; font-weight: 600; text-decoration: none;
            transition: color .15s var(--cbf-ease);
        }
        .cbf-back:hover { color: var(--cbf-accent-deep); }
        .cbf-back svg { flex-shrink: 0; }

        .cbf-card {
            border: 1px solid var(--cbf-border); border-radius: 16px;
            background: #ffffff; box-shadow: var(--cbf-shadow); overflow: hidden;
        }
        @media (prefers-reduced-motion: no-preference) {
            .cbf-card { animation: cbfRise .5s var(--cbf-ease) both; }
            @keyframes cbfRise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        }

        .cbf-head { padding: 20px 24px; border-bottom: 1px solid var(--cbf-border-soft); }
        .cbf-title { margin: 0; color: var(--cbf-ink); font-size: 16px; font-weight: 650; letter-spacing: -.01em; }
        .cbf-copy { margin: 4px 0 0; color: var(--cbf-muted); font-size: 12.5px; line-height: 1.5; }

        .cbf-body { padding: 22px 24px; display: flex; flex-direction: column; gap: 20px; }
        .cbf-field { display: block; }
        .cbf-label { display: block; margin-bottom: 8px; color: var(--cbf-ink-2); font-size: 12.5px; font-weight: 600; }

        .cbf-input {
            width: 100%; border: 1px solid var(--cbf-border-strong); border-radius: 12px;
            background: #f4f6fa; color: var(--cbf-ink); font-size: 14px; min-height: 44px; padding: 0 13px;
            transition: border-color .16s var(--cbf-ease), box-shadow .16s var(--cbf-ease), background-color .16s var(--cbf-ease);
        }
        textarea.cbf-input, .cbf-textarea { min-height: 76px; padding: 11px 13px; resize: vertical; line-height: 1.5; }
        .cbf-input::placeholder { color: #9aa6b8; }
        .cbf-input:focus { border-color: var(--cbf-accent-deep); background: #fff; box-shadow: 0 0 0 3px rgba(15,118,110,.13); outline: none; }
        .cbf-custom { margin-top: 10px; }
        .cbf-hidden { display: none; }

        /* Amount with ₹ prefix */
        .cbf-amount { position: relative; }
        .cbf-amount-prefix { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--cbf-muted); font-size: 14px; pointer-events: none; }
        .cbf-input--amount { padding-left: 28px; font-variant-numeric: tabular-nums; }

        /* Transaction-type selectable cards */
        .cbf-type-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
        .cbf-type { display: block; cursor: pointer; }
        .cbf-type input { position: absolute; opacity: 0; width: 0; height: 0; }
        .cbf-type-box {
            display: block; padding: 14px 15px;
            border: 1px solid var(--cbf-border-strong); border-radius: 12px; background: #fff;
            transition: border-color .16s var(--cbf-ease), background-color .16s var(--cbf-ease), box-shadow .16s var(--cbf-ease);
        }
        .cbf-type-box:hover { background: #f7f9fc; }
        .cbf-type-name { display: block; color: var(--cbf-ink); font-size: 14px; font-weight: 650; }
        .cbf-type-sub { display: block; margin-top: 3px; color: var(--cbf-muted); font-size: 12px; }
        .cbf-type input:checked + .cbf-type-box--in {
            border-color: var(--cbf-accent); background: rgba(13,148,136,.07); box-shadow: inset 0 0 0 1px var(--cbf-accent);
        }
        .cbf-type input:checked + .cbf-type-box--in .cbf-type-name { color: var(--cbf-accent-deep); }
        .cbf-type input:checked + .cbf-type-box--out {
            border-color: #e0584a; background: #fef2f2; box-shadow: inset 0 0 0 1px #e0584a;
        }
        .cbf-type input:checked + .cbf-type-box--out .cbf-type-name { color: var(--cbf-neg); }
        .cbf-type input:focus-visible + .cbf-type-box { box-shadow: 0 0 0 3px rgba(15,118,110,.18); }

        .cbf-error { margin: 8px 0 0; color: var(--cbf-neg); font-size: 12px; }

        /* Footer actions */
        .cbf-actions {
            display: flex; align-items: center; justify-content: flex-end; gap: 10px;
            padding: 16px 24px; border-top: 1px solid var(--cbf-border-soft); background: #fafbfd;
        }
        .cbf-submit {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 42px; padding: 0 18px;
            border: 1px solid var(--cbf-accent-deep); border-radius: 11px;
            background: var(--cbf-accent-deep); color: #fff; font-size: 13.5px; font-weight: 650;
            cursor: pointer; transition: background-color .15s var(--cbf-ease), transform .15s var(--cbf-ease);
        }
        .cbf-submit:hover { background: #115e56; }
        .cbf-submit:active { transform: scale(.98); }
        .cbf-cancel {
            display: inline-flex; align-items: center; justify-content: center;
            min-height: 42px; padding: 0 16px;
            border: 1px solid var(--cbf-border-strong); border-radius: 11px;
            background: #fff; color: var(--cbf-ink-2); font-size: 13.5px; font-weight: 600; text-decoration: none;
            transition: background-color .15s var(--cbf-ease);
        }
        .cbf-cancel:hover { background: #f1f4f8; }

        @media (max-width: 480px) {
            .cbf-body { padding: 18px; gap: 18px; }
            .cbf-head, .cbf-actions { padding-left: 18px; padding-right: 18px; }
            .cbf-actions { flex-direction: column-reverse; align-items: stretch; }
            .cbf-submit, .cbf-cancel { width: 100%; }
        }
    </style>

    <script>
        function handleSourceChange(select) {
            const customInput = document.getElementById('custom_source');
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
        }

        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('source_type');
            if (select && select.value === 'custom') {
                const customInput = document.getElementById('custom_source');
                customInput.required = true;
                customInput.name = 'source_type';
                select.name = '';
            }
        });
    </script>
</x-app-layout>
