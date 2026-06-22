<x-app-layout>
    <x-page-header
        class="customers-page-header schemes-page-header schemes-form-header"
        title="Enroll Customer"
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

    <div class="content-inner schemes-form-page schemes-enroll-page">
        @if($errors->any())
            <div class="schemes-form-errors">
                <strong>Please review the enrollment details.</strong>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('schemes.enroll', $scheme) }}" class="schemes-form-shell">
            @csrf

            <div class="schemes-form-layout schemes-enroll-layout">
                <main class="schemes-form-main">
                    <section class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Enrollment Details</h2>
                                <p>Select the customer and monthly contribution for this gold savings plan.</p>
                            </div>
                        </div>

                        <div class="schemes-form-grid schemes-form-grid--two">
                            <div class="schemes-field schemes-field--full">
                                <label for="customer_id">Customer <span>*</span></label>
                                <select name="customer_id" id="customer_id" required class="schemes-select @error('customer_id') is-error @enderror">
                                    <option value="">Select customer...</option>
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer->id }}" {{ old('customer_id') == $customer->id ? 'selected' : '' }}>
                                            {{ $customer->name }} — {{ $customer->mobile }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('customer_id')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label for="monthly_amount">Monthly Amount (₹) <span>*</span></label>
                                <input type="number" name="monthly_amount" id="monthly_amount" value="{{ old('monthly_amount') }}" step="0.01" min="100" required class="schemes-input @error('monthly_amount') is-error @enderror">
                                @error('monthly_amount')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="schemes-field">
                                <label>Installments</label>
                                <div class="schemes-readonly-field">{{ $scheme->total_installments ?? 11 }} months</div>
                            </div>

                            <div class="schemes-field schemes-field--full">
                                <label for="notes">Notes</label>
                                <textarea name="notes" id="notes" rows="3" class="schemes-textarea @error('notes') is-error @enderror">{{ old('notes') }}</textarea>
                                @error('notes')<p class="schemes-field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <label class="schemes-terms-confirm">
                            <input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }} required>
                            <span>I confirm the customer has accepted the scheme terms and conditions.</span>
                        </label>
                        @error('accept_terms')<p class="schemes-field-error">{{ $message }}</p>@enderror
                    </section>
                </main>

                <aside class="schemes-form-side">
                    <section class="schemes-card schemes-form-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Scheme Summary</h2>
                                <p>Use this to confirm the plan before enrollment.</p>
                            </div>
                        </div>

                        <dl class="schemes-side-list">
                            <div>
                                <dt>Scheme</dt>
                                <dd>{{ $scheme->name }}</dd>
                            </div>
                            <div>
                                <dt>Installments</dt>
                                <dd>{{ $scheme->total_installments ?? 11 }}</dd>
                            </div>
                            <div>
                                <dt>Bonus</dt>
                                <dd>{{ $scheme->bonus_month_value ? '₹' . number_format((float) $scheme->bonus_month_value, 2) : 'Equals 1 month' }}</dd>
                            </div>
                            <div>
                                <dt>Starts</dt>
                                <dd>{{ $scheme->start_date?->format('d M Y') ?? 'Not set' }}</dd>
                            </div>
                            <div>
                                <dt>Ends</dt>
                                <dd>{{ $scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open ended' }}</dd>
                            </div>
                        </dl>
                    </section>

                    @if($scheme->terms)
                        <section class="schemes-card schemes-form-card">
                            <div class="schemes-card-head">
                                <div>
                                    <h2>Terms</h2>
                                    <p>Read back to the customer if needed.</p>
                                </div>
                            </div>
                            <p class="schemes-terms-copy">{{ $scheme->terms }}</p>
                        </section>
                    @endif
                </aside>
            </div>

            <div class="schemes-form-actions">
                <a href="{{ route('schemes.show', $scheme) }}" class="schemes-btn schemes-btn--secondary">Cancel</a>
                <button type="submit" class="schemes-btn schemes-btn--primary">Enroll Customer</button>
            </div>
        </form>
    </div>
</x-app-layout>
