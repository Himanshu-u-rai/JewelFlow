@php
    $typeLabels = [
        'gold_savings' => 'Gold Savings',
        'festival_sale' => 'Festival Sale',
        'discount_offer' => 'Discount Offer',
    ];
@endphp

<x-app-layout>
    <x-page-header
        class="customers-page-header schemes-page-header schemes-form-header"
        title="Edit Scheme"
        :subtitle="$scheme->name"
    >
        <x-slot:actions>
            <a href="{{ route('schemes.show', $scheme) }}" class="customers-row-action schemes-header-action schemes-header-action--neutral schemes-create-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" />
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span class="schemes-create-back-label-full">Back to Scheme</span>
                <span class="schemes-create-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div
        class="content-inner schemes-form-page"
        x-data="{ type: @js(old('type', $scheme->type)), appliesTo: @js(old('applies_to', $scheme->applies_to ?? 'all_items')) }"
    >
        @if($errors->any())
            <div class="schemes-form-errors">
                <strong>Please review the highlighted fields.</strong>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('schemes.update', $scheme) }}" class="schemes-form-shell">
            @csrf
            @method('PUT')

            <div class="schemes-form-layout">
                <main class="schemes-form-main">
                    <section class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Scheme Basics</h2>
                                <p>Update the customer-facing name, type, and description.</p>
                            </div>
                        </div>

                        <div class="schemes-form-grid schemes-form-grid--two">
                            <div class="schemes-field">
                                <label for="name">Scheme Name <span>*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name', $scheme->name) }}" required class="schemes-input @error('name') is-error @enderror">
                                @error('name')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="type">Type <span>*</span></label>
                                <select name="type" id="type" x-model="type" required class="schemes-select @error('type') is-error @enderror">
                                    <option value="gold_savings">Gold Savings Scheme</option>
                                    <option value="festival_sale">Festival Sale</option>
                                    <option value="discount_offer">Discount Offer</option>
                                </select>
                                @error('type')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field schemes-field--full">
                                <label for="description">Description</label>
                                <textarea name="description" id="description" rows="3" class="schemes-textarea @error('description') is-error @enderror">{{ old('description', $scheme->description) }}</textarea>
                                @error('description')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Schedule</h2>
                                <p>Control when the scheme is available.</p>
                            </div>
                        </div>

                        <div class="schemes-form-grid schemes-form-grid--two">
                            <div class="schemes-field">
                                <label for="start_date">Start Date <span>*</span></label>
                                <input type="date" name="start_date" id="start_date" value="{{ old('start_date', $scheme->start_date?->format('Y-m-d')) }}" required class="schemes-input @error('start_date') is-error @enderror">
                                @error('start_date')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="end_date">End Date</label>
                                <input type="date" name="end_date" id="end_date" value="{{ old('end_date', $scheme->end_date?->format('Y-m-d')) }}" class="schemes-input @error('end_date') is-error @enderror">
                                @error('end_date')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section x-show="type === 'gold_savings'" x-cloak class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Gold Savings Settings</h2>
                                <p>Installment count and maturity bonus rules.</p>
                            </div>
                        </div>

                        <div class="schemes-form-grid schemes-form-grid--two">
                            <div class="schemes-field">
                                <label for="total_installments">Total Installments</label>
                                <input type="number" name="total_installments" id="total_installments" value="{{ old('total_installments', $scheme->total_installments ?? 11) }}" min="1" max="36" class="schemes-input @error('total_installments') is-error @enderror">
                                @error('total_installments')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="bonus_month_value">Bonus Amount (₹)</label>
                                <input type="number" name="bonus_month_value" id="bonus_month_value" value="{{ old('bonus_month_value', $scheme->bonus_month_value) }}" step="0.01" min="0" class="schemes-input @error('bonus_month_value') is-error @enderror">
                                @error('bonus_month_value')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section x-show="type !== 'gold_savings'" x-cloak class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Offer Settings</h2>
                                <p>Discount value, eligibility, and checkout behavior.</p>
                            </div>
                        </div>

                        <div class="schemes-form-grid schemes-form-grid--three">
                            <div class="schemes-field">
                                <label for="discount_type">Discount Type</label>
                                <select name="discount_type" id="discount_type" class="schemes-select @error('discount_type') is-error @enderror">
                                    <option value="">None</option>
                                    <option value="percentage" {{ old('discount_type', $scheme->discount_type) === 'percentage' ? 'selected' : '' }}>Percentage</option>
                                    <option value="flat" {{ old('discount_type', $scheme->discount_type) === 'flat' ? 'selected' : '' }}>Flat (₹)</option>
                                </select>
                                @error('discount_type')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="discount_value">Discount Value</label>
                                <input type="number" name="discount_value" id="discount_value" value="{{ old('discount_value', $scheme->discount_value) }}" step="0.01" min="0" class="schemes-input @error('discount_value') is-error @enderror">
                                @error('discount_value')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="max_discount_amount">Max Discount (₹)</label>
                                <input type="number" name="max_discount_amount" id="max_discount_amount" value="{{ old('max_discount_amount', $scheme->max_discount_amount) }}" step="0.01" min="0" class="schemes-input @error('max_discount_amount') is-error @enderror">
                                @error('max_discount_amount')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="min_purchase_amount">Min Purchase (₹)</label>
                                <input type="number" name="min_purchase_amount" id="min_purchase_amount" value="{{ old('min_purchase_amount', $scheme->min_purchase_amount) }}" step="0.01" min="0" class="schemes-input @error('min_purchase_amount') is-error @enderror">
                                @error('min_purchase_amount')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="priority">Priority</label>
                                <input type="number" name="priority" id="priority" value="{{ old('priority', $scheme->priority ?? 100) }}" min="1" max="1000" class="schemes-input @error('priority') is-error @enderror">
                                @error('priority')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="applies_to">Applies To</label>
                                <select name="applies_to" id="applies_to" x-model="appliesTo" class="schemes-select @error('applies_to') is-error @enderror">
                                    <option value="all_items">All items</option>
                                    <option value="category">Specific category</option>
                                    <option value="sub_category">Specific sub-category</option>
                                </select>
                                @error('applies_to')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div x-show="appliesTo !== 'all_items'" x-cloak class="schemes-field">
                                <label for="applies_to_value">Target Value</label>
                                <input type="text" name="applies_to_value" id="applies_to_value" value="{{ old('applies_to_value', $scheme->applies_to_value) }}" placeholder="e.g. Rings" class="schemes-input @error('applies_to_value') is-error @enderror">
                                @error('applies_to_value')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="max_uses_per_customer">Max Uses / Customer</label>
                                <input type="number" name="max_uses_per_customer" id="max_uses_per_customer" value="{{ old('max_uses_per_customer', $scheme->max_uses_per_customer) }}" min="1" class="schemes-input @error('max_uses_per_customer') is-error @enderror">
                                @error('max_uses_per_customer')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <div class="schemes-toggle-grid">
                            <label class="schemes-toggle-row">
                                <input type="hidden" name="auto_apply" value="0">
                                <input type="checkbox" name="auto_apply" value="1" {{ old('auto_apply', $scheme->auto_apply) ? 'checked' : '' }}>
                                <span>
                                    <strong>Auto apply when eligible</strong>
                                    <small>Use when the offer should apply automatically at checkout.</small>
                                </span>
                            </label>

                            <label class="schemes-toggle-row">
                                <input type="hidden" name="stackable" value="0">
                                <input type="checkbox" name="stackable" value="1" {{ old('stackable', $scheme->stackable) ? 'checked' : '' }}>
                                <span>
                                    <strong>Allow stacking</strong>
                                    <small>Keep available if the shop combines offers later.</small>
                                </span>
                            </label>
                        </div>
                    </section>
                </main>

                <aside class="schemes-form-side">
                    <section class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Publish State</h2>
                                <p>Controls whether this scheme can be used.</p>
                            </div>
                        </div>

                        <label class="schemes-toggle-row schemes-toggle-row--single">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $scheme->is_active) ? 'checked' : '' }}>
                            <span>
                                <strong>Active</strong>
                                <small>Disable this to stop new use without deleting history.</small>
                            </span>
                        </label>

                        <dl class="schemes-side-list">
                            <div>
                                <dt>Current type</dt>
                                <dd>{{ $typeLabels[$scheme->type] ?? $scheme->type }}</dd>
                            </div>
                            <div>
                                <dt>Running now</dt>
                                <dd>{{ $scheme->isRunning() ? 'Yes' : 'No' }}</dd>
                            </div>
                        </dl>
                    </section>

                    <section class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Terms</h2>
                                <p>Customer-facing terms or internal conditions.</p>
                            </div>
                        </div>

                        <div class="schemes-field">
                            <label for="terms">Terms & Conditions</label>
                            <textarea name="terms" id="terms" rows="7" class="schemes-textarea @error('terms') is-error @enderror">{{ old('terms', $scheme->terms) }}</textarea>
                            @error('terms')<p class="schemes-field-error">{{ $message }}</p>@enderror
                        </div>
                    </section>
                </aside>
            </div>

            <div class="schemes-form-actions">
                <a href="{{ route('schemes.show', $scheme) }}" class="schemes-btn schemes-btn--secondary">Cancel</a>
                <button type="submit" class="schemes-btn schemes-btn--primary">Update Scheme</button>
            </div>
        </form>
    </div>
</x-app-layout>
