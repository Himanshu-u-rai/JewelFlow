<x-app-layout>
<style>
    [x-cloak] {
        display: none !important;
    }

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
        --items-table-height: 452px;
        --items-table-head-height: 46px;

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

    /* ─── Item picker ──────────────────────────────────── */
    .item-picker-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.28);
        z-index: 230;
    }

    .item-picker-modal {
        position: fixed;
        inset: 0;
        z-index: 240;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        pointer-events: none;
    }

    .item-picker-native {
        display: none;
    }

    .item-picker-panel {
        width: min(640px, 100%);
        max-height: min(76vh, 560px);
        border: 1px solid #dbe3ee;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16), 0 6px 16px rgba(15, 23, 42, 0.08);
        overflow: hidden;
        pointer-events: auto;
    }

    .item-picker-panel-head {
        padding: 12px;
        border-bottom: 1px solid #e5edf5;
        background: #f8fbff;
    }

    .item-picker-panel-title-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
    }

    .item-picker-panel-title {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #475569;
    }

    .item-picker-panel-close {
        border: 0;
        background: transparent;
        color: #64748b;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        padding: 4px 6px;
        border-radius: 8px;
    }

    .item-picker-panel-close:hover {
        background: #eef2f7;
    }

    .item-picker-filter {
        width: 100%;
        min-height: 42px;
        border-radius: 10px;
        border: 1px solid #d6dde7;
        padding: 0 12px;
        font-size: 14px;
        background: #ffffff;
        color: #0f172a;
    }

    .item-picker-filter:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(20, 33, 61, 0.12);
    }

    .item-picker-filter.loading {
        border-color: var(--gold);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
    }

    .item-picker-list {
        display: flex;
        flex-direction: column;
        max-height: min(52vh, 360px);
        overflow-y: auto;
    }

    .item-picker-option {
        width: 100%;
        border: 0;
        border-bottom: 1px solid #eef2f7;
        border-left: 2px solid transparent;
        background: #ffffff;
        padding: 12px 14px 12px 14px;
        text-align: left;
        display: flex;
        flex-direction: column;
        gap: 3px;
        cursor: pointer;
        transition: background-color 140ms cubic-bezier(0.23,1,0.32,1),
                    border-left-color 140ms cubic-bezier(0.23,1,0.32,1);
    }

    .item-picker-option:last-child {
        border-bottom: 0;
    }

    .item-picker-option:hover,
    .item-picker-option.is-active {
        background: rgba(13,148,136,.06);
        border-left-color: #0d9488;
    }

    .item-picker-option.is-focused {
        background: rgba(13,148,136,.10);
        border-left-color: #0d9488;
    }

    .item-picker-option:active {
        background: rgba(13,148,136,.13);
    }

    .item-picker-option.is-disabled {
        opacity: 0.55;
        cursor: not-allowed;
        background: #f8fafc;
    }

    .item-picker-option.is-disabled:hover {
        padding-left: 14px;
    }

    .item-picker-option-main {
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        line-height: 1.35;
    }

    .item-picker-option-meta {
        font-size: 12px;
        color: #64748b;
        line-height: 1.35;
    }

    .item-picker-option-state {
        font-size: 11px;
        font-weight: 700;
        color: #b45309;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .item-picker-empty {
        padding: 16px 14px;
        font-size: 13px;
        color: #64748b;
    }

    .item-picker-empty strong {
        display: block;
        font-size: 13px;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 4px;
    }

    /* ─── Selected items table ────────────────────────── */
    .items-list-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 2px 0 10px;
    }
    .items-list-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .items-list-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .items-list-badge {
        font-size: 11px;
        font-weight: 700;
        color: #7a4d02;
        background: #fff4de;
        padding: 2px 8px;
        border-radius: 9999px;
    }
    .items-list-add {
        width: 38px;
        height: 38px;
        border: 1px solid #233659;
        border-radius: 12px;
        background: var(--accent);
        color: #ffffff;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 12px 24px rgba(20, 33, 61, 0.18), 0 0 0 4px rgba(245, 158, 11, 0.12);
        transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, color 0.15s ease;
    }
    .items-list-add svg {
        width: 18px;
        height: 18px;
        stroke-width: 2.4;
        flex-shrink: 0;
    }
    .items-list-add:hover {
        background: var(--accent-hover);
        border-color: #10284d;
        transform: translateY(-1px);
        box-shadow: 0 16px 28px rgba(20, 33, 61, 0.24), 0 0 0 5px rgba(245, 158, 11, 0.16);
    }
    .items-list-add:focus-visible {
        outline: none;
        background: var(--accent-hover);
        border-color: #10284d;
        box-shadow: 0 16px 28px rgba(20, 33, 61, 0.24), 0 0 0 5px rgba(245, 158, 11, 0.18);
    }
    .items-list-add:active {
        transform: translateY(0);
        box-shadow: 0 10px 20px rgba(20, 33, 61, 0.18), 0 0 0 4px rgba(245, 158, 11, 0.14);
    }
    .items-list-clear {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        height: 38px;
        padding: 0 14px;
        font-size: 13px;
        font-weight: 600;
        color: #dc2626;
        background: #fff;
        border: 1px solid #fecaca;
        cursor: pointer;
        border-radius: 12px;
        transition: background 0.15s, border-color 0.15s, color 0.15s;
    }
    .items-list-clear:hover { background: #fef2f2; border-color: #f87171; color: #b91c1c; }
    .items-list-clear svg { width: 15px; height: 15px; flex-shrink: 0; }
    .items-desktop-view {
        display: block;
    }
    .items-tablet-view,
    .items-mobile-view {
        display: none;
    }
    .items-table-shell {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        overflow: hidden;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }
    .items-table-scroll {
        height: var(--items-table-height);
        overflow: auto;
        scrollbar-width: thin;
        scrollbar-gutter: stable both-edges;
    }
    .items-table {
        width: 100%;
        min-width: 1040px;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        background: #ffffff;
    }
    .items-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        padding: 11px 10px;
        background: #f8fbff;
        border-bottom: 1px solid #e2e8f0;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        text-align: left;
        white-space: nowrap;
    }
    .items-table tbody tr {
        height: 68px;
        background: #ffffff;
    }
    .items-table tbody tr:nth-child(even) {
        background: #fffdfa;
    }
    .items-table tbody td {
        padding: 10px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
        font-size: 12px;
        color: #334155;
    }
    .items-table tbody tr:last-child td {
        border-bottom: 0;
    }
    .items-table-item {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }
    .items-table-thumb {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: #f3f4f6;
        overflow: hidden;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .items-table-thumb img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .items-table-item-copy {
        min-width: 0;
    }
    .items-table-item-title {
        font-size: 13px;
        font-weight: 600;
        color: var(--ink);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .items-table-item-meta {
        margin-top: 2px;
        font-size: 11px;
        color: #526174;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .items-table-money {
        font-size: 12px;
        font-weight: 600;
        color: #0f172a;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .items-table-total {
        font-size: 13px;
        font-weight: 700;
        color: var(--accent);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .items-table-action-cell {
        text-align: center;
    }
    .items-table-remove {
        width: 28px;
        height: 28px;
        border: none;
        border-radius: 8px;
        background: transparent;
        color: var(--danger);
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
    }
    .items-table-remove:hover {
        background: rgba(220,38,38,0.08);
    }
    .items-table-empty-row {
        height: auto !important;
    }
    .items-table-empty-row td {
        height: calc(var(--items-table-height) - var(--items-table-head-height));
    }
    .items-table-empty {
        min-height: 100%;
        padding: 24px 16px;
        text-align: center;
        color: var(--muted);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .items-table-empty strong {
        display: block;
        margin-bottom: 4px;
        font-size: 13px;
        font-weight: 700;
        color: var(--ink);
    }
    .items-tablet-shell,
    .items-mobile-shell {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        overflow: hidden;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
    }
    .items-tablet-scroll {
        height: 430px;
        overflow: auto;
        scrollbar-width: thin;
        scrollbar-gutter: stable both-edges;
    }
    .items-tablet-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        table-layout: fixed;
        background: #ffffff;
    }
    .items-tablet-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        padding: 11px 10px;
        background: #f8fbff;
        border-bottom: 1px solid #e2e8f0;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        text-align: left;
        white-space: nowrap;
    }
    .items-tablet-table tbody tr {
        background: #ffffff;
    }
    .items-tablet-table tbody tr:nth-child(even) {
        background: #fffdfa;
    }
    .items-tablet-table tbody td {
        padding: 10px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: top;
        font-size: 12px;
        color: #334155;
    }
    .items-tablet-table tbody tr:last-child td {
        border-bottom: 0;
    }
    .items-tablet-item-copy {
        min-width: 0;
    }
    .items-tablet-inline-breakdown {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 6px;
    }
    .items-tablet-inline-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 7px;
        border-radius: 9999px;
        background: #f8fbff;
        border: 1px solid #e2e8f0;
        font-size: 10px;
        color: #475569;
        white-space: nowrap;
    }
    .items-tablet-inline-pill strong {
        font-weight: 700;
        color: #0f172a;
    }
    .items-tablet-empty-row td {
        height: 210px;
    }
    .items-tablet-empty {
        height: 100%;
        padding: 20px 16px;
        text-align: center;
        color: var(--muted);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .items-tablet-empty strong {
        display: block;
        margin-bottom: 4px;
        font-size: 13px;
        font-weight: 700;
        color: var(--ink);
    }
    .items-mobile-shell {
        padding: 10px;
    }
    .items-mobile-scroll {
        height: 360px;
        overflow: auto;
    }
    .items-mobile-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .items-mobile-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fffdfa;
        padding: 12px;
        box-shadow: 0 6px 14px rgba(20, 33, 61, 0.05);
    }
    .items-mobile-top {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 10px;
        align-items: start;
    }
    .items-mobile-copy {
        min-width: 0;
    }
    .items-mobile-title {
        font-size: 13px;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.25;
    }
    .items-mobile-meta {
        margin-top: 3px;
        font-size: 11px;
        color: #526174;
        line-height: 1.35;
    }
    .items-mobile-total {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 4px;
        min-width: 0;
    }
    .items-mobile-total-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--muted);
    }
    .items-mobile-total-value {
        font-size: 14px;
        font-weight: 800;
        color: var(--accent);
        white-space: nowrap;
    }
    .items-mobile-remove {
        width: 30px;
        height: 30px;
        border: none;
        border-radius: 10px;
        background: transparent;
        color: var(--danger);
        font-size: 18px;
        line-height: 1;
        cursor: pointer;
        justify-self: end;
    }
    .items-mobile-breakdown {
        margin-top: 10px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .items-mobile-breakdown-cell {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fbff;
        padding: 8px 9px;
    }
    .items-mobile-breakdown-label {
        font-size: 10px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 3px;
    }
    .items-mobile-breakdown-value {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
        white-space: nowrap;
    }
    .items-mobile-empty {
        padding: 20px 14px;
        text-align: center;
        color: var(--muted);
    }
    .items-mobile-empty strong {
        display: block;
        margin-bottom: 4px;
        font-size: 13px;
        font-weight: 700;
        color: var(--ink);
    }

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
        gap: 12px;
    }
    .summary-row > span:first-child { min-width: 0; flex: 1; }
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

    /* ─── Payment status banners ──────────────────────── */
    .pay-status-wrap { margin-top: 10px; }
    .pay-status {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 16px; border-radius: 14px; border: 1px solid;
    }
    .pay-status svg { flex-shrink: 0; }
    .pay-status-body { display: flex; flex-direction: column; line-height: 1.25; }
    .pay-status-label {
        font-size: 11px; font-weight: 600; text-transform: uppercase;
        letter-spacing: 0.4px; opacity: 0.8;
    }
    .pay-status-value { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .pay-status-remaining { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .pay-status-emi        { background: #fffbeb; border-color: #fde68a; color: #b45309; }
    .pay-status-paid       { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
    .pay-status-paid-text  { font-size: 15px; font-weight: 700; }

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
    .summary-sidebar-card {
        display: block;
    }
    .summary-mobile-card {
        display: none;
    }
    .sell-sidebar-block {
        display: block;
    }
    .sell-mobile-block {
        display: none;
    }

    .discount-sidebar-card {
        margin-bottom: 16px;
        padding: 18px 18px 16px;
    }

    .discount-mobile-card {
        display: none;
    }

    .offers-sidebar-card {
        margin-bottom: 16px;
        padding: 18px 18px 16px;
    }

    .offers-mobile-card {
        display: none;
    }

    /* ─── Offers & Redemption trigger (opens modal) ───── */
    .offers-trigger {
        width: 100%; display: flex; align-items: center; justify-content: space-between;
        gap: 12px; padding: 0; background: none; border: none; cursor: pointer;
        text-align: left; color: inherit;
    }
    .offers-trigger-action {
        flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px;
        font-size: 13px; font-weight: 600; color: var(--accent);
    }
    .offers-trigger-action svg { width: 16px; height: 16px; }
    .offers-modal-footer { margin-top: 20px; display: flex; justify-content: flex-end; }
    .offers-modal-done {
        padding: 10px 22px; font-size: 14px; font-weight: 700; color: #fff;
        background: #14213d; border: none; border-radius: 12px; cursor: pointer;
        transition: background 0.15s;
    }
    .offers-modal-done:hover { background: #1f2f54; }

    .discount-card-title {
        margin-bottom: 14px;
    }

    .discount-stack {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .discount-stack-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 10px;
    }

    .discount-stack .field {
        margin-bottom: 0;
    }

    .discount-stack .field-label {
        font-size: 12px;
        margin-bottom: 5px;
    }

    .discount-stack .field-input {
        min-height: 44px;
        padding: 11px 13px;
        font-size: 15px;
    }

    .discount-stack .discount-percent-input {
        padding-right: 34px;
    }

    .discount-stack .roundoff-controls {
        gap: 8px;
    }

    .discount-stack .roundoff-btn {
        min-width: 38px;
        border-radius: 10px;
    }

    .offers-stack {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .offers-stack .field {
        margin-bottom: 0;
    }

    .offers-stack .field-label {
        font-size: 12px;
        margin-bottom: 5px;
    }

    .offers-stack .field-input,
    .offers-stack .field-select {
        min-height: 44px;
        padding: 11px 13px;
        font-size: 15px;
    }

    .offers-stack .offer-select-offset {
        margin-top: 8px;
    }

    .offers-stack .offer-discount-box {
        min-height: 44px;
    }

    .card-accordion {
        transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
    }

    .card-accordion.is-open {
        border-color: #cbd6e4;
        box-shadow: 0 14px 28px rgba(20, 33, 61, 0.1);
    }

    .card-accordion .card-title {
        margin-bottom: 0;
    }

    .card-toggle {
        width: 100%;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        padding: 14px 16px;
        border: 1px solid #dbe3ee;
        border-radius: 14px;
        background: #f8fbff;
        color: inherit;
        text-align: left;
        cursor: pointer;
        transition: background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .card-toggle.is-open {
        background: #ffffff;
        border-color: #d0dae8;
        box-shadow: 0 8px 18px rgba(20, 33, 61, 0.06);
    }

    .card-toggle:hover,
    .card-toggle:focus-visible {
        background: #ffffff;
        border-color: #c6d3e3;
        box-shadow: 0 10px 20px rgba(20, 33, 61, 0.08);
    }

    .card-toggle:focus-visible {
        outline: none;
    }

    .card-toggle-copy {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        min-width: 0;
        flex: 1;
    }

    .card-toggle-text {
        display: flex;
        flex-direction: column;
        gap: 4px;
        min-width: 0;
    }

    .card-toggle-heading {
        font-size: 15px;
        font-weight: 700;
        color: var(--ink);
        line-height: 1.2;
    }

    .card-toggle-summary {
        font-size: 12px;
        line-height: 1.35;
        color: #64748b;
    }

    .card-toggle-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        border: 1px solid #d6dde7;
        background: #ffffff;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .card-toggle.is-open .card-toggle-icon {
        background: #14213d;
        border-color: #14213d;
        color: #ffffff;
    }

    .card-toggle-icon svg {
        width: 16px;
        height: 16px;
    }

    .card-toggle-icon.is-open {
        transform: rotate(180deg);
    }

    .collapsible-shell {
        display: grid;
        grid-template-rows: 0fr;
        opacity: 0;
        transition: grid-template-rows 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.18s ease, margin-top 0.18s ease;
    }

    .collapsible-shell.is-open {
        grid-template-rows: 1fr;
        opacity: 1;
        margin-top: 12px;
    }

    .collapsible-card-body {
        min-height: 0;
        overflow: hidden;
        border-top: 1px solid #eef2f7;
        padding-top: 14px;
    }

    .btn-sell-offset {
        margin-top: 20px;
    }

    /* ─── Responsive ──────────────────────────────────── */
    @media (max-width: 1024px) {
        .pos-page {
            padding: 14px 14px 28px;
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
        .pos-customer-name { font-size: 18px; }
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
        .discount-sidebar-card { display: none; }
        .discount-mobile-card { display: block; }
        .offers-sidebar-card { display: none; }
        .offers-mobile-card { display: block; }
        .summary-sidebar-card { display: none; }
        .summary-mobile-card { display: block; }
        .sell-sidebar-block { display: none; }
        .sell-mobile-block { display: block; }
        .items-desktop-view { display: none; }
        .items-tablet-view { display: block; }
        .items-mobile-view { display: none; }
        .summary-total-val { font-size: 22px; }
        .pay-mode-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }
        .pay-mode-btn {
            flex: none;
            height: auto;
            min-height: 42px;
            padding: 8px 6px;
            font-size: 11px;
            border-radius: 10px;
        }
        .pay-row { flex-wrap: wrap; padding: 10px 12px; }
        .pay-row-label { min-width: auto; width: 100%; margin-bottom: 4px; }
        .pay-row-ref-note { min-width: 0; width: 100%; }
        .pay-row-input { font-size: 15px; }
        .pay-row-ref { width: 100%; }
        .pay-metal-fields { grid-template-columns: 1fr 1fr; }
        .btn-sell { padding: 14px; font-size: 16px; border-radius: 12px; }
        .offer-mode-wrap { padding: 8px 10px; border-radius: 12px; }
    }

    @media (max-width: 640px) {
        .pos-page {
            padding: 10px 10px 22px;
        }
        .pos-header {
            padding: 12px 14px;
            border-radius: 12px;
        }
        .pos-back { padding: 6px 10px; font-size: 12px; border-radius: 8px; }
        .pos-customer-name { font-size: 15px; }
        .pos-customer-phone { font-size: 12px; }
        .pos-header-left {
            width: 100%;
            align-items: flex-start;
            gap: 8px;
        }
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
        .items-tablet-view { display: none; }
        .items-mobile-view { display: block; }
        .items-main-card { order: 1; }
        .offers-mobile-card { order: 2; }
        .summary-mobile-card { order: 3; }
        .discount-mobile-card { order: 4; }
        .payment-card { order: 5; }
        .sell-mobile-block { order: 6; }
        .card { padding: 14px 12px; }
        .card-title { font-size: 14px; margin-bottom: 12px; }
        .field-label { font-size: 12px; }
        .field-input, .field-select { font-size: 14px; padding: 10px 12px; }
        .discount-stack-row {
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 8px;
        }
        .item-picker-modal {
            padding: 10px;
        }
        .item-picker-panel {
            width: min(100%, 100%);
            max-height: min(78vh, 560px);
            border-radius: 18px;
        }
        .item-picker-list { max-height: min(52vh, 360px); }
        .summary-card { padding: 14px; }
        .summary-row { font-size: 13px; padding: 6px 0; align-items: flex-start; }
        .summary-total-val { font-size: 20px; }
        .pay-mode-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pay-mode-btn {
            min-height: 44px;
            font-size: 10px;
            border-radius: 10px;
            line-height: 1.15;
        }
        .pay-mode-icon { font-size: 18px; }
        .pay-row {
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
            padding: 12px;
        }
        .pay-row-label {
            width: auto;
            margin-bottom: 0;
        }
        .pay-row-remove {
            align-self: flex-end;
        }
        .pay-metal-fields { grid-template-columns: 1fr; }
        .btn-sell { padding: 12px; font-size: 15px; }
        .offer-mode-toggle { grid-template-columns: 1fr; }
        .roundoff-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 40px 40px;
            gap: 8px;
        }
        .items-mobile-shell { padding: 8px; }
        .items-mobile-scroll { height: 352px; }
        .items-mobile-card { padding: 11px; }
        .items-mobile-breakdown { gap: 7px; }
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

    @if($splitAlertTotal ?? false)
    <div class="mx-4 mt-3 flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span>
            <strong>Compliance alert:</strong> Combined today's total for {{ $customer->name }} is
            <strong>₹{{ number_format($splitAlertTotal, 0) }}</strong>.
            Ensure all compliance documents are complete.
        </span>
    </div>
    @endif

    <!-- ─── Body: 2 columns ─────────────────────── -->
    <div class="pos-body">
        <!-- ══ LEFT COLUMN ══ -->
        <div class="pos-main">

            <!-- ── Item Selection Card ── -->
            <div class="card items-main-card">
                <template x-if="itemPickerOpen">
                    <div class="item-picker-backdrop" x-cloak @click="closeItemPicker(true)"></div>
                </template>
                <div class="item-picker-modal" x-show="itemPickerOpen" x-cloak>
                    <div class="item-picker-panel" @click.outside="closeItemPicker(true)">
                        <div class="item-picker-panel-head">
                            <div class="item-picker-panel-title-row">
                                <span class="item-picker-panel-title">Search stock</span>
                                <button type="button" class="item-picker-panel-close" @click="closeItemPicker(true)">Close</button>
                            </div>
                            <input type="text"
                                   class="item-picker-filter"
                                   :class="{ 'loading': itemPickerLoading }"
                                   x-model="itemPickerQuery"
                                   @input="renderItemPicker()"
                                   @keydown="handleItemPickerKeydown($event)"
                                   x-ref="itemPickerFilter"
                                   placeholder="Scan or type barcode / design"
                                   autocomplete="off">
                        </div>
                        <div class="item-picker-list" x-ref="itemPickerList">
                            <template x-if="itemPickerQuery.trim().length < itemPickerMinQueryLength">
                                <div class="item-picker-empty">
                                    <strong>Search stock</strong>
                                    <span x-text="'Type at least ' + itemPickerMinQueryLength + ' characters to browse matching items.'"></span>
                                </div>
                            </template>
                            <template x-if="itemPickerQuery.trim().length >= itemPickerMinQueryLength && itemPickerResults.length === 0">
                                <div class="item-picker-empty">
                                    <strong>No matches found</strong>
                                    <span>Try a different barcode, design name, or item code.</span>
                                </div>
                            </template>
                            <template x-for="(result, index) in itemPickerResults" :key="result.id">
                                <button type="button"
                                        class="item-picker-option"
                                        :data-picker-index="index"
                                        :class="{
                                            'is-focused': itemPickerActiveIndex === index,
                                            'is-disabled': result.disabled,
                                            'is-active': !result.disabled && itemPickerActiveIndex === index
                                        }"
                                        :disabled="result.disabled"
                                        @click="selectItemFromPicker(result.id)">
                                    <span class="item-picker-option-main" x-text="result.title"></span>
                                    <span class="item-picker-option-meta" x-text="result.meta"></span>
                                    <template x-if="result.disabled">
                                        <span class="item-picker-option-state">Already added</span>
                                    </template>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <select class="field-select item-picker-native" x-ref="stockSource">
                    <option value="">Browse stock items</option>
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
                            data-making="{{ $item->making_charges ?? 0 }}"
                            data-stone="{{ $item->stone_charges ?? 0 }}"
                            data-hallmark="{{ $item->hallmark_charges ?? 0 }}"
                            data-rhodium="{{ $item->rhodium_charges ?? 0 }}"
                            data-other="{{ $item->other_charges ?? 0 }}"
                            data-barcode="{{ $item->barcode }}"
                            data-image="{{ $item->image ? asset('storage/' . $item->image) : '' }}">
                        {{ $item->barcode }} — {{ $item->design ?? $item->category }} ({{ number_format($item->gross_weight, 3) }}g · {{ $item->purity }}K) — ₹{{ number_format($item->selling_price, 0) }}
                    </option>
                @endforeach
                </select>

                <div class="items-list-header">
                    <span class="items-list-title">Selected Items <span class="items-list-badge" x-text="items.length"></span></span>
                    <div class="items-list-actions">
                        <button type="button" class="items-list-add" @click="openItemPicker()" aria-label="Add item" title="Add item">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 5v14"></path>
                                <path d="M5 12h14"></path>
                            </svg>
                        </button>
                        <button type="button" class="items-list-clear" x-show="items.length > 0" x-cloak @click="clearItems()">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Clear All
                        </button>
                    </div>
                </div>

                <div class="items-desktop-view">
                    <div class="items-table-shell">
                        <div class="items-table-scroll">
                            <table class="items-table">
                                <colgroup>
                                    <col style="width: 29%;">
                                    <col style="width: 9%;">
                                    <col style="width: 9%;">
                                    <col style="width: 9%;">
                                    <col style="width: 9%;">
                                    <col style="width: 9%;">
                                    <col style="width: 9%;">
                                    <col style="width: 11%;">
                                    <col style="width: 6%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Metal</th>
                                        <th>Making</th>
                                        <th>Stone</th>
                                        <th>Hallmark</th>
                                        <th>Rhodium</th>
                                        <th>Other</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-if="items.length === 0">
                                        <tr class="items-table-empty-row">
                                            <td colspan="9">
                                                <div class="items-table-empty">
                                                    <strong>No items selected yet</strong>
                                                    <span>Use the add button to search and add items to the checkout.</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <template x-for="(itm, idx) in items" :key="'desktop-' + itm.id + '-' + idx">
                                        <tr>
                                            <td>
                                                <div class="items-table-item">
                                                    <div class="items-table-thumb">
                                                        <template x-if="itm.image">
                                                            <img :src="itm.image" :alt="itm.design">
                                                        </template>
                                                    </div>
                                                    <div class="items-table-item-copy">
                                                        <div class="items-table-item-title" x-text="itm.design || itm.category"></div>
                                                        <div class="items-table-item-meta">
                                                            <span x-text="itm.barcode"></span> ·
                                                            <span x-text="itm.weight + 'g'"></span> ·
                                                            <span x-text="itm.purity + 'K'"></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.cost)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.makingCharges)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.stoneCharges)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.hallmarkCharges)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.rhodiumCharges)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.otherCharges)"></span></td>
                                            <td><span class="items-table-total" x-text="'₹' + money(itm.selling)"></span></td>
                                            <td class="items-table-action-cell">
                                                <button class="items-table-remove" @click="removeItem(idx)" title="Remove">&times;</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="items-tablet-view">
                    <div class="items-tablet-shell">
                        <div class="items-tablet-scroll">
                            <table class="items-tablet-table">
                                <colgroup>
                                    <col style="width: 46%;">
                                    <col style="width: 12%;">
                                    <col style="width: 12%;">
                                    <col style="width: 12%;">
                                    <col style="width: 12%;">
                                    <col style="width: 6%;">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Metal</th>
                                        <th>Making</th>
                                        <th>Stone</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-if="items.length === 0">
                                        <tr class="items-tablet-empty-row">
                                            <td colspan="6">
                                                <div class="items-tablet-empty">
                                                    <strong>No items selected yet</strong>
                                                    <span>Use the add button to build this bill.</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                    <template x-for="(itm, idx) in items" :key="'tablet-' + itm.id + '-' + idx">
                                        <tr>
                                            <td>
                                                <div class="items-table-item">
                                                    <div class="items-table-thumb">
                                                        <template x-if="itm.image">
                                                            <img :src="itm.image" :alt="itm.design">
                                                        </template>
                                                    </div>
                                                    <div class="items-tablet-item-copy">
                                                        <div class="items-table-item-title" x-text="itm.design || itm.category"></div>
                                                        <div class="items-table-item-meta">
                                                            <span x-text="itm.barcode"></span> ·
                                                            <span x-text="itm.weight + 'g'"></span> ·
                                                            <span x-text="itm.purity + 'K'"></span>
                                                        </div>
                                                        <div class="items-tablet-inline-breakdown">
                                                            <span class="items-tablet-inline-pill">Hm <strong x-text="'₹' + money(itm.hallmarkCharges)"></strong></span>
                                                            <span class="items-tablet-inline-pill">Rh <strong x-text="'₹' + money(itm.rhodiumCharges)"></strong></span>
                                                            <span class="items-tablet-inline-pill">Ot <strong x-text="'₹' + money(itm.otherCharges)"></strong></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.cost)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.makingCharges)"></span></td>
                                            <td><span class="items-table-money" x-text="'₹' + money(itm.stoneCharges)"></span></td>
                                            <td><span class="items-table-total" x-text="'₹' + money(itm.selling)"></span></td>
                                            <td class="items-table-action-cell">
                                                <button class="items-table-remove" @click="removeItem(idx)" title="Remove">&times;</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="items-mobile-view">
                    <div class="items-mobile-shell">
                        <template x-if="items.length === 0">
                            <div class="items-mobile-empty">
                                <strong>No items selected yet</strong>
                                <span>Tap the add button to start the checkout.</span>
                            </div>
                        </template>
                        <template x-if="items.length > 0">
                            <div class="items-mobile-scroll">
                                <div class="items-mobile-list">
                                    <template x-for="(itm, idx) in items" :key="'mobile-' + itm.id + '-' + idx">
                                        <article class="items-mobile-card">
                                            <div class="items-mobile-top">
                                                <div class="items-table-thumb">
                                                    <template x-if="itm.image">
                                                        <img :src="itm.image" :alt="itm.design">
                                                    </template>
                                                </div>
                                                <div class="items-mobile-copy">
                                                    <div class="items-mobile-title" x-text="itm.design || itm.category"></div>
                                                    <div class="items-mobile-meta">
                                                        <span x-text="itm.barcode"></span> ·
                                                        <span x-text="itm.weight + 'g'"></span> ·
                                                        <span x-text="itm.purity + 'K'"></span>
                                                    </div>
                                                </div>
                                                <div class="items-mobile-total">
                                                    <button class="items-mobile-remove" @click="removeItem(idx)" title="Remove">&times;</button>
                                                    <span class="items-mobile-total-label">Total</span>
                                                    <span class="items-mobile-total-value" x-text="'₹' + money(itm.selling)"></span>
                                                </div>
                                            </div>
                                            <div class="items-mobile-breakdown">
                                                <div class="items-mobile-breakdown-cell">
                                                    <div class="items-mobile-breakdown-label">Metal</div>
                                                    <div class="items-mobile-breakdown-value" x-text="'₹' + money(itm.cost)"></div>
                                                </div>
                                                <div class="items-mobile-breakdown-cell">
                                                    <div class="items-mobile-breakdown-label">Making</div>
                                                    <div class="items-mobile-breakdown-value" x-text="'₹' + money(itm.makingCharges)"></div>
                                                </div>
                                                <div class="items-mobile-breakdown-cell">
                                                    <div class="items-mobile-breakdown-label">Stone</div>
                                                    <div class="items-mobile-breakdown-value" x-text="'₹' + money(itm.stoneCharges)"></div>
                                                </div>
                                                <div class="items-mobile-breakdown-cell">
                                                    <div class="items-mobile-breakdown-label">Hallmark</div>
                                                    <div class="items-mobile-breakdown-value" x-text="'₹' + money(itm.hallmarkCharges)"></div>
                                                </div>
                                                <div class="items-mobile-breakdown-cell">
                                                    <div class="items-mobile-breakdown-label">Rhodium</div>
                                                    <div class="items-mobile-breakdown-value" x-text="'₹' + money(itm.rhodiumCharges)"></div>
                                                </div>
                                                <div class="items-mobile-breakdown-cell">
                                                    <div class="items-mobile-breakdown-label">Other</div>
                                                    <div class="items-mobile-breakdown-value" x-text="'₹' + money(itm.otherCharges)"></div>
                                                </div>
                                            </div>
                                        </article>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ── Discount & Round-off Card ── -->
            <div class="card discount-mobile-card">
                <div class="card-title discount-card-title">
                    <span><span class="card-title-icon"></span> Discounts</span>
                </div>
                <div class="discount-stack">
                    <div class="discount-stack-row">
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
                    </div>
                    <template x-if="!posQuoteV2Enabled">
                        <div class="field">
                            <label class="field-label">
                                Round-off (₹)
                                <span class="field-label-hint" x-show="roundOff === 0 && payments.length === 0" style="font-weight:400;color:#94a3b8;font-size:11px;margin-left:4px;">— enter payments first</span>
                                <span class="field-label-hint" x-show="roundOff !== 0" style="font-weight:400;color:#64748b;font-size:11px;margin-left:4px;" x-text="roundOff < 0 ? 'Paise waived off' : 'Added to total'"></span>
                            </label>
                            <div class="roundoff-controls">
                                <input type="number" class="field-input roundoff-input"
                                       x-model.number="roundOff"
                                       step="0.01"
                                       min="-1" max="1"
                                       :disabled="payments.length === 0"
                                       @input="clampAndRecalc()"
                                       placeholder="Auto">
                            </div>
                            <p class="field-hint" style="font-size:11px;color:#94a3b8;margin-top:3px;">Only for paise adjustment (max ±₹1). For larger discounts, use the Discount field above.</p>
                        </div>
                    </template>
                    {{-- Auto round-off (Quote V2) is shown in the Price Summary
                         below, so it's intentionally not duplicated here. The
                         legacy manual round-off input above remains for non-V2 shops. --}}
                </div>
            </div>

            <div class="card offers-mobile-card">
                <button type="button" class="offers-trigger" @click="$dispatch('open-modal', 'offers-modal')">
                    <span class="card-toggle-copy">
                        <span class="card-title-icon"></span>
                        <span class="card-toggle-text">
                            <span class="card-toggle-heading">Offers &amp; Redemption</span>
                            <span class="card-toggle-summary" x-text="offerAccordionSummary()"></span>
                        </span>
                    </span>
                    <span class="offers-trigger-action">
                        Manage
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"></path></svg>
                    </span>
                </button>
            </div>

            <div class="summary-card summary-mobile-card">
                <div class="card-title summary-title-tight">
                    <span><span class="card-title-icon"></span> Price Summary</span>
                </div>

                <div class="summary-row">
                    <span>Tag / Selling Price <template x-if="items.length > 1"><span class="summary-item-count" x-text="'(' + items.length + ' items)'"></span></template></span>
                    <span class="summary-row-val" x-text="'₹ ' + Number(sellingPrice).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row">
                    <span>Manual Discount</span>
                    <span class="summary-row-val" :class="{ 'summary-row-danger': displayDiscount() > 0 }" x-text="(displayDiscount() > 0 ? '- ₹ ' : '₹ ') + Number(displayDiscount()).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row" x-show="offerDiscountAmount() > 0">
                    <span x-text="'Offer: ' + appliedOfferLabel()"></span>
                    <span class="summary-row-val summary-row-danger" x-text="'- ₹ ' + Number(offerDiscountAmount()).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row">
                    <span>GST (<span x-text="gstRate"></span>%) <template x-if="(displayDiscount() + offerDiscountAmount()) > 0"><span class="summary-gst-base" x-text="'on ₹' + Number(Math.max(sellingPrice - displayDiscount() - offerDiscountAmount(), 0)).toLocaleString('en-IN', {minimumFractionDigits:2})"></span></template></span>
                    <span class="summary-row-val" x-text="'₹ ' + Number(gst).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-row">
                    <span>
                        Round-off
                        <span style="display:block;font-size:11px;font-weight:400;color:#94a3b8;margin-top:2px;"
                              x-text="roundOff > 0 ? 'Rounded up — paise added' : (roundOff < 0 ? 'Rounded down — paise waived' : 'No rounding applied')"></span>
                    </span>
                    <span class="summary-row-val" x-text="(roundOff > 0 ? '+ ₹ ' : roundOff < 0 ? '- ₹ ' : '₹ ') + Math.abs(roundOff).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
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

            <!-- ── Payment Card ── -->
            <div class="card payment-card">
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

                <div class="pay-status-wrap">
                    <template x-if="hasEmiMode() && remaining() > 0.01">
                        <div class="pay-status pay-status-emi">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <div class="pay-status-body">
                                <div class="pay-status-label">Will be scheduled in EMI</div>
                                <div class="pay-status-value">₹<span x-text="remaining().toLocaleString('en-IN', {minimumFractionDigits:2})"></span></div>
                            </div>
                        </div>
                    </template>
                    <template x-if="!hasEmiMode() && remaining() > 0.01">
                        <div class="pay-status pay-status-remaining">
                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                            <div class="pay-status-body">
                                <div class="pay-status-label">Remaining to pay</div>
                                <div class="pay-status-value">₹<span x-text="remaining().toLocaleString('en-IN', {minimumFractionDigits:2})"></span></div>
                            </div>
                        </div>
                    </template>
                    <template x-if="!hasEmiMode() && remaining() <= 0.01 && excess() <= 0.5">
                        <div class="pay-status pay-status-paid">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span class="pay-status-paid-text">Fully paid</span>
                        </div>
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

            <div class="sell-mobile-block">
                <button class="btn-sell btn-sell-offset"
                        :disabled="!canSell()"
                        @click="completeSale()">
                    <span x-show="!selling">Complete Sale</span>
                    <span x-show="selling">Processing…</span>
                </button>

                <div x-show="missingAccountSelection()" class="mt-2 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2.5">
                    <svg class="w-4 h-4 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <div>
                        <p class="text-xs font-semibold text-red-700">Account not selected</p>
                        <p class="text-xs text-red-600 mt-0.5">Tap <strong>— Select account</strong> next to each UPI / Bank payment to choose which account to receive this payment.</p>
                    </div>
                </div>
                <p x-show="saleError" class="text-sm text-red-600 mt-3 text-center" x-text="saleError"></p>
            </div>
        </div>

        <!-- ══ RIGHT SIDEBAR ══ -->
        <div class="pos-sidebar">
            <div class="summary-card discount-sidebar-card">
                <div class="card-title discount-card-title">
                    <span><span class="card-title-icon"></span> Discounts</span>
                </div>
                <div class="discount-stack">
                    <div class="discount-stack-row">
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
                    </div>
                    <template x-if="!posQuoteV2Enabled">
                        <div class="field">
                            <label class="field-label">
                                Round-off (₹)
                                <span class="field-label-hint" x-show="roundOff === 0 && payments.length === 0" style="font-weight:400;color:#94a3b8;font-size:11px;margin-left:4px;">— enter payments first</span>
                                <span class="field-label-hint" x-show="roundOff !== 0" style="font-weight:400;color:#64748b;font-size:11px;margin-left:4px;" x-text="roundOff < 0 ? 'Paise waived off' : 'Added to total'"></span>
                            </label>
                            <div class="roundoff-controls">
                                <input type="number" class="field-input roundoff-input"
                                       x-model.number="roundOff"
                                       step="0.01"
                                       min="-1" max="1"
                                       :disabled="payments.length === 0"
                                       @input="clampAndRecalc()"
                                       placeholder="Auto">
                            </div>
                            <p class="field-hint" style="font-size:11px;color:#94a3b8;margin-top:3px;">Only for paise adjustment (max ±₹1). For larger discounts, use the Discount field above.</p>
                        </div>
                    </template>
                    {{-- Auto round-off (Quote V2) is shown in the Price Summary
                         below, so it's intentionally not duplicated here. The
                         legacy manual round-off input above remains for non-V2 shops. --}}
                </div>
            </div>

            <div class="summary-card offers-sidebar-card">
                <button type="button" class="offers-trigger" @click="$dispatch('open-modal', 'offers-modal')">
                    <span class="card-toggle-copy">
                        <span class="card-title-icon"></span>
                        <span class="card-toggle-text">
                            <span class="card-toggle-heading">Offers &amp; Redemption</span>
                            <span class="card-toggle-summary" x-text="offerAccordionSummary()"></span>
                        </span>
                    </span>
                    <span class="offers-trigger-action">
                        Manage
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"></path></svg>
                    </span>
                </button>
            </div>

            <div class="summary-card summary-sidebar-card">
                <div class="card-title summary-title-tight">
                    <span><span class="card-title-icon"></span> Price Summary</span>
                </div>

                <div class="summary-row">
                    <span>Tag / Selling Price <template x-if="items.length > 1"><span class="summary-item-count" x-text="'(' + items.length + ' items)'"></span></template></span>
                    <span class="summary-row-val" x-text="'₹ ' + Number(sellingPrice).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row">
                    <span>Manual Discount</span>
                    <span class="summary-row-val" :class="{ 'summary-row-danger': displayDiscount() > 0 }" x-text="(displayDiscount() > 0 ? '- ₹ ' : '₹ ') + Number(displayDiscount()).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row" x-show="offerDiscountAmount() > 0">
                    <span x-text="'Offer: ' + appliedOfferLabel()"></span>
                    <span class="summary-row-val summary-row-danger" x-text="'- ₹ ' + Number(offerDiscountAmount()).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-row">
                    <span>GST (<span x-text="gstRate"></span>%) <template x-if="(displayDiscount() + offerDiscountAmount()) > 0"><span class="summary-gst-base" x-text="'on ₹' + Number(Math.max(sellingPrice - displayDiscount() - offerDiscountAmount(), 0)).toLocaleString('en-IN', {minimumFractionDigits:2})"></span></template></span>
                    <span class="summary-row-val" x-text="'₹ ' + Number(gst).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-row">
                    <span>
                        Round-off
                        <span style="display:block;font-size:11px;font-weight:400;color:#94a3b8;margin-top:2px;"
                              x-text="roundOff > 0 ? 'Rounded up — paise added' : (roundOff < 0 ? 'Rounded down — paise waived' : 'No rounding applied')"></span>
                    </span>
                    <span class="summary-row-val" x-text="(roundOff > 0 ? '+ ₹ ' : roundOff < 0 ? '- ₹ ' : '₹ ') + Math.abs(roundOff).toLocaleString('en-IN', {minimumFractionDigits:2})"></span>
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

            <div class="sell-sidebar-block">
                <button class="btn-sell btn-sell-offset"
                        :disabled="!canSell()"
                        @click="completeSale()">
                    <span x-show="!selling">Complete Sale</span>
                    <span x-show="selling">Processing…</span>
                </button>

                <div x-show="missingAccountSelection()" class="mt-2 flex items-start gap-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2.5">
                    <svg class="w-4 h-4 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <div>
                        <p class="text-xs font-semibold text-red-700">Account not selected</p>
                        <p class="text-xs text-red-600 mt-0.5">Tap <strong>— Select account</strong> next to each UPI / Bank payment to choose which account to receive this payment.</p>
                    </div>
                </div>
                <p x-show="saleError" class="text-sm text-red-600 mt-3 text-center" x-text="saleError"></p>
            </div>
        </div>
    </div>

    {{-- Offers & Redemption Modal — shared by the mobile + desktop triggers.
         Inside the x-data scope so it reads/writes the same Alpine state. --}}
    <x-modal name="offers-modal" maxWidth="lg">
        <div class="p-6">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-base font-bold text-gray-900">Offers &amp; Redemption</h3>
                <button type="button" class="text-gray-400 hover:text-gray-600 transition" @click="$dispatch('close-modal', 'offers-modal')" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <div class="offers-stack">
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
                <template x-if="redeemableEnrollments.length > 0">
                    <div class="offers-stack">
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
                                   @input="normalizeRedemptionAmount(); recalc(); applyAutoRoundOff(); fetchQuoteDebounced()">
                        </div>
                    </div>
                </template>
            </div>

            <div class="offers-modal-footer">
                <button type="button" class="offers-modal-done" @click="$dispatch('close-modal', 'offers-modal')">Done</button>
            </div>
        </div>
    </x-modal>

    {{-- Compliance Modal — inside x-data scope so it can access Alpine state directly --}}
    <x-modal name="compliance-modal" maxWidth="lg">
        <div class="p-6">
            <div class="flex items-start gap-3 mb-5">
                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-gray-900">Government KYC Required</h3>
                    <p class="text-sm text-gray-500 mt-0.5">
                        This transaction exceeds
                        <span class="font-semibold text-gray-700"
                              x-text="'₹' + Number(complianceThreshold).toLocaleString('en-IN')"></span>.
                        PAN and address details are legally required under Income Tax Rule 114B.
                    </p>
                </div>
            </div>

            {{-- General error banner — surfaces any error not tied to a visible field
                 (e.g. server rejection, validation on a field the modal isn't showing) --}}
            <div x-show="complianceGeneralError" x-cloak
                 class="mb-4 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-sm text-red-700"
                 x-text="complianceGeneralError"></div>

            <div class="space-y-4">

                {{-- PAN field --}}
                <div x-show="complianceMissingFields.includes('pan')">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">
                        PAN Number <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           x-model="compliancePan"
                           @input="compliancePan = $event.target.value.toUpperCase().replace(/\s/g, '')"
                           maxlength="10"
                           placeholder="ABCDE1234F"
                           autocomplete="off"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono tracking-widest uppercase focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <p x-show="complianceErrors.pan" x-text="(complianceErrors.pan || [])[0]" class="mt-1 text-xs text-red-600"></p>
                    <p class="mt-1 text-xs text-gray-400">Format: 5 letters + 4 digits + 1 letter (e.g. ABCDE1234F)</p>
                </div>

                {{-- Mobile field --}}
                <div x-show="complianceMissingFields.includes('mobile')">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">
                        Mobile Number <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           x-model="complianceMobile"
                           maxlength="10"
                           placeholder="10-digit mobile number"
                           autocomplete="off"
                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500">
                    <p x-show="complianceErrors.mobile" x-text="(complianceErrors.mobile || [])[0]" class="mt-1 text-xs text-red-600"></p>
                </div>

                {{-- Address field --}}
                <div x-show="complianceMissingFields.includes('address')">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">
                        Full Address <span class="text-red-500">*</span>
                    </label>
                    <textarea x-model="complianceAddress"
                              rows="2"
                              placeholder="Full address including city and state"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-500 resize-none"></textarea>
                    <p x-show="complianceErrors.address" x-text="(complianceErrors.address || [])[0]" class="mt-1 text-xs text-red-600"></p>
                </div>

                {{-- Consent --}}
                <label class="flex items-start gap-2.5 cursor-pointer pt-1">
                    <input type="checkbox"
                           x-model="complianceConsent"
                           class="mt-0.5 h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <span class="text-sm text-gray-600">
                        The customer has consented to provide these details for statutory compliance purposes and is aware they will be recorded.
                    </span>
                </label>
                <p x-show="complianceErrors.consent" x-text="(complianceErrors.consent || [])[0]" class="text-xs text-red-600"></p>
            </div>

            <div class="flex gap-3 mt-6 justify-end">
                <button type="button"
                        @click="$dispatch('close-modal', 'compliance-modal')"
                        :disabled="complianceSaving"
                        class="px-4 py-2 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    Cancel Sale
                </button>
                <button type="button"
                        @click="submitCompliance()"
                        :disabled="complianceSaving || !complianceConsent"
                        class="px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-semibold hover:bg-amber-700 disabled:opacity-50">
                    <span x-show="!complianceSaving">Save &amp; Complete Sale</span>
                    <span x-show="complianceSaving">Saving…</span>
                </button>
            </div>
        </div>
    </x-modal>
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
        stockCatalog: [],
        itemPickerOpen: false,
        itemPickerLoading: false,
        itemPickerQuery: '',
        itemPickerResults: [],
        itemPickerActiveIndex: -1,
        itemPickerMinQueryLength: 2,
        itemPickerMaxVisibleResults: 60,

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
        // Server-computed discount amount (rupees) from the Quote V2 breakdown.
        // Used for DISPLAY only so a percentage discount shows its rupee value
        // in the Price Summary without being fed back into the quote payload
        // (which would double-apply it). Mirrors `discount` in legacy mode.
        quoteManualDiscount: 0,
        roundOff: 0,
        loyaltyPointsPerHundred: {{ $loyaltyPointsPerHundred ?? 1 }},
        total: 0,

        // Quote-driven pricing (Phase 2). When posQuoteV2Enabled is true the JS
        // calls /pos/quote on every cart/discount/offer change and binds the
        // totals box to the quote response. The local recalc() is the fallback
        // used only when the feature flag is off.
        posQuoteV2Enabled: {{ config('features.pos_quote_v2') ? 'true' : 'false' }},
        posQuoteUrl: @json(route('pos.quote')),
        posQuotePersistUrl: @json(route('pos.quote.persist')),
        currentQuoteId: null,
        currentQuoteSignature: null,
        currentQuoteBreakdownJson: null,
        currentQuoteExpiresAt: null,
        currentQuoteInput: null,
        quoteFetchInFlight: false,
        quoteFetchDebounceTimer: null,
        quoteError: '',
        quoteRoundingMethod: 'none',
        quoteRoundingNearest: 1,
        quoteRoundingAdjustment: 0,

        // Payment methods (configured by shop owner)
        paymentMethods: @json($paymentMethods),

        // Payments
        payments: [],

        // State
        selling: false,
        saleError: '',

        // Compliance modal state
        complianceRequired: false,
        complianceMissingFields: [],
        complianceThreshold: 200000,
        compliancePan: '',
        complianceMobile: '',
        complianceAddress: '',
        complianceIdNumber: '',
        complianceConsent: false,
        complianceSaving: false,
        complianceErrors: {},
        complianceGeneralError: '',
        posComplianceSaveUrl: @json(route('pos.compliance.save')),

        init() {
            this.$nextTick(() => {
                this.buildStockCatalog();

                const params = new URLSearchParams(window.location.search);
                const itemIds = params.getAll('item_ids[]');
                const singleId = params.get('item_id');

                if (itemIds.length > 0) {
                    itemIds.forEach(id => this.addItem(id));
                } else if (singleId) {
                    this.addItem(singleId);
                }

                // If the cart was preloaded from the URL, fetch the initial
                // server-side quote so totals reflect engine output from the
                // first paint. No-op when the flag is off.
                if (this.posQuoteV2Enabled && this.items.length > 0) {
                    this.fetchQuoteDebounced();
                }
            });
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
        buildStockCatalog() {
            const source = this.$refs.stockSource;
            if (!source) {
                this.stockCatalog = [];
                return;
            }

            this.stockCatalog = Array.from(source.options)
                .filter(option => option.value)
                .map(option => ({
                    id: parseInt(option.value, 10),
                    design: option.dataset.design || '',
                    category: option.dataset.category || '',
                    sub_category: option.dataset.sub_category || '',
                    weight: parseFloat(option.dataset.weight || 0),
                    purity: parseFloat(option.dataset.purity || 0),
                    net: parseFloat(option.dataset.net || 0),
                    selling: parseFloat(option.dataset.selling || 0),
                    cost: parseFloat(option.dataset.cost || 0),
                    makingCharges: parseFloat(option.dataset.making || 0),
                    stoneCharges: parseFloat(option.dataset.stone || 0),
                    hallmarkCharges: parseFloat(option.dataset.hallmark || 0),
                    rhodiumCharges: parseFloat(option.dataset.rhodium || 0),
                    otherCharges: parseFloat(option.dataset.other || 0),
                    barcode: option.dataset.barcode || '',
                    image: option.dataset.image || '',
                }));
        },

        itemPickerTriggerLabel() {
            return this.items.length ? `Add more items (${this.items.length})` : 'Browse stock items';
        },

        focusItemPicker() {
            this.$nextTick(() => this.$refs.itemPickerFilter?.focus());
        },

        toggleItemPicker() {
            if (this.itemPickerOpen) {
                this.closeItemPicker(true);
                return;
            }

            this.openItemPicker();
        },

        openItemPicker() {
            this.itemPickerOpen = true;
            this.renderItemPicker();
            this.focusItemPicker();
        },

        closeItemPicker(reset = true) {
            this.itemPickerOpen = false;
            this.itemPickerLoading = false;
            this.itemPickerActiveIndex = -1;

            if (reset) {
                this.itemPickerQuery = '';
                this.itemPickerResults = [];
            }
        },

        money(value) {
            return Number(value || 0).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },

        isItemSelected(id) {
            const numericId = parseInt(id, 10);
            return this.items.some(item => item.id === numericId);
        },

        renderItemPicker() {
            const q = String(this.itemPickerQuery || '').trim().toLowerCase();

            this.itemPickerActiveIndex = -1;
            this.itemPickerResults = [];

            if (q.length < this.itemPickerMinQueryLength) {
                return;
            }

            this.itemPickerResults = this.stockCatalog
                .filter(item => {
                    return String(item.barcode || '').toLowerCase().includes(q)
                        || String(item.design || '').toLowerCase().includes(q)
                        || String(item.category || '').toLowerCase().includes(q);
                })
                .slice(0, this.itemPickerMaxVisibleResults)
                .map(item => ({
                    ...item,
                    disabled: this.isItemSelected(item.id),
                    title: `${item.barcode || item.id} — ${item.design || item.category || 'N/A'}`,
                    meta: `${Number(item.weight || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 })}g · ${item.purity}K · ₹${this.money(item.selling)}`,
                }));
        },

        setItemPickerActive(index) {
            if (index < 0 || index >= this.itemPickerResults.length) {
                this.itemPickerActiveIndex = -1;
                return;
            }

            this.itemPickerActiveIndex = index;

            this.$nextTick(() => {
                const option = this.$refs.itemPickerList?.querySelector(`[data-picker-index="${index}"]`);
                option?.scrollIntoView({ block: 'nearest' });
            });
        },

        findExactStockMatch(query) {
            const q = String(query || '').trim().toLowerCase();
            if (!q) return null;

            return this.stockCatalog.find(item => String(item.barcode || '').toLowerCase() === q) || null;
        },

        selectItemFromPicker(id) {
            if (!id || this.isItemSelected(id)) {
                if (id) window.showToast('Item already added', 'error');
                return;
            }

            this.addItem(id);
            this.itemPickerQuery = '';
            this.renderItemPicker();
            this.focusItemPicker();
        },

        async handleItemPickerKeydown(event) {
            if (event.key === 'ArrowDown') {
                if (this.itemPickerResults.length) {
                    event.preventDefault();
                    const nextIndex = this.itemPickerActiveIndex < this.itemPickerResults.length - 1
                        ? this.itemPickerActiveIndex + 1
                        : 0;
                    this.setItemPickerActive(nextIndex);
                }
                return;
            }

            if (event.key === 'ArrowUp') {
                if (this.itemPickerResults.length) {
                    event.preventDefault();
                    const nextIndex = this.itemPickerActiveIndex > 0
                        ? this.itemPickerActiveIndex - 1
                        : this.itemPickerResults.length - 1;
                    this.setItemPickerActive(nextIndex);
                }
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                this.closeItemPicker(true);
                return;
            }

            if (event.key !== 'Enter') return;

            event.preventDefault();

            const activeResult = this.itemPickerResults[this.itemPickerActiveIndex];
            if (activeResult && !activeResult.disabled) {
                this.selectItemFromPicker(activeResult.id);
                return;
            }

            const exactLocalMatch = this.findExactStockMatch(this.itemPickerQuery);
            if (exactLocalMatch) {
                this.selectItemFromPicker(exactLocalMatch.id);
                return;
            }

            await this.lookupBarcode(this.itemPickerQuery);
        },

        addItem(id) {
            const numericId = parseInt(id, 10);
            if (!numericId || this.isItemSelected(numericId)) return;

            const item = this.stockCatalog.find(entry => entry.id === numericId);
            if (!item) return;

            this.items.push({
                ...item,
            });
            this.recalc();
            this.applyAutoRoundOff();
            this.syncUrl();
            this.renderItemPicker();
            this.fetchQuoteDebounced();
        },

        removeItem(idx) {
            this.items.splice(idx, 1);
            this.recalc();
            this.applyAutoRoundOff();
            this.syncUrl();
            this.renderItemPicker();
            this.fetchQuoteDebounced();
        },

        async lookupBarcode(barcode) {
            const query = String(barcode || '').trim();
            if (!query) return;

            this.itemPickerLoading = true;

            try {
                const res = await fetch(`/api/item-by-barcode/${encodeURIComponent(query)}`);
                if (!res.ok) { window.showToast('Item not found', 'error'); return; }
                const d = await res.json();
                if (d.status && d.status !== 'in_stock') { window.showToast('Item is not in stock', 'error'); return; }
                if (this.isItemSelected(d.id)) { window.showToast('Item already added', 'error'); return; }

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
                    makingCharges: d.making_charges || 0,
                    stoneCharges: d.stone_charges || 0,
                    hallmarkCharges: d.hallmark_charges || 0,
                    rhodiumCharges: d.rhodium_charges || 0,
                    otherCharges: d.other_charges || 0,
                    barcode: d.barcode || query,
                    image: d.image ? '/storage/' + d.image : '',
                });
                this.recalc();
                this.syncUrl();
                this.itemPickerQuery = '';
                this.renderItemPicker();
                this.focusItemPicker();
                this.fetchQuoteDebounced();
            } catch {
                window.showToast('Error looking up barcode', 'error');
            } finally {
                this.itemPickerLoading = false;
            }
        },

        clearItems() {
            this.items = [];
            this.recalc();
            this.syncUrl();
            this.renderItemPicker();
            this.focusItemPicker();
            this.fetchQuoteDebounced();
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
            this.applyAutoRoundOff();
            this.fetchQuoteDebounced();
        },

        onDiscountPercentInput(value) {
            this.discountPercent = this.clampPercent(value);
            this.discountInputSource = 'percent';
            this.recalc();
            this.applyAutoRoundOff();
            this.fetchQuoteDebounced();
        },

        /* Discount amount (rupees) to SHOW in the Price Summary.
         * In Quote V2 the server is authoritative, so we display the
         * server-computed value (covers percentage discounts, which never
         * populate the local `discount` field). In legacy mode local
         * `discount` already holds the computed rupee value. */
        displayDiscount() {
            return this.posQuoteV2Enabled ? Number(this.quoteManualDiscount || 0) : Number(this.discount || 0);
        },

        /* ── Price calculation ──────────────────── */
        recalc() {
            // Quote v2 takes over the totals when enabled — skip local math so
            // we don't overwrite the server-issued breakdown.
            if (this.posQuoteV2Enabled) return;

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

        /* ── Quote v2: debounced fetch ──────────── */
        fetchQuoteDebounced() {
            if (!this.posQuoteV2Enabled) return;
            if (this.quoteFetchDebounceTimer) {
                clearTimeout(this.quoteFetchDebounceTimer);
                this.quoteFetchDebounceTimer = null;
            }
            if (this.items.length === 0) {
                // Empty cart — clear any stale quote so the UI shows ₹0 totals.
                this.currentQuoteId = null;
                this.currentQuoteSignature = null;
                this.currentQuoteBreakdownJson = null;
                this.currentQuoteExpiresAt = null;
                this.currentQuoteInput = null;
                this.sellingPrice = 0;
                this.gst = 0;
                this.total = 0;
                this.roundOff = 0;
                this.quoteRoundingAdjustment = 0;
                return;
            }
            this.quoteFetchDebounceTimer = setTimeout(() => {
                this.fetchQuote();
            }, 150);
        },

        async fetchQuote() {
            if (!this.posQuoteV2Enabled) return;
            if (this.items.length === 0) return;

            this.quoteFetchInFlight = true;
            this.quoteError = '';

            const payload = {
                mode: 'retailer',
                customer_id: {{ $customer->id }},
                item_ids: this.items.map(i => i.id),
                manual_discount: this.discount,
                manual_discount_percent: this.discountInputSource === 'percent' ? this.discountPercent : null,
                offer_scheme_id: this.selectedOfferId || null,
                scheme_redemption: this.appliedRedemptionAmount() > 0 ? {
                    enrollment_id: parseInt(this.schemeRedemptionEnrollmentId, 10),
                    amount: this.appliedRedemptionAmount(),
                } : null,
            };

            try {
                const res = await fetch(this.posQuoteUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(payload),
                });

                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    this.quoteError = data.message || 'Could not refresh price.';
                    // Leave displayed totals untouched so the cashier sees a
                    // stale-but-coherent screen rather than zeros.
                    return;
                }

                this.currentQuoteId = data.quote_id || null;
                this.currentQuoteSignature = data.signature || null;
                this.currentQuoteBreakdownJson = data.breakdown_json || null;
                this.currentQuoteExpiresAt = data.expires_at || null;
                this.currentQuoteInput = payload;

                const b = data.breakdown || {};
                if (b.subtotal !== undefined) this.sellingPrice = Number(b.subtotal);
                // Surface the server-computed manual/percentage discount so the
                // Price Summary can display it (display-only — never fed back into
                // the next quote payload, which would double-apply the discount).
                this.quoteManualDiscount = (b.manual_discount !== undefined) ? Number(b.manual_discount) : 0;
                if (b.gst !== undefined) this.gst = Number(b.gst);
                if (b.gst_rate !== undefined) this.gstRate = Number(b.gst_rate);
                if (b.final_total !== undefined) this.total = Number(b.final_total);
                if (b.rounding_method !== undefined) this.quoteRoundingMethod = b.rounding_method;
                if (b.rounding_nearest !== undefined) this.quoteRoundingNearest = Number(b.rounding_nearest);
                if (b.rounding_adjustment !== undefined) {
                    this.quoteRoundingAdjustment = Number(b.rounding_adjustment);
                    // Keep the legacy roundOff field in sync so any read-only
                    // bindings that still reference it continue to render.
                    this.roundOff = Number(b.rounding_adjustment);
                }
            } catch (e) {
                this.quoteError = 'Network error while refreshing price.';
                // Intentionally do not zero out totals on network errors.
            } finally {
                this.quoteFetchInFlight = false;
            }
        },

        async persistCurrentQuote() {
            if (!this.posQuoteV2Enabled) return;
            if (!this.currentQuoteId || !this.currentQuoteSignature) return;

            try {
                const res = await fetch(this.posQuotePersistUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        quote_id: this.currentQuoteId,
                        signature: this.currentQuoteSignature,
                        breakdown_json: this.currentQuoteBreakdownJson,
                        input_payload: this.currentQuoteInput,
                    }),
                });

                const data = await res.json().catch(() => ({}));
                if (res.ok && data.quote_id) {
                    this.currentQuoteId = data.quote_id;
                    if (data.signature) this.currentQuoteSignature = data.signature;
                    if (data.expires_at) this.currentQuoteExpiresAt = data.expires_at;
                } else if (!res.ok) {
                    // Phase 2 is additive only — log and let the sale proceed.
                    console.warn('persistCurrentQuote failed', res.status, data);
                }
            } catch (e) {
                console.warn('persistCurrentQuote network error', e);
            }
        },

        /* Clamp manual round-off input to ±1 and recalculate.
         * Only used for the legacy (flag-off) cashier-typed paise adjustment.
         * When the quote engine is active, round-off comes from the server's
         * rounding_adjustment (which can be up to ±₹10 under Tally-style
         * rounding) and must NOT be clamped here. */
        clampAndRecalc() {
            if (this.posQuoteV2Enabled) return;
            if (this.roundOff > 1)  this.roundOff = 1;
            if (this.roundOff < -1) this.roundOff = -1;
            this.recalc();
        },

        /* Compute raw total without any round-off applied */
        rawTotal() {
            const totalDiscount = Math.min(this.sellingPrice, this.discount + this.offerDiscountAmount());
            const taxable = Math.max(this.sellingPrice - totalDiscount, 0);
            const gst = Math.round(taxable * (this.gstRate / 100) * 100) / 100;
            return Math.round((this.sellingPrice + gst - totalDiscount) * 100) / 100;
        },

        /* Auto-apply round-off for sub-₹1 paise gaps after payments are entered.
           Always computes against rawTotal (not this.total) to avoid including
           a previous roundOff in the gap calculation. */
        applyAutoRoundOff() {
            // Quote V2 owns rounding (shop rounding strategy, computed server-side
            // and applied in fetchQuote). Running the legacy payment-gap round-off
            // here would set a local estimate that fetchQuote then overwrites
            // ~150ms later — the visible "round-off jumps / summary realigns"
            // flicker. Skip entirely in V2.
            if (this.posQuoteV2Enabled) return;
            if (this.payments.length === 0) {
                if (this.roundOff !== 0) { this.roundOff = 0; this.recalc(); }
                return;
            }
            const raw = this.rawTotal();
            const gap = Math.round((raw - this.paymentTotal() - this.appliedRedemptionAmount()) * 100) / 100;
            if (gap > 0 && gap < 1) {
                // Sub-rupee shortfall — auto waive the paise
                const newRoundOff = Math.round(-gap * 100) / 100;
                if (this.roundOff !== newRoundOff) { this.roundOff = newRoundOff; this.recalc(); }
            } else {
                // Gap is zero, overpaid, or ≥₹1 — clear any auto round-off
                if (this.roundOff !== 0) { this.roundOff = 0; this.recalc(); }
            }
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

        offerAccordionSummary() {
            const offerSummary = this.ignoreAutoOffer
                ? 'Offer skipped'
                : `Offer: ${this.appliedOffer() ? this.appliedOffer().name : 'Auto if eligible'}`;

            if (this.appliedRedemptionAmount() > 0) {
                return `${offerSummary} · Redeem: ₹${Number(this.appliedRedemptionAmount()).toLocaleString('en-IN', {minimumFractionDigits:2})}`;
            }

            if (this.schemeRedemptionEnrollmentId) {
                const plan = this.redemptionSelectedEnrollment();
                return `${offerSummary} · Redeem: ${plan ? (plan.scheme_name || 'Plan selected') : 'Selected'}`;
            }

            return `${offerSummary} · No redemption`;
        },

        onOfferSelectionChange() {
            if (this.selectedOfferId) {
                this.ignoreAutoOffer = false;
            }
            this.recalc();
            this.fetchQuoteDebounced();
        },

        skipOfferForThisBill() {
            this.selectedOfferId = '';
            this.ignoreAutoOffer = true;
            this.recalc();
            this.fetchQuoteDebounced();
        },

        enableAutoOfferForThisBill() {
            this.ignoreAutoOffer = false;
            this.recalc();
            this.fetchQuoteDebounced();
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
                this.fetchQuoteDebounced();
                return;
            }

            const max = this.maxRedeemableForSelection();
            const payableWithoutRedemption = Math.max(0, this.total - this.paymentTotal());
            this.schemeRedemptionAmount = Math.min(max, payableWithoutRedemption > 0 ? payableWithoutRedemption : max);
            this.recalc();
            this.fetchQuoteDebounced();
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
            this.applyAutoRoundOff();
        },

        removePayment(idx) {
            this.payments.splice(idx, 1);
            this.applyAutoRoundOff();
        },

        calcMetalValue(idx) {
            const p = this.payments[idx];
            const net = p.metal_gross_weight * (1 - (p.metal_test_loss || 0) / 100);
            const fine = p.mode === 'old_gold' ? net * (p.metal_purity / 24) : net * (p.metal_purity / 1000);
            p.amount = Math.round(fine * p.metal_rate_per_gram);
            this.applyAutoRoundOff();
        },

        recalcPayments() {
            this.applyAutoRoundOff();
        },

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

            return this.remaining() <= 0.01;
        },

        async completeSale() {
            if (!this.canSell()) return;
            this.selling = true;
            this.saleError = '';

            // Phase 2: persist the latest quote so it's durable before sale.
            // Failures are logged but do NOT block the sale — Phase 2 is
            // additive only; Phase 3a will validate quote_id server-side.
            if (this.posQuoteV2Enabled) {
                await this.persistCurrentQuote();
            }

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
                        // Phase 3a: quote-aware sale. Server uses these (when
                        // present) as the authoritative source for discount/
                        // round-off, ignores the legacy fields above, and stamps
                        // the quote consumed so double-clicks return the same
                        // invoice (idempotency).
                        quote_id: this.posQuoteV2Enabled ? this.currentQuoteId : null,
                        quote_signature: this.posQuoteV2Enabled ? this.currentQuoteSignature : null,
                    }),
                });

                const data = await res.json();

                // Phase 3a: quote went stale between display and submit (offer
                // expired, item price moved, etc.). Server returns 409 with a
                // fresh quote — show the new total non-blockingly and let the
                // cashier confirm. Re-enable the Complete Sale button so they
                // can click again on the refreshed numbers.
                if (res.status === 409 && data && data.error === 'quote_stale' && data.new_quote) {
                    this.currentQuoteId         = data.new_quote.quote_id;
                    this.currentQuoteSignature  = data.new_quote.signature;
                    this.currentQuoteBreakdownJson = data.new_quote.breakdown_json;
                    this.currentQuoteExpiresAt  = data.new_quote.expires_at;
                    const bd = data.new_quote.breakdown || {};
                    this.sellingPrice           = bd.subtotal ?? this.sellingPrice;
                    this.gst                    = bd.gst ?? this.gst;
                    this.gstRate                = bd.gst_rate ?? this.gstRate;
                    this.total                  = bd.final_total ?? this.total;
                    this.roundOff               = bd.rounding_adjustment ?? this.roundOff;
                    this.quoteRoundingAdjustment = bd.rounding_adjustment ?? 0;
                    this.quoteRoundingMethod    = bd.rounding_method ?? 'none';
                    this.quoteRoundingNearest   = bd.rounding_nearest ?? 1;
                    this.saleError              = data.message || 'Prices were refreshed — please confirm the updated total.';
                    this.selling                = false;
                    return;
                }

                // Phase 3a: item raced and got sold by another cashier.
                if (res.status === 409 && data && data.error === 'items_unavailable') {
                    this.saleError = data.message || 'One or more items were sold by another cashier. Refresh to see the updated cart.';
                    this.selling   = false;
                    return;
                }

                if (!res.ok) {
                    if (data.compliance_required) {
                        this.selling = false;
                        this.complianceMissingFields = data.missing_fields || [];
                        this.complianceThreshold = data.threshold || 200000;
                        this.compliancePan = '';
                        this.complianceMobile = '';
                        this.complianceAddress = '';
                        this.complianceIdNumber = '';
                        this.complianceConsent = false;
                        this.complianceErrors = {};
                        this.complianceGeneralError = '';
                        this.$dispatch('open-modal', 'compliance-modal');
                        return;
                    }
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

        async submitCompliance() {
            this.complianceSaving = true;
            this.complianceErrors = {};
            this.complianceGeneralError = '';

            try {
                const res = await fetch(this.posComplianceSaveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        customer_id: {{ $customer->id }},
                        pan: this.compliancePan || null,
                        mobile: this.complianceMobile || null,
                        address: this.complianceAddress || null,
                        id_number: this.complianceIdNumber || null,
                        consent: this.complianceConsent,
                    }),
                });

                let data = {};
                try { data = await res.json(); } catch (_) { data = {}; }

                if (!res.ok) {
                    const errs = (data && data.errors) ? data.errors : {};
                    this.complianceErrors = errs;
                    // Surface any error that won't be shown next to a visible field,
                    // plus a server message fallback (403/500 etc. have no `errors`).
                    const visibleFields = this.complianceMissingFields.concat(['consent']);
                    const hiddenMsgs = [];
                    for (const k of Object.keys(errs)) {
                        if (!visibleFields.includes(k)) {
                            hiddenMsgs.push((errs[k] || [])[0]);
                        }
                    }
                    if (hiddenMsgs.length) {
                        this.complianceGeneralError = hiddenMsgs.filter(Boolean).join(' ');
                    } else if (Object.keys(errs).length === 0) {
                        this.complianceGeneralError = data.message || 'Could not save details. Please try again.';
                    }
                    this.complianceSaving = false;
                    return;
                }

                this.$dispatch('close-modal', 'compliance-modal');
                this.complianceSaving = false;
                await this.completeSale();
            } catch (e) {
                this.complianceGeneralError = 'Network error. Please try again.';
                this.complianceSaving = false;
            }
        },
    };
}
</script>

</x-app-layout>
