@php
    $visibleReturns = $returnOrders->getCollection();
    $visibleRefundTotal = $visibleReturns->sum(fn ($order) => (float) ($order->creditNote?->total ?? 0));
    $visibleSettled = $visibleReturns->where('status', \App\Models\ReturnOrder::STATUS_SETTLED)->count();
    $visiblePending = $visibleReturns->where('status', \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)->count();
    $statusLabel = fn ($status) => match ($status) {
        \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'Pending Approval',
        \App\Models\ReturnOrder::STATUS_SETTLED => 'Settled',
        \App\Models\ReturnOrder::STATUS_DRAFT => 'Draft',
        \App\Models\ReturnOrder::STATUS_SUBMITTED => 'Submitted',
        \App\Models\ReturnOrder::STATUS_CANCELLED => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', (string) $status)),
    };
    $statusTone = fn ($status) => match ($status) {
        \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'warning',
        \App\Models\ReturnOrder::STATUS_SETTLED => 'success',
        \App\Models\ReturnOrder::STATUS_CANCELLED => 'danger',
        default => 'neutral',
    };
    $hasFilters = request()->filled('status')
        || request()->filled('from')
        || request()->filled('to')
        || request()->filled('customer');
    $activeFilterCount = collect(['status', 'from', 'to', 'customer'])->filter(fn ($key) => request()->filled($key))->count();
@endphp

