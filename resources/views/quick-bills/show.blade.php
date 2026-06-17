@php
    $statusMeta = match ($quickBill->status) {
        \App\Models\QuickBill::STATUS_ISSUED => ['class' => 'is-issued', 'label' => 'Issued'],
        \App\Models\QuickBill::STATUS_VOID => ['class' => 'is-void', 'label' => 'Void'],
        default => ['class' => 'is-draft', 'label' => 'Draft'],
    };
    $customerName = $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in customer');
    $customerMobile = $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: 'No mobile');
    $customerAddress = $quickBill->customer_address ?: ($quickBill->customer?->address ?: 'No address');
    $canEditQuickBill = $quickBill->status !== \App\Models\QuickBill::STATUS_VOID;
@endphp

<x-app-layout>
    <x-page-header class="qb-detail-header">
        <div>
            <h1 class="page-title">{{ $quickBill->bill_number }}</h1>
            <p class="text-sm text-gray-600 mt-1">{{ $customerName }} - {{ $quickBill->bill_date?->format('d M Y') }}</p>
        </div>
        <div class="page-actions qb-detail-header-actions">
            <a href="{{ route('quick-bills.index') }}" class="qb-header-action qb-header-action-secondary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H5"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 19-7-7 7-7"/>
                </svg>
                <span>Back</span>
            </a>
            <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="qb-header-action qb-header-action-primary qb-print-action">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 14h12v7H6z"/>
                </svg>
                <span>Print</span>
            </a>
            @if($canEditQuickBill)
                <a href="{{ route('quick-bills.edit', $quickBill) }}" class="qb-header-action qb-header-action-edit qb-edit-action">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                    </svg>
                    <span>Edit</span>
                </a>
            @endif
        </div>
    </x-page-header>

    <style>
        :where(.qb-detail-header, .qb-detail-page, .qb-detail-mobile-fab) {
            --qb-bg: #f6f7f9;
            --qb-surface: #ffffff;
            --qb-soft: #faf6ee;
            --qb-line: #cbd5e1;
            --qb-line-soft: #e2e8f0;
            --qb-ink: #1f2430;
            --qb-text: #4a4334;
            --qb-muted: #64748b;
            /* Primary accent is the JewelFlow gold, not generic navy. */
            --qb-dark: #b45309;
            --qb-dark-hover: #92400e;
            --qb-blue: #b45309;
            --qb-green: #047857;
            --qb-amber: #b45309;
            --qb-red: #b42318;
            --qb-focus: rgba(245, 158, 11, .2);
            --qb-ease: cubic-bezier(.23, 1, .32, 1);
        }

        .qb-detail-page {
            width: 100%;
            max-width: none;
            color: var(--qb-ink);
        }

        .qb-detail-page *,
        .qb-detail-page *::before,
        .qb-detail-page *::after {
            box-sizing: border-box;
        }

        .qb-detail-header .page-actions > .qb-header-action,
        .qb-detail-header .page-actions > .qb-header-action:hover {
            box-shadow: none !important;
            transform: none !important;
        }

        .qb-detail-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .qb-header-action,
        .qb-detail-btn {
            -webkit-tap-highlight-color: transparent;
        }

        .qb-header-action {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 14px;
            border: 1px solid var(--qb-line);
            border-radius: 10px;
            background: #fff;
            color: var(--qb-ink);
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            box-shadow: none !important;
            transition: background .16s ease, border-color .16s ease, color .16s ease, transform .12s var(--qb-ease);
        }

        .qb-detail-header .page-actions > a.qb-header-action-secondary:not([class*="btn-"]) {
            border-color: var(--qb-line) !important;
            background-color: #fff !important;
            background-image: none !important;
            color: var(--qb-ink) !important;
            box-shadow: none !important;
        }

        .qb-header-action svg {
            width: 15px;
            height: 15px;
            flex: 0 0 auto;
        }

        .qb-detail-header .page-actions > a.qb-header-action-primary:not([class*="btn-"]) {
            border-color: var(--qb-dark) !important;
            background-color: var(--qb-dark) !important;
            background-image: none !important;
            color: #fff !important;
            box-shadow: none !important;
        }

        .qb-detail-header .page-actions > a.qb-header-action-edit:not([class*="btn-"]) {
            border-color: #fed7aa !important;
            background-color: #fff7ed !important;
            background-image: none !important;
            color: #9a3412 !important;
            box-shadow: none !important;
        }

        @media (hover: hover) and (pointer: fine) {
            .qb-header-action:hover,
            .qb-detail-btn:hover {
                background-color: var(--qb-soft) !important;
                background-image: none !important;
                border-color: var(--qb-line);
                color: var(--qb-ink);
            }

            .qb-detail-header .page-actions > a.qb-header-action-secondary:not([class*="btn-"]):hover {
                border-color: var(--qb-line) !important;
                background-color: var(--qb-soft) !important;
                background-image: none !important;
                color: var(--qb-ink) !important;
                box-shadow: none !important;
            }

            .qb-detail-header .page-actions > a.qb-header-action-primary:not([class*="btn-"]):hover {
                background-color: var(--qb-dark-hover) !important;
                background-image: none !important;
                border-color: var(--qb-dark-hover) !important;
                color: #fff !important;
                box-shadow: none !important;
            }

            .qb-detail-header .page-actions > a.qb-header-action-edit:not([class*="btn-"]):hover {
                background-color: #ffedd5 !important;
                background-image: none !important;
                border-color: #fdba74 !important;
                color: #9a3412 !important;
                box-shadow: none !important;
            }
        }

        .qb-header-action:active,
        .qb-detail-btn:active {
            transform: scale(.98);
        }

        .qb-header-action:focus-visible,
        .qb-detail-btn:focus-visible,
        .qb-detail-page textarea:focus-visible {
            outline: 3px solid var(--qb-focus);
            outline-offset: 2px;
        }

        .qb-detail-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(300px, 360px);
            gap: 16px;
            align-items: start;
        }

        .qb-detail-main,
        .qb-detail-rail {
            min-width: 0;
            display: grid;
            gap: 16px;
        }

        .qb-detail-rail {
            position: sticky;
            top: 18px;
        }

        .qb-detail-card {
            min-width: 0;
            overflow: hidden;
            border: 1px solid var(--qb-line-soft);
            border-radius: 14px;
            background: var(--qb-surface);
        }

        .qb-detail-card.is-danger {
            border-color: #fecaca;
            background: #fff7f7;
        }

        .qb-detail-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--qb-line-soft);
            background: #fbfcfd;
        }

        .qb-detail-card-title {
            margin: 0;
            color: var(--qb-ink);
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.3px;
            line-height: 1.25;
        }

        .qb-detail-card-meta {
            color: var(--qb-muted);
            font-size: 13px;
            font-weight: 400;
            line-height: 1.35;
        }

        .qb-customer-panel {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            padding: 18px;
        }

        .qb-customer-name-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .qb-customer-name {
            margin: 0;
            color: var(--qb-ink);
            font-size: 22px;
            font-weight: 600;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .qb-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 5px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
        }

        .qb-status.is-issued {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: var(--qb-green);
        }

        .qb-status.is-draft {
            border-color: #fed7aa;
            background: #fff7ed;
            color: var(--qb-amber);
        }

        .qb-status.is-void {
            border-color: #fecaca;
            background: #fef2f2;
            color: var(--qb-red);
        }

        .qb-customer-lines {
            display: grid;
            gap: 4px;
            margin-top: 9px;
            color: var(--qb-muted);
            font-size: 14px;
            line-height: 1.4;
        }

        .qb-customer-lines span {
            overflow-wrap: anywhere;
        }

        .qb-fact-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(112px, 1fr));
            gap: 8px;
            min-width: min(460px, 100%);
        }

        .qb-fact {
            min-width: 0;
            display: grid;
            gap: 4px;
            padding: 11px 12px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 11px;
            background: #f8fafc;
        }

        .qb-fact-label {
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .qb-fact-value {
            color: var(--qb-ink);
            font-size: 16px;
            font-weight: 600;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .qb-fact-value.is-due {
            color: var(--qb-amber);
        }

        .qb-fact-value.is-clear {
            color: var(--qb-green);
        }

        .qb-items-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .qb-items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .qb-items-table th {
            padding: 12px 18px;
            border-bottom: 1px solid var(--qb-line-soft);
            background: #f8fafc;
            color: var(--qb-text);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            text-align: left;
            white-space: nowrap;
        }

        .qb-items-table th.is-right,
        .qb-items-table td.is-right {
            text-align: right;
        }

        .qb-items-table td {
            padding: 15px 18px;
            border-bottom: 1px solid #edf2f7;
            color: var(--qb-text);
            font-size: 14px;
            font-weight: 400;
            line-height: 1.35;
            vertical-align: middle;
        }

        .qb-items-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .qb-item-title {
            color: var(--qb-ink);
            font-size: 15px;
            font-weight: 500;
            line-height: 1.25;
        }

        .qb-item-sub {
            margin-top: 4px;
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 400;
            line-height: 1.25;
        }

        .qb-money {
            color: var(--qb-ink);
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .qb-item-cards {
            display: none;
            padding: 12px;
            background: var(--qb-bg);
        }

        .qb-item-card {
            border: 1px solid var(--qb-line-soft);
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
        }

        .qb-item-card + .qb-item-card {
            margin-top: 10px;
        }

        .qb-item-card-main {
            padding: 13px 14px;
        }

        .qb-item-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--qb-line-soft);
        }

        .qb-item-card-total {
            flex: 0 0 auto;
            text-align: right;
        }

        .qb-item-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 12px;
        }

        .qb-item-metric {
            min-width: 0;
            padding: 10px 11px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 10px;
            background: #f8fafc;
        }

        .qb-item-metric-label {
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .qb-item-metric-value {
            margin-top: 3px;
            color: var(--qb-ink);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .qb-notes-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            padding: 14px;
        }

        .qb-note-block {
            min-width: 0;
            min-height: 110px;
            padding: 13px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 12px;
            background: #f8fafc;
        }

        .qb-note-label {
            color: var(--qb-text);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
        }

        .qb-note-text {
            margin-top: 8px;
            color: var(--qb-text);
            font-size: 14px;
            line-height: 1.55;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .qb-summary-list {
            display: grid;
            gap: 0;
            padding: 8px 18px 0;
        }

        .qb-summary-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 14px;
            padding: 10px 0;
            border-bottom: 1px solid #edf2f7;
            color: var(--qb-text);
            font-size: 14px;
            line-height: 1.3;
        }

        .qb-summary-row:last-child {
            border-bottom: 0;
        }

        .qb-summary-row span:first-child {
            color: var(--qb-muted);
        }

        .qb-summary-row strong {
            color: var(--qb-ink);
            font-weight: 600;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .qb-summary-total {
            margin: 14px 18px 18px;
            padding: 14px;
            border: 1px solid var(--qb-dark);
            border-radius: 12px;
            background: var(--qb-dark);
            color: #fff;
        }

        .qb-summary-total-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }

        .qb-summary-total-label {
            font-size: 13px;
            font-weight: 500;
            color: #fde6c4;
        }

        .qb-summary-total-value {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.15;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }

        .qb-paid-due {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin: 12px 18px 18px;
        }

        .qb-paid-due-item {
            min-width: 0;
            padding: 11px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 11px;
            background: #f8fafc;
        }

        .qb-paid-due-label {
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 500;
        }

        .qb-paid-due-value {
            margin-top: 4px;
            color: var(--qb-ink);
            font-size: 15px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        .qb-paid-due-value.is-clear {
            color: var(--qb-green);
        }

        .qb-paid-due-value.is-due {
            color: var(--qb-amber);
        }

        .qb-payment-list {
            display: grid;
            gap: 10px;
            padding: 14px;
        }

        .qb-payment-item {
            display: grid;
            gap: 4px;
            padding: 12px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 12px;
            background: #f8fafc;
        }

        .qb-payment-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }

        .qb-payment-mode {
            color: var(--qb-ink);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .qb-payment-ref {
            color: var(--qb-muted);
            font-size: 12px;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .qb-empty-row {
            padding: 14px;
            border: 1px dashed var(--qb-line);
            border-radius: 12px;
            background: #f8fafc;
            color: var(--qb-muted);
            font-size: 14px;
            line-height: 1.45;
        }

        .qb-danger-body {
            padding: 16px 18px 18px;
        }

        .qb-danger-title {
            margin: 0;
            color: #9f1239;
            font-size: 16px;
            font-weight: 600;
            line-height: 1.25;
        }

        .qb-danger-copy {
            margin: 5px 0 0;
            color: #9f1239;
            font-size: 14px;
            line-height: 1.45;
        }

        .qb-detail-page textarea {
            width: 100%;
            margin-top: 12px;
            min-height: 96px;
            resize: vertical;
            border: 1px solid #fecaca;
            border-radius: 12px;
            background: #fff;
            color: var(--qb-ink);
            padding: 11px 12px;
            font: inherit;
            font-size: 14px;
            line-height: 1.45;
            box-shadow: none;
        }

        .qb-detail-page textarea::placeholder {
            color: #64748b;
        }

        .qb-detail-page textarea:focus {
            outline: none;
            border-color: #be123c;
        }

        .qb-detail-btn {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid transparent;
            border-radius: 10px;
            padding: 0 14px;
            background: var(--qb-dark);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            cursor: pointer;
            box-shadow: none;
            transition: background .16s ease, border-color .16s ease, transform .12s var(--qb-ease);
        }

        .qb-detail-btn.is-danger {
            margin-top: 10px;
            background: #be123c;
            color: #fff;
        }

        @media (hover: hover) and (pointer: fine) {
            .qb-detail-btn.is-danger:hover {
                background: #9f1239;
            }
        }

        @media (max-width: 1180px) {
            .qb-detail-layout {
                grid-template-columns: minmax(0, 1fr);
            }

            .qb-detail-rail {
                position: static;
            }
        }

        @media (max-width: 860px) {
            .qb-detail-header {
                display: grid;
                grid-template-columns: 40px minmax(0, 1fr) 42px;
                align-items: center;
                column-gap: 8px;
            }

            .qb-detail-header .content-header-nav {
                grid-column: 1;
                grid-row: 1;
                margin-right: 0;
                padding-top: 0;
            }

            .qb-detail-header > :nth-child(2) {
                grid-column: 2;
                grid-row: 1;
                min-width: 0;
                text-align: center;
            }

            .qb-detail-header > :nth-child(2) .page-title {
                margin: 0;
                font-size: 17px;
            }

            .qb-detail-header > :nth-child(2) p {
                display: none;
            }

            .qb-detail-header .page-actions {
                grid-column: 3;
                grid-row: 1;
                width: auto;
                justify-content: flex-end;
                margin-left: 0;
            }

            .qb-detail-header .qb-header-action {
                width: 40px;
                min-height: 38px;
                padding: 0;
            }

            .qb-detail-header .qb-header-action span {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            .qb-detail-header .qb-print-action,
            .qb-detail-header .qb-edit-action {
                display: none !important;
            }

            .qb-detail-page {
                margin-left: -4px;
                margin-right: -4px;
            }

            .qb-detail-layout,
            .qb-detail-main,
            .qb-detail-rail {
                gap: 12px;
            }

            .qb-detail-card {
                border-radius: 12px;
            }

            .qb-detail-card-head {
                padding: 14px;
            }

            .qb-customer-panel {
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
                padding: 14px;
            }

            .qb-customer-name {
                font-size: 19px;
            }

            .qb-fact-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                min-width: 0;
            }

            .qb-fact:last-child:nth-child(odd) {
                grid-column: 1 / -1;
            }

            .qb-items-table-wrap {
                display: none;
            }

            .qb-item-cards {
                display: block;
            }

            .qb-notes-grid {
                grid-template-columns: 1fr;
                padding: 12px;
            }

            .qb-note-block {
                min-height: 96px;
            }

            .qb-summary-list {
                padding-left: 14px;
                padding-right: 14px;
            }

            .qb-summary-total {
                margin-left: 14px;
                margin-right: 14px;
            }

            .qb-paid-due {
                margin-left: 14px;
                margin-right: 14px;
            }

            .qb-payment-list {
                padding: 12px;
            }

            .qb-danger-body {
                padding: 14px;
            }

        }

        @media (max-width: 430px) {
            .qb-fact-strip {
                grid-template-columns: 1fr;
            }

            .qb-item-card-top {
                display: grid;
                grid-template-columns: 1fr;
            }

            .qb-item-card-total {
                text-align: left;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .qb-header-action,
            .qb-detail-btn {
                transition: none;
            }
        }
    </style>

    <div x-data="{ quickBillFabOpen: false }" class="invoice-emi-mobile-fab qb-detail-mobile-fab">
        <div class="invoice-emi-mobile-fab-shell" x-bind:class="{ 'is-open': quickBillFabOpen }" @click.outside="quickBillFabOpen = false">
            <nav class="invoice-emi-mobile-fab-nav" aria-label="Quick bill actions">
                <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="invoice-emi-mobile-fab-link" @click="quickBillFabOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 14h12v7H6z"/>
                    </svg>
                    <span>Print</span>
                </a>
                @if($canEditQuickBill)
                    <a href="{{ route('quick-bills.edit', $quickBill) }}" class="invoice-emi-mobile-fab-link" @click="quickBillFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                        </svg>
                        <span>Edit</span>
                    </a>
                @endif
            </nav>
            <button type="button" class="invoice-emi-mobile-fab-toggle" x-on:click="quickBillFabOpen = !quickBillFabOpen" x-bind:aria-expanded="quickBillFabOpen.toString()" aria-label="Toggle quick bill actions">
                <span class="invoice-emi-mobile-fab-bars" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
    </div>

    <div class="content-inner qb-detail-page">
        <div class="qb-detail-layout">
            <main class="qb-detail-main">
                <section class="qb-detail-card" aria-label="Quick bill customer and totals">
                    <div class="qb-customer-panel">
                        <div>
                            <div class="qb-customer-name-row">
                                <h2 class="qb-customer-name">{{ $customerName }}</h2>
                                <span class="qb-status {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span>
                            </div>
                            <div class="qb-customer-lines">
                                <span>{{ $customerMobile }}</span>
                                <span>{{ $customerAddress }}</span>
                            </div>
                        </div>

                        <div class="qb-fact-strip" aria-label="Bill facts">
                            <div class="qb-fact">
                                <span class="qb-fact-label">Bill date</span>
                                <span class="qb-fact-value">{{ $quickBill->bill_date?->format('d M Y') }}</span>
                            </div>
                            <div class="qb-fact">
                                <span class="qb-fact-label">Total</span>
                                <span class="qb-fact-value">₹{{ number_format((float) $quickBill->total_amount, 2) }}</span>
                            </div>
                            <div class="qb-fact">
                                <span class="qb-fact-label">Due</span>
                                <span class="qb-fact-value {{ (float) $quickBill->due_amount > 0 ? 'is-due' : 'is-clear' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="qb-detail-card" aria-label="Bill items">
                    <div class="qb-detail-card-head">
                        <div>
                            <h2 class="qb-detail-card-title">Bill items</h2>
                            <div class="qb-detail-card-meta">{{ $quickBill->items->count() }} {{ $quickBill->items->count() === 1 ? 'line' : 'lines' }}</div>
                        </div>
                    </div>

                    <div class="qb-items-table-wrap">
                        <table class="qb-items-table">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Metal</th>
                                    <th>Purity</th>
                                    <th>Pcs</th>
                                    <th>Gross</th>
                                    <th>Net</th>
                                    <th>Rate</th>
                                    <th class="is-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($quickBill->items as $item)
                                    @php
                                        $extraCharges = [
                                            'Making' => (float) ($item->making_charge ?? 0),
                                            'Stone' => (float) ($item->stone_charge ?? 0),
                                            'Hallmark' => (float) ($item->hallmark_charge ?? 0),
                                            'Rhodium' => (float) ($item->rhodium_charge ?? 0),
                                            'Other' => (float) ($item->other_charge ?? 0),
                                        ];
                                        $visibleChargeParts = collect($extraCharges)
                                            ->filter(fn ($value) => $value > 0)
                                            ->map(fn ($value, $label) => $label . ': ₹' . number_format($value, 2))
                                            ->values()
                                            ->all();
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="qb-item-title">{{ $item->description ?: 'Untitled item' }}</div>
                                            <div class="qb-item-sub">HSN {{ $item->hsn_code ?: 'Not set' }}</div>
                                            @if(!empty($visibleChargeParts))
                                                <div class="qb-item-sub">{{ implode(', ', $visibleChargeParts) }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $item->metal_type ?: 'Not set' }}</td>
                                        <td>{{ $item->purity ?: 'Not set' }}</td>
                                        <td>{{ $item->pcs }}</td>
                                        <td>{{ number_format((float) $item->gross_weight, 3) }}</td>
                                        <td>{{ number_format((float) $item->net_weight, 3) }}</td>
                                        <td>₹{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="is-right"><span class="qb-money">₹{{ number_format((float) $item->line_total, 2) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="qb-item-cards">
                        @foreach($quickBill->items as $item)
                            @php
                                $extraCharges = [
                                    'Making' => (float) ($item->making_charge ?? 0),
                                    'Stone' => (float) ($item->stone_charge ?? 0),
                                    'Hallmark' => (float) ($item->hallmark_charge ?? 0),
                                    'Rhodium' => (float) ($item->rhodium_charge ?? 0),
                                    'Other' => (float) ($item->other_charge ?? 0),
                                ];
                                $visibleChargeParts = collect($extraCharges)
                                    ->filter(fn ($value) => $value > 0)
                                    ->map(fn ($value, $label) => $label . ': ₹' . number_format($value, 2))
                                    ->values()
                                    ->all();
                            @endphp
                            <article class="qb-item-card">
                                <div class="qb-item-card-main">
                                    <div class="qb-item-card-top">
                                        <div>
                                            <div class="qb-item-title">{{ $item->description ?: 'Untitled item' }}</div>
                                            <div class="qb-item-sub">{{ $item->metal_type ?: 'Not set' }} / {{ $item->purity ?: 'Not set' }} / HSN {{ $item->hsn_code ?: 'Not set' }}</div>
                                        </div>
                                        <div class="qb-item-card-total">
                                            <div class="qb-item-metric-label">Line total</div>
                                            <div class="qb-item-metric-value">₹{{ number_format((float) $item->line_total, 2) }}</div>
                                        </div>
                                    </div>

                                    <div class="qb-item-metrics">
                                        <div class="qb-item-metric">
                                            <div class="qb-item-metric-label">Pcs</div>
                                            <div class="qb-item-metric-value">{{ $item->pcs }}</div>
                                        </div>
                                        <div class="qb-item-metric">
                                            <div class="qb-item-metric-label">Gross</div>
                                            <div class="qb-item-metric-value">{{ number_format((float) $item->gross_weight, 3) }}</div>
                                        </div>
                                        <div class="qb-item-metric">
                                            <div class="qb-item-metric-label">Net</div>
                                            <div class="qb-item-metric-value">{{ number_format((float) $item->net_weight, 3) }}</div>
                                        </div>
                                        <div class="qb-item-metric">
                                            <div class="qb-item-metric-label">Rate</div>
                                            <div class="qb-item-metric-value">₹{{ number_format((float) $item->rate, 2) }}</div>
                                        </div>
                                    </div>

                                    @if(!empty($visibleChargeParts))
                                        <div class="qb-item-sub" style="margin-top: 10px;">{{ implode(', ', $visibleChargeParts) }}</div>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="qb-detail-card" aria-label="Notes and terms">
                    <div class="qb-detail-card-head">
                        <h2 class="qb-detail-card-title">Notes and terms</h2>
                    </div>
                    <div class="qb-notes-grid">
                        <div class="qb-note-block">
                            <div class="qb-note-label">Notes</div>
                            <div class="qb-note-text">{{ $quickBill->notes ?: 'No notes added.' }}</div>
                        </div>
                        <div class="qb-note-block">
                            <div class="qb-note-label">Terms</div>
                            <div class="qb-note-text">{{ $quickBill->terms ?: 'No terms added.' }}</div>
                        </div>
                    </div>
                </section>
            </main>

            <aside class="qb-detail-rail" aria-label="Quick bill summary">
                <section class="qb-detail-card">
                    <div class="qb-detail-card-head">
                        <h2 class="qb-detail-card-title">Bill summary</h2>
                    </div>

                    <div class="qb-summary-list">
                        <div class="qb-summary-row"><span>Pricing mode</span><strong>{{ ucwords(str_replace('_', ' ', $quickBill->pricing_mode)) }}</strong></div>
                        <div class="qb-summary-row"><span>GST rate</span><strong>{{ number_format((float) $quickBill->gst_rate, 2) }}%</strong></div>
                        <div class="qb-summary-row"><span>Subtotal</span><strong>₹{{ number_format((float) $quickBill->subtotal, 2) }}</strong></div>
                        <div class="qb-summary-row"><span>Discount</span><strong>- ₹{{ number_format((float) $quickBill->discount_amount, 2) }}</strong></div>
                        <div class="qb-summary-row"><span>Taxable</span><strong>₹{{ number_format((float) $quickBill->taxable_amount, 2) }}</strong></div>
                        <div class="qb-summary-row"><span>CGST</span><strong>₹{{ number_format((float) $quickBill->cgst_amount, 2) }}</strong></div>
                        <div class="qb-summary-row"><span>SGST</span><strong>₹{{ number_format((float) $quickBill->sgst_amount, 2) }}</strong></div>
                        <div class="qb-summary-row"><span>Round off</span><strong>{{ $quickBill->round_off >= 0 ? '+' : '' }}₹{{ number_format((float) $quickBill->round_off, 2) }}</strong></div>
                    </div>

                    <div class="qb-paid-due">
                        <div class="qb-paid-due-item">
                            <div class="qb-paid-due-label">Paid</div>
                            <div class="qb-paid-due-value">₹{{ number_format((float) $quickBill->paid_amount, 2) }}</div>
                        </div>
                        <div class="qb-paid-due-item">
                            <div class="qb-paid-due-label">Due</div>
                            <div class="qb-paid-due-value {{ (float) $quickBill->due_amount > 0 ? 'is-due' : 'is-clear' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</div>
                        </div>
                    </div>

                    <div class="qb-summary-total">
                        <div class="qb-summary-total-row">
                            <span class="qb-summary-total-label">Grand total</span>
                            <span class="qb-summary-total-value">₹{{ number_format((float) $quickBill->total_amount, 2) }}</span>
                        </div>
                    </div>
                </section>

                <section class="qb-detail-card">
                    <div class="qb-detail-card-head">
                        <h2 class="qb-detail-card-title">Payments</h2>
                    </div>
                    <div class="qb-payment-list">
                        @forelse($quickBill->payments as $payment)
                            <div class="qb-payment-item">
                                <div class="qb-payment-head">
                                    <span class="qb-payment-mode">{{ $payment->payment_mode }}</span>
                                    <span class="qb-money">₹{{ number_format((float) $payment->amount, 2) }}</span>
                                </div>
                                <div class="qb-payment-ref">{{ $payment->reference_no ?: 'No reference' }}</div>
                            </div>
                        @empty
                            <div class="qb-empty-row">No payment rows recorded for this quick bill.</div>
                        @endforelse
                    </div>
                </section>

                @if($quickBill->status !== \App\Models\QuickBill::STATUS_VOID)
                    @can('sales.void')
                        <section class="qb-detail-card is-danger">
                            <div class="qb-danger-body">
                                <h2 class="qb-danger-title">Void quick bill</h2>
                                <p class="qb-danger-copy">Voiding keeps the record but marks it unusable.</p>
                                <form method="POST" action="{{ route('quick-bills.void', $quickBill) }}" data-confirm-message="Void this quick bill?">
                                    @csrf
                                    <textarea name="void_reason" rows="3" placeholder="Reason for voiding"></textarea>
                                    <button type="submit" class="qb-detail-btn is-danger">Void quick bill</button>
                                </form>
                            </div>
                        </section>
                    @endcan
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>
