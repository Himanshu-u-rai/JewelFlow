<x-app-layout>
    <x-page-header class="customers-create-header">
        <h1 class="page-title">Add Customer</h1>
        <div class="page-actions">
            <a href="{{ route('customers.index') }}"
               class="customers-create-back-btn"
               aria-label="Back to customers">
                <svg class="w-4 h-4 customers-create-back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <span>Back to Customers</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner customers-create-page">
        <form method="POST" action="{{ route('customers.store') }}" class="customers-create-form-card">
            @csrf

            <div class="customers-create-card-head">
                <div>
                    <h2>Customer details</h2>
                    <p>Only first and last name are required. Contact details and dates can be completed now or later.</p>
                </div>
                <span class="customers-create-required">Required fields marked *</span>
            </div>

            <div class="customers-create-form-grid">
                <div class="customers-create-field">
                    <label for="first_name">First name <span>*</span></label>
                    <input type="text"
                           name="first_name"
                           id="first_name"
                           value="{{ old('first_name') }}"
                           required
                           class="@error('first_name') is-invalid @enderror"
                           autocomplete="given-name">
                    @error('first_name')
                        <p class="customers-create-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="customers-create-field">
                    <label for="last_name">Last name <span>*</span></label>
                    <input type="text"
                           name="last_name"
                           id="last_name"
                           value="{{ old('last_name') }}"
                           required
                           class="@error('last_name') is-invalid @enderror"
                           autocomplete="family-name">
                    @error('last_name')
                        <p class="customers-create-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="customers-create-field">
                    <label for="mobile">Mobile number <small>Optional</small></label>
                    <input type="tel"
                           name="mobile"
                           id="mobile"
                           value="{{ old('mobile') }}"
                           pattern="[0-9]{10}"
                           maxlength="10"
                           inputmode="numeric"
                           placeholder="10-digit number"
                           class="@error('mobile') is-invalid @enderror"
                           autocomplete="tel">
                    @error('mobile')
                        <p class="customers-create-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="customers-create-field">
                    <label for="email">Email <small>Optional</small></label>
                    <input type="email"
                           name="email"
                           id="email"
                           value="{{ old('email') }}"
                           placeholder="customer@example.com"
                           class="@error('email') is-invalid @enderror"
                           autocomplete="email">
                    @error('email')
                        <p class="customers-create-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="customers-create-field customers-create-field--wide">
                    <label for="address">Address <small>Optional</small></label>
                    <textarea name="address"
                              id="address"
                              rows="4"
                              placeholder="Billing address or delivery note"
                              class="@error('address') is-invalid @enderror"
                              autocomplete="street-address">{{ old('address') }}</textarea>
                    @error('address')
                        <p class="customers-create-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="customers-create-field customers-create-field--notes">
                    <label for="notes">Notes <small>Optional</small></label>
                    <textarea name="notes"
                              id="notes"
                              rows="4"
                              placeholder="Internal notes about preferences, sizing, or reminders"
                              class="@error('notes') is-invalid @enderror">{{ old('notes') }}</textarea>
                    @error('notes')
                        <p class="customers-create-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="customers-create-date-group">
                    <div class="customers-create-date-head">
                        <h3>Occasion dates</h3>
                        <p>Used for reminders and customer follow-up. Leave blank when unknown.</p>
                    </div>

                    <div class="customers-create-date-grid">
                        <div class="customers-create-field">
                            <label for="date_of_birth">Date of birth <small>Optional</small></label>
                            <input type="date"
                                   name="date_of_birth"
                                   id="date_of_birth"
                                   value="{{ old('date_of_birth') }}"
                                   max="{{ now()->subDay()->toDateString() }}"
                                   class="@error('date_of_birth') is-invalid @enderror">
                            @error('date_of_birth')
                                <p class="customers-create-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="customers-create-field">
                            <label for="anniversary_date">Anniversary <small>Optional</small></label>
                            <input type="date"
                                   name="anniversary_date"
                                   id="anniversary_date"
                                   value="{{ old('anniversary_date') }}"
                                   class="@error('anniversary_date') is-invalid @enderror">
                            @error('anniversary_date')
                                <p class="customers-create-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="customers-create-field customers-create-date-last">
                            <label for="wedding_date">Wedding anniversary <small>Optional</small></label>
                            <input type="date"
                                   name="wedding_date"
                                   id="wedding_date"
                                   value="{{ old('wedding_date') }}"
                                   class="@error('wedding_date') is-invalid @enderror">
                            @error('wedding_date')
                                <p class="customers-create-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="customers-create-foot">
                <div class="customers-create-note">
                    Mobile number must be unique for each customer.
                </div>
                <div class="customers-create-actions">
                    <a href="{{ route('customers.index') }}" class="customers-create-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="customers-create-primary">
                        Save Customer
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
