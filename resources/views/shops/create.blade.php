<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Your Shop | JewelFlow</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --gold:#d97706; --gold-deep:#b45309; --gold-soft:#f59e0b;
            --ink:#26221b;
            --muted:#766c5d;            /* warm taupe, readable on cream */
            --line:#ece3d2;             /* warm hairline */
            --field-line:#e2d8c5;
            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        }

        body {
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            /* Warm cream page with a soft gold glow up top, matching the rest of
               the onboarding flow (login, choose-type, plans). */
            background:
                radial-gradient(120% 70% at 50% -8%, rgba(245,158,11,0.16) 0%, rgba(245,158,11,0.05) 32%, transparent 60%),
                radial-gradient(90% 60% at 88% 6%, rgba(251,191,36,0.10) 0%, transparent 50%),
                linear-gradient(180deg, #fdf9f0 0%, #faf4e9 46%, #f7efe1 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: var(--ink);
        }

        /* ---------- Header ---------- */
        .header {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px;
            padding: 18px 32px;
            border-bottom: 1px solid var(--line);
            background: rgba(255,254,251,0.7);
            backdrop-filter: blur(8px);
        }
        .header-brand { display: flex; align-items: center; gap: 10px; }
        .header-brand-mark { width: 30px; height: 30px; }
        .header-brand-text { font-size: 19px; font-weight: 800; color: var(--ink); letter-spacing: -0.3px; }
        .header-brand-text span { color: var(--gold); }
        .header-titles h1 { display: none; }
        .shop-chip {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 700; letter-spacing: .02em;
            color: var(--gold-deep);
            background: linear-gradient(180deg, #fffaf0 0%, #fff4e0 100%);
            border: 1px solid #f4dcae; border-radius: 999px;
            padding: 6px 12px; text-transform: uppercase;
            box-shadow: 0 1px 2px rgba(120,80,20,0.06);
        }

        /* ---------- Layout ---------- */
        .container { max-width: 1180px; margin: 0 auto; padding: 28px 20px 40px; }

        .page-head { text-align: center; margin-bottom: 24px; }
        .page-head h2 {
            font-size: clamp(26px, 4vw, 34px); font-weight: 800; color: var(--ink);
            letter-spacing: -0.6px; text-wrap: balance;
        }
        .page-head p { font-size: 14px; color: var(--muted); margin-top: 6px; }

        .shell {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 22px;
            align-items: start;
        }

        /* ---------- Side rail ---------- */
        .side {
            position: sticky; top: 20px; align-self: start;
            background: #fffefb; border: 1px solid var(--line); border-radius: 18px;
            padding: 22px;
            box-shadow: 0 1px 2px rgba(120,80,20,0.05), 0 18px 42px -24px rgba(120,80,20,0.28);
        }
        .side-title { font-size: 14px; font-weight: 800; color: var(--ink); margin-bottom: 6px; }
        .side-sub { font-size: 13px; color: var(--muted); margin-bottom: 18px; line-height: 1.5; }

        .steps { display: grid; gap: 10px; margin-bottom: 18px; }
        .step { display: flex; gap: 11px; align-items: flex-start; }
        .step-num {
            width: 26px; height: 26px; border-radius: 999px;
            display: grid; place-items: center;
            font-size: 12px; font-weight: 800; color: var(--gold-deep);
            background: rgba(245,158,11,0.14); flex: 0 0 auto;
        }
        .step-text b { display: block; font-size: 13px; color: var(--ink); margin-bottom: 1px; }
        .step-text span { display: block; font-size: 12.5px; color: var(--muted); line-height: 1.4; }

        .note {
            font-size: 12.5px; color: #6b5d44; line-height: 1.5;
            background: linear-gradient(180deg, #fffaf0 0%, #fff5e6 100%);
            border: 1px solid #f4dcae; border-radius: 12px; padding: 12px 14px;
        }

        /* ---------- Main card ---------- */
        .main {
            position: relative; overflow: hidden;
            background: #fffefb; border: 1px solid var(--line); border-radius: 20px;
            box-shadow: 0 1px 2px rgba(120,80,20,0.05), 0 22px 50px -26px rgba(120,80,20,0.28);
        }
        .main::before {
            content: ''; position: absolute; inset: 0 0 auto 0; height: 3px;
            background: linear-gradient(90deg, #fcd34d 0%, #f59e0b 48%, #d97706 100%);
            opacity: 0.9;
        }
        .main-body { padding: 24px 26px 26px; }

        /* ---------- Form sections ---------- */
        .fsection { margin-bottom: 24px; }
        .fsection:last-of-type { margin-bottom: 0; }
        .fsection-title {
            display: flex; align-items: center; gap: 9px;
            font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
            color: var(--gold-deep);
            padding-bottom: 10px; margin-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }
        .fsection-title svg { width: 16px; height: 16px; flex: 0 0 auto; }

        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .field { margin-bottom: 16px; }
        .field:last-child { margin-bottom: 0; }
        .grid-2 .field { margin-bottom: 0; }

        label {
            display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #4a4334;
        }
        label .req { color: var(--gold); margin-left: 2px; }
        label .opt { color: var(--muted); font-weight: 500; font-size: 11.5px; }

        input, select, textarea {
            width: 100%; padding: 11px 13px;
            border: 1px solid var(--field-line); border-radius: 12px;
            font: inherit; font-size: 15px; color: var(--ink);
            background: #fffdfa;
            box-shadow: inset 0 1px 2px rgba(120,80,20,0.04);
            transition: border-color .16s ease, box-shadow .16s ease;
        }
        input::placeholder, textarea::placeholder { color: #b3a892; }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: var(--gold-soft);
            box-shadow: 0 0 0 3px rgba(245,158,11,0.16);
            background: #fff;
        }
        select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23766c5d' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 13px center;
            padding-right: 36px; cursor: pointer;
        }
        .field-hint { font-size: 11.5px; color: var(--muted); margin-top: 5px; line-height: 1.4; }
        .field-hint[data-state="ok"] { color: #15803d; }
        .field-hint[data-state="loading"] { color: var(--gold-deep); }

        /* Locked, non-editable country display. */
        .locked-field {
            display: flex; align-items: center; gap: 8px;
            padding: 11px 13px; border: 1px solid var(--field-line); border-radius: 12px;
            background: #faf4e9; color: var(--muted); font-size: 15px;
        }
        .locked-field svg { width: 15px; height: 15px; color: var(--gold); flex: 0 0 auto; }

        /* ---------- Errors ---------- */
        .errors {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            padding: 14px 16px; border-radius: 14px; margin-bottom: 20px; font-size: 13px;
        }
        .errors ul { list-style: none; margin: 0; padding: 0; }
        .errors li { padding: 3px 0; }
        .errors li:before { content: "• "; color: #dc2626; font-weight: bold; margin-right: 4px; }

        /* ---------- Actions ---------- */
        .form-actions {
            display: flex; justify-content: flex-end; align-items: center; gap: 14px;
            margin-top: 26px; padding-top: 20px; border-top: 1px solid var(--line);
        }
        .form-actions .back-link {
            font-size: 13.5px; font-weight: 600; color: var(--muted); text-decoration: none;
            transition: color .16s ease;
        }
        .form-actions .back-link:hover { color: var(--gold-deep); }
        .btn-primary {
            padding: 13px 30px; background: var(--gold-deep); color: #fff;
            border: none; border-radius: 13px; font: inherit; font-size: 14.5px; font-weight: 700;
            cursor: pointer; box-shadow: 0 10px 26px -10px rgba(180,83,9,0.55);
            transition: background .16s ease, transform .12s var(--ease-out), box-shadow .16s ease;
        }
        .btn-primary:hover { background: #92400e; box-shadow: 0 14px 30px -10px rgba(180,83,9,0.6); }
        .btn-primary:active { transform: scale(0.98); }

        /* ---------- Responsive ---------- */
        @media (max-width: 1024px) {
            .shell { grid-template-columns: 1fr; }
            .side { position: static; }
            .steps { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .header { padding: 16px 16px; }
            .container { padding: 22px 14px 36px; }
            .main-body { padding: 20px 16px 20px; }
            .grid-2 { grid-template-columns: 1fr; gap: 16px; }
            .steps { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column-reverse; align-items: stretch; gap: 10px; }
            .btn-primary { width: 100%; text-align: center; }
            .form-actions .back-link { text-align: center; }
        }

        /* ---------- Entrance motion (occasional screen: a gentle staged reveal) ---------- */
        @keyframes sc-rise {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .page-head, .side, .main {
            opacity: 0; animation: sc-rise 0.55s var(--ease-out) forwards;
        }
        .page-head { animation-delay: 0.02s; }
        .side      { animation-delay: 0.12s; }
        .main      { animation-delay: 0.18s; }

        @media (prefers-reduced-motion: reduce) {
            .page-head, .side, .main { opacity: 1; animation: none; }
            .btn-primary { transition: none; }
        }
    </style>
</head>
<body>

<header class="header">
    <div class="header-brand">
        <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="header-brand-mark">
            <path d="M8 13L16 4L24 13L16 28Z" fill="url(#dg)"/>
            <path d="M8 13L16 4L24 13" stroke="#d97706" stroke-width="1.2" stroke-linejoin="round"/>
            <line x1="8" y1="13" x2="24" y2="13" stroke="#f59e0b" stroke-width="0.8" opacity="0.6"/>
            <defs>
                <linearGradient id="dg" x1="16" y1="4" x2="16" y2="28" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#fcd34d"/>
                    <stop offset="1" stop-color="#f59e0b"/>
                </linearGradient>
            </defs>
        </svg>
        <div class="header-brand-text">Jewel<span>Flow</span></div>
    </div>
    <div class="shop-chip">
        <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v6a4 4 0 01-4 4H6a4 4 0 01-4-4V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5z" clip-rule="evenodd"/>
        </svg>
        {{ ucfirst($shopType) }} Edition
    </div>
</header>

<div class="container">
    <div class="page-head">
        <h2>Set up your shop</h2>
        <p>
            @if($shopType === 'retailer')
                A couple of details and your retail shop is ready to go.
            @else
                A couple of details and your manufacturing shop is ready to go.
            @endif
        </p>
    </div>

    <div class="shell">
        <aside class="side">
            <div class="side-title">Quick setup</div>
            <div class="side-sub">
                @if($shopType === 'retailer')
                    For shops that buy ready-made jewellery and sell to customers.
                @else
                    For shops that make jewellery from raw gold, with lot tracking.
                @endif
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div class="step-text">
                        <b>Shop details</b>
                        <span>Name, phone and GST (if you have one).</span>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div class="step-text">
                        <b>Address</b>
                        <span>Shown on your invoices and receipts.</span>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div class="step-text">
                        <b>Owner</b>
                        <span>The main person in charge of the shop.</span>
                    </div>
                </div>
            </div>

            <div class="note">
                You can change any of these later from Settings. Use a 10-digit mobile number.
            </div>
        </aside>

        <main class="main">
            <div class="main-body">
                @if ($errors->any())
                    <div class="errors">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('shops.store') }}" method="POST">
                    @csrf
                    <input type="hidden" name="shop_type" value="{{ $shopType }}">

                    {{-- Shop Details --}}
                    <section class="fsection">
                        <div class="fsection-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>
                            Shop details
                        </div>

                        <div class="field">
                            <label>Shop name <span class="req">*</span></label>
                            <input type="text" name="name" value="{{ old('name') }}" placeholder="e.g. Golden Jewellers" required>
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label>Shop phone <span class="req">*</span></label>
                                <input type="tel" name="phone" value="{{ old('phone') }}" required
                                       inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                                       placeholder="10-digit number">
                            </div>
                            <div class="field">
                                <label>GST number <span class="opt">(optional)</span></label>
                                <input type="text" name="gst_number" id="gst_number" value="{{ old('gst_number') }}"
                                       maxlength="15" autocapitalize="characters" spellcheck="false"
                                       placeholder="e.g. 24AAACC1206D1ZM">
                                <div class="field-hint">15 characters. Leave blank if you do not have one.</div>
                            </div>
                        </div>

                        @if($shopType === 'manufacturer')
                        <div class="grid-2" style="margin-top: 16px;">
                            <div class="field">
                                <label>GST rate (%) <span class="req">*</span></label>
                                <input type="number" name="gst_rate" value="{{ old('gst_rate', '3') }}"
                                       inputmode="decimal" step="0.01" min="0" max="100" placeholder="3" required>
                            </div>
                            <div class="field">
                                <label>Wastage recovery (%) <span class="req">*</span></label>
                                <input type="number" name="wastage_recovery_percent" value="{{ old('wastage_recovery_percent', '100') }}"
                                       inputmode="decimal" step="0.01" min="0" max="100" placeholder="100" required>
                            </div>
                        </div>
                        @endif
                    </section>

                    {{-- Address --}}
                    <section class="fsection">
                        <div class="fsection-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-5.686-7-11a7 7 0 1114 0c0 5.314-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            Shop address
                        </div>

                        <div class="field">
                            <label>Address line 1 <span class="req">*</span></label>
                            <input type="text" name="address_line1" value="{{ old('address_line1') }}"
                                   placeholder="Shop No. 12, XYZ Complex, Main Road" required>
                        </div>

                        <div class="field">
                            <label>Address line 2 <span class="opt">(optional)</span></label>
                            <input type="text" name="address_line2" value="{{ old('address_line2') }}"
                                   placeholder="Near City Mall, Sarkhej Area">
                        </div>

                        <div class="grid-2" style="margin-bottom: 16px;">
                            <div class="field">
                                <label>Pincode <span class="req">*</span></label>
                                <input type="text" name="pincode" id="pincode" value="{{ old('pincode') }}" required
                                       inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6"
                                       placeholder="6-digit pincode">
                                <div class="field-hint" id="pincode_hint">We will fill in your city and state automatically.</div>
                            </div>
                            <div class="field">
                                <label>City <span class="req">*</span></label>
                                <input type="text" name="city" id="city" value="{{ old('city') }}" placeholder="e.g. Ahmedabad" required>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label>State <span class="req">*</span></label>
                                <select name="state" id="state" required>
                                    <option value="" disabled {{ old('state') ? '' : 'selected' }}>Select your state</option>
                                    @php
                                        $indianStates = [
                                            'Andhra Pradesh','Arunachal Pradesh','Assam','Bihar','Chhattisgarh','Goa','Gujarat',
                                            'Haryana','Himachal Pradesh','Jharkhand','Karnataka','Kerala','Madhya Pradesh',
                                            'Maharashtra','Manipur','Meghalaya','Mizoram','Nagaland','Odisha','Punjab','Rajasthan',
                                            'Sikkim','Tamil Nadu','Telangana','Tripura','Uttar Pradesh','Uttarakhand','West Bengal',
                                            'Andaman and Nicobar Islands','Chandigarh','Dadra and Nagar Haveli and Daman and Diu',
                                            'Delhi','Jammu and Kashmir','Ladakh','Lakshadweep','Puducherry',
                                        ];
                                    @endphp
                                    @foreach($indianStates as $st)
                                        <option value="{{ $st }}" {{ old('state') === $st ? 'selected' : '' }}>{{ $st }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label>Country</label>
                                <div class="locked-field">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zM3.5 9h17M3.5 15h17M12 3c2.5 2.5 3.5 6 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-6-3.5-9S9.5 5.5 12 3z"/></svg>
                                    India
                                </div>
                                <input type="hidden" name="country" value="India">
                            </div>
                        </div>
                    </section>

                    {{-- Owner --}}
                    <section class="fsection">
                        <div class="fsection-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21v-1a6 6 0 016-6h4a6 6 0 016 6v1"/></svg>
                            Owner details
                        </div>

                        <div class="grid-2" style="margin-bottom: 16px;">
                            <div class="field">
                                <label>First name <span class="req">*</span></label>
                                <input type="text" name="owner_first_name" value="{{ old('owner_first_name') }}" placeholder="First name" required>
                            </div>
                            <div class="field">
                                <label>Last name <span class="req">*</span></label>
                                <input type="text" name="owner_last_name" value="{{ old('owner_last_name') }}" placeholder="Last name" required>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="field">
                                <label>Mobile number <span class="req">*</span></label>
                                <input type="tel" name="owner_mobile" value="{{ old('owner_mobile') }}" required
                                       inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                                       placeholder="10-digit mobile">
                            </div>
                            <div class="field">
                                <label>Email address <span class="opt">(optional)</span></label>
                                <input type="email" name="owner_email" value="{{ old('owner_email') }}" placeholder="owner@example.com">
                            </div>
                        </div>
                    </section>

                    <div class="form-actions">
                        <a href="{{ route('shops.choose-type') }}" class="back-link">← Change business type</a>
                        <button type="submit" class="btn-primary">Create my shop →</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<script>
    // GST number is always uppercase. Format as the owner types.
    (function () {
        var gst = document.getElementById('gst_number');
        if (gst) {
            gst.addEventListener('input', function () {
                var pos = this.selectionStart;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(pos, pos);
            });
        }
    })();

    // Pincode -> auto-fill city + state. Best-effort via the free India PIN API;
    // if it fails or returns nothing, the owner just types city/state by hand.
    (function () {
        var pin = document.getElementById('pincode');
        var city = document.getElementById('city');
        var state = document.getElementById('state');
        var hint = document.getElementById('pincode_hint');
        if (!pin) return;

        var defaultHint = hint ? hint.textContent : '';

        function setHint(msg, kind) {
            if (!hint) return;
            hint.textContent = msg;
            if (kind) { hint.setAttribute('data-state', kind); } else { hint.removeAttribute('data-state'); }
        }

        // Match a fetched state name to one of our dropdown options (case-insensitive).
        function selectState(name) {
            if (!state || !name) return;
            var target = name.trim().toLowerCase();
            for (var i = 0; i < state.options.length; i++) {
                if (state.options[i].value.toLowerCase() === target) {
                    state.value = state.options[i].value;
                    return true;
                }
            }
            return false;
        }

        var lastLookup = '';
        pin.addEventListener('input', function () {
            var code = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value !== code) this.value = code;
            if (code.length !== 6 || code === lastLookup) return;
            lastLookup = code;

            setHint('Looking up your area...', 'loading');
            fetch('https://api.postalpincode.in/pincode/' + code)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var rec = Array.isArray(data) ? data[0] : null;
                    if (!rec || rec.Status !== 'Success' || !rec.PostOffice || !rec.PostOffice.length) {
                        setHint('Could not find that pincode. Please type your city and state.', null);
                        return;
                    }
                    var po = rec.PostOffice[0];
                    var stateOk = selectState(po.State);
                    if (city && !city.value) {
                        city.value = po.District || po.Division || '';
                    }
                    if (stateOk) {
                        setHint('Found: ' + (po.District || po.State) + ', ' + po.State + '. Edit if needed.', 'ok');
                    } else {
                        setHint('Found your area. Please confirm the state below.', null);
                    }
                })
                .catch(function () {
                    setHint('Could not look that up. Please type your city and state.', null);
                });
        });

        pin.addEventListener('focus', function () {
            if (this.value.replace(/\D/g, '').length !== 6) setHint(defaultHint, null);
        });
    })();
</script>

</body>
</html>
