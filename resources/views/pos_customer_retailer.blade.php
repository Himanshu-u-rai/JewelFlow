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
    }

    /* ─── Top Bar ─────────────────────────────────────── */
    .pos-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: linear-gradient(120deg, #f7faff 0%, #edf3fb 65%, #e5edf9 100%);
        border: 1px solid #c8d5ea;
        border-radius: 16px;
        padding: 16px 20px;
        margin-bottom: 20px;
        box-shadow: 0 14px 28px rgba(20, 33, 61, 0.12);
        position: relative;
        overflow: hidden;
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
    .pos-header-left { display: flex; align-items: center; gap: 16px; }
    .pos-back {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 14px; border-radius: 10px; font-size: 13px; font-weight: 600;
        background: #14213d; color: #f8fbff; text-decoration: none;
        border: 1px solid #14213d; transition: background 0.15s, color 0.15s, border-color 0.15s;
        box-shadow: 0 10px 18px rgba(20, 33, 61, 0.22);
        position: relative;
        z-index: 1;
    }
    .pos-back:hover { background: #fca311; color: #14213d; border-color: #fca311; }
    .pos-customer-name { font-size: 20px; font-weight: 700; color: var(--ink); }
    .pos-customer-phone { font-size: 14px; color: var(--muted); margin-top: 2px; }
    .pos-badge {
        padding: 6px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700;
        letter-spacing: 0.04em; text-transform: uppercase;
        background: #14213d;
        color: #f8fbff;
        border: 1px solid #14213d;
        position: relative;
        z-index: 1;
    }
    .loyalty-pill {
        margin-left: 12px;
        padding: 6px 12px;
        border-radius: 12px;
        background: #fef3c7;
        border: 1px solid #fde68a;
        font-size: 13px;
        font-weight: 600;
        color: #92400e;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        max-width: 100%;
        line-height: 1.2;
    }
    .loyalty-pill svg {
        flex-shrink: 0;
    }
    .loyalty-pill-points {
        font-weight: 700;
        white-space: nowrap;
    }
    .loyalty-pill-value {
        color: #a16207;
        white-space: nowrap;
    }

    /* ─── Main 2-col layout ───────────────────────────── */
    .pos-body { display: grid; grid-template-columns: 1fr 380px; gap: 24px; align-items: start; }
    .pos-main { display: flex; flex-direction: column; gap: 20px; }

    /* ─── Cards ───────────────────────────────────────── */
    .card {
        background: var(--card); border: 1px solid var(--border);
        border-radius: 16px; padding: 20px 24px;
        box-shadow: 0 12px 24px rgba(20, 33, 61, 0.08);
    }
    .card-title {
        font-size: 15px; font-weight: 700; color: var(--ink); margin-bottom: 16px;
        display: flex; align-items: center; justify-content: space-between;
    }
    .card-title-icon { margin-right: 8px; font-size: 18px; }

    /* ─── Form elements ───────────────────────────────── */
    .field { margin-bottom: 16px; }
    .field:last-child { margin-bottom: 0; }
    .field-label { display: block; font-size: 13px; font-weight: 600; color: var(--muted); margin-bottom: 6px; }
    .field-input, .field-select {
        width: 100%; padding: 12px 14px; font-size: 16px; font-weight: 500;
        border: 1.5px solid var(--border); border-radius: 12px;
        background: #ffffff; color: var(--ink);
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .field-input:focus, .field-select:focus {
        outline: none; border-color: var(--accent); background: #fff;
        box-shadow: 0 0 0 3px rgba(20,33,61,0.12);
    }
    .field-row { display: grid; gap: 16px; margin-bottom: 16px; }
    .field-row:last-child { margin-bottom: 0; }
    .field-row .field { margin-bottom: 0; }
    .field-row-2 { grid-template-columns: 1fr 1fr; }
    .field-row-3 { grid-template-columns: 1fr 1fr 1fr; }

    /* ─── Offer mode control ──────────────────────────── */
    .offer-mode-wrap {
        background: #f8fbff;
        border: 1px solid var(--border);
        border-radius: 14px;
        padding: 10px 12px;
    }
    .offer-mode-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .offer-mode-label {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--muted);
    }
    .offer-mode-state {
        font-size: 11px;
        font-weight: 700;
        padding: 4px 8px;
        border-radius: 9999px;
        border: 1px solid transparent;
    }
    .offer-mode-state.auto {
        color: #7a4d02;
        background: #fff4de;
        border-color: #f2d29c;
    }
    .offer-mode-state.skip {
        color: #b91c1c;
        background: #fff1f2;
        border-color: #fecaca;
    }
    .offer-mode-toggle {
        margin-top: 8px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }
    .offer-mode-btn {
        padding: 8px 10px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: #ffffff;
        color: var(--ink-soft);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: all .15s ease;
    }
    .offer-mode-btn:hover {
        border-color: #94a3b8;
    }
    .offer-mode-btn.active-auto {
        border-color: #14213d;
        color: #ffffff;
        background: #011133;
    }
    .offer-mode-btn.active-skip {
      border-color: #14213d;
        color: #ffffff;
        background: #011133;
    }
    .offer-mode-help {
        margin-top: 8px;
        font-size: 11px;
        color: var(--muted);
    }

    /* ─── Barcode ──────────────────────────────────────── */
    .barcode-wrap {
        background: #f8fbff; border: 1.5px dashed rgba(20,33,61,0.26);
        border-radius: 16px; padding: 16px; margin-bottom: 16px;
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
        padding: 10px 14px; cursor: pointer;
        font-size: 13px; line-height: 1.4;
        border-bottom: 1px solid #f8fafc;
        color: #0f172a;
        transition: background .12s ease, padding-left .12s ease;
    }

    .barcode-sug-item:last-child { border-bottom: none; }
    .barcode-sug-item:hover { background: #fffbeb; padding-left: 18px; }
    .barcode-sug-barcode { font-weight: 700; color: #14213d; }
    .barcode-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .barcode-badge {
        font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        color: #7a4d02; background: #fff4de;
        padding: 3px 10px; border-radius: 9999px;
    }
    .barcode-clear {
        font-size: 12px; color: var(--danger); cursor: pointer; font-weight: 600;
        background: none; border: none; padding: 4px 8px; border-radius: 8px;
    }
    .barcode-clear:hover { background: rgba(220,38,38,0.06); }

    /* ─── Item chip ────────────────────────────────────── */
    .items-list { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
    .items-list-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 4px;
    }
    .items-list-title { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
    .items-list-badge {
        font-size: 11px; font-weight: 700; color: #7a4d02; background: #fff4de;
        padding: 2px 8px; border-radius: 9999px;
    }
    .items-list-clear {
        font-size: 12px; color: var(--danger); cursor: pointer; font-weight: 600;
        background: none; border: none; padding: 4px 8px; border-radius: 8px;
    }
    .items-list-clear:hover { background: rgba(220,38,38,0.06); }
    .item-chip {
        display: flex; align-items: center; gap: 14px;
        background: var(--gold-light); border: 1px solid var(--gold-border);
        border-radius: 14px; padding: 12px 16px;
        box-shadow: inset 0 0 0 1px rgba(252, 163, 17, 0.08);
    }
    .item-chip-icon {
        width: 44px; height: 44px; border-radius: 12px; display: flex;
        align-items: center; justify-content: center; font-size: 24px;
        background: #f3f4f6; overflow: hidden; flex-shrink: 0;
    }
    .item-chip-icon img {
        width: 100%; height: 100%; object-fit: cover;
    }
    .item-chip-info { flex: 1; min-width: 0; }
    .item-chip-design { font-size: 15px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-chip-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
    .item-chip-price { font-size: 18px; font-weight: 800; color: var(--accent); white-space: nowrap; }
    .item-chip-remove {
        background: none; border: none; color: var(--danger); font-size: 18px;
        cursor: pointer; padding: 4px; border-radius: 8px; line-height: 1; flex-shrink: 0;
    }
    .item-chip-remove:hover { background: rgba(220,38,38,0.08); }

    /* ─── Sticky summary ──────────────────────────────── */
    .pos-sidebar { position: sticky; top: 20px; }
    .summary-card {
        background: linear-gradient(180deg, #ffffff 0%, #f7f9fd 100%);
        border: 1.5px solid rgba(20,33,61,0.18);
        border-radius: 16px; padding: 24px;
        box-shadow: 0 18px 32px rgba(20, 33, 61, 0.14);
    }
    .summary-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 8px 0; font-size: 14px; color: var(--ink-soft);
    }
    .summary-row-val { font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; }
    .summary-divider { height: 1px; background: var(--border); margin: 6px 0; }
    .summary-total {
        display: flex; justify-content: space-between; align-items: baseline;
        padding: 14px 0 0; margin-top: 6px;
        border-top: 2px solid #f2d29c;
    }
    .summary-total-label { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
    .summary-total-val { font-size: 28px; font-weight: 800; color: #14213d; }

    /* ─── Payment cards ───────────────────────────────── */
    .pay-mode-grid {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .pay-mode-btn {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 2px;
        padding: 6px 0;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 600;
        border: 1.5px solid var(--border);
        background: #ffffff;
        color: var(--ink-soft);
        cursor: pointer;
        transition: all 0.15s;
        box-shadow: 0 2px 6px rgba(20, 33, 61, 0.06);
        height: 38px;
        line-height: 1.1;
        flex-grow: 1;
        flex-basis: 80px;
    }
    .pay-mode-btn:hover {
        border-color: #fca311;
        color: #7a4d02;
        background: #ffe3b3; /* solid color, no gradient */
    }
    .pay-mode-btn.active {
        border-color: #14213d;
        background: #000d41; /* solid color, no gradient */
        color: #ffffff;
    }
    .pay-mode-icon { font-size: 22px; }
    .pay-row {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 14px; background: var(--bg); border-radius: 12px;
        border: 1px solid var(--border); margin-bottom: 8px;
    }
    .pay-row-label {
        min-width: 70px; font-size: 12px; font-weight: 700; text-transform: uppercase;
        color: var(--accent); letter-spacing: 0.04em;
    }
    .pay-row-input {
        flex: 1; padding: 8px 12px; font-size: 16px; font-weight: 600;
        border: 1.5px solid var(--border); border-radius: 12px;
        background: #fff; color: var(--ink);
    }
    .pay-row-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(15,118,110,0.1); }
    .pay-row-ref {
        width: 130px; padding: 8px 10px; font-size: 13px;
        border: 1px solid var(--border); border-radius: 12px; background: #fff; color: var(--muted);
    }
    .pay-row-ref:focus { outline: none; border-color: var(--accent); }
    .pay-row-ref-required { border-color: #f87171 !important; background: #fff5f5 !important; color: #dc2626 !important; }
    .pay-row-ref-required:focus { border-color: #ef4444 !important; box-shadow: 0 0 0 2px rgba(239,68,68,0.15); }
    .pay-row-remove {
        background: none; border: none; color: var(--danger); font-size: 20px;
        cursor: pointer; padding: 4px; border-radius: 8px; line-height: 1;
    }
    .pay-row-remove:hover { background: rgba(220,38,38,0.08); }
    .pay-remaining { font-size: 13px; font-weight: 600; margin-top: 4px; }

    /* ─── Metal payment sub-fields ────────────────────── */
    .pay-metal-fields {
        display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px;
        padding: 10px 14px; background: #fffbeb; border: 1px solid #fde68a;
        border-radius: 12px; margin: -4px 0 8px;
    }
    .pay-metal-fields label { font-size: 11px; font-weight: 600; color: #92400e; margin-bottom: 2px; display: block; }
    .pay-metal-fields input {
        width: 100%; padding: 6px 8px; font-size: 13px; font-weight: 600;
        border: 1px solid #fde68a; border-radius: 8px; background: #fff; color: var(--ink);
    }
    .pay-metal-fields input:focus { outline: none; border-color: var(--gold); }

    /* ─── Buttons ──────────────────────────────────────── */
    .btn-sell {
        width: 100%; padding: 18px; font-size: 18px; font-weight: 700;
        background: #14213d;
        color: #fff; border: none; border-radius: 14px; cursor: pointer;
        transition: transform 0.1s, box-shadow 0.15s;
        box-shadow: 0 12px 26px rgba(20, 33, 61, 0.28);
    }
    .btn-sell:hover { transform: translateY(-1px); box-shadow: 0 16px 30px rgba(20,33,61,0.34); background: #0f1a33; }
    .btn-sell:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

    .text-meta-muted {
        color: #64748b;
        font-size: 12px;
    }

    .roundoff-controls {
        display: flex;
        gap: 6px;
        align-items: stretch;
    }

    .discount-percent-wrap {
        position: relative;
    }

    .discount-percent-input {
        padding-right: 34px;
    }

    .discount-percent-symbol {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 14px;
        font-weight: 700;
        color: var(--muted);
        pointer-events: none;
    }

    .roundoff-input {
        flex: 1;
        min-width: 0;
    }

    .roundoff-btn {
        padding: 0 10px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        background: #fff;
        font-size: 18px;
        font-weight: 700;
        cursor: pointer;
        line-height: 1;
    }

    .roundoff-btn-down {
        color: #ef4444;
    }

    .roundoff-btn-up {
        color: #10b981;
    }

    .offer-select-offset {
        margin-top: 8px;
    }

    .offer-discount-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
    }

    .offer-discount-value {
        font-weight: 700;
        color: #0f766e;
    }

    .pay-row-ref-note {
        min-width: 220px;
        display: flex;
        align-items: center;
        color: #0f766e;
    }

    .excess-alert {
        margin-top: 8px;
        padding: 10px 14px;
        background: #fef3c7;
        border: 1px solid #f59e0b;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .excess-alert-icon {
        flex-shrink: 0;
    }

    .excess-alert-label {
        font-size: 11px;
        color: #92400e;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .excess-alert-value {
        font-size: 20px;
        font-weight: 700;
        color: #b45309;
    }

    .redemption-note {
        margin-top: 8px;
        padding: 10px 12px;
        border-radius: 12px;
        font-size: 12px;
    }

    .redemption-note-info {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1e3a8a;
    }

    .redemption-note-warning {
        background: #fff7ed;
        border: 1px solid #fdba74;
        color: #9a3412;
    }

    .summary-title-tight {
        margin-bottom: 12px;
    }

    .summary-item-count {
        font-size: 12px;
        color: var(--muted);
    }

    .summary-row-danger {
        color: var(--danger);
    }

    .summary-gst-base {
        font-size: 11px;
        color: var(--muted);
    }

    .summary-loyalty-row {
        margin-top: 8px;
        padding: 6px 10px;
        background: #fef3c7;
        border: 1px solid #fde68a;
        border-radius: 12px;
        font-size: 12px;
        color: #92400e;
    }

    .summary-loyalty-points {
        font-weight: 700;
    }

    .btn-sell-offset {
        margin-top: 20px;
    }

    /* ─── Responsive ──────────────────────────────────── */
    @media (max-width: 900px) {
        .pos-page {
            padding: 12px 12px 24px;
        }
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
        .loyalty-pill {
            margin-left: 0;
        }
        .pos-badge {
            display: none;
        }
        .pos-body { grid-template-columns: 1fr; gap: 16px; }
        .pos-sidebar { position: static; }
        .card { padding: 16px; border-radius: 14px; }
        .field-row-2, .field-row-3 { grid-template-columns: 1fr; }
        .summary-card { padding: 18px; border-radius: 14px; }
        .summary-total-val { font-size: 22px; }
        .pay-mode-grid { gap: 6px; }
        .pay-mode-btn { flex-basis: 64px; height: 34px; font-size: 10px; }
        .pay-row { flex-wrap: wrap; padding: 10px 12px; }
        .pay-row-label { min-width: auto; width: 100%; margin-bottom: 4px; }
        .pay-row-input { font-size: 15px; }
        .pay-row-ref { width: 100%; }
        .pay-metal-fields { grid-template-columns: 1fr 1fr; }
        .btn-sell { padding: 14px; font-size: 16px; border-radius: 12px; }
        .barcode-wrap { padding: 12px; border-radius: 12px; }
        .item-chip { padding: 10px 12px; border-radius: 12px; gap: 10px; }
        .item-chip-icon { width: 38px; height: 38px; border-radius: 10px; }
        .item-chip-price { font-size: 15px; }
        .item-chip-design { font-size: 13px; }
        .offer-mode-wrap { padding: 8px 10px; border-radius: 12px; }
    }

    @media (max-width: 480px) {
        .pos-page {
            padding: 8px 8px 20px;
        }
        .pos-header {
            padding: 12px 14px;
            border-radius: 12px;
        }
        .pos-back { padding: 6px 10px; font-size: 12px; border-radius: 8px; }
        .pos-customer-name { font-size: 15px; }
        .pos-customer-phone { font-size: 12px; }
        .pos-badge { font-size: 10px; padding: 4px 10px; }
        .loyalty-pill {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 11px;
            gap: 4px;
        }
        .loyalty-pill svg {
            width: 13px;
            height: 13px;
        }
        .loyalty-pill-value {
            font-size: 10px;
        }
        .card { padding: 14px 12px; }
        .card-title { font-size: 14px; margin-bottom: 12px; }
        .field-label { font-size: 12px; }
        .field-input, .field-select { font-size: 14px; padding: 10px 12px; }
        .summary-card { padding: 14px; }
        .summary-row { font-size: 13px; padding: 6px 0; }
        .summary-total-val { font-size: 20px; }
        .pay-mode-btn { flex-basis: 56px; height: 32px; font-size: 9px; border-radius: 6px; }
        .pay-mode-icon { font-size: 18px; }
        .pay-metal-fields { grid-template-columns: 1fr; }
        .btn-sell { padding: 12px; font-size: 15px; }
        .item-chip-icon { width: 34px; height: 34px; }
        .item-chip-design { font-size: 12px; }
        .item-chip-meta { font-size: 11px; }
        .item-chip-price { font-size: 14px; }
    }
</style>

<div class="pos-page" x-data="retailerPos()">
    <!-- ─── Header ──────────────────────────────── -->
    <div class="pos-header">
        <div class="pos-header-left">
            <a href="{{ route('pos.index') }}" class="pos-back">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 19l-7-7 7-7"/></svg>
                Back
            </a>
            <div>
                <div class="pos-customer-name">{{ $customer->name }}</div>
                <div class="pos-customer-phone">{{ $customer->phone }}{{ $customer->email ? ' · '.$customer->email : '' }}</div>
            </div>
            @if(($customerLoyaltyPoints ?? 0) > 0)
            <div class="loyalty-pill">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <span class="loyalty-pill-points">{{ number_format($customerLoyaltyPoints) }} pts</span>
                <span class="loyalty-pill-value">₹{{ number_format(($customerLoyaltyPoints) * ($loyaltyPointValue ?? 0.25), 2) }}</span>
            </div>
            @endif
        </div>
        <div class="pos-badge">Retail Sale</div>
    </div>

    <!-- ─── Body: 2 columns ─────────────────────── -->
    <div class="pos-body">

        <!-- ══ LEFT COLUMN ══ -->
        <div class="pos-main">

            <!-- ── Item Selection Card ── -->
            <div class="card">
                <div class="card-title">
                    <span><span class="card-title-icon"></span> Select Item</span>
                </div>

                <!-- Barcode Scanner -->
                <div class="barcode-wrap" :class="{ 'drop-up': barcodeDropUp }">
                    <div class="barcode-top">
                        <span class="barcode-badge">Scan or Type</span>
                    </div>
                          <input type="text" class="field-input" placeholder="Scan or type barcode / design…"
                              @keydown.enter.prevent="lookupBarcode($event.target.value); $event.target.value = ''; barcodeSuggestions = []; barcodeDropUp = false"
                           @input="onBarcodeInput($event.target.value)"
                              @blur="setTimeout(() => { barcodeSuggestions = []; barcodeDropUp = false; }, 150)"
                           @focus="onBarcodeInput($event.target.value)"
                           x-ref="barcodeInput" autofocus autocomplete="off">
                    <div x-show="barcodeSuggestions.length > 0" class="barcode-suggestions">
                        <template x-for="s in barcodeSuggestions" :key="s.id">
                            <div class="barcode-sug-item"
                                 @mousedown.prevent="selectSuggestion(s.id)">
                                <span class="barcode-sug-barcode" x-text="s.barcode"></span>
                                <span x-text="' — ' + s.design"></span>
                                <span class="text-meta-muted" x-text="' (' + s.purity + 'K · ' + s.weight + 'g) — ₹' + s.price"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- OR dropdown -->
                <div class="field">
                    <label class="field-label">Or choose from stock</label>
                    <select class="field-select" @change="addItem($event.target.value); $event.target.value = ''">
                        <option value="">— Select an item —</option>
                        @foreach($items as $item)
                            <option value="{{ $item->id }}"
                                    data-design="{{ $item->design }}"
                                    data-category="{{ $item->category }}"
                                    data-sub_category="{{ $item->sub_category }}"
                                    data-weight="{{ $item->gross_weight }}"
                                    data-purity="{{ $item->purity }}"
                                    data-net="{{ $item->net_metal_weight }}"
                                    data-selling="{{ $item->selling_price }}"
                                    data-cost="{{ $item->cost_price }}"
                                    data-barcode="{{ $item->barcode }}"
                                    data-image="{{ $item->image ? asset('storage/' . $item->image) : '' }}">
                                {{ $item->barcode }} — {{ $item->design ?? $item->category }} ({{ number_format($item->gross_weight, 3) }}g · {{ $item->purity }}K) — ₹{{ number_format($item->selling_price, 0) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Selected items list -->
                <template x-if="items.length > 0">
                    <div>
                        <div class="items-list-header">
                            <span class="items-list-title">Selected Items <span class="items-list-badge" x-text="items.length"></span></span>
                            <button class="items-list-clear" @click="clearItems()"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-0.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear All</button>
                        </div>
                        <div class="items-list">
                            <template x-for="(itm, idx) in items" :key="itm.id">
                                <div class="item-chip">
                                    <div class="item-chip-icon">
                                        <template x-if="itm.image">
                                            <img :src="itm.image" :alt="itm.design">
                                        </template>
                                    </div>
                                    <div class="item-chip-info">
                                        <div class="item-chip-design" x-text="itm.design || itm.category"></div>
                                        <div class="item-chip-meta">
                                            <span x-text="itm.barcode"></span> ·
                                            <span x-text="itm.weight + 'g'"></span> ·
                                            <span x-text="itm.purity + 'K'"></span>
                                        </div>
                                    </div>
                                    <div class="item-chip-price" x-text="'₹' + Number(itm.selling).toLocaleString('en-IN')"></div>
                                    <button class="item-chip-remove" @click="removeItem(idx)" title="Remove">&times;</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ── Discount & Round-off Card ── -->
            <div class="card">
                <div class="card-title">
                    <span><span class="card-title-icon"></span> Discount & Round-off</span>
                </div>
                <div class="field-row field-row-3">
                    <div class="field">
                        <label class="field-label">Manual Discount (₹)</label>
                        <input type="number"
                               class="field-input"
                               x-model.number="discount"
                               step="0.01"
                               min="0"
                               @input="onDiscountAmountInput($event.target.value)">
                    </div>
                    <div class="field">
                        <label class="field-label">Percentage Discount (%)</label>
                        <div class="discount-percent-wrap">
                            <input type="number"
                                   class="field-input discount-percent-input"
                                   x-model.number="discountPercent"
                                   step="0.01"
                                   min="0"
                                   max="100"
                                   @input="onDiscountPercentInput($event.target.value)">
                            <span class="discount-percent-symbol">%</span>
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label">Round-off (₹)</label>
                        <div class="roundoff-controls">
                            <input type="number" class="field-input roundoff-input" x-model.number="roundOff" step="1" @input="recalc()">
                            <button type="button" @click="autoRound(-1)" title="Round down" class="roundoff-btn roundoff-btn-down">−</button>
                            <button type="button" @click="autoRound(1)" title="Round up" class="roundoff-btn roundoff-btn-up">+</button>
                        </div>
                    </div>
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label">Offer / Scheme</label>
                        <div class="offer-mode-wrap">
                            <div class="offer-mode-top">
                                <span class="offer-mode-label">Offer Mode</span>
                                <span class="offer-mode-state" :class="ignoreAutoOffer ? 'skip' : 'auto'" x-text="ignoreAutoOffer ? 'Skipped' : 'Auto Active'"></span>
                            </div>
                            <div class="offer-mode-toggle">
                                <button type="button"
                                        class="offer-mode-btn"
                                        :class="!ignoreAutoOffer ? 'active-auto' : ''"
                                        @click="enableAutoOfferForThisBill()">
                                    Auto Apply
                                </button>
                                <button type="button"
                                        class="offer-mode-btn"
                                        :class="ignoreAutoOffer ? 'active-skip' : ''"
                                        @click="skipOfferForThisBill()">
                                    Skip This Bill
                                </button>
                            </div>
                            <p class="offer-mode-help" x-text="ignoreAutoOffer ? 'No auto-offer will apply on this bill unless you manually choose one below.' : 'Best eligible auto-offer will apply if no manual offer is selected.'"></p>
                        </div>
                        <select class="field-select offer-select-offset" x-model="selectedOfferId" @change="onOfferSelectionChange()">
                            <option value="" x-text="ignoreAutoOffer ? 'Auto offer disabled for this bill' : 'Auto best offer (if eligible)'"></option>
                            <template x-for="offer in offers" :key="offer.id">
                                <option :value="String(offer.id)" x-text="offerLabel(offer)"></option>
                            </template>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label">Applied Offer Discount</label>
                        <div class="field-input offer-discount-box">
                            <span x-text="appliedOfferLabel()"></span>
                            <span class="offer-discount-value" x-text="'₹' + offerDiscountAmount().toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                        </div>
                    </div>
                </div>

                <div class="field-row field-row-2" x-show="redeemableEnrollments.length > 0">
                    <div class="field">
                        <label class="field-label">Gold Saving Redemption (Optional)</label>
                        <select class="field-select" x-model="schemeRedemptionEnrollmentId" @change="onRedemptionEnrollmentChange()">
                            <option value="">Do not redeem now</option>
                            <template x-for="plan in redeemableEnrollments" :key="plan.id">
                                <option :value="String(plan.id)" x-text="redemptionPlanLabel(plan)"></option>
                            </template>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label">Redeem Amount (₹)</label>
                        <input type="number"
                               class="field-input"
                               x-model.number="schemeRedemptionAmount"
                               step="0.01"
                               min="0"
                               :max="maxRedeemableForSelection()"
                               :disabled="!schemeRedemptionEnrollmentId"
                               @input="normalizeRedemptionAmount(); recalc()">
                    </div>
                </div>
            </div>

            <!-- ── Payment Card ── -->
            <div class="card">
                <div class="card-title">
                    <span><span class="card-title-icon"></span> Payment</span>
                </div>

                <!-- Mode buttons: Cash/Other/EMI always shown; UPI/Bank/Wallet only if configured -->
                <div class="pay-mode-grid">
                    <button type="button" class="pay-mode-btn" @click="addPayment('cash')"
                            :class="{'active': payments.some(p => p.mode === 'cash')}">
                        <span class="pay-mode-icon"></span> Cash
                    </button>
                    <template x-if="methodsForType('upi').length > 0">
                        <button type="button" class="pay-mode-btn" @click="addPayment('upi')"
                                :class="{'active': payments.some(p => p.mode === 'upi')}">
                            <span class="pay-mode-icon"></span> UPI
                        </button>
                    </template>
                    <template x-if="methodsForType('bank').length > 0">
                        <button type="button" class="pay-mode-btn" @click="addPayment('bank')"
                                :class="{'active': payments.some(p => p.mode === 'bank')}">
                            <span class="pay-mode-icon"></span> Bank
                        </button>
                    </template>
                    <template x-if="methodsForType('wallet').length > 0">
                        <button type="button" class="pay-mode-btn" @click="addPayment('wallet')"
                                :class="{'active': payments.some(p => p.mode === 'wallet')}">
                            <span class="pay-mode-icon"></span> Wallet
                        </button>
                    </template>
                    <button type="button" class="pay-mode-btn" @click="addPayment('old_gold')"
                            :class="{'active': payments.some(p => p.mode === 'old_gold')}">
                        <span class="pay-mode-icon"></span> Old Gold
                    </button>
                    <button type="button" class="pay-mode-btn" @click="addPayment('old_silver')"
                            :class="{'active': payments.some(p => p.mode === 'old_silver')}">
                        <span class="pay-mode-icon"></span> Old Silver
                    </button>
                    <button type="button" class="pay-mode-btn" @click="addPayment('other')"
                            :class="{'active': payments.some(p => p.mode === 'other')}">
                        <span class="pay-mode-icon"></span> Other
                    </button>
                    <button type="button" class="pay-mode-btn" @click="addPayment('emi')"
                            :class="{'active': payments.some(p => p.mode === 'emi')}">
                        <span class="pay-mode-icon"></span> EMI
                    </button>
                </div>

                <!-- Payment rows -->
                <template x-for="(pay, idx) in payments" :key="idx">
                    <div>
                        <div class="pay-row">
                            <span class="pay-row-label" x-text="pay.reference && methodsForType(pay.mode).length > 1 ? pay.reference : pay.mode.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())"></span>
                            <template x-if="pay.mode !== 'emi'">
                                <input type="number" class="pay-row-input" x-model.number="pay.amount"
                                       placeholder="₹ Amount" step="1" min="0" @input="recalcPayments()">
                            </template>
                            <template x-if="pay.mode === 'emi'">
                                <div class="pay-row-ref pay-row-ref-note">
                                    Continue to EMI form for down payment + first EMI details
                                </div>
                            </template>
                            {{-- Reference field: dropdown when multiple accounts, text input otherwise --}}
                            <template x-if="!['old_gold','old_silver','emi'].includes(pay.mode)">
                                <span>
                                    <template x-if="methodsForType(pay.mode).length > 1">
                                        <select class="pay-row-ref"
                                                :class="{ 'pay-row-ref-required': !pay.payment_method_id }"
                                                @change="onMethodSelect(pay, $event)">
                                            <option value="">— Select account * —</option>
                                            <template x-for="m in methodsForType(pay.mode)" :key="m.id">
                                                <option :value="m.id" x-text="m.account_label" :selected="pay.payment_method_id == m.id"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="methodsForType(pay.mode).length === 1">
                                        <input type="text" class="pay-row-ref" x-model="pay.reference" placeholder="Ref #">
                                    </template>
                                    <template x-if="methodsForType(pay.mode).length === 0">
                                        <input type="text" class="pay-row-ref" x-model="pay.reference" placeholder="Ref #">
                                    </template>
                                </span>
                            </template>
                            <button class="pay-row-remove" @click="removePayment(idx)" title="Remove">&times;</button>
                        </div>
                        <!-- Metal sub-fields for old gold/silver -->
                        <template x-if="pay.mode === 'old_gold' || pay.mode === 'old_silver'">
                            <div class="pay-metal-fields">
                                <div>
                                    <label>Gross Wt (g)</label>
                                    <input type="number" x-model.number="pay.metal_gross_weight" step="0.001" min="0" @input="calcMetalValue(idx)">
                                </div>
                                <div>
                                    <label x-text="pay.mode === 'old_gold' ? 'Purity (K)' : 'Purity (‰)'">Purity</label>
                                    <input type="number" x-model.number="pay.metal_purity" step="0.1" min="0" @input="calcMetalValue(idx)">
                                </div>
                                <div>
                                    <label>Test Loss %</label>
                                    <input type="number" x-model.number="pay.metal_test_loss" step="0.1" min="0" max="100" @input="calcMetalValue(idx)">
                                </div>
                                <div>
                                    <label>Rate/g (₹)</label>
                                    <input type="number" x-model.number="pay.metal_rate_per_gram" step="1" min="0" @input="calcMetalValue(idx)">
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="pay-remaining" :class="hasEmiMode() ? 'text-amber-700' : (remaining() > 0.5 ? 'text-red-600' : (excess() > 0.5 ? '' : 'text-green-600'))">
                    <template x-if="hasEmiMode() && remaining() > 0.5">
                        <span class="text-amber-700">₹<span x-text="remaining().toLocaleString('en-IN', {minimumFractionDigits:2})"></span> will be scheduled in EMI plan</span>
                    </template>
                    <template x-if="!hasEmiMode() && remaining() > 0.5">
                        <span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-0.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>₹<span x-text="remaining().toLocaleString('en-IN', {minimumFractionDigits:2})"></span> remaining</span>
                    </template>
                    <template x-if="!hasEmiMode() && remaining() <= 0.5 && excess() <= 0.5">
                        <span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline -mt-0.5 mr-0.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Fully paid</span>
                    </template>
                    <template x-if="excess() > 0.5">
                        <div class="excess-alert">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="excess-alert-icon"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <div>
                                <div class="excess-alert-label">Return to Customer</div>
                                <div class="excess-alert-value">₹<span x-text="excess().toLocaleString('en-IN', {minimumFractionDigits:2})"></span></div>
                            </div>
                        </div>
                    </template>
                    <template x-if="appliedRedemptionAmount() > 0">
                        <div class="redemption-note redemption-note-info">
                            Scheme redemption used: ₹<span x-text="appliedRedemptionAmount().toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                        </div>
                    </template>
                    <template x-if="hasEmiMode() && appliedRedemptionAmount() > 0">
                        <div class="redemption-note redemption-note-warning">
                            Remove scheme redemption to continue EMI checkout.
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- ══ RIGHT SIDEBAR ══ -->
        <div class="pos-sidebar">
            <div class="summary-card">
                <div class="card-title summary-title-tight">
                    <span><span class="card-title-icon"></span> Price Summary</span>
                </div>

                <div class="summary-row">
                    <span>Tag / Selling Price <template x-if="items.length > 1"><span class="summary-item-count" x-text="'(' + items.length + ' items)'"></span></template></span>
                    <span class="summary-row-val" x-text="'₹ ' + Number(sellingPrice).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row" x-show="discount > 0">
                    <span>Manual Discount</span>
                    <span class="summary-row-val summary-row-danger" x-text="'- ₹ ' + Number(discount).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row" x-show="offerDiscountAmount() > 0">
                    <span x-text="'Offer: ' + appliedOfferLabel()"></span>
                    <span class="summary-row-val summary-row-danger" x-text="'- ₹ ' + Number(offerDiscountAmount()).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row">
                    <span>GST (<span x-text="gstRate"></span>%) <template x-if="(discount + offerDiscountAmount()) > 0"><span class="summary-gst-base" x-text="'on ₹' + Number(Math.max(sellingPrice - discount - offerDiscountAmount(), 0)).toLocaleString('en-IN', {minimumFractionDigits:2})"></span></template></span>
                    <span class="summary-row-val" x-text="'₹ ' + Number(gst).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-row" x-show="roundOff != 0">
                    <span>Round-off</span>
                    <span class="summary-row-val" x-text="(roundOff >= 0 ? '+ ' : '- ') + '₹ ' + Math.abs(roundOff).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-total">
                    <span class="summary-total-label">Total</span>
                    <span class="summary-total-val" x-text="'₹ ' + Number(total).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row summary-loyalty-row" x-show="total > 0">
                    <span>Points you'll earn</span>
                    <span class="summary-loyalty-points" x-text="'+' + Math.floor(total * loyaltyPointsPerHundred / 100) + ' pts'"></span>
                </div>
            </div>

            <!-- Complete Sale Button -->
            <button class="btn-sell btn-sell-offset"
                    :disabled="!canSell()"
                    @click="completeSale()">
                <span x-show="!selling">Complete Sale</span>
                <span x-show="selling">Processing…</span>
            </button>

            <p x-show="missingAccountSelection()" class="text-sm text-red-600 mt-2 text-center">Select an account for all UPI / Bank / Wallet payments.</p>
            <p x-show="saleError" class="text-sm text-red-600 mt-3 text-center" x-text="saleError"></p>
        </div>
    </div>
</div>

@php
    $offerPayload = ($offerSchemes ?? collect())->map(function ($scheme) {
        return [
            'id' => (int) $scheme->id,
            'name' => (string) $scheme->name,
            'type' => (string) $scheme->type,
            'discount_type' => (string) ($scheme->discount_type ?? ''),
            'discount_value' => (float) ($scheme->discount_value ?? 0),
            'min_purchase_amount' => (float) ($scheme->min_purchase_amount ?? 0),
            'max_discount_amount' => (float) ($scheme->max_discount_amount ?? 0),
            'auto_apply' => (bool) ($scheme->auto_apply ?? false),
            'applies_to' => (string) ($scheme->applies_to ?? 'all_items'),
            'applies_to_value' => (string) ($scheme->applies_to_value ?? ''),
            'priority' => (int) ($scheme->priority ?? 100),
        ];
    })->values()->all();

    $redeemableEnrollmentPayload = ($redeemableEnrollments ?? collect())->map(function ($enrollment) {
        return [
            'id' => (int) $enrollment->id,
            'scheme_name' => (string) optional($enrollment->scheme)->name,
            'status' => (string) $enrollment->status,
            'redeemable_amount' => (float) ($enrollment->redeemable_amount ?? 0),
        ];
    })->values()->all();
@endphp

<script>
function retailerPos() {
    return {
        // Items
        items: [],
        barcodeSuggestions: [],
        barcodeDropUp: false,

        // Routes
        posSellUrl: @json(route('pos.sell')),
        invoiceBaseUrl: @json(url('/invoices')),

        // Offers & Redemptions
        offers: @json($offerPayload),
        redeemableEnrollments: @json($redeemableEnrollmentPayload),
        selectedOfferId: '',
        ignoreAutoOffer: false,
        schemeRedemptionEnrollmentId: '',
        schemeRedemptionAmount: 0,
        schemeRedemptionNote: '',

        // Pricing
        sellingPrice: 0,
        gstRate: {{ auth()->user()->shop->gst_rate ?? 3 }},
        gst: 0,
        discount: 0,
        discountPercent: 0,
        discountInputSource: 'amount',
        roundOff: 0,
        roundOffNearest: {{ $roundOffNearest ?? 1 }},
        loyaltyPointsPerHundred: {{ $loyaltyPointsPerHundred ?? 1 }},
        total: 0,

        // Payment methods (configured by shop owner)
        paymentMethods: @json($paymentMethods),

        // Payments
        payments: [],

        // State
        selling: false,
        saleError: '',

        init() {
            const params = new URLSearchParams(window.location.search);
            // Support multiple item_ids[] or single item_id
            const itemIds = params.getAll('item_ids[]');
            const singleId = params.get('item_id');
            if (itemIds.length > 0) {
                this.$nextTick(() => {
                    itemIds.forEach(id => this.addItem(id));
                });
            } else if (singleId) {
                this.$nextTick(() => this.addItem(singleId));
            }

            window.addEventListener('resize', () => {
                this.updateBarcodeSuggestionDirection();
            }, { passive: true });
        },

        /* ── URL sync ────────────────────────────── */
        syncUrl() {
            const url = new URL(window.location);
            url.searchParams.delete('item_ids[]');
            url.searchParams.delete('item_id');
            this.items.forEach(i => url.searchParams.append('item_ids[]', i.id));
            history.replaceState(null, '', url);
        },

        /* ── Item selection ─────────────────────── */
        addItem(id) {
            if (!id) return;
            // Prevent duplicates
            if (this.items.some(i => i.id === parseInt(id))) return;
            const opt = document.querySelector(`select option[value="${id}"]`);
            if (!opt) return;
            this.items.push({
                id: parseInt(id),
                design: opt.dataset.design,
                category: opt.dataset.category,
                sub_category: opt.dataset.sub_category,
                weight: parseFloat(opt.dataset.weight),
                purity: parseFloat(opt.dataset.purity),
                net: parseFloat(opt.dataset.net),
                selling: parseFloat(opt.dataset.selling),
                cost: parseFloat(opt.dataset.cost),
                barcode: opt.dataset.barcode,
                image: opt.dataset.image || '',
            });
            this.recalc();
            this.syncUrl();
            this.$refs.barcodeInput.focus();
        },

        removeItem(idx) {
            this.items.splice(idx, 1);
            this.recalc();
            this.syncUrl();
        },

        async lookupBarcode(barcode) {
            if (!barcode.trim()) return;
            try {
                const res = await fetch(`/api/item-by-barcode/${encodeURIComponent(barcode.trim())}`);
                if (!res.ok) { window.showToast('Item not found', 'error'); return; }
                const d = await res.json();
                if (d.status && d.status !== 'in_stock') { window.showToast('Item is not in stock', 'error'); return; }
                // Prevent duplicates
                if (this.items.some(i => i.id === d.id)) { window.showToast('Item already added', 'error'); return; }
                this.items.push({
                    id: d.id,
                    design: d.design,
                    category: d.category,
                    sub_category: d.sub_category,
                    weight: d.gross_weight || d.weight,
                    purity: d.purity,
                    net: d.weight,
                    selling: d.selling_price || 0,
                    cost: d.cost_price || 0,
                    barcode: barcode.trim(),
                    image: d.image ? '/storage/' + d.image : '',
                });
                this.recalc();
                this.syncUrl();
                this.barcodeDropUp = false;
                this.$refs.barcodeInput.focus();
            } catch { window.showToast('Error looking up barcode', 'error'); }
        },

        clearItems() {
            this.items = [];
            this.recalc();
            this.syncUrl();
            this.barcodeDropUp = false;
            this.$refs.barcodeInput.focus();
        },

        updateBarcodeSuggestionDirection() {
            this.barcodeDropUp = false;

            if (!this.barcodeSuggestions.length) {
                return;
            }

            const wrap = this.$refs.barcodeInput?.closest('.barcode-wrap');
            const panel = wrap?.querySelector('.barcode-suggestions');
            if (!wrap || !panel) {
                return;
            }

            const panelStyles = window.getComputedStyle(panel);
            const maxHeight = parseFloat(panelStyles.maxHeight) || panel.scrollHeight || 280;
            const panelHeight = Math.min(panel.scrollHeight || maxHeight, maxHeight) + 10;
            const rect = wrap.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;

            this.barcodeDropUp = (spaceBelow < panelHeight && spaceAbove > spaceBelow);
        },

        onBarcodeInput(val) {
            const q = val.trim().toLowerCase();
            if (!q) {
                this.barcodeSuggestions = [];
                this.barcodeDropUp = false;
                return;
            }
            const opts = Array.from(document.querySelectorAll('select.field-select option')).filter(o => o.value);
            this.barcodeSuggestions = opts
                .filter(o =>
                    (o.dataset.barcode || '').toLowerCase().includes(q) ||
                    (o.dataset.design  || '').toLowerCase().includes(q)
                )
                .slice(0, 8)
                .map(o => ({
                    id: o.value,
                    barcode: o.dataset.barcode || '',
                    design: o.dataset.design || o.dataset.category || '',
                    purity: o.dataset.purity || '',
                    weight: o.dataset.weight || '',
                    price: Number(o.dataset.selling || 0).toLocaleString('en-IN'),
                }));

            this.$nextTick(() => {
                this.updateBarcodeSuggestionDirection();
            });
        },

        selectSuggestion(id) {
            this.addItem(id);
            this.$refs.barcodeInput.value = '';
            this.barcodeSuggestions = [];
            this.barcodeDropUp = false;
        },

        round2(value) {
            return Math.round(Number(value || 0) * 100) / 100;
        },

        clampPercent(value) {
            return Math.min(100, Math.max(0, this.round2(value)));
        },

        syncDiscountFromAmount() {
            this.discount = Math.max(0, this.round2(this.discount));
            if (this.sellingPrice > 0) {
                this.discountPercent = this.clampPercent((this.discount / this.sellingPrice) * 100);
                return;
            }

            this.discountPercent = 0;
        },

        syncDiscountFromPercent() {
            this.discountPercent = this.clampPercent(this.discountPercent);
            if (this.sellingPrice > 0) {
                this.discount = Math.max(0, this.round2(this.sellingPrice * (this.discountPercent / 100)));
                return;
            }

            this.discount = 0;
        },

        onDiscountAmountInput(value) {
            this.discount = Math.max(0, this.round2(value));
            this.discountInputSource = 'amount';
            this.recalc();
        },

        onDiscountPercentInput(value) {
            this.discountPercent = this.clampPercent(value);
            this.discountInputSource = 'percent';
            this.recalc();
        },

        /* ── Price calculation ──────────────────── */
        recalc() {
            this.sellingPrice = this.items.reduce((sum, i) => sum + i.selling, 0);

            if (this.discountInputSource === 'percent') {
                this.syncDiscountFromPercent();
            } else {
                this.syncDiscountFromAmount();
            }

            const totalDiscount = Math.min(this.sellingPrice, this.discount + this.offerDiscountAmount());
            const taxable = Math.max(this.sellingPrice - totalDiscount, 0);
            this.gst = Math.round(taxable * (this.gstRate / 100) * 100) / 100;
            this.total = Math.round((this.sellingPrice + this.gst - totalDiscount + Number(this.roundOff || 0)) * 100) / 100;
            if (this.total < 0) this.total = 0;
            this.normalizeRedemptionAmount();
        },

        /* dir: -1 = round down, +1 = round up */
        autoRound(dir) {
            // compute total without any existing round-off
            const totalDiscount = Math.min(this.sellingPrice, this.discount + this.offerDiscountAmount());
            const taxable = Math.max(this.sellingPrice - totalDiscount, 0);
            const gst = Math.round(taxable * (this.gstRate / 100) * 100) / 100;
            const raw = Math.round((this.sellingPrice + gst - totalDiscount) * 100) / 100;
            const n = this.roundOffNearest;
            const rounded = dir > 0 ? Math.ceil(raw / n) * n : Math.floor(raw / n) * n;
            this.roundOff = Math.round((rounded - raw) * 100) / 100;
            this.recalc();
        },

        normalizeText(value) {
            return String(value || '').trim().toLowerCase();
        },

        offerMatchesItems(offer) {
            if (!offer || offer.applies_to === 'all_items' || !offer.applies_to_value) return true;
            const target = this.normalizeText(offer.applies_to_value);
            if (!target) return true;

            if (offer.applies_to === 'category') {
                return this.items.some(item => this.normalizeText(item.category) === target);
            }

            if (offer.applies_to === 'sub_category') {
                return this.items.some(item => this.normalizeText(item.sub_category) === target);
            }

            return true;
        },

        offerEligible(offer) {
            if (!offer) return false;
            if (this.sellingPrice <= 0) return false;
            if (Number(offer.min_purchase_amount || 0) > this.sellingPrice) return false;
            if (!['percentage', 'flat'].includes(offer.discount_type)) return false;
            return this.offerMatchesItems(offer);
        },

        selectedOfferCandidate() {
            if (!this.selectedOfferId) return null;
            const offer = this.offers.find(o => String(o.id) === String(this.selectedOfferId));
            return this.offerEligible(offer) ? offer : null;
        },

        autoOfferCandidate() {
            if (this.ignoreAutoOffer) return null;

            const autos = this.offers
                .filter(o => o.auto_apply)
                .filter(o => this.offerEligible(o))
                .sort((a, b) => {
                    if (a.priority !== b.priority) return a.priority - b.priority;
                    return (b.discount_value || 0) - (a.discount_value || 0);
                });
            return autos.length ? autos[0] : null;
        },

        appliedOffer() {
            return this.selectedOfferCandidate() || this.autoOfferCandidate();
        },

        offerDiscountAmount() {
            const offer = this.appliedOffer();
            if (!offer) return 0;

            const value = Number(offer.discount_value || 0);
            if (value <= 0) return 0;

            let amount = offer.discount_type === 'percentage'
                ? (this.sellingPrice * value / 100)
                : value;

            const cap = Number(offer.max_discount_amount || 0);
            if (cap > 0) amount = Math.min(amount, cap);

            return Math.max(0, Math.min(Math.round(amount * 100) / 100, this.sellingPrice));
        },

        offerLabel(offer) {
            if (!offer) return '';
            const value = offer.discount_type === 'percentage'
                ? `${offer.discount_value}%`
                : `₹${Number(offer.discount_value || 0).toLocaleString('en-IN')}`;
            return `${offer.name} (${value})`;
        },

        appliedOfferLabel() {
            const offer = this.appliedOffer();
            if (!offer && this.ignoreAutoOffer) return 'Offer skipped for this bill';
            return offer ? offer.name : 'No offer';
        },

        onOfferSelectionChange() {
            if (this.selectedOfferId) {
                this.ignoreAutoOffer = false;
            }
            this.recalc();
        },

        skipOfferForThisBill() {
            this.selectedOfferId = '';
            this.ignoreAutoOffer = true;
            this.recalc();
        },

        enableAutoOfferForThisBill() {
            this.ignoreAutoOffer = false;
            this.recalc();
        },

        redemptionSelectedEnrollment() {
            if (!this.schemeRedemptionEnrollmentId) return null;
            return this.redeemableEnrollments.find(p => String(p.id) === String(this.schemeRedemptionEnrollmentId)) || null;
        },

        redemptionPlanLabel(plan) {
            if (!plan) return '';
            return `${plan.scheme_name || 'Plan'} · Available ₹${Number(plan.redeemable_amount || 0).toLocaleString('en-IN', {minimumFractionDigits:2})}`;
        },

        maxRedeemableForSelection() {
            const selected = this.redemptionSelectedEnrollment();
            if (!selected) return 0;
            return Math.max(0, Math.min(Number(selected.redeemable_amount || 0), this.total));
        },

        normalizeRedemptionAmount() {
            if (!this.schemeRedemptionEnrollmentId) {
                this.schemeRedemptionAmount = 0;
                return;
            }
            const max = this.maxRedeemableForSelection();
            const current = Math.max(0, Number(this.schemeRedemptionAmount || 0));
            this.schemeRedemptionAmount = Math.min(current, max);
        },

        onRedemptionEnrollmentChange() {
            if (!this.schemeRedemptionEnrollmentId) {
                this.schemeRedemptionAmount = 0;
                this.recalc();
                return;
            }

            const max = this.maxRedeemableForSelection();
            const payableWithoutRedemption = Math.max(0, this.total - this.paymentTotal());
            this.schemeRedemptionAmount = Math.min(max, payableWithoutRedemption > 0 ? payableWithoutRedemption : max);
            this.recalc();
        },

        appliedRedemptionAmount() {
            if (!this.schemeRedemptionEnrollmentId) return 0;
            const max = this.maxRedeemableForSelection();
            return Math.min(Math.max(0, Number(this.schemeRedemptionAmount || 0)), max);
        },

        /* ── Payment helpers ─────────────────────── */
        methodsForType(type) {
            return this.paymentMethods[type] || [];
        },

        onMethodSelect(pay, event) {
            const methodId = parseInt(event.target.value) || null;
            pay.payment_method_id = methodId;
            if (methodId) {
                const m = this.methodsForType(pay.mode).find(x => x.id == methodId);
                pay.reference = m ? m.account_label : '';
            } else {
                pay.reference = '';
            }
        },

        /* ── Payments ────────────────────────────── */
        addPayment(mode) {
            // These modes can only appear once per sale
            if (['old_gold', 'old_silver', 'emi'].includes(mode) && this.payments.some(p => p.mode === mode)) return;

            const pay = { mode, amount: 0, reference: '', payment_method_id: null };
            if (mode === 'old_gold' || mode === 'old_silver') {
                pay.metal_gross_weight = 0;
                pay.metal_purity = mode === 'old_gold' ? 22 : 925;
                pay.metal_test_loss = 0;
                pay.metal_rate_per_gram = 0;
            }
            // Auto-fill single configured account
            const methods = this.methodsForType(mode);
            if (methods.length === 1) {
                pay.payment_method_id = methods[0].id;
                pay.reference = methods[0].account_label;
            }
            // Auto-fill remaining amount when this is the first payment row
            if (this.payments.length === 0 && mode !== 'emi') {
                pay.amount = Math.max(0, this.remaining());
            }
            this.payments.push(pay);
        },

        removePayment(idx) {
            this.payments.splice(idx, 1);
        },

        calcMetalValue(idx) {
            const p = this.payments[idx];
            const net = p.metal_gross_weight * (1 - (p.metal_test_loss || 0) / 100);
            const fine = p.mode === 'old_gold' ? net * (p.metal_purity / 24) : net * (p.metal_purity / 1000);
            p.amount = Math.round(fine * p.metal_rate_per_gram);
        },

        recalcPayments() { /* no-op, just triggers reactivity */ },

        paymentTotal() {
            return this.payments.reduce((s, p) => s + (parseFloat(p.amount) || 0), 0);
        },

        hasEmiMode() {
            return this.payments.some(p => p.mode === 'emi');
        },

        remaining() {
            return this.total - this.paymentTotal() - this.appliedRedemptionAmount();
        },

        excess() {
            const r = this.remaining();
            return r < 0 ? Math.abs(r) : 0;
        },

        /* ── Sale ────────────────────────────────── */
        accountRequired(pay) {
            return ['upi', 'bank', 'wallet'].includes(pay.mode) && methodsForType && this.methodsForType(pay.mode).length > 0;
        },

        missingAccountSelection() {
            return this.payments.some(p =>
                ['upi', 'bank', 'wallet'].includes(p.mode)
                && this.methodsForType(p.mode).length > 0
                && !p.payment_method_id
            );
        },

        canSell() {
            if (!(this.items.length > 0
                && this.total > 0
                && !this.selling)) {
                return false;
            }

            const hasAnySettlement = this.payments.length > 0 || this.appliedRedemptionAmount() > 0;
            if (!hasAnySettlement) return false;

            if (this.missingAccountSelection()) return false;

            if (this.hasEmiMode()) {
                const hasOnlyEmi = this.payments.length === 1 && this.payments[0].mode === 'emi';
                return hasOnlyEmi && this.appliedRedemptionAmount() <= 0;
            }

            return this.remaining() < 1;
        },

        async completeSale() {
            if (!this.canSell()) return;
            this.selling = true;
            this.saleError = '';

            try {
                const res = await fetch(this.posSellUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        customer_id: {{ $customer->id }},
                        item_ids: this.items.map(i => i.id),
                        discount: this.discount,
                        round_off: this.roundOff,
                        offer_scheme_id: this.appliedOffer() ? this.appliedOffer().id : null,
                        scheme_redemption: this.appliedRedemptionAmount() > 0 ? {
                            enrollment_id: parseInt(this.schemeRedemptionEnrollmentId, 10),
                            amount: this.appliedRedemptionAmount(),
                            note: this.schemeRedemptionNote || null,
                        } : null,
                        payments: this.payments.map(p => ({
                            mode: p.mode,
                            amount: parseFloat(p.amount) || 0,
                            reference: p.reference || null,
                            payment_method_id: p.payment_method_id || null,
                            metal_gross_weight: p.metal_gross_weight || null,
                            metal_purity: p.metal_purity || null,
                            metal_test_loss: p.metal_test_loss || null,
                            metal_rate_per_gram: p.metal_rate_per_gram || null,
                        })),
                    }),
                });

                const data = await res.json();
                if (!res.ok) {
                    this.saleError = data.message || 'Sale failed';
                    this.selling = false;
                    return;
                }

                // Success — redirect to EMI create flow when EMI mode is selected
                if (this.hasEmiMode()) {
                    window.location.href = data.redirect_url || ('/installments/create?invoice_id=' + data.invoice_id + '&from_pos_emi=1');
                    return;
                }

                window.location.href = this.invoiceBaseUrl + '/' + data.invoice_id;
            } catch (e) {
                this.saleError = 'Network error. Please try again.';
                this.selling = false;
            }
        },
    };
}
</script>
</x-app-layout>
