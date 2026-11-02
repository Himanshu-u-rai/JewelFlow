<x-app-layout>
    <x-page-header
        class="customers-page-header schemes-page-header schemes-form-header"
        title="Create Scheme"
        subtitle="Manage savings plans and store offers"
    >
        <x-slot:actions>
            <a href="{{ route('schemes.index') }}" class="schemes-header-action schemes-header-action--neutral schemes-create-back-btn">
                <x-icon name="arrow-left" size="15" />
                <span class="schemes-create-back-label-full">Back to Schemes</span>
                <span class="schemes-create-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner schemes-form-page schemes-create-page">
        <div class="schemes-create-shell">
            @if($errors->any())
                <div class="schemes-form-errors">
                    <p class="schemes-create-errors-title">Please review the highlighted fields.</p>
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('schemes.store') }}"
                class="schemes-create-form"
                x-data="{ type: '{{ old('type', 'gold_savings') }}', appliesTo: '{{ old('applies_to', 'all_items') }}' }"
                data-enhance-selects="true"
                data-enhance-selects-variant="standard"
            >
                @csrf

                <section id="scheme-basics" aria-labelledby="scheme-basics-title" class="schemes-card schemes-create-section schemes-create-basics">
                    <div class="schemes-card-head">
                        <h2 id="scheme-basics-title" class="schemes-create-section-title">Scheme Basics</h2>
                        <p class="schemes-create-section-copy">Define the scheme name, core type, and what customers should understand at a glance.</p>
                    </div>

                    <div class="schemes-form-grid schemes-create-grid schemes-create-grid--basics">
                        <div class="schemes-field">
                            <label for="name" class="schemes-create-label">Scheme Name <span class="schemes-create-required">*</span></label>
                            <input type="text" name="name" id="name" value="{{ old('name') }}" required class="schemes-input @error('name') is-error @enderror">
                            @error('name')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="type" class="schemes-create-label">Type <span class="schemes-create-required">*</span></label>
                            <select name="type" id="type" x-model="type" required class="schemes-select @error('type') is-error @enderror">
                                <option value="gold_savings">Gold Savings Scheme</option>
                                <option value="festival_sale">Festival Sale</option>
                                <option value="discount_offer">Discount Offer</option>
                            </select>
                            @error('type')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="start_date" class="schemes-create-label">Start Date <span class="schemes-create-required">*</span></label>
                            <input type="date" name="start_date" id="start_date" value="{{ old('start_date', now()->format('Y-m-d')) }}" required class="schemes-input @error('start_date') is-error @enderror">
                            @error('start_date')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="end_date" class="schemes-create-label">End Date <span class="schemes-create-label-meta">(Optional)</span></label>
                            <input type="date" name="end_date" id="end_date" value="{{ old('end_date') }}" class="schemes-input @error('end_date') is-error @enderror">
                            @error('end_date')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="schemes-field schemes-field--full">
                            <label for="description" class="schemes-create-label">Description</label>
                            <textarea name="description" id="description" rows="2" class="schemes-textarea @error('description') is-error @enderror">{{ old('description') }}</textarea>
                            @error('description')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section x-show="type === 'gold_savings'" x-cloak class="schemes-card schemes-create-section schemes-create-section--gold">
                    <div class="schemes-card-head">
                        <h2 class="schemes-create-section-title">Gold Savings Settings</h2>
                        <p class="schemes-create-section-copy">Configure the installment cycle and optional bonus amount for a savings plan.</p>
                    </div>

                    <div class="schemes-form-grid schemes-create-grid schemes-create-grid--two">
                        <div class="schemes-field">
                            <label for="total_installments" class="schemes-create-label">Total Installments</label>
                            <input type="number" name="total_installments" id="total_installments" value="{{ old('total_installments', 11) }}" min="1" max="36" class="schemes-input @error('total_installments') is-error @enderror">
                            <p class="schemes-create-hint">Typically 11 months when the shop covers the final month.</p>
                            @error('total_installments')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="bonus_month_value" class="schemes-create-label">Bonus Amount (₹)</label>
                            <input type="number" name="bonus_month_value" id="bonus_month_value" value="{{ old('bonus_month_value') }}" step="0.01" min="0" class="schemes-input @error('bonus_month_value') is-error @enderror">
                            <p class="schemes-create-hint">If left blank, the bonus can match a regular monthly payment.</p>
                            @error('bonus_month_value')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section x-show="type !== 'gold_savings'" x-cloak class="schemes-card schemes-create-section schemes-create-section--offers">
                    <div class="schemes-card-head">
                        <h2 class="schemes-create-section-title">Discount Settings</h2>
                        <p class="schemes-create-section-copy">Define how the offer is calculated, when it applies, and how often it can be used.</p>
                    </div>

                    <div class="schemes-form-grid schemes-create-grid schemes-create-grid--offers">
                        <div class="schemes-field">
                            <label for="discount_type" class="schemes-create-label">Discount Type</label>
                            <select name="discount_type" id="discount_type" class="schemes-select @error('discount_type') is-error @enderror">
                                <option value="">None</option>
                                <option value="percentage" {{ old('discount_type') === 'percentage' ? 'selected' : '' }}>Percentage (%)</option>
                                <option value="flat" {{ old('discount_type') === 'flat' ? 'selected' : '' }}>Flat (₹)</option>
                            </select>
                            @error('discount_type')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="discount_value" class="schemes-create-label">Discount Value</label>
                            <input type="number" name="discount_value" id="discount_value" value="{{ old('discount_value') }}" step="0.01" min="0" class="schemes-input @error('discount_value') is-error @enderror">
                            @error('discount_value')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="max_discount_amount" class="schemes-create-label">Max Discount (₹)</label>
                            <input type="number" name="max_discount_amount" id="max_discount_amount" value="{{ old('max_discount_amount') }}" step="0.01" min="0" class="schemes-input @error('max_discount_amount') is-error @enderror">
                            @error('max_discount_amount')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="min_purchase_amount" class="schemes-create-label">Min Purchase (₹)</label>
                            <input type="number" name="min_purchase_amount" id="min_purchase_amount" value="{{ old('min_purchase_amount') }}" step="0.01" min="0" class="schemes-input @error('min_purchase_amount') is-error @enderror">
                            @error('min_purchase_amount')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="priority" class="schemes-create-label">Priority <span class="schemes-create-label-meta">(Lower = higher)</span></label>
                            <input type="number" name="priority" id="priority" value="{{ old('priority', 100) }}" min="1" max="1000" class="schemes-input @error('priority') is-error @enderror">
                            @error('priority')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="max_uses_per_customer" class="schemes-create-label">Max Uses / Customer</label>
                            <input type="number" name="max_uses_per_customer" id="max_uses_per_customer" value="{{ old('max_uses_per_customer') }}" min="1" class="schemes-input @error('max_uses_per_customer') is-error @enderror">
                            @error('max_uses_per_customer')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="schemes-field">
                            <label for="applies_to" class="schemes-create-label">Applies To</label>
                            <select name="applies_to" id="applies_to" x-model="appliesTo" class="schemes-select @error('applies_to') is-error @enderror">
                                <option value="all_items">All items</option>
                                <option value="category">Specific category</option>
                                <option value="sub_category">Specific sub-category</option>
                            </select>
                            @error('applies_to')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>

                        <div x-show="appliesTo !== 'all_items'" x-cloak class="schemes-field">
                            <label for="applies_to_value" class="schemes-create-label">Target Value</label>
                            <input type="text" name="applies_to_value" id="applies_to_value" value="{{ old('applies_to_value') }}" placeholder="e.g. Rings" class="schemes-input @error('applies_to_value') is-error @enderror">
                            @error('applies_to_value')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <div class="schemes-toggle-grid">
                        <label class="schemes-toggle-row">
                            <input type="hidden" name="auto_apply" value="0">
                            <input type="checkbox" name="auto_apply" value="1" {{ old('auto_apply') ? 'checked' : '' }}>
                            <span>
                                <strong>Auto apply when eligible</strong>
                                <small>Useful when the offer should activate automatically at checkout.</small>
                            </span>
                        </label>

                        <label class="schemes-toggle-row">
                            <input type="hidden" name="stackable" value="0">
                            <input type="checkbox" name="stackable" value="1" {{ old('stackable') ? 'checked' : '' }}>
                            <span>
                                <strong>Allow stacking</strong>
                                <small>Keep this available if the shop plans to combine offers later.</small>
                            </span>
                        </label>
                    </div>
                </section>

                <section class="schemes-card schemes-create-section">
                    <div class="schemes-card-head">
                        <h2 class="schemes-create-section-title">Terms & Conditions</h2>
                        <p class="schemes-create-section-copy">Add the key customer-facing terms or internal conditions for this scheme.</p>
                    </div>

                    <div class="schemes-form-grid schemes-create-grid">
                        <div class="schemes-field">
                            <label for="terms" class="schemes-create-label">Terms</label>
                            <textarea name="terms" id="terms" rows="3" class="schemes-textarea @error('terms') is-error @enderror">{{ old('terms') }}</textarea>
                            @error('terms')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <div class="schemes-form-actions schemes-create-actions">
                    <a href="{{ route('schemes.index') }}" class="schemes-btn schemes-btn--secondary">Cancel</a>
                    <button type="submit" class="schemes-btn schemes-btn--primary">Create Scheme</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
