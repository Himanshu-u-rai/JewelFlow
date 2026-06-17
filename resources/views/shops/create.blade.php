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
            /* Gold stays the brand accent, but the surfaces are clean neutral
               so the setup form reads as professional software, not a card. */
            --gold:#d97706; --gold-deep:#b45309; --gold-soft:#f59e0b;

            --page:#f4f5f7;             /* cool light gray page */
            --card:#ffffff;
            --ink:#1e2530;             /* slate ink */
            --ink-soft:#475467;        /* secondary slate */
            --muted:#667085;           /* tertiary / hints */
            --line:#e6e8ec;            /* neutral hairline */
            --field-line:#d3d8e0;
            --field-bg:#ffffff;

            --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
        }

        body {
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--page);
            min-height: 100vh;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        /* ---------- Header (sticky) ---------- */
        .header {
            position: sticky; top: 0; z-index: 50;
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px;
            padding: 13px 28px;
            border-bottom: 1px solid var(--line);
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .header-left { display: flex; align-items: center; gap: 16px; min-width: 0; }
        .header-brand { display: flex; align-items: center; gap: 9px; }
        .header-brand-mark { width: 28px; height: 28px; flex: 0 0 auto; }
        .header-brand-text { font-size: 18px; font-weight: 800; color: var(--ink); letter-spacing: -0.3px; }
        .header-brand-text span { color: var(--gold); }

        /* Change-business-type as a real button, set apart from the form so it
           cannot be mistaken for the submit action. */
        .header-back {
            display: inline-flex; align-items: center; gap: 6px;
            font: inherit; font-size: 13px; font-weight: 600; color: var(--ink-soft);
            text-decoration: none; cursor: pointer; white-space: nowrap;
            background: #fff; border: 1px solid var(--field-line); border-radius: 9px;
            padding: 8px 13px;
            transition: background .16s ease, border-color .16s ease, color .16s ease, transform .12s var(--ease-out);
        }
        .header-back svg { width: 14px; height: 14px; flex: 0 0 auto; transition: transform .18s var(--ease-out); }
        @media (hover: hover) and (pointer: fine) {
            .header-back:hover { background: #f9fafb; border-color: #c4cad3; color: var(--ink); transform: translateY(-1px); }
            /* The chevron nudges back, hinting the direction this takes you. */
            .header-back:hover svg { transform: translateX(-2px); }
        }
        .header-back:active { transform: scale(0.97); }

        .shop-chip {
            display: inline-flex; align-items: center; gap: 6px; flex: 0 0 auto;
            font-size: 11.5px; font-weight: 700; letter-spacing: .03em;
            color: var(--gold-deep);
            background: #fdf6ec; border: 1px solid #f3dcb6; border-radius: 8px;
            padding: 6px 11px; text-transform: uppercase;
        }
        .shop-chip svg { flex: 0 0 auto; }

        /* ---------- Layout ---------- */
        .container { max-width: 880px; margin: 0 auto; padding: 28px 20px 48px; }

        .page-head { margin-bottom: 22px; }
        .page-head h2 {
            font-size: clamp(22px, 3vw, 28px); font-weight: 800; color: var(--ink);
            letter-spacing: -0.5px; text-wrap: balance;
        }
        .page-head p { font-size: 14px; color: var(--muted); margin-top: 5px; }

        /* ---------- Section cards ---------- */
        .form-stack { display: grid; gap: 16px; }

        .fcard {
            background: var(--card); border: 1px solid var(--line); border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 1px 3px rgba(16,24,40,0.04);
            transition: box-shadow .2s var(--ease-out), border-color .2s ease;
        }
        /* Barely-there lift when the pointer is over a card, so each section
           reads as a real surface you can work on (emil: unseen details). */
        @media (hover: hover) and (pointer: fine) {
            .fcard:hover {
                border-color: #dadde2;
                box-shadow: 0 1px 2px rgba(16,24,40,0.05), 0 10px 28px -16px rgba(16,24,40,0.2);
            }
        }

        .fcard-head {
            display: flex; align-items: center; gap: 12px;
            padding-bottom: 14px; margin-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }
        .fcard-icon {
            width: 36px; height: 36px; flex: 0 0 auto; border-radius: 9px;
            display: grid; place-items: center;
            color: var(--gold-deep); background: #fdf6ec; border: 1px solid #f3dcb6;
        }
        .fcard-icon svg { width: 18px; height: 18px; }
        .fcard-titles b { display: block; font-size: 15px; font-weight: 700; color: var(--ink); letter-spacing: -0.2px; }
        .fcard-titles span { display: block; font-size: 12.5px; color: var(--muted); margin-top: 1px; }

        /* Responsive field grid: fields reflow automatically with no manual
           column spans, so it collapses cleanly at any width (including phones)
           without inline overrides fighting the media query. */
        .frow {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        /* A field that should always take the full row (e.g. address line). */
        .frow .span-all { grid-column: 1 / -1; }
        .field { min-width: 0; }

        label {
            display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--ink-soft);
        }
        label .req { color: var(--gold); margin-left: 2px; }
        label .opt { color: var(--muted); font-weight: 500; font-size: 11.5px; }

        input, select, textarea {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--field-line); border-radius: 9px;
            font: inherit; font-size: 14.5px; color: var(--ink);
            background: var(--field-bg);
            /* Exact properties, eased: the ring/border should arrive, not snap. */
            transition: border-color .16s ease, box-shadow .18s var(--ease-out);
        }
        input::placeholder, textarea::placeholder { color: #98a2b3; }
        /* Pointer feedback: the border darkens on hover so a field reads as
           interactive before you click into it (emil: respond to the pointer). */
        @media (hover: hover) and (pointer: fine) {
            input:hover, select:hover, textarea:hover { border-color: #b8c0cc; }
        }
        input:focus, select:focus, textarea:focus {
            outline: none; border-color: var(--gold-soft);
            box-shadow: 0 0 0 3px rgba(245,158,11,0.18);
        }
        select {
            appearance: none; -webkit-appearance: none;
            /* The chevron is a real element (.field-select-wrap svg) so it can
               rotate on focus; the native control draws no arrow. */
            padding-right: 34px; cursor: pointer;
        }
        .field-select-wrap { position: relative; }
        .field-select-wrap > svg {
            position: absolute; right: 12px; top: 50%; width: 15px; height: 15px;
            margin-top: -7.5px; color: var(--muted); pointer-events: none;
            transition: transform .18s var(--ease-out), color .16s ease;
        }
        .field-select-wrap:focus-within > svg { transform: rotate(180deg); color: var(--gold-deep); }

        .field-hint { font-size: 11.5px; color: var(--muted); margin-top: 5px; line-height: 1.4; display: flex; align-items: center; gap: 6px; }
        .field-hint[data-state="ok"] { color: #047857; }
        .field-hint[data-state="loading"] { color: var(--gold-deep); }
        /* Tiny spinner shown only while a pincode lookup is in flight, so the
           wait reads as active work (emil: feedback + perceived performance). */
        .hint-spinner {
            width: 12px; height: 12px; flex: 0 0 auto; border-radius: 50%;
            border: 1.8px solid rgba(180,83,9,0.25); border-top-color: var(--gold-deep);
            animation: hint-spin .6s linear infinite; display: none;
        }
        .field-hint[data-state="loading"] .hint-spinner { display: inline-block; }
        @keyframes hint-spin { to { transform: rotate(360deg); } }

        /* Locked, non-editable country display. */
        .locked-field {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; border: 1px solid var(--field-line); border-radius: 9px;
            background: #f4f5f7; color: var(--ink-soft); font-size: 14.5px;
        }
        .locked-field svg { width: 15px; height: 15px; color: var(--muted); flex: 0 0 auto; }

        /* ---------- Errors ---------- */
        .errors {
            background: #fef3f2; border: 1px solid #fecdca; color: #b42318;
            padding: 14px 16px; border-radius: 12px; margin-bottom: 18px; font-size: 13px;
        }
        .errors ul { list-style: none; margin: 0; padding: 0; }
        .errors li { padding: 3px 0; }
        .errors li:before { content: "• "; color: #d92d20; font-weight: bold; margin-right: 4px; }

        /* ---------- Actions ---------- */
        .form-actions {
            display: flex; justify-content: flex-end; align-items: center;
            margin-top: 18px; padding: 2px;
        }
        .btn-primary {
            padding: 12px 28px; background: var(--gold-deep); color: #fff;
            border: none; border-radius: 10px; font: inherit; font-size: 14.5px; font-weight: 700;
            cursor: pointer; box-shadow: 0 1px 2px rgba(16,24,40,0.08), 0 8px 20px -10px rgba(180,83,9,0.5);
            transition: background .16s ease, transform .12s var(--ease-out), box-shadow .16s ease;
        }
        .btn-primary:hover { background: #92400e; box-shadow: 0 1px 2px rgba(16,24,40,0.08), 0 12px 24px -10px rgba(180,83,9,0.55); }
        .btn-primary:active { transform: scale(0.98); }

        /* ---------- Responsive ---------- */
        @media (max-width: 600px) {
            /* One tidy row, no wrapping. The back button collapses to an
               icon-only control so the logo and edition chip still fit. */
            .header { padding: 10px 14px; gap: 10px; flex-wrap: nowrap; }
            .header-left { gap: 10px; flex: 0 1 auto; min-width: 0; }
            .header-brand-text { font-size: 16px; }
            .header-brand-mark { width: 26px; height: 26px; }
            .header-back { padding: 8px; gap: 0; }
            .header-back svg { width: 16px; height: 16px; }
            .header-back-label { display: none; }   /* icon-only on mobile (aria-label keeps it accessible) */
            .shop-chip { font-size: 10.5px; padding: 5px 9px; gap: 5px; }

            .container { padding: 20px 16px 40px; }
            .fcard { padding: 18px 16px; border-radius: 13px; }
            .fcard-head { gap: 10px; }
            /* Single column on phones; one rule, nothing to override. */
            .frow { grid-template-columns: 1fr; gap: 14px; }
            .form-actions { margin-top: 16px; }
            .btn-primary { width: 100%; text-align: center; }
        }

        /* Very small phones: the edition chip drops its label-heavy padding;
           if it still cannot fit beside the logo + back button, hide it (the
           edition is also stated in the page sub-heading below). */
        @media (max-width: 360px) {
            .header-brand-text { font-size: 15px; }
            .shop-chip { display: none; }
        }

        /* ---------- Entrance motion ----------
           Occasional screen, so a gentle staged reveal. Strong ease-out, short,
           and fully disabled under reduced-motion (emil-design-eng). */
        @keyframes sc-rise {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .page-head, .fcard, .form-actions {
            opacity: 0; animation: sc-rise 0.5s var(--ease-out) forwards;
        }
        .page-head            { animation-delay: 0.02s; }
        .fcard:nth-of-type(1) { animation-delay: 0.09s; }
        .fcard:nth-of-type(2) { animation-delay: 0.15s; }
        .fcard:nth-of-type(3) { animation-delay: 0.21s; }
        .form-actions         { animation-delay: 0.27s; }

        @media (prefers-reduced-motion: reduce) {
            .page-head, .fcard, .form-actions { opacity: 1; animation: none; }
            .btn-primary, .header-back, .header-back svg,
            .fcard, .field-select-wrap > svg, input, select, textarea { transition: none; }
            /* No spinning loader under reduced motion: keep it as a static dot. */
            .hint-spinner { animation: none; border-top-color: rgba(180,83,9,0.25); }
        }
    </style>
</head>
<body>

<header class="header">
    <div class="header-left">
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
        <a href="{{ route('shops.choose-type') }}" class="header-back" aria-label="Change business type">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            <span class="header-back-label">Change business type</span>
        </a>
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
                A few details and your retail shop is ready to go.
            @else
                A few details and your manufacturing shop is ready to go.
            @endif
        </p>
    </div>

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

        <div class="form-stack">

            {{-- Shop Details --}}
            <section class="fcard">
                <div class="fcard-head">
                    <div class="fcard-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/></svg>
                    </div>
                    <div class="fcard-titles">
                        <b>Shop details</b>
                        <span>Name, phone and GST (if you have one).</span>
                    </div>
                </div>

                <div class="frow">
                    <div class="field span-all">
                        <label>Shop name <span class="req">*</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" placeholder="e.g. Golden Jewellers" required>
                    </div>
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
                        <div class="field-hint">15 characters. Leave blank if none.</div>
                    </div>

                    @if($shopType === 'manufacturer')
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
                    @endif
                </div>
            </section>

            {{-- Address --}}
            <section class="fcard">
                <div class="fcard-head">
                    <div class="fcard-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-5.686-7-11a7 7 0 1114 0c0 5.314-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    </div>
                    <div class="fcard-titles">
                        <b>Shop address</b>
                        <span>Shown on your invoices and receipts.</span>
                    </div>
                </div>

                <div class="frow">
                    <div class="field span-all">
                        <label>Address line 1 <span class="req">*</span></label>
                        <input type="text" name="address_line1" value="{{ old('address_line1') }}"
                               placeholder="Shop No. 12, Main Road" required>
                    </div>
                    <div class="field span-all">
                        <label>Address line 2 <span class="opt">(optional)</span></label>
                        <input type="text" name="address_line2" value="{{ old('address_line2') }}"
                               placeholder="Near City Mall, Sarkhej Area">
                    </div>

                    <div class="field">
                        <label>Pincode <span class="req">*</span></label>
                        <input type="text" name="pincode" id="pincode" value="{{ old('pincode') }}" required
                               inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6"
                               placeholder="6-digit">
                        <div class="field-hint" id="pincode_hint"><span class="hint-spinner" aria-hidden="true"></span><span id="pincode_hint_text">Fills your city &amp; state for you.</span></div>
                    </div>
                    <div class="field">
                        <label>City <span class="req">*</span></label>
                        <input type="text" name="city" id="city" value="{{ old('city') }}" placeholder="e.g. Ahmedabad" required>
                    </div>
                    <div class="field">
                        <label>State <span class="req">*</span></label>
                        <div class="field-select-wrap">
                            <select name="state" id="state" required>
                                <option value="" disabled {{ old('state') ? '' : 'selected' }}>Select state</option>
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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                        </div>
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
            <section class="fcard">
                <div class="fcard-head">
                    <div class="fcard-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21v-1a6 6 0 016-6h4a6 6 0 016 6v1"/></svg>
                    </div>
                    <div class="fcard-titles">
                        <b>Owner details</b>
                        <span>The main person in charge of the shop.</span>
                    </div>
                </div>

                <div class="frow">
                    <div class="field">
                        <label>First name <span class="req">*</span></label>
                        <input type="text" name="owner_first_name" value="{{ old('owner_first_name') }}" placeholder="First name" required>
                    </div>
                    <div class="field">
                        <label>Last name <span class="req">*</span></label>
                        <input type="text" name="owner_last_name" value="{{ old('owner_last_name') }}" placeholder="Last name" required>
                    </div>
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

        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Create my shop →</button>
        </div>
    </form>
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

    // Pincode -> auto-fill city + state. The lookup goes through our own server
    // (route shops.pincode-lookup), which proxies the India PIN API. Calling it
    // same-origin keeps it within the page's Content-Security-Policy; a direct
    // browser call to the external API would be blocked by connect-src 'self'.
    // Best-effort: any failure just leaves the fields for manual entry.
    (function () {
        var pin = document.getElementById('pincode');
        var city = document.getElementById('city');
        var state = document.getElementById('state');
        var hint = document.getElementById('pincode_hint');
        if (!pin) return;

        var hintText = document.getElementById('pincode_hint_text');
        var lookupBase = @json(url('/shops/pincode'));
        var defaultHint = hintText ? hintText.textContent : '';

        // Write to the text span only, so the spinner element is preserved; the
        // data-state on the parent drives the spinner + colour.
        function setHint(msg, kind) {
            if (hintText) hintText.textContent = msg;
            if (!hint) return;
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
            fetch(lookupBase + '/' + code, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.found) {
                        setHint('Could not find that pincode. Please type your city and state.', null);
                        return;
                    }
                    var stateOk = selectState(data.state);
                    if (city && !city.value) {
                        city.value = data.city || '';
                    }
                    if (stateOk) {
                        setHint('Found: ' + (data.city || data.state) + ', ' + data.state + '. Edit if needed.', 'ok');
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