<x-app-layout>
<style>
    /* ── Returns Inbox ─────────────────────────────────────────────
       Scoped legacy .ri-* helpers remain for compatibility, but the
       final page treatment now aligns with the Customer register system.
       ─────────────────────────────────────────────────────────── */
    .ri-page {
        --ri-border:        #e7ebf1;
        --ri-border-soft:   #eef1f6;
        --ri-border-strong: #d9dfe8;
        --ri-ink:           #0f172a;
        --ri-ink-2:         #3d4861;
        --ri-muted:         #6a7588;
        --ri-accent:        #0d9488;
        --ri-accent-deep:   #0f766e;
        --ri-accent-soft:   rgba(13,148,136,.08);
        --ri-shadow:        0 1px 2px rgba(16,24,40,.04), 0 12px 28px -16px rgba(16,24,40,.16);
        --ri-ease:          cubic-bezier(0.23,1,0.32,1);
        max-width: 1360px;
    }

    @media (prefers-reduced-motion: no-preference) {
        .ri-page .ri-card { animation: riRise .5s var(--ri-ease) both; }
        .ri-page .ri-card:nth-child(2) { animation-delay: .04s; }
        .ri-page .ri-card:nth-child(3) { animation-delay: .08s; }
        @keyframes riRise {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    }

    .ri-card {
        border: 1px solid var(--ri-border);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: var(--ri-shadow);
        overflow: hidden;
    }

    .ri-flow {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* ── Back button ── */
    .ri-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 40px;
        padding: 0 15px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 12px;
        background: #ffffff;
        color: var(--ri-ink-2);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .16s var(--ri-ease);
    }
    .ri-back-btn:hover { background: #f7f9fc; }
    .ri-back-btn:active { transform: scale(.98); }

    /* ── KPI strip — one bordered panel, hairline-divided columns ── */
    .ri-kpi-strip {
        display: grid;
        grid-template-columns: repeat(5, minmax(0,1fr));
        border: 1px solid var(--ri-border);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: var(--ri-shadow);
        overflow: hidden;
    }

    .ri-kpi {
        padding: 16px 18px;
        border-right: 1px solid var(--ri-border);
    }
    .ri-kpi:last-child { border-right: 0; }

    .ri-kpi-label {
        color: var(--ri-muted);
        font-size: 12px;
        font-weight: 500;
        margin: 0 0 6px;
    }

    .ri-kpi-value {
        color: var(--ri-ink);
        font-size: 20px;
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -.01em;
        font-variant-numeric: tabular-nums;
        margin: 0;
    }

    .ri-kpi-value--accent { color: var(--ri-accent-deep); }
    .ri-kpi-value--warn   { color: #92400e; }

    /* ── Filter bar ── */
    .ri-filter {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--ri-border-soft);
        background: #fafbfd;
    }

    .ri-filter-field { display: flex; flex-direction: column; gap: 6px; }

    .ri-filter-label {
        color: var(--ri-muted);
        font-size: 11.5px;
        font-weight: 600;
    }

    .ri-filter-control {
        height: 38px;
        padding: 0 11px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 10px;
        background: #ffffff;
        color: var(--ri-ink);
        font-size: 13px;
        min-width: 140px;
        transition: border-color .15s var(--ri-ease), box-shadow .15s var(--ri-ease);
    }
    .ri-filter-control:focus {
        border-color: var(--ri-accent-deep);
        box-shadow: 0 0 0 3px rgba(15,118,110,.12);
        outline: none;
    }

    .ri-filter-apply {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 38px;
        padding: 0 16px;
        border: 1px solid var(--ri-accent-deep);
        border-radius: 10px;
        background: var(--ri-accent-deep);
        color: #ffffff;
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
        transition: background-color .15s var(--ri-ease), transform .15s var(--ri-ease);
    }
    .ri-filter-apply:hover { background: #115e56; }
    .ri-filter-apply:active { transform: scale(.98); }

    .ri-filter-clear {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 38px;
        padding: 0 14px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 10px;
        background: #ffffff;
        color: var(--ri-ink-2);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .15s var(--ri-ease);
    }
    .ri-filter-clear:hover { background: #f7f9fc; }

    /* ── Table section header ── */
    .ri-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--ri-border-soft);
    }

    .ri-section-title {
        margin: 0;
        color: var(--ri-ink);
        font-size: 14px;
        font-weight: 650;
    }

    .ri-section-count {
        color: var(--ri-muted);
        font-size: 13px;
        margin: 0;
    }

    /* ── Table ── */
    .ri-table-wrap { overflow-x: auto; }

    .ri-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .ri-table thead th {
        padding: 11px 16px;
        text-align: left;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--ri-muted);
        background: #fafbfd;
        border-bottom: 1px solid var(--ri-border);
        white-space: nowrap;
    }
    .ri-table thead th.text-right { text-align: right; }
    .ri-table thead th.text-center { text-align: center; }

    .ri-table tbody td {
        padding: 13px 16px;
        vertical-align: middle;
        border-bottom: 1px solid var(--ri-border-soft);
        color: var(--ri-ink-2);
    }
    .ri-table tbody tr:last-child td { border-bottom: 0; }
    .ri-table tbody tr:hover { background: #fafbfd; }
    .ri-table td.text-right { text-align: right; }
    .ri-table td.text-center { text-align: center; }

    /* Cell content helpers */
    .ri-cn-number {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        font-weight: 700;
        color: var(--ri-ink);
        display: block;
    }

    .ri-cell-sub {
        display: block;
        font-size: 11.5px;
        color: var(--ri-muted);
        margin-top: 2px;
    }

    .ri-invoice-link {
        font-weight: 650;
        color: var(--ri-accent-deep);
        text-decoration: none;
    }
    .ri-invoice-link:hover { text-decoration: underline; }

    .ri-customer-name { font-weight: 600; color: var(--ri-ink); }

    .ri-refund-amount {
        font-weight: 700;
        color: var(--ri-ink);
        font-variant-numeric: tabular-nums;
    }

    .ri-muted { color: var(--ri-muted); }

    /* Status pills */
    .ri-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 650;
        white-space: nowrap;
    }
    .ri-status::before {
        content: '';
        width: 5px;
        height: 5px;
        border-radius: 999px;
        background: currentColor;
        flex-shrink: 0;
    }
    .ri-status--success  { background: #ecfdf5; color: #065f46; }
    .ri-status--warning  { background: #fffbeb; color: #92400e; }
    .ri-status--danger   { background: #fef2f2; color: #991b1b; }
    .ri-status--neutral  { background: #f1f5f9; color: #475569; }

    /* Action buttons */
    .ri-btn-view {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 8px;
        background: #ffffff;
        color: var(--ri-ink-2);
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .14s var(--ri-ease);
    }
    .ri-btn-view:hover { background: #f7f9fc; }

    .ri-btn-approve {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border: 1px solid #16a34a;
        border-radius: 8px;
        background: #16a34a;
        color: #ffffff;
        font-size: 12px;
        font-weight: 650;
        cursor: pointer;
        transition: background-color .14s var(--ri-ease);
    }
    .ri-btn-approve:hover { background: #15803d; }

    .ri-btn-reject {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border: 1px solid #fecaca;
        border-radius: 8px;
        background: #fef2f2;
        color: #991b1b;
        font-size: 12px;
        font-weight: 650;
        text-decoration: none;
        transition: background-color .14s var(--ri-ease);
    }
    .ri-btn-reject:hover { background: #fee2e2; }

    /* ── Empty state ── */
    .ri-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
        padding: 60px 24px;
        text-align: center;
    }

    .ri-empty-icon {
        width: 44px;
        height: 44px;
        color: #c8d0db;
    }

    .ri-empty-title {
        margin: 0;
        color: var(--ri-ink);
        font-size: 15px;
        font-weight: 650;
    }

    .ri-empty-copy {
        margin: 0;
        max-width: 38ch;
        color: var(--ri-muted);
        font-size: 13px;
        line-height: 1.6;
    }

    /* ── Pagination ── */
    .ri-pagination {
        padding: 14px 18px;
        border-top: 1px solid var(--ri-border-soft);
        background: #fafbfd;
    }

    /* ── Mobile card list (< 768px) ── */
    .ri-table-wrap,
    .ri-section-head { display: block; }
    .ri-mobile-list { display: none; }

    @media (max-width: 767px) {
        .ri-table-wrap { display: none; }
        .ri-mobile-list { display: block; }

        .ri-kpi-strip {
            grid-template-columns: repeat(2, minmax(0,1fr));
        }
        .ri-kpi { border-bottom: 1px solid var(--ri-border); }
        .ri-kpi:nth-child(2n) { border-right: 0; }
        .ri-kpi:nth-last-child(-n+2):nth-child(odd),
        .ri-kpi:last-child { border-bottom: 0; }

        .ri-filter { gap: 10px; }
        .ri-filter-control { min-width: 0; width: 100%; }
        .ri-filter-field { width: 100%; }
        .ri-filter-apply,
        .ri-filter-clear { height: 42px; flex: 1; }
    }

    .ri-mobile-card {
        padding: 16px;
        border-bottom: 1px solid var(--ri-border-soft);
    }
    .ri-mobile-card:last-child { border-bottom: 0; }

    .ri-mobile-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }

    .ri-mobile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0,1fr));
        gap: 8px;
        margin-bottom: 12px;
    }

    .ri-mobile-grid dt {
        font-size: 11px;
        font-weight: 600;
        color: var(--ri-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 2px;
    }

    .ri-mobile-grid dd {
        font-size: 13px;
        font-weight: 600;
        color: var(--ri-ink);
        margin: 0;
    }

    /* Current JewelFlow ERP treatment: flat, compact, and consistent with return detail pages. */
    .content-header.returns-index-header {
        border-bottom: 1px solid #cbd5e1;
        background: #ffffff;
        box-shadow: none;
        backdrop-filter: none;
    }

    .content-header.returns-index-header .page-title {
        color: #111827;
        font-size: 22px;
        font-weight: 620;
        line-height: 1.2;
        letter-spacing: 0;
    }

    .content-header.returns-index-header .page-subtitle {
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
        line-height: 1.35;
    }

    .content-header.returns-index-header .page-actions > .ri-back-btn {
        border-color: #cbd5e1 !important;
        border-radius: 10px !important;
        background: #ffffff !important;
        color: #111827 !important;
        box-shadow: none !important;
    }

    .content-header.returns-index-header .page-actions > .ri-back-btn:hover {
        border-color: #94a3b8 !important;
        background: #f8fafc !important;
        color: #111827 !important;
        transform: none;
    }

    .content-inner.ri-page {
        --ri-border: #cbd5e1;
        --ri-border-soft: #e2e8f0;
        --ri-border-strong: #cbd5e1;
        --ri-ink: #111827;
        --ri-ink-2: #334155;
        --ri-muted: #64748b;
        --ri-accent: #b45309;
        --ri-accent-deep: #b45309;
        --ri-accent-soft: #fff7ed;
        --ri-shadow: none;
        width: 100%;
        max-width: none;
        background: #f6f7f9;
        color: #111827;
    }

    .ri-flow {
        gap: 16px;
    }

    .ri-card,
    .ri-kpi-strip {
        border-color: #cbd5e1;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: none !important;
    }

    .ri-kpi-strip {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }

    .ri-kpi {
        border-color: #e2e8f0;
        padding: 14px 16px;
    }

    .ri-kpi-label,
    .ri-filter-label,
    .ri-table thead th,
    .ri-mobile-grid dt {
        color: #64748b;
        font-size: 11px;
        font-weight: 620;
        line-height: 1.2;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .ri-kpi-value {
        color: #111827;
        font-size: 19px;
        font-weight: 620;
        letter-spacing: 0;
    }

    .ri-kpi-value--accent {
        color: #047857;
    }

    .ri-kpi-value--warn {
        color: #b45309;
    }

    .ri-kpi-review {
        display: inline-flex;
        align-items: center;
        min-height: 26px;
        margin-left: 8px;
        border: 1px solid #f8d28b;
        border-radius: 8px;
        background: #fffaf0;
        padding: 0 8px;
        color: #b45309;
        font-size: 12px;
        font-weight: 600;
        line-height: 1;
        text-decoration: none;
        vertical-align: middle;
    }

    .ri-filter-shell {
        border-bottom: 1px solid #e2e8f0;
    }

    .ri-filter-check {
        position: absolute;
        width: 1px;
        height: 1px;
        opacity: 0;
        pointer-events: none;
    }

    .ri-filter-toggle {
        display: none;
    }

    .ri-filter {
        gap: 10px;
        border-bottom: 0;
        background: #ffffff;
        padding: 14px 16px;
    }

    .ri-filter-field {
        min-width: 150px;
    }

    .ri-filter-field--customer {
        flex: 1 1 240px;
    }

    .ri-filter-control {
        min-width: 0;
        height: 40px;
        border-color: #cbd5e1;
        border-radius: 10px;
        color: #111827;
        font-size: 13px;
        font-weight: 500;
        box-shadow: none;
    }

    .ri-filter-control:focus {
        border-color: #b45309;
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18);
    }

    .ri-filter-actions,
    .ri-row-actions,
    .ri-mobile-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .ri-filter-actions {
        align-items: flex-end;
        margin-left: auto;
    }

    .ri-row-actions {
        justify-content: flex-end;
    }

    .ri-filter-apply,
    .ri-filter-clear,
    .ri-btn-view,
    .ri-btn-approve,
    .ri-btn-reject {
        border-radius: 9px;
        box-shadow: none !important;
        transition: background-color 150ms ease-out, border-color 150ms ease-out, color 150ms ease-out;
    }

    .ri-filter-apply,
    .ri-btn-view {
        border-color: #b45309;
        background: #b45309;
        color: #ffffff;
    }

    .ri-filter-apply:hover,
    .ri-btn-view:hover {
        border-color: #92400e;
        background: #92400e;
        color: #ffffff;
        text-decoration: none;
        transform: none;
    }

    .ri-filter-clear {
        border-color: #cbd5e1;
        background: #ffffff;
        color: #334155;
    }

    .ri-filter-clear:hover {
        border-color: #94a3b8;
        background: #f8fafc;
        color: #111827;
    }

    .ri-section-head {
        background: #ffffff;
        padding: 15px 16px;
    }

    .ri-section-title {
        color: #111827;
        font-size: 17px;
        font-weight: 620;
        line-height: 1.25;
    }

    .ri-section-count {
        color: #64748b;
        font-size: 13px;
        font-weight: 500;
    }

    .ri-table {
        font-size: 14px;
    }

    .ri-table thead th {
        display: table-cell;
        padding: 12px 16px;
        background: #f8fafc;
        border-bottom-color: #e2e8f0;
        white-space: nowrap;
    }

    .ri-table tbody td {
        padding: 14px 16px;
        border-bottom-color: #e2e8f0;
        color: #334155;
        font-size: 14px;
        font-weight: 450;
        line-height: 1.35;
    }

    .ri-table tbody tr:hover {
        background: #f8fafc;
    }

    .ri-cn-number,
    .ri-customer-name {
        color: #111827;
        font-weight: 600;
    }

    .ri-cell-sub {
        color: #64748b;
        font-size: 12px;
    }

    .ri-invoice-link,
    .ri-mobile-invoice-link {
        color: #b45309;
        font-weight: 600;
        text-decoration: none;
    }

    .ri-invoice-link:hover,
    .ri-mobile-invoice-link:hover {
        color: #92400e;
        text-decoration: underline;
    }

    .ri-refund-amount {
        color: #047857;
        font-weight: 620;
    }

    .ri-status {
        border: 1px solid transparent;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }

    .ri-status--success {
        border-color: #bbf7d0;
        background: #ecfdf5;
        color: #047857;
    }

    .ri-status--warning {
        border-color: #fde68a;
        background: #fffbeb;
        color: #92400e;
    }

    .ri-status--danger {
        border-color: #fecdd3;
        background: #fff1f2;
        color: #be123c;
    }

    .ri-status--neutral {
        border-color: #e2e8f0;
        background: #f8fafc;
        color: #475569;
    }

    .ri-empty {
        padding: 46px 20px;
    }

    .ri-pagination {
        background: #ffffff;
    }

    @media (max-width: 767px) {
        .content-header.returns-index-header {
            display: grid;
            grid-template-columns: 40px minmax(0, 1fr) 40px;
            align-items: center;
            gap: 8px;
            min-height: 64px;
            padding: 12px 14px !important;
            overflow: hidden;
        }

        .content-header.returns-index-header .content-header-nav {
            grid-column: 1;
            grid-row: 1;
            margin-right: 0;
            padding-top: 0;
        }

        .content-header.returns-index-header > :nth-child(2) {
            grid-column: 2;
            grid-row: 1;
            min-width: 0;
            text-align: center;
        }

        .content-header.returns-index-header .page-title {
            margin: 0;
            font-size: 17px;
            white-space: nowrap;
        }

        .content-header.returns-index-header .page-subtitle {
            display: none;
        }

        .content-header.returns-index-header .page-actions {
            grid-column: 3;
            grid-row: 1;
            justify-self: end;
            width: 40px !important;
            height: 40px !important;
            overflow: hidden;
        }

        .content-header.returns-index-header .page-actions > .ri-back-btn {
            width: 36px !important;
            min-width: 36px !important;
            height: 36px !important;
            min-height: 36px !important;
            padding: 0 !important;
            font-size: 0 !important;
            line-height: 0 !important;
        }

        .content-header.returns-index-header .page-actions > .ri-back-btn span {
            display: none !important;
        }

        .content-header.returns-index-header .page-actions > .ri-back-btn svg {
            width: 16px !important;
            height: 16px !important;
            margin: 0 !important;
        }

        .content-inner.ri-page {
            padding: 14px !important;
        }

        .ri-flow {
            gap: 12px;
        }

        .ri-kpi-strip {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            border: 0;
            background: transparent;
            overflow: visible;
        }

        .ri-kpi {
            min-width: 0;
            border: 1px solid #cbd5e1 !important;
            border-radius: 12px;
            background: #ffffff;
            padding: 11px 12px;
        }

        .ri-kpi:nth-child(2) {
            grid-column: 1 / -1;
            order: -1;
        }

        .ri-kpi-value {
            font-size: 16px;
        }

        .ri-kpi:nth-child(2) .ri-kpi-value {
            font-size: 22px;
        }

        .ri-kpi-review {
            min-height: 24px;
            margin-left: 4px;
            padding-inline: 7px;
            font-size: 11px;
        }

        .ri-filter-shell {
            border-bottom: 1px solid #e2e8f0;
        }

        .ri-filter-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 52px;
            padding: 0 14px;
            color: #111827;
            cursor: pointer;
            list-style: none;
        }

        .ri-filter-toggle strong {
            font-size: 14px;
            font-weight: 620;
        }

        .ri-filter-toggle span {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .ri-filter-toggle small {
            color: #64748b;
            font-size: 12px;
            font-weight: 500;
        }

        .ri-filter-toggle::after {
            content: "+";
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            color: #334155;
            font-size: 15px;
            font-weight: 650;
            flex: 0 0 auto;
        }

        .ri-filter-check:checked + .ri-filter-toggle::after {
            content: "-";
        }

        .ri-filter {
            display: none;
            padding: 12px 14px 14px;
            border-top: 1px solid #e2e8f0;
        }

        .ri-filter-check:checked ~ .ri-filter {
            display: flex;
        }

        .ri-filter-field,
        .ri-filter-field--customer {
            width: 100%;
            min-width: 0;
            flex: 1 1 100%;
        }

        .ri-filter-control {
            width: 100%;
        }

        .ri-filter-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            width: 100%;
            margin-left: 0;
        }

        .ri-filter-actions .ri-filter-apply,
        .ri-filter-actions .ri-filter-clear {
            width: 100%;
            height: 40px;
        }

        .ri-section-head {
            padding: 13px 14px;
        }

        .ri-section-title {
            font-size: 16px;
        }

        .ri-mobile-card {
            padding: 14px;
            background: #ffffff;
        }

        .ri-mobile-head {
            align-items: center;
            margin-bottom: 12px;
        }

        .ri-mobile-grid {
            gap: 10px;
        }

        .ri-mobile-grid dd {
            color: #111827;
            font-size: 13px;
            font-weight: 560;
            overflow-wrap: anywhere;
        }

        .ri-mobile-grid > div:first-child {
            grid-column: 1 / -1;
            border: 1px solid #d1fae5;
            border-radius: 10px;
            background: #ecfdf5;
            padding: 10px;
        }

        .ri-mobile-grid > div:first-child dd {
            color: #047857;
            font-size: 18px;
            font-weight: 620;
        }

        .ri-mobile-actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
        }

        .ri-mobile-actions :is(a, button, form) {
            width: 100%;
        }

        .ri-mobile-actions :is(a, button) {
            justify-content: center;
        }
    }

    /* Theme lock: Returns must read like the Customer register, not a
       separate one-off page. These final rules intentionally override the
       older ri-* treatment above without changing routes or form behavior. */
    .returns-index-page {
        --cust-gold: #b45309;
        --cust-gold-hover: #92400e;
        --cust-line-soft: #e2e8f0;
        --cust-line: #cbd5e1;
        --cust-ink: #1f2430;
        --cust-text: #4a4334;
        --cust-muted: #64748b;
        --cust-soft: #faf6ee;
        --cust-focus: rgba(245, 158, 11, .2);
        background: #f6f7f9;
    }

    .returns-index-page .returns-kpi-grid {
        display: grid !important;
        grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
        gap: 12px !important;
        margin-bottom: 16px !important;
        border: 0 !important;
        background: transparent !important;
        overflow: visible !important;
    }

    .returns-index-page .returns-kpi-card {
        border: 1px solid var(--cust-line-soft) !important;
        border-radius: 14px !important;
        background: #ffffff !important;
        padding: 14px 15px !important;
        animation: none !important;
        box-shadow: none !important;
        transform: none !important;
    }

    .returns-index-page .returns-kpi-card .ri-kpi-label {
        margin: 0 0 4px !important;
        color: var(--cust-muted) !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        letter-spacing: 0 !important;
        text-transform: none !important;
    }

    .returns-index-page .returns-kpi-card .ri-kpi-value {
        color: var(--cust-ink) !important;
        font-size: 21px !important;
        font-weight: 650 !important;
        letter-spacing: -0.2px !important;
        line-height: 1.1 !important;
    }

    .returns-index-page .returns-kpi-card .ri-kpi-value--accent {
        color: #047857 !important;
    }

    .returns-index-page .returns-kpi-card .ri-kpi-value--warn {
        color: #b45309 !important;
    }

    .returns-index-page .returns-table-card {
        border: 1px solid var(--cust-line-soft) !important;
        border-radius: 14px !important;
        background: #ffffff !important;
        animation: none !important;
        box-shadow: none !important;
        transform: none !important;
    }

    .returns-index-page .returns-register-head {
        align-items: flex-end;
        gap: 16px;
        padding: 16px 18px !important;
        border-bottom: 1px solid var(--cust-line-soft) !important;
        background: #ffffff !important;
    }

    .returns-index-page .returns-filter-shell {
        width: min(100%, 920px);
        border: 0 !important;
    }

    .returns-index-page .returns-filter-backdrop,
    .returns-index-page .returns-filter-sheet-head {
        display: none;
    }

    .returns-index-page .returns-register-toolbar {
        display: grid !important;
        grid-template-columns: minmax(130px, .75fr) minmax(145px, .8fr) minmax(145px, .8fr) minmax(220px, 1.1fr) auto !important;
        align-items: end;
        gap: 10px !important;
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
    }

    .returns-index-page .returns-register-toolbar .ri-filter-field,
    .returns-index-page .returns-register-toolbar .ri-filter-field--customer {
        width: auto;
        min-width: 0;
        flex: initial;
    }

    .returns-index-page .returns-register-toolbar .ri-filter-label {
        color: var(--cust-text) !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        letter-spacing: 0 !important;
        text-transform: none !important;
    }

    .returns-index-page .returns-register-toolbar .ri-filter-control {
        width: 100%;
        height: 40px;
        min-height: 40px;
        border: 1px solid var(--cust-line) !important;
        border-radius: 10px !important;
        background: #ffffff !important;
        color: var(--cust-ink) !important;
        font-size: 14px !important;
        font-weight: 400 !important;
        box-shadow: none !important;
    }

    .returns-index-page .returns-register-toolbar .ri-filter-control:focus {
        outline: none !important;
        border-color: var(--cust-gold) !important;
        box-shadow: 0 0 0 3px var(--cust-focus) !important;
    }

    .returns-index-page .ri-filter-actions {
        display: flex;
        align-items: flex-end;
        gap: 8px;
        margin-left: 0;
    }

    .returns-index-page .ri-filter-apply,
    .returns-index-page .ri-filter-clear {
        min-height: 40px;
        border-radius: 10px !important;
        font-size: 13px;
        font-weight: 600;
        box-shadow: none !important;
    }

    .returns-index-page .ri-filter-apply {
        border-color: var(--cust-gold) !important;
        background: var(--cust-gold) !important;
        color: #ffffff !important;
    }

    .returns-index-page .ri-filter-apply:hover {
        border-color: var(--cust-gold-hover) !important;
        background: var(--cust-gold-hover) !important;
    }

    .returns-index-page .ri-filter-clear {
        border-color: var(--cust-line) !important;
        background: #ffffff !important;
        color: var(--cust-text) !important;
    }

    .returns-index-page .returns-data-table thead th {
        background: #f8fafc !important;
        color: #475569 !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        letter-spacing: 0 !important;
        text-transform: none !important;
        border-bottom: 1px solid #e5e7eb !important;
    }

    .returns-index-page .returns-data-table tbody td {
        color: var(--cust-text);
        font-size: 15px;
        font-weight: 400;
        border-bottom: 1px solid #edf2f7 !important;
    }

    .returns-index-page .returns-data-table tbody tr:nth-child(even) {
        background: #f8fbff;
    }

    .returns-index-page .returns-data-table tbody tr:hover {
        background: #edf5ff !important;
    }

    .returns-index-page .returns-row-action--success {
        border-color: #bbf7d0 !important;
        background: #ecfdf5 !important;
        color: #047857 !important;
    }

    .returns-index-page .returns-row-action--danger {
        border-color: #fecaca !important;
        background: #fff1f2 !important;
        color: #be123c !important;
    }

    .returns-index-page .returns-mobile-status--warning {
        border-color: #fde68a;
        background: #fffbeb;
        color: #92400e;
    }

    .returns-index-page .returns-mobile-status--danger {
        border-color: #fecaca;
        background: #fff1f2;
        color: #be123c;
    }

    .returns-index-page .returns-mobile-status--neutral {
        border-color: var(--cust-line-soft);
        background: #f8fafc;
        color: #475569;
    }

    @media (max-width: 1180px) {
        .returns-index-page .returns-kpi-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }

        .returns-index-page .returns-register-head {
            align-items: stretch;
            flex-direction: column;
        }

        .returns-index-page .returns-filter-shell {
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        .returns-index-page {
            padding: 14px !important;
        }

        .returns-index-page .returns-kpi-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 8px !important;
        }

        .returns-index-page .returns-kpi-card {
            padding: 10px !important;
        }

        .returns-index-page .returns-kpi-card--amount {
            grid-column: 1 / -1;
            order: -1;
        }

        .returns-index-page .returns-kpi-card:not(.returns-kpi-card--amount) {
            min-height: 72px;
            padding: 9px 8px !important;
        }

        .returns-index-page .returns-kpi-card:not(.returns-kpi-card--amount) > .flex {
            align-items: flex-start;
            gap: 7px;
        }

        .returns-index-page .returns-kpi-card:not(.returns-kpi-card--amount) :is(.bg-amber-100, .bg-slate-100, .bg-emerald-100, .bg-green-100, .bg-rose-100) {
            width: 30px;
            height: 30px;
            flex-basis: 30px;
            border-radius: 9px !important;
        }

        .returns-index-page .returns-kpi-card:not(.returns-kpi-card--amount) :is(.bg-amber-100, .bg-slate-100, .bg-emerald-100, .bg-green-100, .bg-rose-100) svg {
            width: 14px !important;
            height: 14px !important;
        }

        .returns-index-page .returns-kpi-card:not(.returns-kpi-card--amount) .ri-kpi-label {
            display: -webkit-box;
            margin-bottom: 3px !important;
            font-size: 10.5px !important;
            line-height: 1.05 !important;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .returns-index-page .returns-kpi-card:not(.returns-kpi-card--amount) .ri-kpi-value {
            font-size: 18px !important;
            line-height: 1 !important;
        }

        .returns-index-page .returns-kpi-card .ri-kpi-value {
            font-size: clamp(18px, 5vw, 21px) !important;
        }

        .returns-index-page .returns-kpi-card--amount .ri-kpi-value {
            font-size: clamp(21px, 6vw, 24px) !important;
        }

        .returns-index-page .returns-register-head {
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center !important;
            gap: 10px;
            padding: 12px !important;
        }

        .returns-index-page .returns-register-head .customers-register-titleblock {
            min-width: 0;
        }

        .returns-index-page .returns-register-head .customers-register-titleblock h2 {
            font-size: 16px !important;
            line-height: 1.15;
        }

        .returns-index-page .returns-register-head .customers-register-titleblock p {
            margin-top: 3px !important;
            font-size: 12px !important;
        }

        .returns-index-page .returns-filter-shell {
            width: auto;
            justify-self: end;
        }

        .returns-index-page .returns-filter-toggle {
            display: flex;
            width: auto;
            min-height: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 11px;
            border: 1px solid var(--cust-line);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cust-text);
            white-space: nowrap;
        }

        .returns-index-page .returns-filter-toggle::after {
            content: none !important;
        }

        .returns-index-page .returns-filter-toggle .returns-filter-icon {
            flex: 0 0 auto;
            color: var(--cust-gold);
        }

        .returns-index-page .returns-filter-toggle span {
            display: inline-flex;
            min-width: 0;
            flex-direction: row;
            align-items: center;
            gap: 6px;
        }

        .returns-index-page .returns-filter-toggle strong {
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
        }

        .returns-index-page .returns-filter-toggle small {
            display: none;
        }

        .returns-index-page .returns-filter-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            border-radius: 999px;
            background: #fff7ed;
            color: var(--cust-gold);
            font-size: 11px;
            font-style: normal;
            font-weight: 650;
            line-height: 1;
        }

        .returns-index-page .returns-filter-backdrop {
            position: fixed;
            inset: 0;
            z-index: 70;
            display: block;
            background: rgba(15, 23, 42, 0.42);
        }

        .returns-index-page .returns-register-toolbar {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 80;
            display: grid !important;
            grid-template-columns: minmax(0, 1fr) !important;
            max-height: min(82dvh, 620px);
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 14px 14px calc(16px + env(safe-area-inset-bottom)) !important;
            border: 1px solid var(--cust-line) !important;
            border-bottom: 0 !important;
            border-radius: 18px 18px 0 0 !important;
            background: #ffffff !important;
            opacity: 0;
            pointer-events: none;
            transform: translateY(105%);
            transition: transform 180ms ease, opacity 180ms ease;
            visibility: hidden;
        }

        .returns-index-page .returns-register-toolbar.is-open {
            display: grid !important;
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
            visibility: visible;
        }

        .returns-index-page .returns-filter-sheet-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 4px;
        }

        .returns-index-page .returns-filter-sheet-head strong,
        .returns-index-page .returns-filter-sheet-head span {
            display: block;
        }

        .returns-index-page .returns-filter-sheet-head strong {
            color: var(--cust-ink);
            font-size: 16px;
            font-weight: 650;
            line-height: 1.2;
        }

        .returns-index-page .returns-filter-sheet-head span {
            margin-top: 3px;
            color: var(--cust-muted);
            font-size: 12px;
            font-weight: 500;
        }

        .returns-index-page .returns-filter-sheet-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border: 1px solid var(--cust-line);
            border-radius: 10px;
            background: #ffffff;
            color: var(--cust-text);
        }

        .returns-index-page .ri-filter-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            width: 100%;
        }

        .returns-index-page .customers-table-shell {
            display: none;
        }

        .returns-index-page .returns-mobile-cards {
            display: grid;
            gap: 10px;
            padding: 12px;
            border-top: 1px solid var(--cust-line-soft);
            background: #fbfcfd;
        }

        .returns-index-page .returns-mobile-cards .ri-mobile-grid > div:first-child {
            grid-column: auto;
            border: 1px solid var(--cust-line-soft);
            border-radius: 10px;
            background: #f8fafc;
            padding: 9px 10px;
        }

        .returns-index-page .returns-mobile-cards .ri-mobile-grid > div:first-child strong {
            color: var(--cust-ink);
            font-size: 13px;
            font-weight: 600;
        }

        .returns-index-page .returns-mobile-cards .ri-mobile-actions {
            display: flex;
            gap: 8px;
        }

        .returns-index-page .returns-mobile-cards .ri-mobile-actions :is(a, form) {
            width: auto;
            flex: 1 1 0;
        }

        .returns-index-page .returns-mobile-cards .ri-mobile-actions button {
            width: 100%;
            justify-content: center;
        }
    }
