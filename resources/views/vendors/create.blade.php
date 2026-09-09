<x-app-layout>
    <style>
        .vendors-create-page {
            --vendors-create-border: #e2e8f0;
            --vendors-create-border-strong: #cbd5e1;
            --vendors-create-surface: #ffffff;
            --vendors-create-surface-soft: #f8fafc;
            --vendors-create-text: #0f172a;
            --vendors-create-text-soft: #64748b;
            --vendors-create-accent: #b45309;
            --vendors-create-accent-hover: #92400e;
            --vendors-create-accent-soft: #fff7ed;
            --vendors-create-shadow: none;
        }

        .vendors-create-page .vendors-create-shell {
            max-width: 1240px;
            margin: 0 auto;
        }

        .vendors-create-page .vendors-create-intro,
        .vendors-create-page .vendors-create-card {
            border: 1px solid var(--vendors-create-border);
            border-radius: 16px;
            background: var(--vendors-create-surface);
            box-shadow: var(--vendors-create-shadow);
        }

        .vendors-create-page .vendors-create-intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            margin-bottom: 16px;
        }

        .vendors-create-page .vendors-create-kicker {
            margin: 0 0 6px;
            color: var(--vendors-create-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .vendors-create-page .vendors-create-title {
            margin: 0;
            color: var(--vendors-create-text);
            font-size: 24px;
            font-weight: 700;
            line-height: 1.15;
        }

        .vendors-create-page .vendors-create-copy {
            margin: 6px 0 0;
            max-width: 760px;
            color: var(--vendors-create-text-soft);
            font-size: 14px;
            line-height: 1.5;
        }

        .vendors-create-page .vendors-create-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid #f3dcb6;
            background: var(--vendors-create-accent-soft);
            color: var(--vendors-create-accent);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .vendors-create-page .vendors-create-card {
            overflow: hidden;
        }

        .vendors-create-page .vendors-create-errors {
            margin-bottom: 18px;
            border: 1px solid #fecaca;
            border-radius: 18px;
            background: #fef2f2;
            padding: 14px 16px;
            color: #b91c1c;
        }

        .vendors-create-page .vendors-create-errors-title {
            margin: 0 0 6px;
            font-size: 14px;
            font-weight: 700;
        }

        .vendors-create-page .vendors-create-errors ul {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            line-height: 1.6;
        }

        .vendors-create-page .vendors-create-form {
            padding: 24px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .vendors-create-page .vendors-create-section {
            border: 1px solid var(--vendors-create-border);
            border-radius: 14px;
            background: #fbfcfe;
            padding: 22px;
            min-width: 0;
        }

        .vendors-create-page .vendors-create-section + .vendors-create-section {
            margin-top: 0;
            padding-top: 22px;
            border-top: 0;
        }

        .vendors-create-page .vendors-create-section-head {
            margin-bottom: 14px;
        }

        .vendors-create-page .vendors-create-section-title {
            margin: 0;
            color: var(--vendors-create-text);
            font-size: 19px;
            font-weight: 700;
        }

        .vendors-create-page .vendors-create-section-copy {
            margin: 6px 0 0;
            color: var(--vendors-create-text-soft);
            font-size: 14px;
            line-height: 1.6;
        }

        .vendors-create-page .vendors-create-grid {
            display: grid;
            gap: 18px;
        }

        .vendors-create-page .vendors-create-grid--two {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .vendors-create-page .vendors-create-grid--single {
            grid-template-columns: 1fr;
        }

        .vendors-create-page .vendors-create-section--wide,
        .vendors-create-page .vendors-create-actions {
            grid-column: 1 / -1;
        }

        .vendors-create-page .vendors-create-field {
            min-width: 0;
        }

        .vendors-create-page .vendors-create-field--full {
            grid-column: 1 / -1;
        }

        .vendors-create-page .vendors-create-label {
            display: block;
            margin-bottom: 8px;
            color: var(--vendors-create-text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
        }

        .vendors-create-page .vendors-create-label-meta {
            color: var(--vendors-create-text-soft);
            font-size: 12px;
            font-weight: 500;
        }

        .vendors-create-page .vendors-create-required {
            color: #dc2626;
        }

        .vendors-create-page .vendors-create-input,
        .vendors-create-page .vendors-create-textarea {
            display: block;
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--vendors-create-border-strong);
            background: var(--vendors-create-surface-soft);
            color: var(--vendors-create-text);
            font-size: 14px;
            line-height: 1.4;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .vendors-create-page .vendors-create-input {
            min-height: 48px;
            padding: 0 14px;
        }

        .vendors-create-page .vendors-create-textarea {
            min-height: 112px;
            resize: vertical;
            padding: 12px 14px;
        }

        .vendors-create-page .vendors-create-input::placeholder,
        .vendors-create-page .vendors-create-textarea::placeholder {
            color: #8a99b1;
        }

        .vendors-create-page .vendors-create-input:focus,
        .vendors-create-page .vendors-create-textarea:focus {
            outline: none;
            border-color: rgba(245, 158, 11, 0.58);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.16);
        }

        .vendors-create-page .vendors-create-input.is-error,
        .vendors-create-page .vendors-create-textarea.is-error {
            border-color: #ef4444;
            background: #fff;
        }

        .vendors-create-page .vendors-create-error {
            margin: 6px 0 0;
            color: #dc2626;
            font-size: 12px;
            font-weight: 500;
            line-height: 1.5;
        }

        .vendors-create-page .vendors-create-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: -2px;
            padding-top: 0;
            border-top: 0;
        }

        .vendors-create-page .vendors-create-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 12px;
            border: 1px solid var(--vendors-create-border);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
            white-space: nowrap;
        }

        .vendors-create-page .vendors-create-btn:hover {
            transform: none;
        }

        .vendors-create-page .vendors-create-btn--ghost {
            background: #fff;
            color: var(--vendors-create-text);
        }

        .vendors-create-page .vendors-create-btn--ghost:hover {
            border-color: #f3dcb6;
            background: #fff7ed;
            color: var(--vendors-create-accent-hover);
        }

        .vendors-create-page .vendors-create-btn--primary {
            border-color: var(--vendors-create-accent);
            background: var(--vendors-create-accent);
            color: #fff;
            box-shadow: none;
        }

        .vendors-create-page .vendors-create-btn--primary:hover {
            background: var(--vendors-create-accent-hover);
            border-color: var(--vendors-create-accent-hover);
        }

        @media (max-width: 1100px) {
            .vendors-create-page .vendors-create-shell {
                max-width: 980px;
            }

            .vendors-create-page .vendors-create-form {
                grid-template-columns: 1fr;
            }

            .vendors-create-page .vendors-create-section,
            .vendors-create-page .vendors-create-section--wide,
            .vendors-create-page .vendors-create-actions {
                grid-column: auto;
            }
        }

        @media (max-width: 767px) {
            .vendors-create-page .vendors-create-intro {
                padding: 16px;
                border-radius: 20px;
                margin-bottom: 14px;
            }

            .vendors-create-page .vendors-create-title {
                font-size: 20px;
            }

            .vendors-create-page .vendors-create-copy {
                display: none;
            }

            .vendors-create-page .vendors-create-pill {
                min-height: 32px;
                padding: 0 10px;
                font-size: 11px;
            }

            .vendors-create-page .vendors-create-form {
                padding: 16px;
                gap: 16px;
            }

            .vendors-create-page .vendors-create-section {
                padding: 16px;
                border-radius: 18px;
            }

            .vendors-create-page .vendors-create-section-title {
                font-size: 17px;
            }

            .vendors-create-page .vendors-create-section-copy {
                font-size: 13px;
            }

            .vendors-create-page .vendors-create-grid--two {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .vendors-create-page .vendors-create-label {
                margin-bottom: 6px;
                font-size: 13px;
            }

            .vendors-create-page .vendors-create-label-meta {
                font-size: 11px;
            }

            .vendors-create-page .vendors-create-input,
            .vendors-create-page .vendors-create-textarea {
                border-radius: 14px;
                font-size: 13px;
            }

            .vendors-create-page .vendors-create-input {
                min-height: 44px;
                padding: 0 12px;
            }

            .vendors-create-page .vendors-create-textarea {
                min-height: 96px;
                padding: 10px 12px;
            }

            .vendors-create-page .vendors-create-actions {
                flex-direction: column-reverse;
                align-items: stretch;
                gap: 8px;
                margin-top: 0;
                padding-top: 0;
            }

            .vendors-create-page .vendors-create-btn {
                width: 100%;
                min-height: 44px;
                border-radius: 14px;
                font-size: 13px;
            }
        }
    </style>

    <x-page-header class="vendors-create-header ops-treatment-header">
        <h1 class="page-title">Add Vendor</h1>
        <div class="page-actions">
            <a href="{{ route('vendors.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-medium transition-colors vendors-create-back-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                <span class="vendors-create-back-label-full">Back to Vendors</span>
                <span class="vendors-create-back-label-short">Back</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page vendors-create-page">
        <div class="vendors-create-shell">
            <section class="vendors-create-intro">
                <div>
                    <p class="vendors-create-kicker">Directory</p>
                    <h2 class="vendors-create-title">Vendor Information</h2>
                    <p class="vendors-create-copy">Add supplier contact and registration details in a clean format that stays easy to complete on mobile.</p>
                </div>
                <span class="vendors-create-pill">1 required field</span>
            </section>

            @if($errors->any())
                <div class="vendors-create-errors">
                    <p class="vendors-create-errors-title">Please review the highlighted fields.</p>
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="vendors-create-card">
                <form method="POST" action="{{ route('vendors.store') }}" class="vendors-create-form">
                    @csrf

                    <section class="vendors-create-section">
                        <div class="vendors-create-section-head">
                            <h3 class="vendors-create-section-title">Business Details</h3>
                            <p class="vendors-create-section-copy">Core supplier identity and the main contact you work with.</p>
                        </div>

                        <div class="vendors-create-grid vendors-create-grid--two">
                            <div class="vendors-create-field">
                                <label for="name" class="vendors-create-label">Business Name <span class="vendors-create-required">*</span></label>
                                <input
                                    type="text"
                                    name="name"
                                    id="name"
                                    value="{{ old('name') }}"
                                    required
                                    class="vendors-create-input @error('name') is-error @enderror"
                                >
                                @error('name')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-create-field">
                                <label for="contact_person" class="vendors-create-label">Contact Person <span class="vendors-create-label-meta">(Optional)</span></label>
                                <input
                                    type="text"
                                    name="contact_person"
                                    id="contact_person"
                                    value="{{ old('contact_person') }}"
                                    class="vendors-create-input @error('contact_person') is-error @enderror"
                                >
                                @error('contact_person')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-create-field">
                                <label for="mobile" class="vendors-create-label">Mobile</label>
                                <input
                                    type="tel"
                                    inputmode="numeric"
                                    maxlength="10"
                                    pattern="[0-9]{10}"
                                    placeholder="10-digit mobile"
                                    name="mobile"
                                    id="mobile"
                                    value="{{ old('mobile') }}"
                                    class="vendors-create-input @error('mobile') is-error @enderror"
                                >
                                @error('mobile')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-create-field">
                                <label for="email" class="vendors-create-label">Email</label>
                                <input
                                    type="email"
                                    name="email"
                                    id="email"
                                    value="{{ old('email') }}"
                                    class="vendors-create-input @error('email') is-error @enderror"
                                >
                                @error('email')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="vendors-create-section">
                        <div class="vendors-create-section-head">
                            <h3 class="vendors-create-section-title">Location & Tax</h3>
                            <p class="vendors-create-section-copy">Store the place and GST details you may need during purchasing and billing.</p>
                        </div>

                        <div class="vendors-create-grid vendors-create-grid--two">
                            <div class="vendors-create-field">
                                <label for="city" class="vendors-create-label">City</label>
                                <input
                                    type="text"
                                    name="city"
                                    id="city"
                                    value="{{ old('city') }}"
                                    class="vendors-create-input @error('city') is-error @enderror"
                                >
                                @error('city')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-create-field">
                                <label for="state" class="vendors-create-label">State</label>
                                <input
                                    type="text"
                                    name="state"
                                    id="state"
                                    value="{{ old('state') }}"
                                    class="vendors-create-input @error('state') is-error @enderror"
                                >
                                @error('state')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-create-field vendors-create-field--full">
                                <label for="gst_number" class="vendors-create-label">GST Number</label>
                                <input
                                    type="text"
                                    name="gst_number"
                                    id="gst_number"
                                    value="{{ old('gst_number') }}"
                                    maxlength="15"
                                    placeholder="e.g. 22AAAAA0000A1Z5"
                                    class="vendors-create-input @error('gst_number') is-error @enderror"
                                >
                                @error('gst_number')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="vendors-create-section vendors-create-section--wide">
                        <div class="vendors-create-section-head">
                            <h3 class="vendors-create-section-title">Address & Notes</h3>
                            <p class="vendors-create-section-copy">Keep any additional address or supplier context available for future reference.</p>
                        </div>

                        <div class="vendors-create-grid vendors-create-grid--two">
                            <div class="vendors-create-field">
                                <label for="address" class="vendors-create-label">Address</label>
                                <textarea
                                    name="address"
                                    id="address"
                                    rows="3"
                                    class="vendors-create-textarea @error('address') is-error @enderror"
                                >{{ old('address') }}</textarea>
                                @error('address')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>

                            <div class="vendors-create-field">
                                <label for="notes" class="vendors-create-label">Notes</label>
                                <textarea
                                    name="notes"
                                    id="notes"
                                    rows="2"
                                    class="vendors-create-textarea @error('notes') is-error @enderror"
                                >{{ old('notes') }}</textarea>
                                @error('notes')<p class="vendors-create-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </section>

                    <div class="vendors-create-actions">
                        <a href="{{ route('vendors.index') }}" class="vendors-create-btn vendors-create-btn--ghost">Cancel</a>
                        <button type="submit" class="vendors-create-btn vendors-create-btn--primary">Save Vendor</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
