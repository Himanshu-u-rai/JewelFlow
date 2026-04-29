<x-app-layout>
    <style>
        .vendors-edit-page {
            --vendors-edit-border: #d8e1ef;
            --vendors-edit-border-strong: #c8d5e7;
            --vendors-edit-surface: #ffffff;
            --vendors-edit-surface-soft: #f7f9fc;
            --vendors-edit-text: #16213d;
            --vendors-edit-text-soft: #64748b;
            --vendors-edit-accent: #0d9488;
            --vendors-edit-accent-soft: rgba(13, 148, 136, 0.1);
            --vendors-edit-shadow: 0 18px 44px rgba(15, 23, 42, 0.06);
        }

        .vendors-edit-page .vendors-edit-shell {
            max-width: 1240px;
            margin: 0 auto;
        }

        .vendors-edit-page .vendors-edit-intro,
        .vendors-edit-page .vendors-edit-card {
            border: 1px solid var(--vendors-edit-border);
            border-radius: 24px;
            background: var(--vendors-edit-surface);
            box-shadow: var(--vendors-edit-shadow);
        }

        .vendors-edit-page .vendors-edit-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            margin-bottom: 16px;
        }

        .vendors-edit-page .vendors-edit-kicker {
            margin: 0 0 6px;
            color: var(--vendors-edit-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .vendors-edit-page .vendors-edit-title {
            margin: 0;
            color: var(--vendors-edit-text);
            font-size: 24px;
            font-weight: 700;
            line-height: 1.15;
        }

        .vendors-edit-page .vendors-edit-copy {
            margin: 6px 0 0;
            max-width: 760px;
            color: var(--vendors-edit-text-soft);
            font-size: 14px;
            line-height: 1.5;
        }

        .vendors-edit-page .vendors-edit-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(13, 148, 136, 0.16);
            background: var(--vendors-edit-accent-soft);
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .vendors-edit-page .vendors-edit-pill--inactive {
            border-color: rgba(148, 163, 184, 0.22);
            background: #eef2f7;
            color: #64748b;
        }

        .vendors-edit-page .vendors-edit-errors {
            margin-bottom: 18px;
            border: 1px solid #fecaca;
            border-radius: 18px;
            background: #fef2f2;
            padding: 14px 16px;
            color: #b91c1c;
        }

        .vendors-edit-page .vendors-edit-errors-title {
            margin: 0 0 6px;
            font-size: 14px;
            font-weight: 700;
        }

        .vendors-edit-page .vendors-edit-errors ul {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            line-height: 1.6;
        }

        .vendors-edit-page .vendors-edit-card {
            overflow: hidden;
        }

        .vendors-edit-page .vendors-edit-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            padding: 24px;
        }

        .vendors-edit-page .vendors-edit-section {
            min-width: 0;
            border: 1px solid #e7edf6;
            border-radius: 22px;
            background: #fbfcfe;
            padding: 22px;
        }

        .vendors-edit-page .vendors-edit-section--wide,
        .vendors-edit-page .vendors-edit-actions {
            grid-column: 1 / -1;
        }

        .vendors-edit-page .vendors-edit-section-head {
            margin-bottom: 14px;
        }

        .vendors-edit-page .vendors-edit-section-title {
            margin: 0;
            color: var(--vendors-edit-text);
            font-size: 19px;
            font-weight: 700;
        }

        .vendors-edit-page .vendors-edit-section-copy {
            margin: 6px 0 0;
            color: var(--vendors-edit-text-soft);
            font-size: 14px;
            line-height: 1.6;
        }

        .vendors-edit-page .vendors-edit-grid {
            display: grid;
            gap: 18px;
        }

        .vendors-edit-page .vendors-edit-grid--two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .vendors-edit-page .vendors-edit-field {
            min-width: 0;
        }

        .vendors-edit-page .vendors-edit-field--full {
            grid-column: 1 / -1;
        }

        .vendors-edit-page .vendors-edit-label {
            display: block;
            margin-bottom: 8px;
            color: var(--vendors-edit-text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
        }

        .vendors-edit-page .vendors-edit-label-meta {
            color: var(--vendors-edit-text-soft);
            font-size: 12px;
            font-weight: 500;
        }

        .vendors-edit-page .vendors-edit-required {
            color: #dc2626;
        }

        .vendors-edit-page .vendors-edit-input,
        .vendors-edit-page .vendors-edit-textarea {
            display: block;
            width: 100%;
            border-radius: 16px;
            border: 1px solid var(--vendors-edit-border-strong);
            background: var(--vendors-edit-surface-soft);
            color: var(--vendors-edit-text);
            font-size: 14px;
            line-height: 1.4;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .vendors-edit-page .vendors-edit-input {
            min-height: 48px;
            padding: 0 14px;
        }

        .vendors-edit-page .vendors-edit-textarea {
            min-height: 112px;
            resize: vertical;
            padding: 12px 14px;
        }

        .vendors-edit-page .vendors-edit-input::placeholder,
        .vendors-edit-page .vendors-edit-textarea::placeholder {
            color: #8a99b1;
        }

        .vendors-edit-page .vendors-edit-input:focus,
        .vendors-edit-page .vendors-edit-textarea:focus {
            outline: none;
            border-color: rgba(13, 148, 136, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1);
        }

        .vendors-edit-page .vendors-edit-input.is-error,
        .vendors-edit-page .vendors-edit-textarea.is-error {
            border-color: #ef4444;
            background: #fff;
        }

        .vendors-edit-page .vendors-edit-error {
            margin: 6px 0 0;
            color: #dc2626;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.5;
        }

        .vendors-edit-page .vendors-edit-status-panel {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 16px 18px;
            border: 1px solid #e7edf6;
            border-radius: 18px;
            background: #fff;
        }

        .vendors-edit-page .vendors-edit-status-title {
            margin: 0;
            color: var(--vendors-edit-text);
            font-size: 15px;
            font-weight: 700;
        }

        .vendors-edit-page .vendors-edit-status-copy {
            margin: 4px 0 0;
            color: var(--vendors-edit-text-soft);
            font-size: 13px;
            line-height: 1.5;
        }

        .vendors-edit-page .vendors-edit-status-toggle {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .vendors-edit-page .vendors-edit-status-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            border-radius: 6px;
            border: 1px solid #b8c6da;
            color: var(--vendors-edit-accent);
            box-shadow: none;
        }

        .vendors-edit-page .vendors-edit-status-toggle input[type="checkbox"]:focus {
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.14);
        }

        .vendors-edit-page .vendors-edit-status-label {
            color: var(--vendors-edit-text);
            font-size: 14px;
            font-weight: 600;
        }

        .vendors-edit-page .vendors-edit-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: -2px;
        }

        .vendors-edit-page .vendors-edit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 16px;
            border: 1px solid var(--vendors-edit-border);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
            white-space: nowrap;
        }

        .vendors-edit-page .vendors-edit-btn:hover {
            transform: translateY(-1px);
        }

        .vendors-edit-page .vendors-edit-btn--ghost {
            background: #fff;
            color: var(--vendors-edit-text);
        }

        .vendors-edit-page .vendors-edit-btn--ghost:hover {
            background: var(--vendors-edit-surface-soft);
        }

        .vendors-edit-page .vendors-edit-btn--primary {
            border-color: var(--vendors-edit-accent);
            background: var(--vendors-edit-accent);
            color: #fff;
            box-shadow: 0 12px 24px rgba(13, 148, 136, 0.16);
        }

        .vendors-edit-page .vendors-edit-btn--primary:hover {
            background: #0f766e;
            border-color: #0f766e;
        }

        @media (max-width: 1100px) {
            .vendors-edit-page .vendors-edit-shell {
                max-width: 980px;
            }

            .vendors-edit-page .vendors-edit-form {
                grid-template-columns: 1fr;
            }

            .vendors-edit-page .vendors-edit-section,
            .vendors-edit-page .vendors-edit-section--wide,
            .vendors-edit-page .vendors-edit-actions {
                grid-column: auto;
            }
        }

        @media (max-width: 767px) {
            .vendors-edit-page .vendors-edit-intro {
                padding: 16px;
                border-radius: 20px;
                margin-bottom: 14px;
            }

            .vendors-edit-page .vendors-edit-title {
                font-size: 20px;
            }

            .vendors-edit-page .vendors-edit-copy {
                display: none;
            }

            .vendors-edit-page .vendors-edit-pill {
                min-height: 32px;
                padding: 0 10px;
                font-size: 11px;
            }

            .vendors-edit-page .vendors-edit-form {
                padding: 16px;
                gap: 16px;
            }

            .vendors-edit-page .vendors-edit-section {
                padding: 16px;
                border-radius: 18px;
            }

            .vendors-edit-page .vendors-edit-section-title {
                font-size: 17px;
            }

            .vendors-edit-page .vendors-edit-section-copy {
                font-size: 13px;
            }

            .vendors-edit-page .vendors-edit-grid--two {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .vendors-edit-page .vendors-edit-label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            .vendors-edit-page .vendors-edit-label-meta {
                font-size: 11px;
            }

            .vendors-edit-page .vendors-edit-input,
            .vendors-edit-page .vendors-edit-textarea {
                border-radius: 14px;
                font-size: 13px;
            }

            .vendors-edit-page .vendors-edit-input {
                min-height: 44px;
                padding: 0 12px;
            }

            .vendors-edit-page .vendors-edit-textarea {
                min-height: 96px;
                padding: 10px 12px;
            }

            .vendors-edit-page .vendors-edit-status-panel {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
                padding: 14px;
                border-radius: 16px;
            }

            .vendors-edit-page .vendors-edit-status-title {
                font-size: 14px;
            }

            .vendors-edit-page .vendors-edit-status-copy,
            .vendors-edit-page .vendors-edit-status-label {
                font-size: 12px;
            }

            .vendors-edit-page .vendors-edit-actions {
                flex-direction: column-reverse;
                align-items: stretch;
                gap: 8px;
                margin-top: 0;
            }

            .vendors-edit-page .vendors-edit-btn {
                width: 100%;
                min-height: 44px;
                border-radius: 14px;
                font-size: 13px;
            }
        }
    </style>

    <x-page-header class="vendors-edit-header ops-treatment-header">
        <h1 class="page-title">Edit Vendor</h1>
        <div class="page-actions">
            <a href="{{ route('vendors.show', $vendor) }}" class="inline-flex items-center px-4 py-2 text-sm font-medium transition-colors vendors-edit-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 19 5 12 12 5" />
                </svg>
                <span class="vendors-edit-back-label-full">Back to Vendor</span>
                <span class="vendors-edit-back-label-short">Back</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page vendors-edit-page">
        <div class="vendors-edit-shell">
            <section class="vendors-edit-intro">
                <div>
                    <p class="vendors-edit-kicker">Directory</p>
                    <h2 class="vendors-edit-title">Edit {{ $vendor->name }}</h2>
                    <p class="vendors-edit-copy">Update supplier details without changing the existing vendor flow or record structure.</p>
                </div>
                <span class="vendors-edit-pill {{ $vendor->is_active ? '' : 'vendors-edit-pill--inactive' }}">
                    {{ $vendor->is_active ? 'Active vendor' : 'Inactive vendor' }}
                </span>
            </section>

            @if($errors->any())
                <div class="vendors-edit-errors">
                    <p class="vendors-edit-errors-title">Please review the highlighted fields.</p>
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="vendors-edit-card">
                <form method="POST" action="{{ route('vendors.update', $vendor) }}" class="vendors-edit-form">
                    @csrf
                    @method('PUT')

                    <section class="vendors-edit-section">
                        <div class="vendors-edit-section-head">
                            <h3 class="vendors-edit-section-title">Business Details</h3>
                            <p class="vendors-edit-section-copy">Core supplier identity and the main contact tied to this vendor record.</p>
                        </div>

                        <div class="vendors-edit-grid vendors-edit-grid--two">
                            <div class="vendors-edit-field">
                                <label for="name" class="vendors-edit-label">Business Name <span class="vendors-edit-required">*</span></label>
                                <input type="text" name="name" id="name" value="{{ old('name', $vendor->name) }}" required class="vendors-edit-input @error('name') is-error @enderror">
                                @error('name')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-edit-field">
                                <label for="contact_person" class="vendors-edit-label">Contact Person <span class="vendors-edit-label-meta">(Optional)</span></label>
                                <input type="text" name="contact_person" id="contact_person" value="{{ old('contact_person', $vendor->contact_person) }}" class="vendors-edit-input @error('contact_person') is-error @enderror">
                                @error('contact_person')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-edit-field">
                                <label for="mobile" class="vendors-edit-label">Mobile</label>
                                <input type="tel" name="mobile" id="mobile" value="{{ old('mobile', $vendor->mobile) }}" class="vendors-edit-input @error('mobile') is-error @enderror">
                                @error('mobile')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-edit-field">
                                <label for="email" class="vendors-edit-label">Email</label>
                                <input type="email" name="email" id="email" value="{{ old('email', $vendor->email) }}" class="vendors-edit-input @error('email') is-error @enderror">
                                @error('email')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="vendors-edit-section">
                        <div class="vendors-edit-section-head">
                            <h3 class="vendors-edit-section-title">Location & Tax</h3>
                            <p class="vendors-edit-section-copy">Keep operational and billing details current for future stock entries and reporting.</p>
                        </div>

                        <div class="vendors-edit-grid vendors-edit-grid--two">
                            <div class="vendors-edit-field">
                                <label for="city" class="vendors-edit-label">City</label>
                                <input type="text" name="city" id="city" value="{{ old('city', $vendor->city) }}" class="vendors-edit-input @error('city') is-error @enderror">
                                @error('city')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-edit-field">
                                <label for="state" class="vendors-edit-label">State</label>
                                <input type="text" name="state" id="state" value="{{ old('state', $vendor->state) }}" class="vendors-edit-input @error('state') is-error @enderror">
                                @error('state')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-edit-field vendors-edit-field--full">
                                <label for="gst_number" class="vendors-edit-label">GST Number</label>
                                <input type="text" name="gst_number" id="gst_number" value="{{ old('gst_number', $vendor->gst_number) }}" maxlength="15" placeholder="e.g. 22AAAAA0000A1Z5" class="vendors-edit-input @error('gst_number') is-error @enderror">
                                @error('gst_number')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="vendors-edit-section vendors-edit-section--wide">
                        <div class="vendors-edit-section-head">
                            <h3 class="vendors-edit-section-title">Address & Notes</h3>
                            <p class="vendors-edit-section-copy">Keep any additional address or vendor-specific context available for your team.</p>
                        </div>

                        <div class="vendors-edit-grid vendors-edit-grid--two">
                            <div class="vendors-edit-field">
                                <label for="address" class="vendors-edit-label">Address</label>
                                <textarea name="address" id="address" rows="3" class="vendors-edit-textarea @error('address') is-error @enderror">{{ old('address', $vendor->address) }}</textarea>
                                @error('address')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-edit-field">
                                <label for="notes" class="vendors-edit-label">Notes</label>
                                <textarea name="notes" id="notes" rows="2" class="vendors-edit-textarea @error('notes') is-error @enderror">{{ old('notes', $vendor->notes) }}</textarea>
                                @error('notes')<p class="vendors-edit-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="vendors-edit-section vendors-edit-section--wide">
                        <div class="vendors-edit-section-head">
                            <h3 class="vendors-edit-section-title">Status</h3>
                            <p class="vendors-edit-section-copy">Control whether this vendor remains active for your ongoing operations.</p>
                        </div>

                        <div class="vendors-edit-status-panel">
                            <div>
                                <p class="vendors-edit-status-title">Vendor Availability</p>
                                <p class="vendors-edit-status-copy">Inactive vendors remain stored but are clearly marked in the directory.</p>
                            </div>

                            <div class="vendors-edit-status-toggle">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $vendor->is_active) ? 'checked' : '' }}>
                                <label for="is_active" class="vendors-edit-status-label">Active vendor</label>
                            </div>
                        </div>
                    </section>

                    <div class="vendors-edit-actions">
                        <a href="{{ route('vendors.show', $vendor) }}" class="vendors-edit-btn vendors-edit-btn--ghost">Cancel</a>
                        <button type="submit" class="vendors-edit-btn vendors-edit-btn--primary">Update Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
