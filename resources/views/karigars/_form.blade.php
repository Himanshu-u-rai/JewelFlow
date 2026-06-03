@php
    $k = $karigar ?? null;
@endphp

<style>
    .kf-shell {
        --kf-border: #e7ebf1;
        --kf-border-soft: #eef1f6;
        --kf-border-strong: #d9dfe8;
        --kf-ink: #0f172a;
        --kf-ink-2: #3d4861;
        --kf-muted: #6a7588;
        --kf-accent: #0d9488;
        --kf-accent-deep: #0f766e;
        --kf-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 12px 28px -16px rgba(16, 24, 40, .16);
        --kf-ease: cubic-bezier(0.23, 1, 0.32, 1);
        max-width: 1180px;
    }

    .kf-card {
        border: 1px solid var(--kf-border);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: var(--kf-shadow);
    }

    @media (prefers-reduced-motion: no-preference) {
        .kf-card { animation: kfRise .5s var(--kf-ease) both; }
        @keyframes kfRise {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    }

    .kf-form { padding: 24px 26px; }

    /* Full-width, multi-column field grid: fields flow across the whole width
       (≈4 columns on desktop) so the form stays a few short rows, not a tall
       scrolling column. */
    .kf-fields {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 16px 22px;
        align-items: start;
    }

    .kf-group {
        grid-column: 1 / -1;
        margin: 8px 0 0;
        padding-top: 16px;
        border-top: 1px solid var(--kf-border-soft);
        color: var(--kf-ink);
        font-size: 12.5px;
        font-weight: 650;
    }

    .kf-group--first {
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
    }

    .kf-field { display: block; min-width: 0; }
    .kf-field--full { grid-column: 1 / -1; }
    .kf-field--wide { grid-column: span 2; }

    .kf-label {
        display: block;
        margin-bottom: 7px;
        color: var(--kf-ink-2);
        font-size: 12.5px;
        font-weight: 600;
    }

    .kf-input {
        width: 100%;
        border: 1px solid var(--kf-border-strong);
        border-radius: 12px;
        background: #f4f6fa;
        color: var(--kf-ink);
        font-size: 14px;
        min-height: 44px;
        padding: 0 13px;
        transition: border-color .16s var(--kf-ease), box-shadow .16s var(--kf-ease), background-color .16s var(--kf-ease);
    }

    textarea.kf-input {
        min-height: 64px;
        padding: 10px 13px;
        resize: vertical;
    }

    .kf-input::placeholder { color: #9aa6b8; }

    .kf-input:focus {
        border-color: var(--kf-accent-deep);
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .13);
        outline: none;
    }

    .kf-input.kf-mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        text-transform: uppercase;
    }

    .kf-hint {
        margin-top: 6px;
        color: var(--kf-muted);
        font-size: 11.5px;
        line-height: 1.5;
    }

    .kf-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 22px;
        padding-top: 20px;
        border-top: 1px solid var(--kf-border-soft);
    }

    .kf-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        border-radius: 12px;
        border: 1px solid var(--kf-accent-deep);
        background: var(--kf-accent-deep);
        padding: 10px 18px;
        color: #ffffff;
        font-size: 14px;
        font-weight: 650;
        box-shadow: 0 1px 2px rgba(15, 118, 110, .22);
        cursor: pointer;
        transition: background-color .16s var(--kf-ease), transform .16s var(--kf-ease);
    }

    .kf-submit:hover { background: #115e56; }
    .kf-submit:active { transform: scale(.98); }

    .kf-cancel {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 44px;
        border-radius: 12px;
        border: 1px solid var(--kf-border-strong);
        background: #ffffff;
        padding: 10px 16px;
        color: var(--kf-ink-2);
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .16s var(--kf-ease);
    }

    .kf-cancel:hover { background: #f7f9fc; }

    @media (max-width: 640px) {
        .kf-form { padding: 18px; }
        .kf-fields { grid-template-columns: 1fr; }
        .kf-field--wide { grid-column: 1 / -1; }
        .kf-actions { flex-direction: column; align-items: stretch; }
        .kf-submit, .kf-cancel { width: 100%; }
    }
</style>

<div class="kf-fields">
    <p class="kf-group kf-group--first">Profile</p>

    <label class="kf-field">
        <span class="kf-label">Name *</span>
        <input type="text" name="name" value="{{ old('name', $k?->name) }}" required class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">Contact Person</span>
        <input type="text" name="contact_person" value="{{ old('contact_person', $k?->contact_person) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">Mobile</span>
        <input type="text" name="mobile" value="{{ old('mobile', $k?->mobile) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">Email</span>
        <input type="email" name="email" value="{{ old('email', $k?->email) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">GST Number</span>
        <input type="text" name="gst_number" value="{{ old('gst_number', $k?->gst_number) }}" class="kf-input kf-mono">
    </label>

    <label class="kf-field">
        <span class="kf-label">PAN</span>
        <input type="text" name="pan_number" value="{{ old('pan_number', $k?->pan_number) }}" class="kf-input kf-mono">
    </label>

    <p class="kf-group">Address</p>

    <label class="kf-field kf-field--full">
        <span class="kf-label">Address</span>
        <input type="text" name="address" value="{{ old('address', $k?->address) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">City</span>
        <input type="text" name="city" value="{{ old('city', $k?->city) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">State</span>
        <input type="text" name="state" value="{{ old('state', $k?->state) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">PIN code</span>
        <input type="text" name="pincode" value="{{ old('pincode', $k?->pincode) }}" class="kf-input">
    </label>

    <p class="kf-group">Billing defaults</p>

    <label class="kf-field">
        <span class="kf-label">Default Wastage %</span>
        <input type="number" step="0.01" min="0" max="50" name="default_wastage_percent" value="{{ old('default_wastage_percent', $k?->default_wastage_percent) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">Default Making/g (₹)</span>
        <input type="number" step="0.01" min="0" name="default_making_per_gram" value="{{ old('default_making_per_gram', $k?->default_making_per_gram) }}" class="kf-input">
    </label>

    <label class="kf-field">
        <span class="kf-label">Opening Balance (₹)</span>
        <input type="number" step="0.01" name="opening_balance" value="{{ old('opening_balance', $k?->opening_balance ?? 0) }}" class="kf-input">
        <p class="kf-hint">Positive = we owe the karigar.</p>
    </label>

    <label class="kf-field">
        <span class="kf-label">Opening Balance Date</span>
        <input type="date" name="opening_balance_at" value="{{ old('opening_balance_at', $k?->opening_balance_at?->toDateString()) }}" class="kf-input">
    </label>

    <label class="kf-field kf-field--full">
        <span class="kf-label">Notes</span>
        <textarea name="notes" rows="2" class="kf-input">{{ old('notes', $k?->notes) }}</textarea>
    </label>
</div>