</style>

    <x-page-header class="customers-page-header returns-index-header" title="Returns" subtitle="Credit notes issued against customer returns">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="ri-back-btn">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
                <span>Back to Invoices</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page returns-index-page ri-page jf-skeleton-host is-loading"
         x-data="{ returnsFiltersOpen: false }"
         @keydown.escape.window="returnsFiltersOpen = false">
        <div class="ri-flow">

            {{-- Policy warning --}}
            <x-return-policy-banner />

            {{-- KPI strip: one bordered panel, hairline-divided --}}
            <div class="ri-kpi-strip customers-kpi-grid returns-kpi-grid">
                <div class="ri-kpi rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card returns-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v6a2 2 0 01-2 2h-4m-6 4l3 3m0 0l3-3m-3 3V14"/>
                            </svg>
                        </div>
                        <div>
                            <p class="ri-kpi-label text-[11px] uppercase tracking-[0.18em] text-slate-500">Total returns</p>
                            <p class="ri-kpi-value text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($returnOrders->total()) }}</p>
                        </div>
                    </div>
                </div>
                <div class="ri-kpi rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card returns-kpi-card returns-kpi-card--amount">
                    <div class="flex items-center gap-3">
                        <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 3h12M6 8h12M7 13h5a4 4 0 010 8H8m4-8L8 3"/>
                            </svg>
                        </div>
                        <div>
                            <p class="ri-kpi-label text-[11px] uppercase tracking-[0.18em] text-slate-500">Visible refunds</p>
                            <p class="ri-kpi-value ri-kpi-value--accent text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">₹{{ number_format($visibleRefundTotal, 2) }}</p>
                        </div>
                    </div>
                </div>
                <div class="ri-kpi rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card returns-kpi-card returns-kpi-card--amount">
                    <div class="flex items-center gap-3">
                        <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="ri-kpi-label text-[11px] uppercase tracking-[0.18em] text-slate-500">Today's refunds</p>
                            <p class="ri-kpi-value text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">₹{{ number_format($todayRefunds, 2) }}</p>
                        </div>
                    </div>
                </div>
                <div class="ri-kpi rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card returns-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="ri-kpi-label text-[11px] uppercase tracking-[0.18em] text-slate-500">Pending approval</p>
                            <p class="ri-kpi-value {{ $pendingApprovalCount > 0 ? 'ri-kpi-value--warn' : '' }} text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">
                                {{ number_format($pendingApprovalCount) }}
                                @if($pendingApprovalCount > 0)
                                    <a href="{{ route('returns.control-center') }}" class="ri-kpi-review">Review</a>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
                <div class="ri-kpi rounded-2xl border border-slate-200 bg-white p-4 shadow-sm customers-kpi-card returns-kpi-card">
                    <div class="flex items-center gap-3">
                        <div class="bg-rose-100 text-rose-700 rounded-xl p-2.5">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7M12 11v10"/>
                            </svg>
                        </div>
                        <div>
                            <p class="ri-kpi-label text-[11px] uppercase tracking-[0.18em] text-slate-500">Awaiting inspection</p>
                            <p class="ri-kpi-value {{ $pendingRestockCount > 0 ? 'ri-kpi-value--warn' : '' }} text-2xl font-semibold text-slate-900 jf-skel jf-skel-value tabular-nums">{{ number_format($pendingRestockCount) }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Filter + table card --}}
            <div class="ri-card customers-table-card returns-table-card">
                <div class="customers-table-card-header customers-register-head returns-register-head p-5 border-b border-slate-200">
                    <div class="customers-register-titleblock">
                        <h2 class="text-lg font-semibold text-slate-900">Return Orders</h2>
                        <p class="text-sm text-slate-500 mt-1">{{ number_format($returnOrders->total()) }} {{ $returnOrders->total() === 1 ? 'return' : 'returns' }}</p>
                    </div>

                    <div class="ri-filter-shell returns-filter-shell">
                    <button type="button"
                            class="ri-filter-toggle returns-filter-toggle"
                            @click="returnsFiltersOpen = true"
                            :aria-expanded="returnsFiltersOpen.toString()"
                            aria-controls="returns-filter-panel">
                        <svg class="returns-filter-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M7 12h10M10 18h4"/>
                        </svg>
                        <span>
                            <strong>Filters</strong>
                            <small>{{ $hasFilters ? $activeFilterCount . ' active' : 'Status, date, customer' }}</small>
                        </span>
                        @if($hasFilters)
                            <em class="returns-filter-count">{{ $activeFilterCount }}</em>
                        @endif
                    </button>

                    <div class="returns-filter-backdrop"
                         x-show="returnsFiltersOpen"
                         x-transition.opacity
                         x-cloak
                         @click="returnsFiltersOpen = false"
                         aria-hidden="true"></div>

                    <form method="GET"
                          action="{{ route('returns.index') }}"
                          id="returns-filter-panel"
                          class="ri-filter customers-register-toolbar returns-register-toolbar"
                          :class="{ 'is-open': returnsFiltersOpen }">
                        <div class="returns-filter-sheet-head">
                            <div>
                                <strong>Filters</strong>
                                <span>{{ $hasFilters ? $activeFilterCount . ' active filter' . ($activeFilterCount === 1 ? '' : 's') : 'Status, date, customer' }}</span>
                            </div>
                            <button type="button" class="returns-filter-sheet-close" @click="returnsFiltersOpen = false" aria-label="Close filters">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12M18 6L6 18"/>
                                </svg>
                            </button>
                        </div>
                        <div class="ri-filter-field ui-filter-field">
                            <label class="ri-filter-label">Status</label>
                            <select name="status" class="ri-filter-control">
                                <option value="">All</option>
                                <option value="settled"          {{ request('status') === 'settled'          ? 'selected' : '' }}>Settled</option>
                                <option value="pending_approval" {{ request('status') === 'pending_approval' ? 'selected' : '' }}>Pending Approval</option>
                                <option value="cancelled"        {{ request('status') === 'cancelled'        ? 'selected' : '' }}>Cancelled</option>
                            </select>
                        </div>
                        <div class="ri-filter-field ui-filter-field">
                            <label class="ri-filter-label">From</label>
                            <input type="date" name="from" value="{{ request('from') }}" class="ri-filter-control">
                        </div>
                        <div class="ri-filter-field ui-filter-field">
                            <label class="ri-filter-label">To</label>
                            <input type="date" name="to" value="{{ request('to') }}" class="ri-filter-control">
                        </div>
                        <div class="ri-filter-field ri-filter-field--customer ui-filter-field">
                            <label class="ri-filter-label">Customer</label>
                            <input type="text" name="customer" value="{{ request('customer') }}" placeholder="Name or mobile" class="ri-filter-control">
                        </div>
                        <div class="ri-filter-actions">
                            <button type="submit" class="ri-filter-apply">Apply</button>
                            @if($hasFilters)
                                <a href="{{ route('returns.index') }}" class="ri-filter-clear">Clear</a>
                            @endif
                        </div>
                    </form>
                    </div>
                </div>

                @if($returnOrders->isEmpty())
                    <div class="ri-empty">
                        <svg class="ri-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M9 14H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v6a2 2 0 01-2 2h-4m-6 4l3 3m0 0l3-3m-3 3V14"/>
                        </svg>
                        <h2 class="ri-empty-title">No returns yet</h2>
                        <p class="ri-empty-copy">When a customer return is processed, the credit note appears here. Open the original invoice and use Cancel via Reversal to start a return.</p>
                    </div>
                @else
                    {{-- Desktop table --}}
                    <div class="ri-table-wrap customers-table-shell returns-table-shell">
                        <table class="ri-table customers-data-table returns-data-table">
                            <thead>
                                <tr>
                                    <th>Credit Note</th>
                                    <th>Original Invoice</th>
                                    <th>Customer</th>
                                    <th class="text-right">Refund</th>
                                    <th class="text-center">Lines</th>
                                    <th>Status</th>
                                    <th>Settled</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($returnOrders as $ro)
                                    @php
                                        $cn = $ro->creditNote;
                                        $customer = $ro->customer;
                                        $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
                                        $tone = $statusTone($ro->status);
                                    @endphp
                                    <tr>
                                        <td>
                                            @if($cn)
                                                <span class="ri-cn-number">{{ $cn->credit_note_number }}</span>
                                                <span class="ri-cell-sub">{{ optional($cn->issued_at)->format('d M Y') }}</span>
                                            @else
                                                <span class="ri-muted">No credit note yet</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($ro->invoice)
                                                <a class="ri-invoice-link" href="{{ route('invoices.show', $ro->invoice) }}">{{ $ro->invoice->invoice_number }}</a>
                                                <span class="ri-cell-sub">₹{{ number_format((float) $ro->invoice->total, 2) }}</span>
                                            @else
                                                <span class="ri-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($customerName)
                                                <span class="ri-customer-name">{{ $customerName }}</span>
                                                <span class="ri-cell-sub">{{ $customer->mobile ?: '' }}</span>
                                            @else
                                                <span class="ri-muted">Walk-in</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if($cn)
                                                <span class="ri-refund-amount">₹{{ number_format((float) $cn->total, 2) }}</span>
                                            @else
                                                <span class="ri-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ $ro->lineItems->count() }}</td>
                                        <td>
                                            <span class="ri-status ri-status--{{ $tone }}">{{ $statusLabel($ro->status) }}</span>
                                        </td>
                                        <td>
                                            <span>{{ optional($ro->settled_at)->format('d M Y') ?? '-' }}</span>
                                            <span class="ri-cell-sub">{{ $ro->settledBy?->name ?? $ro->createdBy?->name ?? '' }}</span>
                                        </td>
                                        <td class="text-right">
                                            <div class="ri-row-actions">
                                                <a href="{{ route('returns.show', $ro) }}" class="ri-btn-view customers-row-action customers-row-action--primary">View</a>
                                                @if($ro->status === \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)
                                                    @can('returns.approve')
                                                        <form method="POST" action="{{ route('returns.approve', $ro) }}"
                                                              onsubmit="return confirm('Approve this return and issue the credit note?')">
                                                            @csrf
                                                            <button type="submit" class="ri-btn-approve customers-row-action returns-row-action--success">Approve</button>
                                                        </form>
                                                        <a href="{{ route('returns.show', $ro) }}" class="ri-btn-reject customers-row-action returns-row-action--danger">Reject</a>
                                                    @endcan
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile card list --}}
                    <div class="ri-mobile-list customers-mobile-cards returns-mobile-cards">
                        @foreach($returnOrders as $ro)
                            @php
                                $cn = $ro->creditNote;
                                $customer = $ro->customer;
                                $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in';
                                $mobileInitials = $customer
                                    ? trim(strtoupper(substr($customer->first_name ?? '', 0, 1) . substr($customer->last_name ?? '', 0, 1)))
                                    : 'WI';
                                $mobileInitials = $mobileInitials !== '' ? $mobileInitials : 'RT';
                                $tone = $statusTone($ro->status);
                            @endphp
                            <article class="ri-mobile-card customers-mobile-card returns-mobile-card">
                                <div class="ri-mobile-head customers-mobile-card__top">
                                    <div class="customers-mobile-card__identity">
                                        <span class="customers-mobile-avatar">{{ $mobileInitials }}</span>
                                        <div>
                                            <span class="ri-cn-number customers-mobile-card__title">{{ $cn?->credit_note_number ?? 'Pending' }}</span>
                                            <span class="ri-cell-sub customers-mobile-card__sub">{{ $cn?->issued_at?->format('d M Y') ?? 'Not issued yet' }}</span>
                                        </div>
                                    </div>
                                    <span class="ri-status ri-status--{{ $tone }} customers-mobile-pill returns-mobile-status returns-mobile-status--{{ $tone }}">{{ $statusLabel($ro->status) }}</span>
                                </div>

                                <dl class="ri-mobile-grid customers-mobile-card__metrics">
                                    <div>
                                        <span>Refund</span>
                                        <strong>{{ $cn ? '₹' . number_format((float) $cn->total, 2) : '-' }}</strong>
                                    </div>
                                    <div>
                                        <span>Lines</span>
                                        <strong>{{ $ro->lineItems->count() }}</strong>
                                    </div>
                                    <div>
                                        <span>Invoice</span>
                                        <strong>
                                            @if($ro->invoice)
                                                <a href="{{ route('invoices.show', $ro->invoice) }}" class="ri-mobile-invoice-link">{{ $ro->invoice->invoice_number }}</a>
                                            @else
                                                -
                                            @endif
                                        </strong>
                                    </div>
                                    <div>
                                        <span>Customer</span>
                                        <strong>{{ $customerName }}</strong>
                                    </div>
                                </dl>

                                <div class="ri-mobile-actions customers-mobile-card__actions">
                                    <a href="{{ route('returns.show', $ro) }}" class="ri-btn-view customers-row-action customers-row-action--primary">View Return</a>
                                    @if($ro->status === \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)
                                        @can('returns.approve')
                                            <form method="POST" action="{{ route('returns.approve', $ro) }}"
                                                  onsubmit="return confirm('Approve this return and issue the credit note?')">
                                                @csrf
                                                <button type="submit" class="ri-btn-approve customers-row-action returns-row-action--success">Approve</button>
                                            </form>
                                            <a href="{{ route('returns.show', $ro) }}" class="ri-btn-reject customers-row-action returns-row-action--danger">Reject</a>
                                        @endcan
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if($returnOrders->hasPages())
                        <div class="ri-pagination">
                            {{ $returnOrders->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
