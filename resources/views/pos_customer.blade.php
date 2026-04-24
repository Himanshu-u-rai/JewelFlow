<x-app-layout>
<style>
    /* ─── Variables & Base ────────────────────────────── */
    .pos-page {
        --ink: #0f172a;
        --ink-soft: #334155;
        --muted: #64748b;
        --border: #d6dde7;
        --border-light: #f1f5f9;
        --card: #ffffff;
        --bg: #f2f5f9;
        --accent: #14213d;
        --accent-hover: #0f1a33;
        --gold: #f59e0b;
        --gold-light: #fff7e8;
        --gold-border: #f2d29c;
        --success: #16a34a;
        --danger: #dc2626;

        padding: 20px 24px 32px;
        color: var(--ink);
        background: linear-gradient(180deg, #eff4f9 0%, #f8fbff 100%);
        min-height: 100vh;
    }

    /* ─── Top Bar ─────────────────────────────────────── */
    .pos-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: linear-gradient(120deg, #f7faff 0%, #edf3fb 65%, #e5edf9 100%);
        border: 1px solid #c8d5ea;
        box-shadow: 0 14px 28px rgba(20, 33, 61, 0.12);
        position: relative;
        overflow: hidden;
        padding: 16px 20px;
        margin-bottom: 20px;
    }
    .pos-header::after {
        content: "";
        position: absolute;
        right: -120px;
        top: -120px;
        width: 260px;
        height: 260px;
        background: radial-gradient(circle, rgba(252,163,17,0.14) 0%, rgba(252,163,17,0) 70%);
        pointer-events: none;
    }

    .pos-header-left {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .pos-back {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;

        font-size: 13px;
        font-weight: 600;
        background: #14213d;
        color: #f8fbff;
        text-decoration: none;
        border: 1px solid #14213d;
        transition: background 0.15s, color 0.15s, border-color 0.15s;
        box-shadow: 0 10px 18px rgba(20, 33, 61, 0.22);
        position: relative;
        z-index: 1;
    }

    .pos-back:hover { background: #fca311; color: #14213d; border-color: #fca311; }

    .pos-customer-name {
        font-size: 20px;
        font-weight: 700;
        color: var(--ink);
    }

    .pos-customer-phone {
        font-size: 14px;
        color: var(--muted);
        margin-top: 2px;
    }

    .pos-badge {
        padding: 6px 12px;

        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        background: #14213d;
        color: #f8fbff;
        border: 1px solid #14213d;
        position: relative;
        z-index: 1;
    }

    /* ─── Main 2-col layout ───────────────────────────── */
    .pos-body {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 24px;
        align-items: start;
    }

    .pos-main {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* ─── Cards ───────────────────────────────────────── */
    .card {
        background: var(--card);
        border: 1px solid var(--border);
        box-shadow: 0 12px 24px rgba(20, 33, 61, 0.08);
        padding: 20px 24px;
    }

    .card-title {
        font-size: 15px;
        font-weight: 700;
        color: var(--ink);
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .card-title-icon {
        margin-right: 8px;
        font-size: 18px;
    }

    /* ─── Form Elements ───────────────────────────────── */
    .field {
        margin-bottom: 16px;
    }

    .field:last-child { margin-bottom: 0; }

    .field-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: var(--muted);
        margin-bottom: 6px;
    }

    .field-input,
    .field-select {
        width: 100%;
        padding: 12px 14px;
        font-size: 16px;
        font-weight: 500;
        border: 1.5px solid var(--border);

        background: #ffffff;
        color: var(--ink);
        transition: border-color 0.15s, box-shadow 0.15s;
    }

    .field-input:focus,
    .field-select:focus {
        outline: none;
        border-color: var(--accent);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(20, 33, 61, 0.12);
    }

    .field-input-lg {
        font-size: 22px;
        font-weight: 700;
        padding: 16px 18px;
        background: #fff;
        border-color: var(--gold-border);
    }

    .field-input-lg:focus {
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.12);
    }

    .field-row {
        display: grid;
        gap: 16px;
        margin-bottom: 16px;
    }

    .field-row:last-child { margin-bottom: 0; }
    .field-row .field { margin-bottom: 0; }

    .field-row-2 { grid-template-columns: 1fr 1fr; }
    .field-row-3 { grid-template-columns: 1fr 1fr 1fr; }

    /* ─── Barcode scanner ─────────────────────────────── */
    .barcode-wrap {
        background: var(--bg);
        border: 1.5px dashed rgba(20, 33, 61, 0.26);
        border-radius: 16px;
        padding: 16px;
        margin-bottom: 16px;
        position: relative;
    }

    .barcode-suggestions {
        position: absolute;
        left: 0; right: 0;
        top: calc(100% + 4px);
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 12px 32px rgba(15,23,42,.12), 0 4px 8px rgba(15,23,42,.06);
        z-index: 200;
        max-height: 280px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .barcode-wrap.drop-up .barcode-suggestions {
        top: auto;
        bottom: calc(100% + 4px);
    }

    .barcode-sug-item {
        padding: 10px 14px;
        cursor: pointer;
        font-size: 13px;
        line-height: 1.4;
        border-bottom: 1px solid #f8fafc;
        color: var(--ink);
        transition: background .12s ease, padding-left .12s ease;
    }

    .barcode-sug-item:last-child { border-bottom: none; }

    .barcode-sug-item:hover,
    .barcode-sug-item.active {
        background: #fffbeb;
        padding-left: 18px;
    }

    .barcode-field.loading {
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(245,158,11,.15);
    }

    .barcode-sug-barcode {
        font-weight: 700;
        color: var(--accent);
    }

    .barcode-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
    }

    .barcode-badge {
        font-size: 11px;
        font-weight: 700;
        color: #7a4d02;
        background: #fff4de;
        padding: 4px 10px;
        border-radius: 9999px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .barcode-field {
        width: 100%;
        padding: 14px 16px;
        font-size: 17px;
        font-weight: 600;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        background: #fff;
    }

    .barcode-field:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }

    .barcode-hint {
        margin-top: 6px;
        font-size: 12px;
        color: var(--muted);
    }

    /* ─── Item details strip ──────────────────────────── */
    .item-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        background: var(--bg);
        border-left: 4px solid var(--accent);
        border-radius: 12px;
        padding: 12px 16px;
    }

    .item-strip-cell {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .item-strip-label {
        font-size: 11px;
        font-weight: 600;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .item-strip-value {
        font-size: 15px;
        font-weight: 700;
        color: var(--ink);
    }

    /* ─── Payment rows ────────────────────────────────── */
    .pay-row {
        background: #f8fbff;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        padding: 12px 14px;
        margin-bottom: 10px;
        box-shadow: inset 0 0 0 1px rgba(20,33,61,0.04);
    }

    .pay-row-top {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .pay-mode {
        width: 170px;
        flex-shrink: 0;
        padding: 12px 14px;
        font-size: 15px;
        font-weight: 600;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        background: #fff;
        color: var(--ink);
    }

    .pay-amount {
        flex: 1;
        padding: 12px 14px;
        font-size: 18px;
        font-weight: 700;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        background: #fff;
        color: var(--ink);
    }

    .pay-amount:focus,
    .pay-mode:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
    }

    .pay-remove {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        border: 1px solid rgba(220, 38, 38, 0.25);
        background: #fef2f2;
        color: var(--danger);
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        flex-shrink: 0;
    }

    .pay-remove:hover { background: #fee2e2; }

    .pay-details {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px dashed var(--border);
    }

    .metal-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .metal-grid .field { margin-bottom: 0; }

    .metal-grid .field-label {
        font-size: 12px;
        margin-bottom: 4px;
    }

    .metal-grid .field-input {
        padding: 10px 12px;
        font-size: 15px;
    }

    .metal-result {
        margin-top: 10px;
        padding: 10px 14px;
        border-radius: 12px;
        background: var(--gold-light);
        border: 1px solid var(--gold-border);
        font-size: 14px;
        color: #92400e;
        font-weight: 600;
    }

    .ref-field {
        margin-top: 10px;
    }

    .ref-field .field-input {
        padding: 10px 12px;
        font-size: 15px;
    }

    /* ─── Balance bar ─────────────────────────────────── */
    .balance-bar {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        padding: 14px 18px;
        border-radius: 14px;
        font-size: 15px;
        font-weight: 700;
        background: var(--border-light);
        color: var(--ink-soft);
        border: 1px solid var(--border);
        margin-top: 12px;
    }

    /* ─── Sticky sidebar ──────────────────────────────── */
    .pos-sidebar {
        position: sticky;
        top: 20px;
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .summary-card {
        background: linear-gradient(135deg, #14213d 0%, #0f1a33 100%);
        border-radius: 16px;
        padding: 24px;
        color: #f8fafc;
        box-shadow: 0 20px 32px rgba(20, 33, 61, 0.24);
    }

    .summary-title {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        margin-bottom: 16px;
    }

    .summary-title-text {
        font-size: 17px;
        font-weight: 700;
    }

    .summary-live {
        font-size: 10px;
        font-weight: 700;
        color: #34d399;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        background: rgba(52, 211, 153, 0.12);
        padding: 3px 8px;
        border-radius: 9999px;
    }

    .summary-rows {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        font-size: 15px;
    }

    .summary-row-label {
        color: #94a3b8;
    }

    .summary-row-value {
        font-weight: 700;
        color: #e2e8f0;
    }

    .summary-divider {
        border: none;
        border-top: 1px solid rgba(148, 163, 184, 0.2);
        margin: 10px 0;
    }

    .summary-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0 4px;
    }

    .summary-total-label {
        font-size: 16px;
        font-weight: 700;
        color: #cbd5e1;
    }

    .summary-total-value {
        font-size: 26px;
        font-weight: 800;
        color: #fca311;
    }

    .btn-sale {
        display: block;
        width: 100%;
        padding: 18px;
        font-size: 17px;
        font-weight: 700;
        border: none;
        border-radius: 14px;
        cursor: pointer;
        background: #14213d;
        color: #fff;
        transition: transform 0.15s, box-shadow 0.15s;
        box-shadow: 0 12px 26px rgba(20, 33, 61, 0.28);
    }

    .btn-sale:hover {
        transform: translateY(-1px);
        box-shadow: 0 16px 30px rgba(20, 33, 61, 0.34);
        background: #0f1a33;
    }

    .btn-sale:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .sale-hint {
        text-align: center;
        font-size: 12px;
        color: #64748b;
        margin-top: 8px;
    }

    /* ─── Utility buttons ─────────────────────────────── */
    .btn-add-mode {
        padding: 8px 14px;
        font-size: 13px;
        font-weight: 600;
        border-radius: 10px;
        border: 1px solid var(--border);
        background: #ffffff;
        color: #32425f;
        cursor: pointer;
        transition: background 0.15s;
        box-shadow: 0 8px 16px rgba(20, 33, 61, 0.08);
    }

    .btn-add-mode:hover { background: #14213d; color: #ffffff; border-color: #14213d; }

    /* ─── Responsive ──────────────────────────────────── */
    @media (max-width: 1100px) {
        .pos-body {
            grid-template-columns: 1fr;
        }

        .pos-sidebar {
            position: static;
        }
    }

    @media (max-width: 720px) {
        .pos-page { padding: 12px; }
        .pos-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 14px;
        }
        .pos-header-left { flex-wrap: wrap; gap: 10px; }
        .pos-customer-name { font-size: 17px; }
        .card { padding: 16px; border-radius: 14px; }
        .field-row-2, .field-row-3 { grid-template-columns: 1fr; }
        .item-strip { grid-template-columns: 1fr 1fr; }
        .pay-row-top { flex-wrap: wrap; }
        .pay-mode { width: 100%; }
        .summary-card { padding: 18px; border-radius: 14px; }
        .summary-total-val { font-size: 22px; }
        .barcode-wrap { padding: 12px; border-radius: 12px; }
        .item-chip { padding: 10px 12px; border-radius: 12px; }
        .item-chip-icon { width: 38px; height: 38px; }
        .btn-sell { padding: 14px; font-size: 16px; border-radius: 12px; }
    }

    @media (max-width: 480px) {
        .pos-page { padding: 10px 8px 20px; }
        .pos-header { padding: 12px 14px; border-radius: 12px; }
        .pos-back { padding: 6px 10px; font-size: 12px; border-radius: 8px; }
        .pos-customer-name { font-size: 15px; }
        .pos-customer-phone { font-size: 12px; }
        .pos-badge { font-size: 10px; padding: 4px 10px; }
        .card { padding: 14px 12px; }
        .card-title { font-size: 14px; }
        .field-label { font-size: 12px; }
        .field-input, .field-select { font-size: 14px; padding: 10px 12px; }
        .item-strip { grid-template-columns: 1fr; }
        .item-chip-icon { width: 34px; height: 34px; }
        .item-chip-design { font-size: 12px; }
        .item-chip-price { font-size: 14px; }
        .summary-card { padding: 14px; }
        .summary-row { font-size: 13px; }
        .summary-total-val { font-size: 20px; }
        .btn-sell { padding: 12px; font-size: 15px; }
    }
</style>

<div class="pos-page">
    {{-- ─── Header ─────────────────────────────────────────── --}}
    <div class="pos-header">
        <div class="pos-header-left">
            <a href="/pos" class="pos-back">&larr; Back</a>
            <div>
                <div class="pos-customer-name">{{ $customer->first_name }} {{ $customer->last_name }}</div>
                <div class="pos-customer-phone">{{ $customer->mobile }} &middot; #{{ $customer->customer_code ?? '—' }}</div>
            </div>
        </div>
        <span class="pos-badge">POS Sale</span>
    </div>

    {{-- ─── Body ───────────────────────────────────────────── --}}
    <div class="pos-body">

        {{-- ── LEFT: Main form ─────────────────────────────── --}}
        <form id="saleForm" class="pos-main" method="POST" action="/pos/sell">
            @csrf
            <input type="hidden" name="customer_id" value="{{ $customer->id }}">

            {{-- 1. Item Selection --}}
            <div class="card">
                <div class="card-title">
                    <span><span class="card-title-icon"></span> Select Item</span>
                </div>

                <div class="barcode-wrap">
                    <div class="barcode-top">
                        <span class="field-label" style="margin-bottom:0;">Barcode / Quick Scan</span>
                        <span class="barcode-badge">Scanner Ready</span>
                    </div>
                    <input type="text" id="barcodeInput" class="barcode-field" placeholder="Scan or type barcode / design to find item..." autofocus autocomplete="off">
                    <div id="barcodeSuggestions" class="barcode-suggestions" style="display:none;"></div>
                    <div class="barcode-hint">Type to filter suggestions · Press Enter or click to select</div>
                </div>

                <div class="field">
                    <label class="field-label">Choose Item</label>
                    <select name="item_id" id="itemSelect" class="field-select" onchange="showItemDetails()">
                        <option value="">-- Select an item --</option>
                        @foreach($items as $item)
                            <option value="{{ $item->id }}"
                                    data-design="{{ $item->design ?? 'N/A' }}"
                                    data-barcode="{{ $item->barcode }}"
                                    data-purity="{{ $item->purity }}"
                                    data-weight="{{ $item->net_metal_weight }}"
                                    {{ request('item_id') == $item->id ? 'selected' : '' }}>
                                {{ $item->barcode }} — {{ $item->design ?? 'N/A' }} ({{ $item->purity }}K, {{ $item->net_metal_weight }}g)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div id="itemStrip" style="display:none;" class="item-strip">
                    <div class="item-strip-cell">
                        <span class="item-strip-label">Barcode</span>
                        <span class="item-strip-value" id="stripBarcode"></span>
                    </div>
                    <div class="item-strip-cell">
                        <span class="item-strip-label">Design</span>
                        <span class="item-strip-value" id="stripDesign"></span>
                    </div>
                    <div class="item-strip-cell">
                        <span class="item-strip-label">Purity</span>
                        <span class="item-strip-value" id="stripPurity"></span>
                    </div>
                    <div class="item-strip-cell">
                        <span class="item-strip-label">Weight</span>
                        <span class="item-strip-value" id="stripWeight"></span>
                    </div>
                </div>
            </div>

            {{-- 2. Pricing --}}
            <div class="card">
                <div class="card-title">
                    <span><span class="card-title-icon"></span> Pricing</span>
                </div>

                <div class="field">
                    <label class="field-label">Gold Rate (24K per gram)</label>
                    <input type="number" name="gold_rate" id="goldRate" class="field-input field-input-lg" value="" step="0.01" min="0.01" oninput="updatePreview()" placeholder="Enter today's rate...">
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label">Making Charges (₹)</label>
                        <input type="number" name="making" id="makingInput" class="field-input" value="500" step="0.01" min="0" oninput="updatePreview()">
                    </div>
                    <div class="field">
                        <label class="field-label">Stone Charges (₹)</label>
                        <input type="number" name="stone" id="stoneInput" class="field-input" value="0" step="0.01" min="0" oninput="updatePreview()">
                    </div>
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label">Discount (₹)</label>
                        <input type="number" name="discount" id="discountInput" class="field-input" value="0" step="1" min="0" oninput="updatePreview()">
                    </div>
                    <div class="field">
                        <label class="field-label">Round Off (₹)</label>
                        <input type="number" name="round_off" id="roundOffInput" class="field-input" value="0" step="1" oninput="updatePreview()" placeholder="e.g. −20">
                    </div>
                </div>
            </div>

            {{-- 3. Payment Modes --}}
            <div class="card">
                <div class="card-title">
                    <span><span class="card-title-icon"></span> Payment</span>
                    <button type="button" onclick="addPaymentRow()" class="btn-add-mode">+ Add Mode</button>
                </div>

                <div id="paymentRows">
                    <div class="pay-row" data-index="0">
                        <div class="pay-row-top">
                            <select class="pay-mode payment-mode-select" onchange="onPaymentModeChange(this)">
                                <option value="cash" selected>Cash</option>
                                <option value="upi">UPI</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="old_gold">Old Gold</option>
                                <option value="old_silver">Old Silver</option>
                                <option value="other">Other</option>
                            </select>
                            <input type="number" class="pay-amount payment-amount" placeholder="Amount (₹)" step="0.01" min="0" oninput="recalcPaymentBalance()">
                            <button type="button" onclick="removePaymentRow(this)" class="pay-remove" title="Remove" style="display:none;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
                        </div>
                        <div class="pay-details" style="display:none;"></div>
                    </div>
                </div>

                <div id="paymentBalanceBar" class="balance-bar">
                    <span>Bill: <span id="balanceBill">₹0</span></span>
                    <span>Paid: <span id="balancePaid">₹0</span></span>
                    <span id="balanceRemaining" style="color: var(--danger);">Remaining: ₹0</span>
                </div>
            </div>
        </form>

        {{-- ── RIGHT: Sticky summary ──────────────────────── --}}
        <div class="pos-sidebar">
            <div class="summary-card">
                <div class="summary-title">
                    <span class="summary-title-text">Price Summary</span>
                    <span class="summary-live">LIVE</span>
                </div>

                <div class="summary-rows" id="pricePreview">
                    <div class="summary-row">
                        <span class="summary-row-label">Gold Value</span>
                        <span class="summary-row-value">₹0.00</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-row-label">Making</span>
                        <span class="summary-row-value">₹0.00</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-row-label">GST</span>
                        <span class="summary-row-value">₹0.00</span>
                    </div>
                    <hr class="summary-divider">
                    <div class="summary-total">
                        <span class="summary-total-label">Total</span>
                        <span class="summary-total-value">₹0.00</span>
                    </div>
                </div>
            </div>

            <button type="button" class="btn-sale" onclick="completeSale()" id="completeSaleBtn">
                Complete Sale
            </button>
            <div class="sale-hint">Payments must cover the full bill amount</div>
        </div>

    </div>
</div>

<script>
const CSRF = '{{ csrf_token() }}';
const CUSTOMER_ID = {{ $customer->id }};
const POS_SELL_URL = @json(route('pos.sell'));
const INVOICE_BASE_URL = @json(url('/invoices'));
let lastPreview = null;
let paymentRowIndex = 1;

@if(request('item_id'))
    window.addEventListener('DOMContentLoaded', () => showItemDetails());
@endif

// ─── Item selection ──────────────────────────────────────
function showItemDetails() {
    const sel = document.getElementById('itemSelect');
    const opt = sel.options[sel.selectedIndex];
    const strip = document.getElementById('itemStrip');

    if (!opt.value) { strip.style.display = 'none'; return; }

    strip.style.display = 'grid';
    document.getElementById('stripBarcode').textContent = opt.dataset.barcode;
    document.getElementById('stripDesign').textContent = opt.dataset.design;
    document.getElementById('stripPurity').textContent = opt.dataset.purity + 'K';
    document.getElementById('stripWeight').textContent = opt.dataset.weight + 'g';

    updatePreview();
}

// ─── Price preview ───────────────────────────────────────
let previewTimer = null;
function updatePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(_fetchPreview, 250);
}

function _fetchPreview() {
    const itemId = document.getElementById('itemSelect').value;
    if (!itemId) return;

    const goldRate = document.getElementById('goldRate').value;
    if (!goldRate || Number(goldRate) <= 0) {
        document.getElementById('pricePreview').innerHTML =
            '<div style="color:#64748b;font-size:14px;padding:8px 0;">Enter gold rate to see preview</div>';
        return;
    }

    document.getElementById('pricePreview').innerHTML =
        '<div style="color:var(--muted);font-size:14px;padding:8px 0;">Calculating...</div>';

    fetch('/api/price-preview', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            item_id: itemId,
            customer_id: CUSTOMER_ID,
            gold_rate: goldRate,
            making: document.getElementById('makingInput').value || 0,
            stone: document.getElementById('stoneInput').value || 0,
            discount: document.getElementById('discountInput').value || 0,
            round_off: document.getElementById('roundOffInput').value || 0,
        })
    })
    .then(r => r.ok ? r.json() : r.json().then(j => { throw new Error(j.message || 'Error'); }))
    .then(d => {
        lastPreview = d;

        const lines = [
            ['Gold Value', '\u20B9' + n(d.gold_value)],
            ['Making', '\u20B9' + n(d.making)],
            d.stone > 0 ? ['Stone', '\u20B9' + n(d.stone)] : null,
            d.wastage_charge > 0 ? ['Wastage', '\u20B9' + n(d.wastage_charge)] : null,
            ['GST (' + d.gst_rate + '%)', '\u20B9' + n(d.gst)],
            d.discount > 0 ? ['Discount', '\u2212\u20B9' + n(d.discount)] : null,
            d.round_off != 0 ? ['Round Off', '\u20B9' + n(d.round_off)] : null,
        ].filter(Boolean);

        let html = lines.map(l =>
            '<div class="summary-row"><span class="summary-row-label">' + l[0] + '</span><span class="summary-row-value">' + l[1] + '</span></div>'
        ).join('');

        html += '<hr class="summary-divider">';
        html += '<div class="summary-total"><span class="summary-total-label">Total</span><span class="summary-total-value">\u20B9' + n(d.total) + '</span></div>';

        document.getElementById('pricePreview').innerHTML = html;

        // Auto-fill first payment if empty
        const firstAmt = document.querySelector('.pay-row[data-index="0"] .payment-amount');
        if (firstAmt && (!firstAmt.value || firstAmt.value === '0')) {
            firstAmt.value = d.total.toFixed(2);
        }

        recalcPaymentBalance();
    })
    .catch(err => {
        console.error(err);
        document.getElementById('pricePreview').innerHTML =
            '<div style="color:#ef4444;font-size:14px;padding:8px 0;">Error loading preview</div>';
    });
}

function n(v) { return Number(v).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }

// ─── Payment rows ────────────────────────────────────────
function addPaymentRow() {
    const idx = paymentRowIndex++;
    const c = document.getElementById('paymentRows');
    const div = document.createElement('div');
    div.className = 'pay-row';
    div.dataset.index = idx;
    div.innerHTML = '<div class="pay-row-top">'
        + '<select class="pay-mode payment-mode-select" onchange="onPaymentModeChange(this)">'
        + '<option value="cash">Cash</option>'
        + '<option value="upi">UPI</option>'
        + '<option value="bank">Bank Transfer</option>'
        + '<option value="old_gold">Old Gold</option>'
        + '<option value="old_silver">Old Silver</option>'
        + '<option value="other">Other</option>'
        + '</select>'
        + '<input type="number" class="pay-amount payment-amount" placeholder="Amount (\u20B9)" step="0.01" min="0" oninput="recalcPaymentBalance()">'
        + '<button type="button" onclick="removePaymentRow(this)" class="pay-remove" title="Remove">\u2715</button>'
        + '</div>'
        + '<div class="pay-details" style="display:none;"></div>';
    c.appendChild(div);
}

function removePaymentRow(btn) {
    btn.closest('.pay-row').remove();
    recalcPaymentBalance();
}

function onPaymentModeChange(select) {
    const row = select.closest('.pay-row');
    const details = row.querySelector('.pay-details');
    const mode = select.value;

    if (mode === 'old_gold' || mode === 'old_silver') {
        const label = mode === 'old_gold' ? 'Gold' : 'Silver';
        const pLabel = mode === 'old_gold' ? 'Purity (Karat)' : 'Purity (Millesimal, e.g. 925)';
        const pDef = mode === 'old_gold' ? '22' : '925';
        const pStep = mode === 'old_gold' ? '0.1' : '1';

        details.innerHTML = '<div class="metal-grid">'
            + '<div class="field"><label class="field-label">Gross Weight (g)</label>'
            + '<input type="number" class="field-input metal-gross" placeholder="0.000" step="0.001" min="0" oninput="calcMetalValue(this)"></div>'
            + '<div class="field"><label class="field-label">' + pLabel + '</label>'
            + '<input type="number" class="field-input metal-purity" value="' + pDef + '" step="' + pStep + '" min="0" oninput="calcMetalValue(this)"></div>'
            + '<div class="field"><label class="field-label">Test Loss (%)</label>'
            + '<input type="number" class="field-input metal-test-loss" value="2" step="0.1" min="0" max="100" oninput="calcMetalValue(this)"></div>'
            + '<div class="field"><label class="field-label">' + label + ' Rate (\u20B9/g fine)</label>'
            + '<input type="number" class="field-input metal-rate" placeholder="Rate per fine gram" step="0.01" min="0" oninput="calcMetalValue(this)"></div>'
            + '</div>'
            + '<div class="metal-result" style="display:none;">'
            + 'Fine: <span class="metal-fine-display">0.000</span>g \u00A0\u00B7\u00A0 Value: \u20B9<span class="metal-value-calc">0.00</span>'
            + '</div>';
        details.style.display = 'block';

        if (mode === 'old_gold') {
            const mainRate = document.getElementById('goldRate').value;
            if (mainRate) details.querySelector('.metal-rate').value = mainRate;
        }
    } else if (mode === 'upi' || mode === 'bank') {
        details.innerHTML = '<div class="ref-field">'
            + '<input type="text" class="field-input payment-ref" placeholder="'
            + (mode === 'upi' ? 'UPI Reference / Transaction ID' : 'Bank Transaction Reference')
            + '" maxlength="100"></div>';
        details.style.display = 'block';
    } else {
        details.innerHTML = '';
        details.style.display = 'none';
    }
}

function calcMetalValue(input) {
    const row = input.closest('.pay-row');
    const gross = Math.max(0, parseFloat(row.querySelector('.metal-gross')?.value) || 0);
    const purity = Math.max(0, parseFloat(row.querySelector('.metal-purity')?.value) || 0);
    const testLoss = Math.min(100, Math.max(0, parseFloat(row.querySelector('.metal-test-loss')?.value) || 0));
    const rate = Math.max(0, parseFloat(row.querySelector('.metal-rate')?.value) || 0);
    const mode = row.querySelector('.payment-mode-select').value;

    const net = Math.round(gross * (1 - testLoss / 100) * 1000) / 1000;
    const fine = mode === 'old_gold' ? Math.round(net * (purity / 24) * 1000) / 1000 : Math.round(net * (purity / 1000) * 1000) / 1000;
    const value = Math.round(fine * rate * 100) / 100;

    const display = row.querySelector('.metal-result');
    if (display) {
        display.style.display = (gross > 0 && rate > 0) ? 'block' : 'none';
        row.querySelector('.metal-fine-display').textContent = fine.toFixed(3);
        row.querySelector('.metal-value-calc').textContent = n(value);
    }

    const amtInput = row.querySelector('.payment-amount');
    if (value > 0) amtInput.value = value.toFixed(2);
    recalcPaymentBalance();
}

// ─── Balance bar ─────────────────────────────────────────
function recalcPaymentBalance() {
    const total = lastPreview ? lastPreview.total : 0;
    let paid = 0;
    document.querySelectorAll('.payment-amount').forEach(function(el) { paid += parseFloat(el.value) || 0; });

    document.getElementById('balanceBill').textContent = '\u20B9' + n(total);
    document.getElementById('balancePaid').textContent = '\u20B9' + n(paid);

    const remaining = total - paid;
    const el = document.getElementById('balanceRemaining');
    const bar = document.getElementById('paymentBalanceBar');

    if (Math.abs(remaining) < 0.5) {
        el.textContent = '\u2713 Settled';
        el.style.color = '#16a34a';
        bar.style.background = '#f0fdf4';
        bar.style.borderColor = '#bbf7d0';
    } else if (remaining > 0) {
        el.textContent = 'Remaining: \u20B9' + n(remaining);
        el.style.color = '#dc2626';
        bar.style.background = '#fef2f2';
        bar.style.borderColor = '#fecaca';
    } else {
        el.textContent = 'Overpaid: \u20B9' + n(Math.abs(remaining));
        el.style.color = '#f59e0b';
        bar.style.background = '#fffbeb';
        bar.style.borderColor = '#fde68a';
    }
}

// ─── Complete Sale ───────────────────────────────────────
function completeSale() {
    const itemId = document.getElementById('itemSelect').value;
    if (!itemId) return window.showToast('Please select an item first.', 'error');

    const goldRate = document.getElementById('goldRate').value;
    if (!goldRate || Number(goldRate) <= 0) return window.showToast('Enter a valid gold rate.', 'error');

    const payments = [];
    let totalPaid = 0;
    let valid = true;

    document.querySelectorAll('.pay-row').forEach(function(row) {
        const mode = row.querySelector('.payment-mode-select').value;
        const amount = parseFloat(row.querySelector('.payment-amount').value) || 0;
        if (amount <= 0) return;

        const entry = { mode: mode, amount: amount };

        if (mode === 'old_gold' || mode === 'old_silver') {
            entry.metal_gross_weight = parseFloat(row.querySelector('.metal-gross') ? row.querySelector('.metal-gross').value : 0) || 0;
            entry.metal_purity = parseFloat(row.querySelector('.metal-purity') ? row.querySelector('.metal-purity').value : 0) || 0;
            entry.metal_test_loss = parseFloat(row.querySelector('.metal-test-loss') ? row.querySelector('.metal-test-loss').value : 0) || 0;
            entry.metal_rate_per_gram = parseFloat(row.querySelector('.metal-rate') ? row.querySelector('.metal-rate').value : 0) || 0;

            if (entry.metal_gross_weight <= 0 || entry.metal_rate_per_gram <= 0) {
                window.showToast('Please fill in ' + (mode === 'old_gold' ? 'gold' : 'silver') + ' weight and rate.', 'error');
                valid = false;
                return;
            }
        }

        if (mode === 'upi' || mode === 'bank') {
            entry.reference = row.querySelector('.payment-ref') ? row.querySelector('.payment-ref').value : '';
        }

        payments.push(entry);
        totalPaid += amount;
    });

    if (!valid) return;
    if (!payments.length) return window.showToast('Add at least one payment mode.', 'error');

    const billTotal = lastPreview ? lastPreview.total : 0;
    if (billTotal > 0 && totalPaid < billTotal - 0.5) {
        if (!confirm('Payment is short by \u20B9' + (billTotal - totalPaid).toFixed(2) + '. Proceed anyway?')) return;
    }

    const btn = document.getElementById('completeSaleBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';

    fetch(POS_SELL_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            customer_id: CUSTOMER_ID,
            item_id: itemId,
            gold_rate: goldRate,
            making: document.getElementById('makingInput').value || 0,
            stone: document.getElementById('stoneInput').value || 0,
            discount: document.getElementById('discountInput').value || 0,
            round_off: document.getElementById('roundOffInput').value || 0,
            payments: payments,
        }),
    })
    .then(function(r) {
        if (!r.ok) return r.json().then(function(j) {
            var errs = j.errors ? Object.values(j.errors).flat().join('\n') : '';
            throw new Error((j.message || 'Server error') + (errs ? '\n' + errs : ''));
        });
        return r.json();
    })
    .then(function(res) {
        if (res.invoice_id) {
            window.location.href = INVOICE_BASE_URL + '/' + res.invoice_id;
        } else {
            window.showToast('Error completing sale', 'error');
            btn.disabled = false;
            btn.textContent = 'Complete Sale';
        }
    })
    .catch(function(err) {
        window.showToast('Error: ' + err.message, 'error');
        btn.disabled = false;
        btn.textContent = 'Complete Sale';
    });
}

