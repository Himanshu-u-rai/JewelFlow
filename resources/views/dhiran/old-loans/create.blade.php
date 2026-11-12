<x-dhiran-layout title="Add Old Loan">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Add Old Loan</h1>
            <p class="text-sm text-gray-500 mt-1">A loan already running in your paper book, entered as it stands today. Nothing goes into the cash book.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.old-loans.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="arrow-left" size="14" class="mr-1" />
                Old Loans
            </a>
        </div>
    </x-dhiran.page-header>

    @php
        $oldItems = collect(old('items') ?: [[]])->values()->map(fn ($i) => [
            'description' => (string) ($i['description'] ?? ''), 'metal_type' => (string) ($i['metal_type'] ?? 'gold'),
            'purity' => (string) ($i['purity'] ?? '22'), 'gross_weight' => (string) ($i['gross_weight'] ?? ''),
            'stone_weight' => (string) ($i['stone_weight'] ?? ''), 'rate_per_gram_at_pledge' => (string) ($i['rate_per_gram_at_pledge'] ?? ''),
            'market_value' => (string) ($i['market_value'] ?? ''),
        ]);
        $err = fn ($k) => $errors->first($k);
    @endphp

    <div class="content-inner">
        <form method="POST" action="{{ route('dhiran.old-loans.store') }}" class="max-w-4xl mx-auto space-y-6" data-turbo-frame="_top"
              x-data="{
                  customerId: @js((string) old('customer_id', '')),
                  items: @js($oldItems),
                  purities: { gold: [24, 22, 20, 18, 14], silver: [999, 958, 925, 900, 800] },
                  add() { this.items.push({ description: '', metal_type: 'gold', purity: '22', gross_weight: '', stone_weight: '', rate_per_gram_at_pledge: '', market_value: '' }) },
                  remove(i) { if (this.items.length > 1) this.items.splice(i, 1) },
              }">
            @csrf <x-dhiran.once />

            @if($errors->has('loan'))
                <div class="dh-flash dh-flash-error">{{ $errors->first('loan') }}</div>
            @endif

            {{-- Borrower --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title"><x-icon name="user" /> Borrower</div>
                <label for="customer_id" class="dh-label">Already a customer?</label>
                <select name="customer_id" id="customer_id" x-model="customerId" class="dh-select">
                    <option value="">No, add a new customer</option>
                    @foreach($customers as $c)
                        <option value="{{ $c->id }}">{{ trim($c->first_name . ' ' . $c->last_name) }} · {{ $c->mobile }}</option>
                    @endforeach
                </select>
                @if($err('customer_id'))<p class="mt-1 text-sm text-red-600">{{ $err('customer_id') }}</p>@endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" x-show="customerId === ''">
                    @foreach([['first_name', 'First name *', 'text'], ['last_name', 'Last name', 'text'], ['mobile', 'Mobile *', 'tel'], ['pan', 'PAN', 'text'], ['id_number', 'ID number (Aadhaar / other)', 'text'], ['address', 'Address', 'text']] as [$f, $label, $type])
                        <div>
                            <label for="borrower_{{ $f }}" class="dh-label">{{ $label }}</label>
                            <input type="{{ $type }}" name="borrower[{{ $f }}]" id="borrower_{{ $f }}" value="{{ old("borrower.{$f}") }}" class="dh-input"
                                   :disabled="customerId !== ''" @if(str_ends_with($label, '*')) :required="customerId === ''" @endif>
                            @if($err("borrower.{$f}"))<p class="mt-1 text-sm text-red-600">{{ $err("borrower.{$f}") }}</p>@endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- The loan as it stands in the paper book --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title"><x-icon name="book-open" /> Loan in your book</div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach([
                        ['paper_loan_number', 'Ticket number in your book *', 'text', ''],
                        ['loan_date', 'Date the loan was given *', 'date', ''],
                        ['principal_amount', 'Amount lent (₹) *', 'number', ''],
                        ['principal_owed', 'Principal still owed today (₹) *', 'number', ''],
                        ['interest_rate_monthly', 'Interest rate (% per month) *', 'number', $settings->default_interest_rate_monthly],
                        ['tenure_months', 'Tenure (months) *', 'number', $settings->default_tenure_months],
                        ['interest_paid_until', 'Interest paid up to *', 'date', ''],
                        ['unpaid_interest', 'Interest unpaid since then, as per your book (₹)', 'number', ''],
                        ['penalty_rate_monthly', 'Penalty rate (% per month)', 'number', $settings->default_penalty_rate_monthly],
                        ['grace_period_days', 'Grace days after maturity', 'number', $settings->grace_period_days],
                        ['gold_rate_on_date', 'Gold rate used (₹ per gram)', 'number', ''],
                        ['silver_rate_on_date', 'Silver rate used (₹ per gram)', 'number', ''],
                    ] as [$f, $label, $type, $default])
                        <div>
                            <label for="{{ $f }}" class="dh-label">{{ $label }}</label>
                            <input type="{{ $type }}" name="{{ $f }}" id="{{ $f }}" value="{{ old($f, $default) }}" class="dh-input"
                                   @if($type === 'number') step="0.01" min="0" @endif @if($type === 'date') max="{{ today()->toDateString() }}" @endif
                                   @if(str_ends_with($label, '*')) required @endif>
                            @if($f === 'unpaid_interest')<p class="mt-1 text-xs text-slate-500">Leave blank to work it out from the paid-up date at this rate.</p>@endif
                            @if($err($f))<p class="mt-1 text-sm text-red-600">{{ $err($f) }}</p>@endif
                        </div>
                    @endforeach
                    <div>
                        <label for="interest_type" class="dh-label">Interest type *</label>
                        <select name="interest_type" id="interest_type" class="dh-select">
                            @foreach(['flat' => 'Flat', 'daily' => 'Daily', 'compound' => 'Compound (monthly)'] as $v => $l)
                                <option value="{{ $v }}" @selected(old('interest_type', $settings->default_interest_type ?? 'flat') === $v)>{{ $l }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- Pledged items: every loan has collateral --}}
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-visible p-6">
                <div class="dh-section-title"><x-icon name="package" /> Pledged items</div>
                @if($err('items'))<p class="mb-2 text-sm text-red-600">{{ $err('items') }}</p>@endif
                <template x-for="(item, i) in items" :key="i">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 pb-4 mb-4 border-b border-slate-100">
                        <div class="col-span-2">
                            <label class="dh-label">Description *</label>
                            <input type="text" :name="`items[${i}][description]`" x-model="item.description" class="dh-input" required placeholder="e.g. Gold chain">
                        </div>
                        <div>
                            <label class="dh-label">Type *</label>
                            <select :name="`items[${i}][metal_type]`" x-model="item.metal_type" class="dh-select">
                                <option value="gold">Gold</option><option value="silver">Silver</option><option value="other">Other (appraised)</option>
                            </select>
                        </div>
                        <div x-show="item.metal_type !== 'other'">
                            <label class="dh-label">Purity *</label>
                            <select :name="`items[${i}][purity]`" x-model="item.purity" class="dh-select" :disabled="item.metal_type === 'other'">
                                <template x-for="p in (purities[item.metal_type] || [])" :key="p"><option :value="p" x-text="item.metal_type === 'gold' ? p + 'K' : p" :selected="String(p) === String(item.purity)"></option></template>
                            </select>
                        </div>
                        <div x-show="item.metal_type !== 'other'">
                            <label class="dh-label">Gross weight (g) *</label>
                            <input type="number" step="0.001" min="0" :name="`items[${i}][gross_weight]`" x-model="item.gross_weight" class="dh-input" :disabled="item.metal_type === 'other'">
                        </div>
                        <div x-show="item.metal_type !== 'other'">
                            <label class="dh-label">Stone weight (g)</label>
                            <input type="number" step="0.001" min="0" :name="`items[${i}][stone_weight]`" x-model="item.stone_weight" class="dh-input" :disabled="item.metal_type === 'other'">
                        </div>
                        <div x-show="item.metal_type !== 'other'">
                            <label class="dh-label">Rate per gram (₹) *</label>
                            <input type="number" step="0.01" min="0" :name="`items[${i}][rate_per_gram_at_pledge]`" x-model="item.rate_per_gram_at_pledge" class="dh-input" :disabled="item.metal_type === 'other'">
                        </div>
                        <div x-show="item.metal_type === 'other'">
                            <label class="dh-label">Appraised value (₹) *</label>
                            <input type="number" step="0.01" min="0" :name="`items[${i}][market_value]`" x-model="item.market_value" class="dh-input" :disabled="item.metal_type !== 'other'">
                        </div>
                        <div class="flex items-end">
                            <button type="button" class="btn btn-secondary btn-sm" @click="remove(i)" x-show="items.length > 1">Remove item</button>
                        </div>
                    </div>
                </template>
                @foreach($errors->getMessages() as $k => $msgs)
                    @if(str_starts_with($k, 'items.'))<p class="text-sm text-red-600">Item {{ (int) explode('.', $k)[1] + 1 }}: {{ $msgs[0] }}</p>@endif
                @endforeach
                <button type="button" class="dh-add-item-btn" @click="add()"><x-icon name="plus" /> Add item</button>
            </div>

            <div>
                <label for="notes" class="dh-label">Notes</label>
                <textarea name="notes" id="notes" rows="2" class="dh-textarea">{{ old('notes') }}</textarea>
            </div>

            <div class="flex justify-end gap-2">
                <a href="{{ route('dhiran.old-loans.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm"><x-icon name="save" size="14" class="mr-1" /> Bring in loan</button>
            </div>
        </form>
    </div>
</x-dhiran-layout>
