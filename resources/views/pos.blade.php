<x-app-layout>
<style>
    .pos-page {
        --ink: #0f172a;
        --ink-soft: #334155;
        --muted: #64748b;
        --border: #d6dde7;
        --card: #ffffff;
        --bg: #f2f5f9;
        --accent: #14213d;
        --accent-hover: #0f1a33;
        --success: #16a34a;
        --danger: #dc2626;
        --gold: #f59e0b;
        --gold-light: #fff7e8;
        --gold-border: #f2d29c;
        display: flex; flex-direction: column;
        height: 100%;
        background: linear-gradient(180deg, #eff4f9 0%, #f8fbff 100%);
        overflow: hidden;
    }

    /* ─── Top bar ────────────────────── */
    .pos-topbar {
        background: #000000;
        border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.3);
        min-height: 62px; display: flex;
        align-items: center; justify-content: space-between;
        padding: 12px 24px; flex-shrink: 0;
        position: relative;
        overflow: hidden;
        gap: 12px;
        flex-wrap: wrap;
    }
    .pos-topbar-main {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        position: relative;
        z-index: 1;
        flex: 1 1 auto;
        max-width: min(760px, 100%);
    }
    .pos-topbar::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, rgba(255,255,255,0.04), transparent 45%);
        pointer-events: none;
    }
    .pos-topbar-title {
        font-size: 18px;
        font-weight: 800;
        color: #f8fbff;
        letter-spacing: 0.02em;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        position: relative;
        z-index: 1;
    }
    .pos-topbar-title span { color: #fca311; }
    .pos-topbar-title::after {
        content: "Sales Counter";
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.09em;
        text-transform: uppercase;
        color: rgba(244, 248, 255, 0.72);
    }
    .pos-topbar .pos-topbar-menu {
        color: #f8fbff;
        background: transparent;
        border: none;
        width: 34px;
        height: 34px;
    }
    .pos-topbar .pos-topbar-menu:hover {
        background: rgba(255, 255, 255, 0.08);
    }
    .pos-topbar-search {
        display: none;
        position: relative;
        flex: 1 1 280px;
        min-width: 220px;
        max-width: 360px;
        margin-left: 4px;
    }
    .pos-topbar-search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: rgba(51, 65, 85, 0.7);
        pointer-events: none;
    }
    .pos-topbar-search-input {
        width: 100%;
        height: 40px;
        padding: 0 14px 0 38px;
        border-radius: 12px;
        border: 1px solid rgba(255, 255, 255, 0.28);
        background: rgba(255, 255, 255, 0.95);
        color: var(--ink);
        font-size: 13px;
        font-weight: 600;
        box-shadow: 0 10px 18px rgba(8, 16, 31, 0.16);
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .pos-topbar-search-input:focus {
        outline: none;
        border-color: #fca311;
        box-shadow: 0 0 0 3px rgba(252,163,17,0.18);
        background: #ffffff;
    }
    .pos-topbar-search-input::placeholder {
        color: #94a3b8;
        font-weight: 500;
    }
    .pos-topbar-links {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
    }
    .pos-topbar-links a {
        min-height: 40px;
        padding: 8px 12px; color: #e9f0ff; border-radius: 10px;
        text-decoration: none; font-size: 12px; font-weight: 700; transition: all .15s;
        border: 1px solid rgba(255,255,255,0.2);
        background: rgba(255, 255, 255, 0.08);
        box-shadow: 0 8px 14px rgba(10, 18, 35, 0.22);
        position: relative;
        z-index: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }
    .pos-topbar-links a:hover {
        color: #14213d;
        background: #fca311;
        border-color: #fca311;
    }

    .pos-mobile-fab {
        display: none;
    }

    .pos-mobile-fab-shell {
        position: fixed;
        right: 16px;
        bottom: calc(16px + env(safe-area-inset-bottom, 0px));
        z-index: 70;
    }

    .pos-mobile-fab-nav {
        position: absolute;
        right: 0;
        bottom: calc(100% + 12px);
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
    }

    .pos-mobile-fab-link {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        min-width: 152px;
        padding: 11px 14px;
        border-radius: 999px;
        border: 1px solid rgba(20, 33, 61, 0.12);
        background: rgba(255, 255, 255, 0.98);
        color: #14213d;
        text-decoration: none;
        font-size: 12px;
        font-weight: 700;
        box-shadow: 0 14px 24px rgba(20, 33, 61, 0.14);
        transform: translateY(18px) scale(0.92);
        opacity: 0;
        transition: transform 180ms ease, opacity 180ms ease, box-shadow 180ms ease;
    }

    .pos-mobile-fab-link svg {
        flex-shrink: 0;
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-nav {
        pointer-events: auto;
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-link {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-link:nth-child(1) {
        transition-delay: 0ms;
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-link:nth-child(2) {
        transition-delay: 32ms;
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-link:nth-child(3) {
        transition-delay: 64ms;
    }

    .pos-mobile-fab-toggle {
        position: relative;
        width: 56px;
        height: 56px;
        border: none;
        border-radius: 999px;
        background: linear-gradient(135deg, #14213d 0%, #22375f 100%);
        box-shadow: 0 18px 30px rgba(20, 33, 61, 0.28);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    .pos-mobile-fab-toggle::after {
        content: "";
        position: absolute;
        inset: 4px;
        border-radius: inherit;
        border: 1px solid rgba(252, 163, 17, 0.34);
    }

    .pos-mobile-fab-bars {
        position: relative;
        width: 22px;
        height: 18px;
    }

    .pos-mobile-fab-bars span {
        position: absolute;
        left: 0;
        width: 22px;
        height: 2.5px;
        border-radius: 999px;
        background: #ffffff;
        transition: transform 220ms cubic-bezier(0.4, 0, 0.2, 1), opacity 180ms ease, top 220ms cubic-bezier(0.4, 0, 0.2, 1);
    }

    .pos-mobile-fab-bars span:nth-child(1) { top: 1px; }
    .pos-mobile-fab-bars span:nth-child(2) { top: 8px; }
    .pos-mobile-fab-bars span:nth-child(3) { top: 15px; }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-bars span:nth-child(1) {
        top: 8px;
        transform: rotate(45deg);
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-bars span:nth-child(2) {
        opacity: 0;
    }

    .pos-mobile-fab-shell.is-open .pos-mobile-fab-bars span:nth-child(3) {
        top: 8px;
        transform: rotate(-45deg);
    }

    /* ─── 2-col layout ───────────────── */
    .pos-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, 380px); flex: 1; min-height: 0; overflow: hidden; }

    /* ─── LEFT: products ─────────────── */
    .pos-left {
        display: flex; flex-direction: column; min-height: 0; padding: 16px 20px; gap: 14px; overflow: hidden;
        background: rgba(255,255,255,0.62);
    }
    .pos-search-wrap { position: relative; flex-shrink: 0; }
    .pos-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
    .pos-search {
        width: 100%; padding: 12px 14px 12px 42px; font-size: 15px; font-weight: 500;
        border: 1.5px solid var(--border); border-radius: 12px; background: #ffffff;
        color: var(--ink); transition: border-color .15s, box-shadow .15s;
        box-shadow: 0 8px 18px rgba(20, 33, 61, 0.06);
    }
    .pos-search:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(20,33,61,.12); }
    .pos-search::placeholder { color: #9ca3af; font-weight: 400; }

    /* ─── Filter toolbar ─────────────── */
    .pos-filter-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto auto;
        gap: 10px;
        align-items: end;
        flex-shrink: 0;
    }
    .pos-filter-group {
        min-width: 0;
        position: relative;
    }
    .pos-filter-label {
        display: block;
        margin-bottom: 6px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--muted);
    }
    .pos-filter-dropdown {
        position: relative;
        z-index: 6;
    }
    .pos-filter-trigger {
        width: 100%;
        min-height: 42px;
        padding: 10px 12px;
        border: 1.5px solid var(--border);
        border-radius: 12px;
        background: #ffffff;
        color: var(--ink);
        font-size: 13px;
        font-weight: 600;
        box-shadow: 0 8px 18px rgba(20, 33, 61, 0.06);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        cursor: pointer;
        text-align: left;
    }
    .pos-filter-trigger:focus {
        outline: none;
        border-color: #f59e0b;
        box-shadow: 0 0 0 3px rgba(245,158,11,.12);
    }
    .pos-filter-trigger:disabled {
        background: #f8fafc;
        color: #94a3b8;
        box-shadow: none;
        cursor: not-allowed;
    }
    .pos-filter-trigger-text {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .pos-filter-trigger-icon {
        flex-shrink: 0;
        color: #94a3b8;
        transition: transform .18s ease, color .18s ease;
    }
    .pos-filter-dropdown.is-open .pos-filter-trigger {
        border-color: #f59e0b;
        box-shadow: 0 0 0 3px rgba(245,158,11,.12);
    }
    .pos-filter-dropdown.is-open {
        z-index: 38;
    }
    .pos-filter-dropdown.is-open .pos-filter-trigger-icon {
        color: #b45309;
        transform: rotate(180deg);
    }
    .pos-filter-menu {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        right: 0;
        z-index: 35;
        display: none;
        padding: 8px;
        border: 1px solid #d8e1ed;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 18px 38px rgba(20, 33, 61, 0.14);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        max-height: 280px;
        overflow-y: auto;
    }
    .pos-filter-dropdown.is-open .pos-filter-menu {
        display: block;
        animation: pos-filter-menu-in .14s ease-out;
    }
    .pos-filter-dropdown.drop-up .pos-filter-menu {
        top: auto;
        bottom: calc(100% + 8px);
    }
    .pos-filter-dropdown.drop-up.is-open .pos-filter-menu {
        animation: pos-filter-menu-up-in .14s ease-out;
    }
    @keyframes pos-filter-menu-in {
        from { opacity: 0; transform: translateY(-6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes pos-filter-menu-up-in {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .pos-filter-option {
        width: 100%;
        border: none;
        background: transparent;
        border-radius: 12px;
        padding: 10px 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        text-align: left;
        color: var(--ink);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: background .14s ease, color .14s ease, transform .14s ease;
    }
    .pos-filter-option:hover {
        background: #fff8ef;
        color: #7a4d02;
    }
    .pos-filter-option.is-active {
        background: #14213d;
        color: #ffffff;
    }
    .pos-filter-option-check {
        opacity: 0;
        transition: opacity .14s ease;
    }
    .pos-filter-option.is-active .pos-filter-option-check {
        opacity: 1;
    }
    .pos-filter-empty {
        padding: 12px;
        color: #94a3b8;
        font-size: 12px;
        text-align: center;
    }
    .pos-filter-clear {
        min-height: 42px;
        padding: 0 14px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: #ffffff;
        color: var(--ink-soft);
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 18px rgba(20, 33, 61, 0.05);
    }
    .pos-filter-clear-icon {
        display: none;
        font-size: 18px;
        line-height: 1;
        font-weight: 700;
    }
    .pos-filter-clear:hover {
        border-color: #fca311;
        color: #7a4d02;
        background: #fff9ef;
    }
    .pos-filter-count {
        min-height: 42px;
        padding: 0 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-width: 86px;
        border-radius: 12px;
        border: 1px dashed #b7c4d8;
        background: linear-gradient(180deg, #f5f8fc 0%, #edf2f8 100%);
        color: #334155;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
        cursor: default;
        pointer-events: none;
        user-select: none;
    }
    .pos-filter-count-number {
        min-width: 24px;
        height: 24px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        color: #ffffff;
        background: #14213d;
        box-shadow: 0 4px 10px rgba(20, 33, 61, 0.22);
        font-variant-numeric: tabular-nums;
        line-height: 1;
    }
    .pos-filter-count-label {
        color: #64748b;
        font-size: 10px;
        letter-spacing: .08em;
        text-transform: uppercase;
        font-weight: 700;
    }

    /* ─── Product grid ───────────────── */
    .pos-products { flex: 1; min-height: 0; overflow-y: auto; }
    .pos-product-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 14px; padding-bottom: 16px;
    }
    .pos-product-card {
        background: var(--card); border: 1.5px solid var(--border); border-radius: 16px;
        padding: 14px; display: flex; flex-direction: column; gap: 8px;
        cursor: pointer; transition: border-color .15s, box-shadow .15s; position: relative;
        box-shadow: 0 10px 20px rgba(20, 33, 61, 0.08);
    }
    .pos-product-card:hover { border-color: #fca311; box-shadow: 0 16px 30px rgba(20, 33, 61, 0.14); }
    .pos-product-card.in-cart { border-color: #fca311; background: #fffaf0; }
    .pos-product-img {
        width: 100%; aspect-ratio: 1; border-radius: 12px; object-fit: cover;
        background: #f1f5f9; border: 1px solid var(--border);
    }
    .pos-product-img-placeholder {
        width: 100%;
        aspect-ratio: 1;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
    }
    .pos-product-img-placeholder::after {
        content: "";
        position: absolute;
        inset: 0;
        background:
            radial-gradient(circle at 22% 18%, rgba(255, 255, 255, 0.72), transparent 45%),
            linear-gradient(135deg, transparent 0%, rgba(15, 23, 42, 0.04) 100%);
        pointer-events: none;
    }
    .pos-product-img-placeholder-chip {
        position: relative;
        z-index: 1;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        border: 1px solid rgba(15, 23, 42, 0.16);
        background: rgba(255, 255, 255, 0.82);
        color: #334155;
        max-width: 78%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .pos-product-img-placeholder.is-gold {
        background: linear-gradient(140deg, #fff5dc 0%, #ffe4b3 52%, #ffd48a 100%);
        border-color: #f3c66b;
    }
    .pos-product-img-placeholder.is-gold .pos-product-img-placeholder-chip {
        color: #8a4b00;
        border-color: rgba(138, 75, 0, 0.26);
        background: rgba(255, 247, 231, 0.84);
    }
    .pos-product-img-placeholder.is-silver {
        background: linear-gradient(140deg, #f8fbff 0%, #e7edf5 55%, #d8e0ea 100%);
        border-color: #cdd7e3;
    }
    .pos-product-img-placeholder.is-silver .pos-product-img-placeholder-chip {
        color: #3f556e;
        border-color: rgba(63, 85, 110, 0.24);
        background: rgba(250, 252, 255, 0.86);
    }
    .pos-product-img-placeholder.is-diamond {
        background: linear-gradient(140deg, #f2f8ff 0%, #d8eaff 54%, #c7defb 100%);
        border-color: #b5cff2;
    }
    .pos-product-img-placeholder.is-diamond .pos-product-img-placeholder-chip {
        color: #21528a;
        border-color: rgba(33, 82, 138, 0.26);
        background: rgba(243, 250, 255, 0.84);
    }
    .pos-product-img-placeholder.is-platinum {
        background: linear-gradient(140deg, #f6f7fb 0%, #e8ebf2 56%, #d9dee8 100%);
        border-color: #cfd6e2;
    }
    .pos-product-img-placeholder.is-platinum .pos-product-img-placeholder-chip {
        color: #4a5466;
        border-color: rgba(74, 84, 102, 0.24);
        background: rgba(250, 251, 255, 0.84);
    }
    .pos-product-img-placeholder.is-default {
        background: linear-gradient(140deg, #f7f9fc 0%, #eaf0f6 54%, #dde5ef 100%);
        border-color: #d4deea;
    }
    .pos-product-img-placeholder.is-default .pos-product-img-placeholder-chip {
        color: #475569;
    }
    .pos-product-name { font-size: 14px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pos-product-meta { font-size: 12px; color: var(--muted); line-height: 1.4; }
    .pos-product-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: auto; }
    .pos-product-price { font-size: 16px; font-weight: 800; color: var(--accent); }
    .pos-product-add {
        width: 32px; height: 32px; border-radius: 9999px; border: none;
        background: #14213d; color: #fff;
        cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
        transition: transform .1s, background .15s;
        padding: 0;
        line-height: 0;
        box-shadow: 0 8px 16px rgba(20, 33, 61, 0.25);
    }
    .pos-product-add-icon {
        width: 14px;
        height: 14px;
        stroke: currentColor;
        stroke-width: 2.6;
        fill: none;
        stroke-linecap: round;
        stroke-linejoin: round;
        display: block;
    }
    .pos-product-add:hover { background: #0f1a33; transform: scale(1.06); }
    .pos-product-check {
        width: 32px; height: 32px; border-radius: 9999px; border: none;
        background: var(--success); color: #fff; font-size: 16px;
        display: flex; align-items: center; justify-content: center;
    }
    .pos-empty-state { padding: 60px 20px; text-align: center; color: var(--muted); font-size: 14px; }

    /* ─── RIGHT: sidebar ─────────────── */
    .pos-right {
        background: linear-gradient(180deg, #ffffff 0%, #f6f8fc 100%); border-left: 1px solid var(--border);
        display: flex; flex-direction: column; min-height: 0; overflow: hidden;
        box-shadow: -8px 0 22px rgba(20, 33, 61, 0.08);
        min-width: 0;
    }
    .pos-right-scroll { flex: 1; overflow-y: auto; min-height: 0; }
    .pos-section { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
    .pos-section:last-child { border-bottom: none; }
    .pos-section-title {
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .08em; color: var(--muted); margin-bottom: 10px;
    }
    .pos-section-title-row {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .cart-count-badge {
        min-width: 18px;
        height: 18px;
        padding: 0 6px;
        border-radius: 999px;
        background: #fca311;
        color: #14213d;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 800;
        line-height: 1;
        box-shadow: 0 6px 12px rgba(252, 163, 17, 0.22);
    }
    .cart-count-badge.is-visible {
        display: inline-flex;
    }

    /* ─── Customer search ────────────── */
    .cust-search-wrap { position: relative; }
    .cust-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--muted); }
    .cust-search {
        width: 100%; padding: 9px 12px 9px 34px; font-size: 13px;
        border: 1px solid var(--border); border-radius: 12px; background: var(--card);
        color: var(--ink); transition: border-color .15s;
    }
    .cust-search:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,.12); }
    .cust-search.loading { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
    .cust-search-desktop { display: block; }
    .cust-mobile-actions { display: none; }
    .btn-cust-search-mobile {
        width: 42px;
        min-width: 42px;
        height: 42px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: #ffffff;
        color: var(--ink-soft);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 18px rgba(20, 33, 61, 0.05);
        cursor: pointer;
        transition: border-color .15s ease, color .15s ease, background .15s ease;
    }
    .btn-cust-search-mobile:hover {
        border-color: #fca311;
        color: #7a4d02;
        background: #fff9ef;
    }
    .btn-add-cust-mobile {
        position: relative;
        width: 42px;
        min-width: 42px;
        height: 42px;
        border: 1px dashed #f2d29c;
        border-radius: 12px;
        background: #fff9ef;
        color: #7a4d02;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: border-color .15s ease, color .15s ease, background .15s ease, transform .15s ease;
        box-shadow: 0 8px 18px rgba(20, 33, 61, 0.05);
    }
    .btn-add-cust-mobile:hover {
        border-color: #f59e0b;
        background: #fff2d8;
        transform: translateY(-1px);
    }
    .btn-add-cust-mobile-plus {
        position: absolute;
        top: -5px;
        right: -5px;
        width: 18px;
        height: 18px;
        border-radius: 999px;
        background: #14213d;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 800;
        line-height: 1;
        box-shadow: 0 6px 12px rgba(20, 33, 61, 0.22);
    }
    .cust-dropdown {
        position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fff;
        border: 1.5px solid #e2e8f0; border-radius: 12px;
        max-height: 240px; overflow-y: auto; z-index: 20;
        box-shadow: 0 12px 32px rgba(15,23,42,.12), 0 4px 8px rgba(15,23,42,.06);
        display: none; scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent;
    }
    .cust-dropdown.active { display: block; animation: cust-dd-in .15s ease-out; }
    .cust-dropdown.drop-up {
        top: auto;
        bottom: calc(100% + 4px);
    }
    .cust-dropdown.active.drop-up {
        animation: cust-dd-up-in .15s ease-out;
    }
    @keyframes cust-dd-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes cust-dd-up-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
    .cust-opt { padding: 10px 14px; cursor: pointer; transition: background .12s, padding-left .12s; border-bottom: 1px solid #f8fafc; }
    .cust-opt:last-child { border-bottom: none; }
    .cust-opt:hover { background: #fffbeb; padding-left: 18px; }
    .cust-opt-name { font-size: 13px; font-weight: 600; color: var(--ink); }
    .cust-opt-mobile { font-size: 11px; color: var(--muted); }
    .cust-no-match { padding: 12px; text-align: center; color: var(--muted); font-size: 12px; }
    .btn-add-cust {
        width: 100%; margin-top: 8px; padding: 7px; font-size: 12px; font-weight: 600;
        color: #7a4d02; background: #fff9ef; border: 1px dashed #f2d29c;
        border-radius: 10px; cursor: pointer; transition: all .15s;
    }
    .btn-add-cust:hover { background: #fff2d8; border-color: #f59e0b; }

    /* ─── Selected customer ──────────── */
    .cust-selected {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 12px; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 12px;
    }
    .cust-selected-left { display: flex; align-items: center; gap: 10px; }
    .cust-avatar {
        width: 34px; height: 34px; border-radius: 9999px; background: var(--accent);
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 13px;
    }
    .cust-name { font-size: 13px; font-weight: 700; color: var(--ink); }
    .cust-mobile { font-size: 11px; color: var(--ink-soft); }
    .cust-remove {
        background: none; border: none; color: #9ca3af; cursor: pointer;
        padding: 4px; border-radius: 8px; transition: all .15s;
    }
    .cust-remove:hover { color: var(--danger); background: #fef2f2; }

    /* ─── Cart items in sidebar ───────── */
    .cart-items { display: flex; flex-direction: column; gap: 8px; }
    .cart-item {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px; background: var(--gold-light); border: 1px solid var(--gold-border);
        box-shadow: inset 0 0 0 1px rgba(252, 163, 17, 0.08);
        border-radius: 12px;
    }
    .cart-item-img { width: 38px; height: 38px; border-radius: 8px; object-fit: cover; border: 1px solid var(--border); flex-shrink: 0; }
    .cart-item-img-ph {
        width: 38px; height: 38px; border-radius: 8px; background: #f1f5f9;
        display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 16px; flex-shrink: 0;
    }
    .cart-item-info { flex: 1; min-width: 0; }
    .cart-item-name { font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cart-item-meta { font-size: 11px; color: var(--muted); }
    .cart-item-price { font-size: 14px; font-weight: 800; color: var(--accent); white-space: nowrap; }
    .cart-item-remove {
        background: none; border: none; color: #9ca3af; cursor: pointer;
        padding: 2px; border-radius: 8px; line-height: 1; flex-shrink: 0;
    }
    .cart-item-remove:hover { color: var(--danger); }
    .cart-empty-msg { text-align: center; padding: 20px 0; color: var(--muted); font-size: 13px; }
    .pos-items-checkout-row {
        display: block;
        min-height: 0;
    }

    /* ─── Checkout footer ────────────── */
    .pos-checkout {
        padding: 16px 20px; border-top: 1px solid var(--border);
        background: #ffffff; flex-shrink: 0;
        box-shadow: 0 -10px 24px rgba(20, 33, 61, 0.08);
    }
    .checkout-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: 13px; }
    .checkout-row-label { color: var(--ink-soft); }
    .checkout-row-val { font-weight: 600; color: var(--ink); }
    .checkout-divider { height: 1px; background: var(--border); margin: 6px 0; }
    .checkout-total { font-size: 16px; }
    .checkout-total .checkout-row-label { font-weight: 700; color: var(--ink); }
    .checkout-total .checkout-row-val { font-weight: 800; color: var(--accent); font-size: 18px; }
    .btn-checkout {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        width: 100%; padding: 13px; margin-top: 12px; font-size: 15px; font-weight: 700;
        color: #fff; background: #14213d; border: none; border-radius: 14px;
        cursor: pointer; box-shadow: 0 10px 24px rgba(20, 33, 61, 0.26); transition: all .15s;
    }
    .btn-checkout:hover:not(:disabled) { background: #0f1a33; transform: translateY(-1px); box-shadow: 0 14px 28px rgba(20,33,61,.32); }
    .btn-checkout:disabled { background: #d1d5db; cursor: not-allowed; box-shadow: none; }
    .btn-checkout-mobile {
        display: none;
        width: 58px;
        min-width: 58px;
        height: auto;
        align-self: center;
        min-height: 0;
        margin-top: 0;
        padding: 10px 0;
        border-radius: 14px;
        background: linear-gradient(180deg, #fff7e8 0%, #fdd68a 100%);
            border: 1px solid #f2d29c;
            box-shadow: 0 12px 24px rgba(20, 33, 61, 0.12);
        }
    .btn-checkout-mobile:hover:not(:disabled) {
        background: linear-gradient(180deg, #fff4db 0%, #f7c96d 100%);
        transform: translateY(-1px);
        box-shadow: 0 14px 26px rgba(20, 33, 61, 0.16);
    }
    .btn-checkout-mobile:disabled {
        background: linear-gradient(180deg, #eef2f7 0%, #dde4ee 100%);
        border-color: #d7dfe9;
        box-shadow: none;
    }
    .btn-checkout-mobile-icon {
        width: 26px;
        height: 26px;
        object-fit: contain;
        display: block;
    }

    /* ─── Modal ──────────────────────── */
    .modal-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5);
        z-index: 100; align-items: center; justify-content: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box { background: #fff; border-radius: 14px; width: 400px; max-width: 90vw; box-shadow: 0 25px 50px rgba(0,0,0,.2); overflow: hidden; }
    .modal-header { padding: 16px 20px; font-size: 15px; font-weight: 700; color: var(--ink); border-bottom: 1px solid #e5e5e5; }
    .modal-body { padding: 16px 20px; }
    .modal-footer { padding: 14px 20px; border-top: 1px solid #d1d5db; display: flex; justify-content: flex-end; gap: 10px; background: #f9fafb; }
    .form-group { margin-bottom: 12px; }
    .form-group:last-child { margin-bottom: 0; }
    .form-label { display: block; font-size: 12px; font-weight: 600; color: var(--ink-soft); margin-bottom: 4px; }
    .form-input {
        width: 100%; padding: 9px 12px; font-size: 14px; border: 1.5px solid #d1d5db;
        border-radius: 12px; background: #fff; color: var(--ink); transition: border-color .15s;
    }
    .form-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(13,148,136,.12); }
    .btn-modal { padding: 10px 20px; font-size: 14px; font-weight: 600; border-radius: 10px; cursor: pointer; border: none; transition: all .15s; }
    .btn-modal-primary { background: #0d9488; color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
    .btn-modal-primary:hover { background: #0f766e; }
    .btn-modal-secondary { background: #e5e7eb; color: #374151; }
    .btn-modal-secondary:hover { background: #d1d5db; }
    .cust-search-modal-box {
        width: 420px;
        max-width: min(92vw, 420px);
    }
    .cust-search-modal-body {
        padding: 16px 20px 18px;
    }
    .cust-search-modal-wrap {
        position: relative;
    }
    .cust-search-modal-wrap .cust-dropdown {
        position: static;
        margin-top: 8px;
        max-height: 260px;
        display: block;
        box-shadow: none;
        border-color: #e2e8f0;
    }
    .cust-search-modal-wrap .cust-dropdown:not(.active) {
        display: none;
    }

    /* ─── Responsive ─────────────────── */
    @media (max-width: 900px) {
        .pos-mobile-fab-shell {
            bottom: calc(clamp(230px, 36svh, 320px) + 14px);
        }
        .pos-page {
            height: 100vh;
            height: 100svh;
            overflow: hidden;
        }
        .pos-topbar {
            padding: 8px 12px;
            min-height: 48px;
            align-items: center;
        }
        .pos-topbar-main {
            width: 100%;
            flex-wrap: nowrap;
            gap: 8px;
            align-items: center;
            min-height: 32px;
        }
        .pos-topbar-title {
            flex: 0 0 auto;
            font-size: 15px;
            white-space: nowrap;
        }
        .pos-topbar-title::after {
            display: none;
        }
        .pos-topbar-search {
            display: block;
            flex: 1 1 auto;
            max-width: none;
            min-width: 0;
            margin-left: 0;
            margin-top: 0;
        }
        .pos-topbar-search-input {
            height: 32px;
            font-size: 11px;
            padding-left: 30px;
            border-radius: 9px;
        }
        .pos-search-desktop-wrap {
            display: none;
        }
        .pos-topbar-links {
            display: none;
        }
        .pos-mobile-fab {
            display: block;
        }
        .pos-body {
            grid-template-columns: 1fr;
            grid-template-rows: minmax(0, 1fr) auto;
            overflow: hidden;
        }
        .pos-left {
            min-height: 0;
            overflow: hidden;
        }
        .pos-right {
            border-left: none;
            border-top: 1px solid var(--border);
            height: clamp(230px, 36svh, 320px);
            max-height: clamp(230px, 36svh, 320px);
            min-height: 230px;
            border-top-left-radius: 22px;
            border-top-right-radius: 22px;
            box-shadow: 0 -14px 28px rgba(20, 33, 61, 0.12);
            overflow: hidden;
            position: relative;
        }
        .pos-right-scroll {
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }
        .pos-section-customer {
            flex-shrink: 0;
        }
        .cust-search-desktop {
            display: none;
        }
        .cust-mobile-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pos-section-items {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        .pos-items-checkout-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 58px;
            gap: 8px;
            flex: 1;
            min-height: 0;
            align-items: center;
        }
        .pos-cart-scroll {
            flex: 1;
            min-height: 0;
            max-height: 100%;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding-right: 0;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .pos-cart-scroll::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        .pos-checkout {
            display: none;
        }
        .btn-checkout-mobile {
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .pos-product-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
    }

    @media (max-width: 640px) {
        .pos-mobile-fab-shell {
            bottom: calc(clamp(210px, 33svh, 280px) + 12px);
        }
        .btn-scan-mobile {
            width: 40px;
            height: 40px;
            padding: 0;
            gap: 0;
        }

        .btn-scan-mobile .scan-mobile-label {
            display: none;
        }

        .pos-left {
            padding: 12px;
            gap: 12px;
        }
        .pos-right {
            height: clamp(210px, 33svh, 280px);
            max-height: clamp(210px, 33svh, 280px);
            min-height: 210px;
        }
        .pos-section {
            padding: 12px;
        }
        .pos-section-title {
            font-size: 10px;
            margin-bottom: 8px;
        }
        .cust-search {
            padding: 8px 10px 8px 32px;
            font-size: 12px;
        }
        .btn-add-cust {
            margin-top: 6px;
            padding: 7px;
            font-size: 11px;
        }
        .btn-cust-search-mobile {
            width: 40px;
            min-width: 40px;
            height: 40px;
            border-radius: 11px;
        }
        .btn-add-cust-mobile {
            width: 40px;
            min-width: 40px;
            height: 40px;
            border-radius: 11px;
        }
        .pos-checkout {
            padding: 10px 12px 6px;
        }
        .pos-checkout .checkout-row,
        .pos-checkout .checkout-divider {
            display: none;
        }
        .pos-checkout .btn-checkout {
            margin-top: 0;
        }
        .cust-selected {
            padding: 8px 10px;
        }
        .cust-avatar {
            width: 30px;
            height: 30px;
            font-size: 12px;
        }
        .cust-name {
            font-size: 12px;
        }
        .cust-mobile {
            font-size: 10px;
        }
        .cart-items {
            gap: 5px;
        }
        .cart-item {
            padding: 7px 8px;
            gap: 8px;
        }
        .cart-item-img,
        .cart-item-img-ph {
            width: 32px;
            height: 32px;
            border-radius: 7px;
        }
        .cart-item-name {
            font-size: 11px;
        }
        .cart-item-meta {
            font-size: 9px;
        }
        .cart-item-price {
            font-size: 12px;
        }
        .cart-empty-msg {
            padding: 10px 0;
            font-size: 11px;
        }
        .pos-checkout {
            padding: 10px 12px 6px;
        }
        .checkout-row {
            font-size: 12px;
        }
        .checkout-total {
            font-size: 14px;
        }
        .checkout-total .checkout-row-val {
            font-size: 16px;
        }
        .btn-checkout {
            margin-top: 10px;
            padding: 11px;
            font-size: 14px;
            border-radius: 12px;
        }
        .pos-topbar-search-input {
            height: 38px;
            font-size: 12px;
            padding-left: 36px;
            border-radius: 11px;
        }
        .pos-search {
            font-size: 14px;
        }
        .pos-filter-toolbar {
            grid-template-columns: minmax(0, 0.88fr) minmax(0, 0.88fr) 34px 62px;
            gap: 5px;
            align-items: end;
        }
        .pos-filter-label {
            margin-bottom: 4px;
            font-size: 9px;
        }
        .pos-filter-trigger {
            min-height: 36px;
            padding: 7px 8px;
            font-size: 11px;
            border-radius: 11px;
        }
        .pos-filter-trigger-icon {
            width: 12px;
            height: 12px;
        }
        .pos-filter-clear {
            width: 34px;
            min-width: 34px;
            min-height: 36px;
            padding: 0;
            border-radius: 11px;
            align-self: end;
        }
        .pos-filter-clear-text {
            display: none;
        }
        .pos-filter-clear-icon {
            display: inline-block;
        }
        .pos-filter-count {
            min-width: 62px;
            width: 62px;
            min-height: 36px;
            padding: 0 6px;
            font-size: 11px;
            border-radius: 11px;
            gap: 4px;
            border-style: solid;
        }
        .pos-filter-count-number {
            min-width: 18px;
            height: 18px;
            font-size: 9px;
        }
        .pos-filter-count-label {
            display: inline;
            font-size: 7px;
            letter-spacing: .06em;
        }
        .pos-product-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .pos-product-card {
            padding: 12px;
        }
        .pos-section,
        .pos-checkout {
            padding: 14px;
        }
    }

    /* ─── Mobile Scanner Modal ───────── */
    .scan-modal-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(10,16,32,0.72); z-index: 200;
        align-items: center; justify-content: center;
        backdrop-filter: blur(4px);
    }
    .scan-modal-overlay.active { display: flex; }
    .scan-modal-box {
        background: #fff; width: 380px; max-width: 94vw;
        border-radius: 14px; overflow: hidden;
        box-shadow: 0 30px 60px rgba(0,0,0,0.3);
        animation: scanModalIn 0.2s ease;
    }
    @keyframes scanModalIn {
        from { opacity:0; transform: scale(0.96) translateY(10px); }
        to   { opacity:1; transform: scale(1) translateY(0); }
    }
    .scan-modal-header {
        background: linear-gradient(120deg,#14213d 0%,#1c2f56 100%);
        padding: 16px 20px;
        display: flex; align-items: center; justify-content: space-between;
    }
    .scan-modal-title {
        color: #fff; font-size: 15px; font-weight: 700;
        display: flex; align-items: center; gap: 8px;
    }
    .scan-modal-close {
        background: none; border: none; color: rgba(255,255,255,0.6);
        cursor: pointer; font-size: 20px; line-height: 1; padding: 2px;
    }
    .scan-modal-close:hover { color: #fff; }
    .scan-modal-body { padding: 24px 20px; text-align: center; }
    .scan-qr-wrap {
        width: 200px; height: 200px; margin: 0 auto 16px;
        border: 3px solid #e2e8f0; border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        background: #f8fafc; overflow: hidden; position: relative;
    }
    .scan-qr-wrap img { width: 100%; height: 100%; object-fit: contain; }
    .scan-qr-loading {
        display: flex; flex-direction: column; align-items: center; gap: 8px;
        color: #94a3b8; font-size: 13px;
    }
    .scan-qr-spinner {
        width: 28px; height: 28px; border: 3px solid #e2e8f0;
        border-top-color: #14213d; border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .scan-status-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 14px; border-radius: 999px;
        font-size: 12px; font-weight: 700;
        background: #f1f5f9; color: #64748b;
        border: 1px solid #e2e8f0;
        margin-bottom: 12px; transition: all 0.3s;
    }
    .scan-status-badge.waiting  { background:#fff7e8; color:#b45309; border-color:#f2d29c; }
    .scan-status-badge.active   { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; }
    .scan-status-badge.scanning { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .scan-status-badge.expired  { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
    .scan-modal-hint {
        font-size: 12px; color: #94a3b8; margin-bottom: 14px; line-height: 1.5;
    }
    .scan-countdown {
        font-size: 11px; color: #94a3b8; margin-bottom: 14px;
    }
    .scan-countdown span { font-weight: 700; color: #475569; }
    .scan-countdown-sub {
        margin-top: 4px;
        font-size: 10px;
        color: #64748b;
    }
    .scan-recent-scans {
        border-top: 1px solid #f1f5f9; margin-top: 8px; padding-top: 12px;
        text-align: left; max-height: 120px; overflow-y: auto;
    }
    .scan-recent-title {
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; color: #94a3b8; margin-bottom: 6px;
    }
    .scan-recent-item {
        display: flex; align-items: center; gap: 8px;
        padding: 5px 0; border-bottom: 1px solid #f8fafc; font-size: 12px;
    }
    .scan-recent-item:last-child { border: none; }
    .scan-recent-dot { width:6px; height:6px; border-radius:50%; flex-shrink: 0; }
    .scan-recent-dot.ok    { background: #22c55e; }
    .scan-recent-dot.error { background: #ef4444; }
    .scan-recent-name { flex:1; font-weight:600; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .scan-recent-time { color:#94a3b8; }
    .btn-scan-stop {
        width:100%; padding:10px; font-size:13px; font-weight:700;
        background:#fef2f2; color:#dc2626; border:1px solid #fecaca;
        border-radius:10px; cursor:pointer; margin-top:4px; transition:all .15s;
    }
    .btn-scan-stop:hover { background:#fee2e2; }
    /* Toast */
    .pos-toast {
        position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
        padding: 10px 20px; border-radius: 8px; font-size: 13px; font-weight: 600;
        z-index: 500; pointer-events: none;
        animation: toastIn 0.3s ease, toastOut 0.3s ease 2.4s forwards;
        white-space: nowrap; box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    }
    .pos-toast.success { background: #16a34a; color: #fff; }
    .pos-toast.error   { background: #dc2626; color: #fff; }
    @keyframes toastIn  { from { opacity:0; transform:translateX(-50%) translateY(10px); } to { opacity:1; transform:translateX(-50%) translateY(0); } }
    @keyframes toastOut { from { opacity:1; } to { opacity:0; } }

    /* Scan button */
    .btn-scan-mobile {
        min-height: 40px;
        padding: 8px 12px;
        background: rgba(252,163,17,0.15);
        border: 1px solid rgba(252,163,17,0.5);
        color: #fca311;
        border-radius: 10px;
        cursor: pointer;
        transition: all .15s;
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        position: relative; z-index: 1;
        flex-shrink: 0;
        white-space: nowrap;
    }
    .btn-scan-mobile:hover { background: rgba(252,163,17,0.25); border-color: #fca311; }
    .btn-scan-mobile.active {
        background: #fca311; color: #14213d;
        animation: pulse-btn 2s ease infinite;
    }
    @keyframes pulse-btn {
        0%,100% { box-shadow: 0 0 0 0 rgba(252,163,17,0.4); }
        50%      { box-shadow: 0 0 0 6px rgba(252,163,17,0); }
    }
</style>

    <div class="pos-page" id="posApp">
    <!-- ─── Top bar ──────────────────── -->
    <div class="pos-topbar">
        <div class="pos-topbar-main">
            <button type="button" class="mobile-menu-btn pos-topbar-menu" data-mobile-menu-toggle="tenant" aria-controls="main-sidebar" aria-expanded="false" aria-label="Open navigation">
                <span class="drawer-toggle-icon drawer-toggle-icon-menu" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </span>
                <span class="drawer-toggle-icon drawer-toggle-icon-close" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </span>
            </button>
            <div class="pos-topbar-title"><span>Point Of Sale</span></div>
            <div class="pos-topbar-search">
                <span class="pos-topbar-search-icon">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                </span>
                <input type="text" id="posSearchMobile" class="pos-topbar-search-input" placeholder="Search by name, barcode, or category…" oninput="syncPosSearch('mobile')" autocomplete="off">
            </div>
        </div>
        <div class="pos-topbar-links">
            <button id="btnScanMobile" class="btn-scan-mobile" onclick="openScanModal()" title="Scan via Mobile Camera" aria-label="Scan via Mobile Camera">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 7V5h2"/>
                    <path d="M20 7V5h-2"/>
                    <path d="M4 17v2h2"/>
                    <path d="M20 17v2h-2"/>
                    <path d="M7 5v14"/>
                    <path d="M10 5v14"/>
                    <path d="M14 5v14"/>
                    <path d="M17 5v14"/>
                </svg>
                <span class="scan-mobile-label">Scan via Mobile</span>
            </button>
            <a href="{{ route('repairs.index') }}">Repairs</a>
            <a href="{{ route('invoices.index') }}">Invoices</a>
            <a href="{{ route('inventory.items.index') }}">Inventory</a>
        </div>
    </div>

    <div
        x-data="{ posMobileNavOpen: false }"
        class="pos-mobile-fab"
        @keydown.escape.window="posMobileNavOpen = false"
    >
        <div class="pos-mobile-fab-shell" x-bind:class="{ 'is-open': posMobileNavOpen }" @click.outside="posMobileNavOpen = false">
            <nav class="pos-mobile-fab-nav" aria-label="POS mobile navigation">
                <button type="button" class="pos-mobile-fab-link" onclick="openScanModal()" @click="posMobileNavOpen = false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14213d" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 7V5h2"/>
                        <path d="M20 7V5h-2"/>
                        <path d="M4 17v2h2"/>
                        <path d="M20 17v2h-2"/>
                        <path d="M7 5v14"/>
                        <path d="M10 5v14"/>
                        <path d="M14 5v14"/>
                        <path d="M17 5v14"/>
                    </svg>
                    <span>Scan via Mobile</span>
                </button>
                <a href="{{ route('inventory.items.index') }}" class="pos-mobile-fab-link" @click="posMobileNavOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14213d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                    <span>Inventory</span>
                </a>
                <a href="{{ route('invoices.index') }}" class="pos-mobile-fab-link" @click="posMobileNavOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14213d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span>Invoices</span>
                </a>
                <a href="{{ route('repairs.index') }}" class="pos-mobile-fab-link" @click="posMobileNavOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14213d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                    <span>Repairs</span>
                </a>
            </nav>

            <button type="button" class="pos-mobile-fab-toggle" x-on:click="posMobileNavOpen = !posMobileNavOpen" x-bind:aria-expanded="posMobileNavOpen.toString()" aria-label="Toggle POS mobile navigation">
                <span class="pos-mobile-fab-bars" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
    </div>

    <div class="pos-body">
        <!-- ══ LEFT: Search + Filters + Product Grid ══ -->
        <div class="pos-left">
            <!-- Search -->
            <div class="pos-search-wrap pos-search-desktop-wrap">
                <span class="pos-search-icon">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                </span>
                <input type="text" id="posSearchDesktop" class="pos-search" placeholder="Search by name, barcode, or category…" oninput="syncPosSearch('desktop')" autocomplete="off" autofocus>
            </div>

            <!-- Category + SubCategory filters -->
            <div class="pos-filter-toolbar">
                <div class="pos-filter-group">
                    <label class="pos-filter-label" for="posCategoryTrigger">Category</label>
                    <div class="pos-filter-dropdown" id="posCategoryDropdown">
                        <button type="button" id="posCategoryTrigger" class="pos-filter-trigger" onclick="togglePosFilterMenu('category')" aria-expanded="false">
                            <span class="pos-filter-trigger-text" id="posCategoryLabel">All categories</span>
                            <svg class="pos-filter-trigger-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="pos-filter-menu" id="posCategoryMenu">
                            <button type="button" class="pos-filter-option is-active" data-category="" onclick="setCategory('')">
                                <span>All categories</span>
                                <span class="pos-filter-option-check">✓</span>
                            </button>
                            @foreach($categories as $cat)
                                <button type="button" class="pos-filter-option" data-category="{{ $cat }}" onclick="setCategory(this.dataset.category)">
                                    <span>{{ $cat }}</span>
                                    <span class="pos-filter-option-check">✓</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="pos-filter-group">
                    <label class="pos-filter-label" for="posSubCategoryTrigger">Sub-category</label>
                    <div class="pos-filter-dropdown" id="posSubCategoryDropdown">
                        <button type="button" id="posSubCategoryTrigger" class="pos-filter-trigger" onclick="togglePosFilterMenu('sub')" aria-expanded="false" disabled>
                            <span class="pos-filter-trigger-text" id="posSubCategoryLabel">All sub-categories</span>
                            <svg class="pos-filter-trigger-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="pos-filter-menu" id="posSubCategoryMenu">
                            <div class="pos-filter-empty">Choose a category first</div>
                        </div>
                    </div>
                </div>
                <button type="button" id="posFilterClear" class="pos-filter-clear" onclick="resetPosFilters()" hidden aria-label="Clear filters" title="Clear filters">
                    <span class="pos-filter-clear-text">Clear Filters</span>
                    <span class="pos-filter-clear-icon" aria-hidden="true">&times;</span>
                </button>
                <div class="pos-filter-count" id="posFilterCount" aria-live="polite">
                    <span class="pos-filter-count-number" id="posFilterCountNumber">0</span>
                    <span class="pos-filter-count-label">items</span>
                </div>
            </div>

            <!-- Product grid (scrollable) -->
            <div class="pos-products">
                <div class="pos-product-grid" id="productGrid"></div>
                <div class="pos-empty-state" id="noProducts" style="display:none;">No items match your search.</div>
            </div>
        </div>

        <!-- ══ RIGHT: Customer + Cart + Checkout ══ -->
        <div class="pos-right">
            <div class="pos-right-scroll">
                <!-- Customer -->
                <div class="pos-section pos-section-customer">
                    <div class="pos-section-title">Customer</div>
                    <div id="custSearchSection">
                        <div class="cust-search-desktop">
                            <div class="cust-search-wrap">
                                <span class="cust-search-icon">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                                </span>
                                <input type="text" id="custSearch" class="cust-search" placeholder="Search customer…" oninput="onCustSearch()" onfocus="onCustSearch()">
                                <div class="cust-dropdown" id="custDropdown"></div>
                            </div>
                            <button class="btn-add-cust" onclick="showModal()">+ Add New Customer</button>
                        </div>
                        <div class="cust-mobile-actions">
                            <button type="button" class="btn-cust-search-mobile" onclick="showCustSearchModal()" title="Search Customer" aria-label="Search Customer">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            </button>
                            <button type="button" class="btn-add-cust-mobile" onclick="showModal()" title="Add New Customer" aria-label="Add New Customer">
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                                <span class="btn-add-cust-mobile-plus" aria-hidden="true">+</span>
                            </button>
                        </div>
                    </div>
                    <div id="custSelected" style="display:none;">
                        <div class="cust-selected">
                            <div class="cust-selected-left">
                                <div class="cust-avatar" id="custAvatar">-</div>
                                <div>
                                    <div class="cust-name" id="custName">-</div>
                                    <div class="cust-mobile" id="custMobile">-</div>
                                </div>
                            </div>
                            <button class="cust-remove" onclick="deselectCust()" title="Change">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Selected items -->
                <div class="pos-section pos-section-items">
                    <div class="pos-section-title pos-section-title-row">
                        <span>Selected Items</span>
                        <span class="cart-count-badge" id="cartCountBadge">0</span>
                    </div>
                    <div class="pos-items-checkout-row">
                        <div id="cartList" class="pos-cart-scroll">
                            <div class="cart-empty-msg" id="cartEmpty">No items selected.<br>Click + on a product to add it.</div>
                            <div class="cart-items" id="cartItems"></div>
                        </div>
                        <button class="btn-checkout btn-checkout-mobile" id="btnCheckoutMobile" disabled onclick="goCheckout()" aria-label="Proceed to checkout" title="Proceed to checkout">
                            <img src="{{ asset('images/cashless-payment.png') }}" alt="Cashless payment" class="btn-checkout-mobile-icon">
                        </button>
                    </div>
                </div>
            </div>

            <!-- Checkout footer (sticky bottom) -->
            <div class="pos-checkout">
                <div class="checkout-row">
                    <span class="checkout-row-label">Items</span>
                    <span class="checkout-row-val" id="checkoutItemCount">0</span>
                </div>
                <div class="checkout-row">
                    <span class="checkout-row-label">Subtotal</span>
                    <span class="checkout-row-val" id="checkoutSubtotal">₹0</span>
                </div>
                <div class="checkout-divider"></div>
                <div class="checkout-row checkout-total">
                    <span class="checkout-row-label">Estimated Total</span>
                    <span class="checkout-row-val" id="checkoutTotal">₹0</span>
                </div>
                <button class="btn-checkout" id="btnCheckout" disabled onclick="goCheckout()">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    Proceed to Checkout
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ADD CUSTOMER MODAL -->
<div class="modal-overlay" id="addCustModal">
    <div class="modal-box">
        <div class="modal-header">Add New Customer</div>
        <form id="addCustForm">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-input" required placeholder="First name">
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-input" required placeholder="Last name">
                </div>
                <div class="form-group">
                    <label class="form-label">Mobile Number <span style="color:var(--muted);font-weight:normal">(optional)</span></label>
                    <input type="tel" name="mobile" class="form-input" pattern="[0-9]{10}" maxlength="10" placeholder="10-digit mobile (optional)">
                </div>
                <div class="form-group">
                    <label class="form-label">Address (Optional)</label>
                    <input type="text" name="address" class="form-input" placeholder="Street address">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal btn-modal-secondary" onclick="hideModal()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-primary">Create Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- MOBILE CUSTOMER SEARCH MODAL -->
<div class="modal-overlay" id="custSearchModal">
    <div class="modal-box cust-search-modal-box">
        <div class="modal-header">Search Customer</div>
        <div class="cust-search-modal-body">
            <div class="cust-search-modal-wrap">
                <div class="cust-search-wrap">
                    <span class="cust-search-icon">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    </span>
                    <input type="text" id="custSearchMobile" class="cust-search" placeholder="Search customer…" oninput="onCustSearch('mobile')" onfocus="onCustSearch('mobile')">
                </div>
                <div class="cust-dropdown" id="custDropdownMobile"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal btn-modal-secondary" onclick="hideCustSearchModal()">Close</button>
            <button type="button" class="btn-modal btn-modal-primary" onclick="showModal(); hideCustSearchModal();">+ Add Customer</button>
        </div>
    </div>
</div>

<!-- MOBILE SCANNER MODAL -->
<div class="scan-modal-overlay" id="scanModal">
    <div class="scan-modal-box">
        <div class="scan-modal-header">
            <div class="scan-modal-title">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3m0 4h4m-4 0v-4m-4 4h-3"/></svg>
                Scan via Mobile
            </div>
            <button class="scan-modal-close" onclick="hideScanModal()">&times;</button>
        </div>
        <div class="scan-modal-body">
            <div class="scan-status-badge waiting" id="scanStatusBadge">Scan to Connect</div>

            <div class="scan-qr-wrap" id="scanQrWrap">
                <div class="scan-qr-loading" id="scanQrLoading">
                    <div class="scan-qr-spinner"></div>
                    <span>Generating…</span>
                </div>
                <img id="scanQrImg" src="" alt="QR Code" style="display:none;">
            </div>

            <p class="scan-modal-hint" id="scanModalHint">Open camera on your phone and scan the QR code above.<br>Items will appear in the cart automatically.</p>
            <div class="scan-countdown" id="scanCountdownWrap" style="display:none;">
                Session ends in: <span id="scanCountdown">--</span>
                <div class="scan-countdown-sub" id="scanEndsAt"></div>
            </div>

            <div class="scan-recent-scans" id="scanRecentWrap" style="display:none;">
                <div class="scan-recent-title">Recent Scans</div>
                <div id="scanRecentList"></div>
            </div>

            <button class="btn-scan-stop" id="btnScanAction" onclick="stopScanSession()">Disconnect</button>
        </div>
    </div>
</div>

@php
    $posItems = $items->map(fn($item) => [
        'id'          => $item->id,
        'barcode'     => $item->barcode,
        'design'      => $item->design ?? '',
        'category'    => $item->category ?? '',
        'subCategory' => $item->sub_category ?? '',
        'grossWeight' => (string) $item->gross_weight,
        'purity'      => (string) $item->purity,
        'netWeight'   => (string) $item->net_metal_weight,
        'sellingPrice'=> (float)  $item->selling_price,
        'image'       => $item->image ? asset('storage/' . $item->image) : '',
    ]);
@endphp

<script>
(function() {
    /* ═══ DATA ═══ */
    // Customers loaded on-demand via API — no server-side embed
    const CUSTOMERS_API_URL = @json(route('pos.customers.search'));
    let custSearchTimer = null;

    const allItems = @json($posItems);
    window.__posAllItems = allItems;

    /* ═══ ROUTES ═══ */
    const POS_CUSTOMER_BASE = @json(url('/pos/customer'));
    const POS_STORAGE_SCOPE = @json((string) auth()->id() . '_' . (string) auth()->user()->shop_id);
    const CART_STORAGE_KEY = 'pos_cart_' + POS_STORAGE_SCOPE;
    const CUSTOMER_STORAGE_KEY = 'pos_customer_' + POS_STORAGE_SCOPE;

    /* ═══ STATE ═══ */
    let cartIds = [];
    let selectedCust = null;
    let activeCategory = '';
    let activeSubCategory = '';

    /* ═══ PERSISTENCE ═══ */
    function save() {
        sessionStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cartIds));
        sessionStorage.setItem(CUSTOMER_STORAGE_KEY, JSON.stringify(selectedCust));
    }
    function restore() {
        try {
            const c = JSON.parse(sessionStorage.getItem(CART_STORAGE_KEY));
            if (c) cartIds = c.filter(id => allItems.some(i => i.id === id));
            const cu = JSON.parse(sessionStorage.getItem(CUSTOMER_STORAGE_KEY));
            if (cu && cu.id) pickCust(cu.id, cu.firstName, cu.lastName, cu.mobile);
        } catch(e) {}
        renderProducts();
        renderCart();
    }

    /* ═══ PRODUCT GRID ═══ */
    function filterProducts() {
        renderProducts();
    }

    function getPosSearchValue() {
        const desktopEl = document.getElementById('posSearchDesktop');
        const mobileEl = document.getElementById('posSearchMobile');
        return ((mobileEl && window.innerWidth <= 900 ? mobileEl.value : desktopEl?.value) || mobileEl?.value || desktopEl?.value || '').toLowerCase().trim();
    }

    function syncPosSearch(source) {
        const desktopEl = document.getElementById('posSearchDesktop');
        const mobileEl = document.getElementById('posSearchMobile');
        if (source === 'desktop' && mobileEl && desktopEl) {
            mobileEl.value = desktopEl.value;
        }
        if (source === 'mobile' && mobileEl && desktopEl) {
            desktopEl.value = mobileEl.value;
        }
        filterProducts();
    }

    function getCategoryPlaceholderTone(category) {
        const value = String(category || '').toLowerCase();
        if (value.includes('silver')) return 'is-silver';
        if (value.includes('gold')) return 'is-gold';
        if (value.includes('diamond') || value.includes('gem') || value.includes('stone')) return 'is-diamond';
        if (value.includes('platinum')) return 'is-platinum';
        return 'is-default';
    }

    function getCategoryPlaceholderLabel(category) {
        const raw = String(category || '').trim();
        if (!raw) return 'Jewellery';
        const cleaned = raw.replace(/[^a-z0-9\s&/-]/gi, '').trim();
        if (!cleaned) return 'Jewellery';
        if (cleaned.length <= 14) return cleaned;
        return cleaned.slice(0, 13) + '…';
    }

    function renderProducts() {
        const search = getPosSearchValue();
        const grid = document.getElementById('productGrid');
        const empty = document.getElementById('noProducts');
        const countEl = document.getElementById('posFilterCount');
        const countNumberEl = document.getElementById('posFilterCountNumber');
        const clearBtn = document.getElementById('posFilterClear');

        let filtered = allItems;

        if (search) {
            filtered = filtered.filter(i =>
                i.design.toLowerCase().includes(search) ||
                i.barcode.toLowerCase().includes(search) ||
                i.category.toLowerCase().includes(search) ||
                i.subCategory.toLowerCase().includes(search)
            );
        }
        if (activeCategory) {
            filtered = filtered.filter(i => i.category === activeCategory);
        }
        if (activeSubCategory) {
            filtered = filtered.filter(i => i.subCategory === activeSubCategory);
        }

        grid.innerHTML = '';
        if (countEl) {
            countEl.setAttribute('aria-label', filtered.length + ' item' + (filtered.length === 1 ? '' : 's'));
        }
        if (countNumberEl) {
            countNumberEl.textContent = String(filtered.length);
        }
        if (clearBtn) {
            clearBtn.hidden = !(activeCategory || activeSubCategory);
        }

        if (filtered.length === 0) {
            empty.style.display = 'block';
            return;
        }
        empty.style.display = 'none';

        filtered.forEach(item => {
            const inCart = cartIds.includes(item.id);
            const card = document.createElement('div');
            card.className = 'pos-product-card' + (inCart ? ' in-cart' : '');

            const placeholderTone = getCategoryPlaceholderTone(item.category);
            const placeholderLabel = escHtml(getCategoryPlaceholderLabel(item.category));
            const itemLabel = escHtml(item.design || item.category || 'Item');
            const imgHtml = item.image
                ? '<img src="' + escHtml(item.image) + '" alt="' + itemLabel + ' image" class="pos-product-img">'
                : '<div class="pos-product-img-placeholder ' + placeholderTone + '"><span class="pos-product-img-placeholder-chip">' + placeholderLabel + '</span></div>';

            const actionBtn = inCart
                ? '<span class="pos-product-check" title="Remove from cart"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>'
                : '<button class="pos-product-add" title="Add to cart" aria-label="Add to cart"><svg class="pos-product-add-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>';

            const price = item.sellingPrice;
            const meta = [];
            if (item.barcode) meta.push(escHtml(item.barcode));
            if (item.purity && item.purity !== '0.00') meta.push(escHtml(item.purity) + 'K');
            if (item.grossWeight && item.grossWeight !== '0.000000') meta.push(escHtml(item.grossWeight) + 'g');
            if (item.category) meta.push(escHtml(item.category));

            card.innerHTML =
                imgHtml +
                '<div class="pos-product-name">' + escHtml(item.design || item.category || 'Item') + '</div>' +
                '<div class="pos-product-meta">' + meta.join(' &middot; ') + '</div>' +
                '<div class="pos-product-bottom">' +
                    '<span class="pos-product-price">₹' + price.toLocaleString('en-IN') + '</span>' +
                    actionBtn +
                '</div>';

            // Bind add button click safely (no inline onclick)
            const addBtn = card.querySelector('.pos-product-add');
            if (addBtn) {
                addBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    addToCart(item.id);
                });
            }

            card.addEventListener('click', function() {
                if (inCart) {
                    removeFromCart(item.id);
                    return;
                }
                addToCart(item.id);
            });
            grid.appendChild(card);
        });
    }

    /* ═══ CATEGORY FILTERS ═══ */
    function applyDropdownDirection(anchorEl, panelEl, dropClass = 'drop-up', gap = 10) {
        if (!anchorEl || !panelEl) return;

        panelEl.classList.remove(dropClass);

        const panelStyles = window.getComputedStyle(panelEl);
        if (panelStyles.position === 'static') {
            return;
        }

        const maxHeight = parseFloat(panelStyles.maxHeight) || panelEl.scrollHeight || 220;
        const panelHeight = Math.min(panelEl.scrollHeight || maxHeight, maxHeight) + gap;
        const anchorRect = anchorEl.getBoundingClientRect();
        const spaceBelow = window.innerHeight - anchorRect.bottom;
        const spaceAbove = anchorRect.top;

        if (spaceBelow < panelHeight && spaceAbove > spaceBelow) {
            panelEl.classList.add(dropClass);
        }
    }

    function updatePosFilterDirection(dropdownEl) {
        if (!dropdownEl) return;
        const menuEl = dropdownEl.querySelector('.pos-filter-menu');
        applyDropdownDirection(dropdownEl, menuEl);
        dropdownEl.classList.toggle('drop-up', !!menuEl?.classList.contains('drop-up'));
    }

    function updateCustomerDropdownDirection(searchEl, dropdownEl) {
        const searchWrap = searchEl?.closest('.cust-search-wrap');
        applyDropdownDirection(searchWrap, dropdownEl);
    }

    function closePosFilterMenus() {
        [
            { dropdown: 'posCategoryDropdown', trigger: 'posCategoryTrigger' },
            { dropdown: 'posSubCategoryDropdown', trigger: 'posSubCategoryTrigger' },
        ].forEach(({ dropdown, trigger }) => {
            const dropdownEl = document.getElementById(dropdown);
            const triggerEl = document.getElementById(trigger);
            if (dropdownEl) {
                dropdownEl.classList.remove('is-open', 'drop-up');
                dropdownEl.querySelector('.pos-filter-menu')?.classList.remove('drop-up');
            }
            if (triggerEl) triggerEl.setAttribute('aria-expanded', 'false');
        });
    }

    function togglePosFilterMenu(type) {
        const mapping = type === 'sub'
            ? { dropdown: 'posSubCategoryDropdown', trigger: 'posSubCategoryTrigger' }
            : { dropdown: 'posCategoryDropdown', trigger: 'posCategoryTrigger' };

        const dropdownEl = document.getElementById(mapping.dropdown);
        const triggerEl = document.getElementById(mapping.trigger);
        if (!dropdownEl || !triggerEl || triggerEl.disabled) return;

        const willOpen = !dropdownEl.classList.contains('is-open');
        closePosFilterMenus();
        if (willOpen) {
            dropdownEl.classList.add('is-open');
            triggerEl.setAttribute('aria-expanded', 'true');
            updatePosFilterDirection(dropdownEl);
        }
    }

    function setCategory(cat) {
        activeCategory = cat;
        activeSubCategory = '';

        const categoryLabel = document.getElementById('posCategoryLabel');
        if (categoryLabel) categoryLabel.textContent = cat || 'All categories';

        document.querySelectorAll('#posCategoryMenu .pos-filter-option').forEach((option) => {
            option.classList.toggle('is-active', (option.dataset.category || '') === cat);
        });

        updateSubCategoryOptions();
        closePosFilterMenus();
        renderProducts();
    }

    function updateSubCategoryOptions() {
        const subMenu = document.getElementById('posSubCategoryMenu');
        const subTrigger = document.getElementById('posSubCategoryTrigger');
        const subLabel = document.getElementById('posSubCategoryLabel');
        if (!subMenu || !subTrigger || !subLabel) return;

        const subs = activeCategory
            ? [...new Set(allItems
                .filter(i => i.category === activeCategory && i.subCategory)
                .map(i => i.subCategory))].sort()
            : [];

        if (!activeCategory) {
            subLabel.textContent = 'All sub-categories';
            subTrigger.disabled = true;
            subMenu.innerHTML = '<div class="pos-filter-empty">Choose a category first</div>';
            return;
        }

        if (subs.length === 0) {
            subLabel.textContent = 'No sub-categories';
            subTrigger.disabled = true;
            subMenu.innerHTML = '<div class="pos-filter-empty">No sub-categories available</div>';
            return;
        }

        subTrigger.disabled = false;
        subLabel.textContent = activeSubCategory || 'All sub-categories';
        subMenu.innerHTML =
            '<button type="button" class="pos-filter-option' + (activeSubCategory === '' ? ' is-active' : '') + '" data-subcat="" onclick="setSubCategory(\'\')">' +
                '<span>All sub-categories</span><span class="pos-filter-option-check">✓</span>' +
            '</button>' +
            subs.map((sub) =>
                '<button type="button" class="pos-filter-option' + (activeSubCategory === sub ? ' is-active' : '') + '" data-subcat="' + escHtml(sub) + '" onclick="setSubCategory(this.dataset.subcat)">' +
                    '<span>' + escHtml(sub) + '</span><span class="pos-filter-option-check">✓</span>' +
                '</button>'
            ).join('');
    }

    function setSubCategory(sub) {
        activeSubCategory = sub;
        const subLabel = document.getElementById('posSubCategoryLabel');
        if (subLabel) subLabel.textContent = sub || 'All sub-categories';
        document.querySelectorAll('#posSubCategoryMenu .pos-filter-option').forEach((option) => {
            option.classList.toggle('is-active', (option.dataset.subcat || '') === sub);
        });
        closePosFilterMenus();
        renderProducts();
    }

    function resetPosFilters() {
        setCategory('');
    }

    /* ═══ CART ═══ */
    function addToCart(id) {
        if (cartIds.includes(id)) return;
        cartIds.push(id);
        save();
        renderProducts();
        renderCart();
    }

    function removeFromCart(id) {
        cartIds = cartIds.filter(i => i !== id);
        save();
        renderProducts();
        renderCart();
    }

    function renderCart() {
        const container = document.getElementById('cartItems');
        const emptyMsg = document.getElementById('cartEmpty');
        const countBadgeEl = document.getElementById('cartCountBadge');

        if (cartIds.length === 0) {
            container.innerHTML = '';
            emptyMsg.style.display = 'block';
            if (countBadgeEl) {
                countBadgeEl.textContent = '0';
                countBadgeEl.classList.remove('is-visible');
            }
        } else {
            emptyMsg.style.display = 'none';
            if (countBadgeEl) {
                countBadgeEl.textContent = String(cartIds.length);
                countBadgeEl.classList.add('is-visible');
            }
            container.innerHTML = '';

            cartIds.forEach(id => {
                const item = allItems.find(i => i.id === id);
                if (!item) return;
                const cartItemLabel = escHtml(item.design || item.category || 'Item');

                const imgHtml = item.image
                    ? '<img src="' + escHtml(item.image) + '" alt="' + cartItemLabel + ' image" class="cart-item-img">'
                    : '<div class="cart-item-img-ph"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" opacity=".4"><rect x="3" y="3" width="18" height="18" rx="2"/></svg></div>';

                const div = document.createElement('div');
                div.className = 'cart-item';
                div.innerHTML =
                    imgHtml +
                    '<div class="cart-item-info">' +
                        '<div class="cart-item-name">' + (item.design || item.category || 'Item') + '</div>' +
                        '<div class="cart-item-meta">' + item.barcode + (item.purity && item.purity !== '0.00' ? ' · ' + item.purity + 'K' : '') + '</div>' +
                    '</div>' +
                    '<span class="cart-item-price">₹' + item.sellingPrice.toLocaleString('en-IN') + '</span>' +
                    '<button class="cart-item-remove" onclick="removeFromCart(' + item.id + ')" title="Remove">&times;</button>';
                container.appendChild(div);
            });
        }

        // Update checkout
        let total = 0;
        cartIds.forEach(id => {
            const item = allItems.find(i => i.id === id);
            if (item) total += item.sellingPrice;
        });
        document.getElementById('checkoutItemCount').textContent = cartIds.length;
        document.getElementById('checkoutSubtotal').textContent = '₹' + total.toLocaleString('en-IN');
        document.getElementById('checkoutTotal').textContent = '₹' + total.toLocaleString('en-IN');
        updateCheckoutBtn();
    }

    /* ═══ CUSTOMER ═══ */
    let custAbort = null;
    function getCustSearchEls(mode) {
        if (mode === 'mobile') {
            return {
                searchEl: document.getElementById('custSearchMobile'),
                dd: document.getElementById('custDropdownMobile'),
            };
        }
        return {
            searchEl: document.getElementById('custSearch'),
            dd: document.getElementById('custDropdown'),
        };
    }

    function onCustSearch(mode = 'desktop') {
        const { searchEl, dd } = getCustSearchEls(mode);
        if (!searchEl || !dd) return;
        const input = searchEl.value.trim();
        if (!input) { dd.classList.remove('active'); searchEl.classList.remove('loading'); return; }

        // Debounce API call
        clearTimeout(custSearchTimer);
        custSearchTimer = setTimeout(async () => {
            if (custAbort) custAbort.abort();
            custAbort = new AbortController();
            const timeoutId = setTimeout(() => custAbort.abort(), 8000);
            searchEl.classList.add('loading');
            try {
                const res = await fetch(CUSTOMERS_API_URL + '?search=' + encodeURIComponent(input), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: custAbort.signal,
                });
                clearTimeout(timeoutId);
                if (!res.ok) throw new Error('Search failed');
                const customers = await res.json();
                dd.innerHTML = '';
                dd.classList.add('active');

                const matches = Array.isArray(customers) ? customers.slice(0, 8) : [];

                if (matches.length === 0) {
                    dd.innerHTML = '<div class="cust-no-match">No customers found</div>';
                    updateCustomerDropdownDirection(searchEl, dd);
                    return;
                }
                matches.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'cust-opt';
                    div.onclick = function() { pickCust(c.id, c.first_name, c.last_name, c.mobile); };
                    div.innerHTML = '<div class="cust-opt-name">' + escHtml(c.first_name + ' ' + c.last_name) + '</div><div class="cust-opt-mobile">' + escHtml(c.mobile || '') + '</div>';
                    dd.appendChild(div);
                });
                updateCustomerDropdownDirection(searchEl, dd);
            } catch(e) {
                if (e.name === 'AbortError') {
                    dd.innerHTML = '<div class="cust-no-match">Search timed out — try again</div>';
                    dd.classList.add('active');
                    updateCustomerDropdownDirection(searchEl, dd);
                    return;
                }
                console.error('Customer search error:', e);
                dd.innerHTML = '<div class="cust-no-match">Search error — try again</div>';
                dd.classList.add('active');
                updateCustomerDropdownDirection(searchEl, dd);
            } finally {
                searchEl.classList.remove('loading');
            }
        }, 200);

        dd.innerHTML = '<div class="cust-no-match">Searching…</div>';
        dd.classList.add('active');
        updateCustomerDropdownDirection(searchEl, dd);
    }

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function pickCust(id, first, last, mobile) {
        selectedCust = { id, firstName: first, lastName: last, mobile };
        document.getElementById('custDropdown').classList.remove('active');
        document.getElementById('custDropdownMobile').classList.remove('active');
        document.getElementById('custSearchSection').style.display = 'none';
        document.getElementById('custSelected').style.display = 'block';
        document.getElementById('custAvatar').textContent = (first || '-').charAt(0).toUpperCase();
        document.getElementById('custName').textContent = first + ' ' + last;
        document.getElementById('custMobile').textContent = mobile || '—';
        hideCustSearchModal();
        save();
        updateCheckoutBtn();
    }

    function deselectCust() {
        selectedCust = null;
        document.getElementById('custSearchSection').style.display = 'block';
        document.getElementById('custSelected').style.display = 'none';
        document.getElementById('custSearch').value = '';
        document.getElementById('custSearchMobile').value = '';
        document.getElementById('custDropdownMobile').classList.remove('active');
        save();
        updateCheckoutBtn();
    }

    /* ═══ CHECKOUT ═══ */
    function updateCheckoutBtn() {
        const disabled = !(selectedCust && cartIds.length > 0);
        ['btnCheckout', 'btnCheckoutMobile'].forEach((id) => {
            const button = document.getElementById(id);
            if (button) button.disabled = disabled;
        });
    }

    let checkoutInProgress = false;
    function goCheckout() {
        if (!selectedCust || cartIds.length === 0 || checkoutInProgress) return;
        checkoutInProgress = true;
        ['btnCheckout', 'btnCheckoutMobile'].forEach(id => {
            const b = document.getElementById(id);
            if (b) { b.disabled = true; b.textContent = 'Redirecting…'; }
        });
        sessionStorage.removeItem(CART_STORAGE_KEY);
        sessionStorage.removeItem(CUSTOMER_STORAGE_KEY);
        const params = cartIds.map(id => 'item_ids[]=' + id).join('&');
        window.location.href = POS_CUSTOMER_BASE + '/' + selectedCust.id + '?' + params;
    }

    /* ═══ ADD CUSTOMER MODAL ═══ */
    function showModal() { document.getElementById('addCustModal').classList.add('active'); }
    function hideModal() { document.getElementById('addCustModal').classList.remove('active'); document.getElementById('addCustForm').reset(); }
    function showCustSearchModal() {
        const modal = document.getElementById('custSearchModal');
        if (!modal) return;
        modal.classList.add('active');
        setTimeout(() => {
            const searchEl = document.getElementById('custSearchMobile');
            if (searchEl) searchEl.focus();
        }, 30);
    }
    function hideCustSearchModal() {
        const modal = document.getElementById('custSearchModal');
        const dd = document.getElementById('custDropdownMobile');
        if (modal) modal.classList.remove('active');
        if (dd) dd.classList.remove('active');
    }

    document.getElementById('addCustForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        const submitBtn = this.querySelector('[type="submit"]');
        const origText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving...'; }
        fetch('{{ route("customers.store") }}', {
            method: 'POST', body: fd,
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => { if (!r.ok) return r.json().then(j => { throw new Error(j.message || 'Error'); }); return r.json(); })
        .then(data => {
            if (data.id) {
                const nc = { id: data.id, firstName: data.first_name || fd.get('first_name'), lastName: data.last_name || fd.get('last_name'), mobile: data.mobile || fd.get('mobile') };
                pickCust(nc.id, nc.firstName, nc.lastName, nc.mobile);
                hideModal();
            }
        })
        .catch(err => window.showToast('Error: ' + err.message, 'error'))
        .finally(() => { if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = origText; } });
    });

    /* ═══ GLOBAL HANDLERS ═══ */
    function posDocClick(e) {
        if (!document.querySelector('.pos-topbar-search')?.contains(e.target) && !document.querySelector('.pos-search-wrap')?.contains(e.target)) {
            // close any search dropdown if we add one later
        }
        if (!e.target.closest('.pos-filter-dropdown')) {
            closePosFilterMenus();
        }
        if (!document.querySelector('.cust-search-wrap')?.contains(e.target)) {
            var dd = document.getElementById('custDropdown');
            if (dd) dd.classList.remove('active');
        }
        if (!document.querySelector('.cust-search-modal-wrap')?.contains(e.target)) {
            var mobileDd = document.getElementById('custDropdownMobile');
            if (mobileDd) mobileDd.classList.remove('active');
        }
    }
    function posEsc(e) {
        if (e.key === 'Escape') {
            hideModal();
            hideCustSearchModal();
            closePosFilterMenus();
            var dd = document.getElementById('custDropdown');
            if (dd) dd.classList.remove('active');
            var mobileDd = document.getElementById('custDropdownMobile');
            if (mobileDd) mobileDd.classList.remove('active');
        }
    }
    if (window._posDocClick) document.removeEventListener('click', window._posDocClick);
    if (window._posEsc) document.removeEventListener('keydown', window._posEsc);
    if (window._posResizeDropdowns) window.removeEventListener('resize', window._posResizeDropdowns);
    window._posDocClick = posDocClick;
    window._posEsc = posEsc;
    window._posResizeDropdowns = function() {
        document.querySelectorAll('.pos-filter-dropdown.is-open').forEach(updatePosFilterDirection);

        const desktopSearch = document.getElementById('custSearch');
        const desktopDd = document.getElementById('custDropdown');
        if (desktopSearch && desktopDd && desktopDd.classList.contains('active')) {
            updateCustomerDropdownDirection(desktopSearch, desktopDd);
        }
    };
    document.addEventListener('click', posDocClick);
    document.addEventListener('keydown', posEsc);
    window.addEventListener('resize', window._posResizeDropdowns, { passive: true });

    var modal = document.getElementById('addCustModal');
    if (modal) modal.addEventListener('click', function(e) { if (e.target === this) hideModal(); });
    var custSearchModal = document.getElementById('custSearchModal');
    if (custSearchModal) custSearchModal.addEventListener('click', function(e) { if (e.target === this) hideCustSearchModal(); });

    /* ═══ EXPOSE ═══ */
    window.filterProducts = filterProducts;
    window.syncPosSearch = syncPosSearch;
    window.togglePosFilterMenu = togglePosFilterMenu;
    window.setCategory = setCategory;
    window.setSubCategory = setSubCategory;
    window.resetPosFilters = resetPosFilters;
    window.addToCart = addToCart;
    window.removeFromCart = removeFromCart;
    window.onCustSearch = onCustSearch;
    window.pickCust = pickCust;
    window.deselectCust = deselectCust;
    window.goCheckout = goCheckout;
    window.showModal = showModal;
    window.hideModal = hideModal;
    window.showCustSearchModal = showCustSearchModal;
    window.hideCustSearchModal = hideCustSearchModal;

    /* ═══ INIT ═══ */
    updateSubCategoryOptions();
    restore();
})();
</script>

<script src="{{ asset('js/qrcode.min.js') }}"></script>
<script>
/* ═══════════════════════════════════════════════════
   MOBILE SCANNER — Polling + Cart Integration
   ═════════════════════════════════════════════════ */
(function() {
    const CSRF         = '{{ csrf_token() }}';
    const CREATE_URL   = '{{ route("scan.session.create") }}';
    const POS_LOOKUP_BASE = @json(url('/api/item-by-barcode'));
    const POLL_URL_TEMPLATE = @json(route('scan.session.poll', ['token' => '__TOKEN__']));
    const EXPIRE_URL_TEMPLATE = @json(route('scan.session.expire', ['token' => '__TOKEN__']));
    const SCAN_STATE_KEY = @json('pos_scan_state_v1_' . (string) auth()->id() . '_' . (string) auth()->user()->shop_id);
    const LEGACY_SCAN_STATE_KEY = 'pos_scan_state_v1';

    function getPosItems() {
        return Array.isArray(window.__posAllItems) ? window.__posAllItems : [];
    }

    let scanToken        = null;
    let pollTimer        = null;
    let countdownTimer   = null;
    let expiresAt        = null;
    let sessionActive    = false;
    let mobileConnected  = false;

    function saveScanState() {
        if (sessionActive && scanToken) {
            localStorage.setItem(SCAN_STATE_KEY, JSON.stringify({
                token: scanToken,
                expiresAt: expiresAt,
            }));
            localStorage.removeItem(LEGACY_SCAN_STATE_KEY);
            return;
        }
        localStorage.removeItem(SCAN_STATE_KEY);
        localStorage.removeItem(LEGACY_SCAN_STATE_KEY);
    }

    function restoreScanState() {
        try {
            const raw = localStorage.getItem(SCAN_STATE_KEY);
            const legacyRaw = localStorage.getItem(LEGACY_SCAN_STATE_KEY);
            const effectiveRaw = raw || legacyRaw;
            if (!effectiveRaw) return;

            const parsed = JSON.parse(effectiveRaw);
            if (!parsed || !parsed.token) return;

            scanToken = String(parsed.token);
            expiresAt = parsed.expiresAt ? Number(parsed.expiresAt) : null;
            sessionActive = true;
            mobileConnected = false;
            saveScanState();
            setScanStatus('waiting', 'Scan QR to Connect');
            updateActionButton();
            startCountdown();
            startPolling();
        } catch (_) {
            localStorage.removeItem(SCAN_STATE_KEY);
            localStorage.removeItem(LEGACY_SCAN_STATE_KEY);
        }
    }

    // ── Toast helper ─────────────────────────────────
    function showToast(msg, type) {
        const old = document.querySelector('.pos-toast');
        if (old) old.remove();
        const t = document.createElement('div');
        t.className = 'pos-toast ' + (type || 'success');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), 2800);
    }

    // ── Add barcode to POS cart ───────────────────────
    function normalizeBarcode(raw) {
        return String(raw || '')
            .trim()
            .replace(/\s+/g, '')
            .replace(/^0+(?=\d+$)/, '');
    }

    function mapLookupItemToPosItem(barcode, item) {
        return {
            id: item.id,
            barcode: barcode,
            design: item.design || '',
            category: item.category || '',
            subCategory: item.sub_category || '',
            grossWeight: String(item.gross_weight ?? ''),
            purity: String(item.purity ?? ''),
            netWeight: String(item.weight ?? item.gross_weight ?? ''),
            sellingPrice: Number(item.selling_price ?? 0),
            image: item.image ? '/storage/' + item.image : '',
        };
    }

    async function handleBarcode(barcode) {
        const posItems = getPosItems();
        const normalized = normalizeBarcode(barcode);
        const item = posItems.find(i => normalizeBarcode(i.barcode) === normalized);

        if (item) {
            if (typeof window.addToCart === 'function') {
                window.addToCart(item.id);
            }
            showToast('✓ ' + (item.design || item.category || barcode) + ' added', 'success');
            addScanRecord(barcode, item.design || item.category || barcode, true);
            return;
        }

        try {
            const res = await fetch(POS_LOOKUP_BASE + '/' + encodeURIComponent(barcode), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!res.ok) {
                showToast('✗ Item not found: ' + barcode, 'error');
                addScanRecord(barcode, 'Not found', false);
                return;
            }

            const payload = await res.json();
            const posItem = mapLookupItemToPosItem(barcode, payload);

            if (!posItems.some(i => i.id === posItem.id)) {
                posItems.push(posItem);
            }

            if (typeof window.addToCart === 'function') {
                window.addToCart(posItem.id);
            }
            showToast('✓ ' + (posItem.design || posItem.category || barcode) + ' added', 'success');
            addScanRecord(barcode, posItem.design || posItem.category || barcode, true);
        } catch (e) {
            showToast('✗ Scan lookup failed for: ' + barcode, 'error');
            addScanRecord(barcode, 'Lookup failed', false);
        }
    }

    // ── Recent scan history in the modal ─────────────
    function addScanRecord(barcode, label, ok) {
        const wrap = document.getElementById('scanRecentWrap');
        const list = document.getElementById('scanRecentList');
        if (!wrap || !list) return;
        wrap.style.display = 'block';
        const now = new Date().toLocaleTimeString('en-IN', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
        const div = document.createElement('div');
        div.className = 'scan-recent-item';
        div.innerHTML =
            '<div class="scan-recent-dot ' + (ok ? 'ok' : 'error') + '"></div>' +
            '<div class="scan-recent-name">' + label + ' <span style="color:#94a3b8;font-weight:400">(' + barcode + ')</span></div>' +
            '<div class="scan-recent-time">' + now + '</div>';
        list.prepend(div);
        while (list.children.length > 6) list.removeChild(list.lastChild);
    }

    // ── Countdown timer ───────────────────────────────
    function formatScanCountdown(seconds) {
        if (!Number.isFinite(seconds) || seconds <= 0) return 'Expired';
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;

        if (hours > 0) {
            return hours + 'h ' + String(minutes).padStart(2, '0') + 'm';
        }

        return String(minutes).padStart(2, '0') + 'm ' + String(secs).padStart(2, '0') + 's';
    }

    function startCountdown() {
        const el = document.getElementById('scanCountdown');
        const endsAtEl = document.getElementById('scanEndsAt');
        const countdownWrap = document.getElementById('scanCountdownWrap');
        if (!el) return;

        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }

        if (countdownWrap) countdownWrap.style.display = '';

        countdownTimer = setInterval(() => {
            if (!expiresAt) {
                el.textContent = '--';
                if (endsAtEl) endsAtEl.textContent = '';
                return;
            }

            const diffSeconds = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
            el.textContent = formatScanCountdown(diffSeconds);

            if (endsAtEl) {
                endsAtEl.textContent = 'Ends at ' + new Date(expiresAt).toLocaleTimeString('en-IN', {
                    hour: '2-digit',
                    minute: '2-digit',
                });
            }
        }, 1000);
    }

    // ── Status badge ──────────────────────────────────
    function setScanStatus(cls, text) {
        const badge = document.getElementById('scanStatusBadge');
        if (!badge) return;
        badge.className = 'scan-status-badge ' + cls;
        badge.textContent = text;
        const btn = document.getElementById('btnScanMobile');
        if (btn) btn.classList.toggle('active', sessionActive);
    }

    // ── Action button state ──────────────────────────
    function updateActionButton() {
        const btn = document.getElementById('btnScanAction');
        if (!btn) return;

        if (mobileConnected) {
            btn.textContent = 'Disconnect';
            btn.style.display = '';
        } else if (sessionActive) {
            btn.textContent = 'Disconnect';
            btn.style.display = '';
        } else {
            btn.style.display = 'none';
        }
    }

    // ── Polling loop (1.5s) ───────────────────────────
    function startPolling() {
        if (!scanToken) return;
        const POLL_URL = POLL_URL_TEMPLATE.replace('__TOKEN__', encodeURIComponent(scanToken));

        pollTimer = setInterval(async () => {
            if (!sessionActive) { stopPolling(); return; }
            try {
                const res = await fetch(POLL_URL, {
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });
                const data = await res.json();

                if (!res.ok) {
                    setScanStatus('expired', 'Connection failed');
                    if (res.status === 404 || res.status === 410) {
                        sessionActive = false;
                        mobileConnected = false;
                        stopPolling();
                        saveScanState();
                        updateActionButton();
                    }
                    return;
                }

                if (data.expires_at) {
                    const nextExpiry = new Date(data.expires_at).getTime();
                    if (!Number.isNaN(nextExpiry)) {
                        expiresAt = nextExpiry;
                        saveScanState();
                    }
                }

                if (data.status === 'expired') {
                    sessionActive = false;
                    mobileConnected = false;
                    setScanStatus('expired', 'Session Expired');
                    stopPolling();
                    saveScanState();
                    updateActionButton();
                    return;
                }

                // Track real mobile connection from server
                if (data.mobile_connected && !mobileConnected) {
                    mobileConnected = true;
                    setScanStatus('active', 'Connected');
                    updateActionButton();
                } else if (!data.mobile_connected && !mobileConnected) {
                    setScanStatus('waiting', 'Scan QR to Connect');
                }

                if (data.barcodes && data.barcodes.length > 0) {
                    setScanStatus('scanning', 'Scanning...');
                    data.barcodes.forEach(barcode => handleBarcode(barcode));
                    setTimeout(() => {
                        if (sessionActive && mobileConnected) {
                            setScanStatus('active', 'Connected');
                        }
                    }, 1500);
                }
            } catch(e) {
                setScanStatus('expired', 'Connection issue');
            }
        }, 1500);
    }

    function stopPolling() {
        if (pollTimer)    { clearInterval(pollTimer);    pollTimer = null; }
        if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        sessionActive = false;
        mobileConnected = false;
        const btn = document.getElementById('btnScanMobile');
        if (btn) btn.classList.remove('active');
    }

    // ── Open modal + create session ──────────────────
    window.openScanModal = async function() {
        document.getElementById('scanModal').classList.add('active');

        // If session already active, just reopen modal.
        if (sessionActive && scanToken) {
            if (mobileConnected) {
                setScanStatus('active', 'Connected');
            } else {
                setScanStatus('waiting', 'Scan QR to Connect');
            }
            updateActionButton();
            return;
        }

        stopPolling();

        // Reset modal state
        document.getElementById('scanQrLoading').style.display = 'flex';
        document.getElementById('scanQrImg').style.display = 'none';
        document.getElementById('scanRecentWrap').style.display = 'none';
        document.getElementById('scanRecentList').innerHTML = '';
        document.getElementById('scanCountdown').textContent = '--';
        document.getElementById('scanEndsAt').textContent = '';
        const countdownWrap = document.getElementById('scanCountdownWrap');
        if (countdownWrap) countdownWrap.style.display = 'none';
        setScanStatus('waiting', 'Generating QR...');
        updateActionButton();

        try {
            if (typeof QRCode === 'undefined') {
                setScanStatus('expired', 'QR library failed to load');
                return;
            }

            const res = await fetch(CREATE_URL, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': CSRF,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json();

            if (!res.ok || data.error) {
                setScanStatus('expired', data.error || 'Failed to create session');
                return;
            }

            scanToken     = data.token;
            expiresAt     = new Date(data.expires_at).getTime();
            sessionActive = true;
            mobileConnected = false;
            saveScanState();

            // Generate QR code client-side
            const qrWrap = document.getElementById('scanQrWrap');
            qrWrap.innerHTML = '<div id="scanQrCanvas" style="width:196px;height:196px;"></div>';

            try {
                new QRCode(document.getElementById('scanQrCanvas'), {
                    text: data.scan_url,
                    width: 196,
                    height: 196,
                    colorDark: '#0f172a',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H,
                });
            } catch (qrErr) {
                setScanStatus('expired', 'QR generation failed');
                sessionActive = false;
                saveScanState();
                updateActionButton();
                return;
            }

            setScanStatus('waiting', 'Scan QR to Connect');
            document.getElementById('btnScanMobile').classList.add('active');
            updateActionButton();

            startCountdown();
            startPolling();

        } catch(e) {
            setScanStatus('expired', 'Connection error');
            updateActionButton();
        }
    };

    // ── Hide modal only (session remains active) ─────
    window.hideScanModal = function() {
        document.getElementById('scanModal').classList.remove('active');
    };

    // ── Explicitly disconnect session ────────────────
    window.stopScanSession = function() {
        stopPolling();
        document.getElementById('scanModal').classList.remove('active');
        if (scanToken) {
            const expireUrl = EXPIRE_URL_TEMPLATE.replace('__TOKEN__', encodeURIComponent(scanToken));
            navigator.sendBeacon(expireUrl);
            scanToken = null;
        }
        expiresAt = null;
        mobileConnected = false;
        const countdownEl = document.getElementById('scanCountdown');
        const endsAtEl = document.getElementById('scanEndsAt');
        const countdownWrap = document.getElementById('scanCountdownWrap');
        if (countdownEl) countdownEl.textContent = '--';
        if (endsAtEl) endsAtEl.textContent = '';
        if (countdownWrap) countdownWrap.style.display = 'none';
        saveScanState();
        setScanStatus('waiting', 'Scan to Connect');
        updateActionButton();
    };

    window.closeScanModal = window.stopScanSession;

    // Close on backdrop click
    document.getElementById('scanModal').addEventListener('click', function(e) {
        if (e.target === this) window.hideScanModal();
    });

    restoreScanState();

})();
</script>

</x-app-layout>