const saleFormEl = document.getElementById('saleForm');
if (saleFormEl && saleFormEl.dataset.boundSubmit !== 'true') {
    saleFormEl.dataset.boundSubmit = 'true';
    saleFormEl.addEventListener('submit', function(event) {
        event.preventDefault();
        completeSale();
    });
}

// ─── Barcode / design live suggestions ───────────────────
(function() {
    var barcodeInput = document.getElementById('barcodeInput');
    var suggBox      = document.getElementById('barcodeSuggestions');
    var barcodeWrap  = barcodeInput ? barcodeInput.closest('.barcode-wrap') : null;
    var activeIdx    = -1;
    var currentMatches = [];

    function getItemOptions() {
        return Array.from(document.getElementById('itemSelect').options).filter(function(o) { return o.value; });
    }

    function escText(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function setActive(idx) {
        var items = suggBox.querySelectorAll('.barcode-sug-item');
        items.forEach(function(el) { el.classList.remove('active'); });
        if (idx >= 0 && idx < items.length) {
            activeIdx = idx;
            items[idx].classList.add('active');
            items[idx].scrollIntoView({ block: 'nearest' });
        } else {
            activeIdx = -1;
        }
    }

    function selectItem(option) {
        document.getElementById('itemSelect').value = option.value;
        showItemDetails();
        barcodeInput.value = '';
        suggBox.style.display = 'none';
        if (barcodeWrap) barcodeWrap.classList.remove('drop-up');
        activeIdx = -1;
        currentMatches = [];
    }

    function closeSuggestions() {
        suggBox.style.display = 'none';
        if (barcodeWrap) barcodeWrap.classList.remove('drop-up');
        activeIdx = -1;
    }

    function updateSuggestionDirection() {
        if (!barcodeWrap || !suggBox || suggBox.style.display !== 'block') return;
        barcodeWrap.classList.remove('drop-up');

        var styles = window.getComputedStyle(suggBox);
        var maxHeight = parseFloat(styles.maxHeight) || suggBox.scrollHeight || 280;
        var panelHeight = Math.min(suggBox.scrollHeight || maxHeight, maxHeight) + 10;
        var wrapRect = barcodeWrap.getBoundingClientRect();
        var spaceBelow = window.innerHeight - wrapRect.bottom;
        var spaceAbove = wrapRect.top;

        if (spaceBelow < panelHeight && spaceAbove > spaceBelow) {
            barcodeWrap.classList.add('drop-up');
        }
    }

    function showSuggestions(q) {
        suggBox.innerHTML = '';
        activeIdx = -1;
        q = q.trim().toLowerCase();
        if (!q) { suggBox.style.display = 'none'; currentMatches = []; return; }

        currentMatches = getItemOptions().filter(function(o) {
            return (o.dataset.barcode || '').toLowerCase().includes(q)
                || (o.dataset.design  || '').toLowerCase().includes(q);
        }).slice(0, 8);

        if (!currentMatches.length) { suggBox.style.display = 'none'; return; }

        currentMatches.forEach(function(o, i) {
            var div = document.createElement('div');
            div.className = 'barcode-sug-item';
            div.dataset.idx = i;
            div.innerHTML =
                '<span class="barcode-sug-barcode">' + escText(o.dataset.barcode || '') + '</span>'
                + ' — ' + escText(o.dataset.design || 'N/A')
                + ' <span style="color:var(--muted);font-size:12px;">(' + escText(o.dataset.purity || '') + 'K · ' + escText(o.dataset.weight || '') + 'g)</span>';
            div.addEventListener('mousedown', function(e) {
                e.preventDefault();
                selectItem(o);
            });
            suggBox.appendChild(div);
        });

        suggBox.style.display = 'block';
        updateSuggestionDirection();
    }

    barcodeInput.addEventListener('input', function() {
        showSuggestions(this.value);
    });

    barcodeInput.addEventListener('blur', function() {
        setTimeout(function() { closeSuggestions(); }, 150);
    });

    barcodeInput.addEventListener('focus', function() {
        if (this.value.trim()) showSuggestions(this.value);
    });

    window.addEventListener('resize', function() {
        updateSuggestionDirection();
    }, { passive: true });

    // ─── Keyboard navigation + Enter/Escape ──────────────
    barcodeInput.addEventListener('keydown', function(e) {
        var sugVisible = suggBox.style.display === 'block';
        var count = currentMatches.length;

        // Arrow Down: move highlight to next suggestion
        if (e.key === 'ArrowDown' && sugVisible && count > 0) {
            e.preventDefault();
            setActive(activeIdx < count - 1 ? activeIdx + 1 : 0);
            return;
        }

        // Arrow Up: move highlight to previous suggestion
        if (e.key === 'ArrowUp' && sugVisible && count > 0) {
            e.preventDefault();
            setActive(activeIdx > 0 ? activeIdx - 1 : count - 1);
            return;
        }

        // Escape: close the dropdown
        if (e.key === 'Escape' && sugVisible) {
            e.preventDefault();
            closeSuggestions();
            return;
        }

        // Enter: select highlighted suggestion, or fall back to exact/API match
        if (e.key !== 'Enter') return;
        e.preventDefault();

        // If a suggestion is highlighted, select it
        if (sugVisible && activeIdx >= 0 && activeIdx < count) {
            selectItem(currentMatches[activeIdx]);
            return;
        }

        var barcode = this.value.trim();
        if (!barcode) return;

        // Try exact match from already-loaded options first
        var opts = getItemOptions();
        var exact = opts.find(function(o) { return (o.dataset.barcode || '').toLowerCase() === barcode.toLowerCase(); });
        if (exact) {
            selectItem(exact);
            return;
        }

        // Fallback: API lookup (handles barcodes not yet in DOM options)
        barcodeInput.classList.add('loading');
        fetch('/api/item-by-barcode/' + encodeURIComponent(barcode), { headers: { 'Accept': 'application/json' } })
            .then(function(r) { return r.ok ? r.json() : r.json().then(function(j) { throw new Error(j.message || 'Not found'); }); })
            .then(function(item) {
                if (item.error) return window.showToast(item.error, 'error');
                document.getElementById('itemSelect').value = item.id;
                showItemDetails();
            })
            .catch(function(err) { window.showToast('Error: ' + err, 'error'); })
            .finally(function() { barcodeInput.classList.remove('loading'); });
        this.value = '';
        closeSuggestions();
    });
}());
</script>
</x-app-layout>
