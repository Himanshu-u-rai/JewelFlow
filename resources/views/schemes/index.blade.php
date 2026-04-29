@php
    $hasFilters = request()->hasAny(['search', 'type']);
    $schemeTotal = method_exists($schemes, 'total') ? $schemes->total() : $schemes->count();
    $typeColors = [
        'gold_savings' => 'schemes-type-badge--gold',
        'festival_sale' => 'schemes-type-badge--festival',
        'discount_offer' => 'schemes-type-badge--discount',
    ];
    $typeLabels = [
        'gold_savings' => 'Gold Savings',
        'festival_sale' => 'Festival Sale',
        'discount_offer' => 'Discount Offer',
    ];
@endphp

<x-app-layout>
    <style>
        .schemes-index-page {
            --schemes-border: #d8e1ef;
            --schemes-border-strong: #c7d4e6;
            --schemes-surface: #ffffff;
            --schemes-surface-soft: #f6f8fc;
            --schemes-text: #16213d;
            --schemes-text-soft: #60708f;
            --schemes-accent: #f59e0b;
            --schemes-accent-soft: rgba(245, 158, 11, 0.12);
            --schemes-success: #12926a;
            --schemes-success-soft: rgba(18, 146, 106, 0.12);
            --schemes-shadow: 0 18px 42px rgba(15, 23, 42, 0.06);
        }

        .schemes-index-page .schemes-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .schemes-index-page .schemes-stat-card,
        .schemes-index-page .schemes-toolbar-card,
        .schemes-index-page .schemes-list-card,
        .schemes-index-page .scheme-mobile-card {
            border: 1px solid var(--schemes-border);
            border-radius: 24px;
            background: var(--schemes-surface);
            box-shadow: var(--schemes-shadow);
        }

        .schemes-index-page .schemes-stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px;
        }

        .schemes-index-page .schemes-stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            border: 1px solid var(--schemes-border);
            background: linear-gradient(180deg, #fffaf1 0%, #fff 100%);
            color: #d97706;
            flex-shrink: 0;
        }

        .schemes-index-page .schemes-stat-icon--festival {
            background: linear-gradient(180deg, #fff2f4 0%, #fff 100%);
            color: #be185d;
        }

        .schemes-index-page .schemes-stat-icon--success {
            background: linear-gradient(180deg, #f0fdf7 0%, #fff 100%);
            color: var(--schemes-success);
        }

        .schemes-index-page .schemes-stat-label {
            margin: 0 0 6px;
            color: var(--schemes-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .schemes-index-page .schemes-stat-value {
            margin: 0;
            color: var(--schemes-text);
            font-size: 30px;
            font-weight: 700;
            line-height: 1;
        }

        .schemes-index-page .schemes-stat-note {
            margin: 6px 0 0;
            color: var(--schemes-text-soft);
            font-size: 13px;
        }

        .schemes-index-page .schemes-toolbar-card {
            padding: 18px;
            margin-bottom: 20px;
        }

        .schemes-index-page .schemes-toolbar-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .schemes-index-page .schemes-toolbar-kicker {
            margin: 0 0 6px;
            color: var(--schemes-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .schemes-index-page .schemes-toolbar-title {
            margin: 0;
            color: var(--schemes-text);
            font-size: 20px;
            font-weight: 700;
            line-height: 1.15;
        }

        .schemes-index-page .schemes-toolbar-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .schemes-index-page .schemes-meta-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--schemes-border);
            background: var(--schemes-surface-soft);
            color: var(--schemes-text);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .schemes-index-page .schemes-meta-pill--accent {
            border-color: rgba(245, 158, 11, 0.22);
            background: var(--schemes-accent-soft);
            color: #b45309;
        }

        .schemes-index-page .schemes-filter-form {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(180px, 220px) auto;
            gap: 12px;
            align-items: end;
        }

        .schemes-index-page .schemes-field-label {
            display: block;
            margin-bottom: 7px;
            color: var(--schemes-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .schemes-index-page .schemes-search-wrap {
            position: relative;
            display: block;
        }

        .schemes-index-page .schemes-search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #b7791f;
            pointer-events: none;
            z-index: 2;
        }

        .schemes-index-page .schemes-search-icon svg {
            display: block;
        }

        .schemes-index-page .schemes-search-input,
        .schemes-index-page .schemes-filter-select {
            width: 100%;
            min-height: 48px;
            border-radius: 18px;
            border: 1px solid #ccd7e7;
            background: #fbfcfe;
            color: var(--schemes-text);
            font-size: 14px;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
            padding: 0 14px;
        }

        .schemes-index-page .schemes-search-input {
            box-sizing: border-box;
            width: 100%;
            padding: 0 16px 0 46px;
        }

        .schemes-index-page .schemes-search-input::placeholder {
            color: #8a9ab3;
        }

        .schemes-index-page .schemes-search-input:focus,
        .schemes-index-page .schemes-filter-select:focus {
            border-color: rgba(245, 158, 11, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
            outline: none;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-trigger {
            min-height: 48px;
            border-radius: 18px;
            border-color: #ccd7e7;
            background: #fbfcfe;
            padding: 0 14px;
            font-size: 14px;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-trigger:hover {
            background: #fff;
            border-color: #c5d1e2;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-trigger.is-open,
        .schemes-index-page .schemes-type-field .ui-filter-select-trigger:focus-visible {
            border-color: rgba(245, 158, 11, 0.45);
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
            background: #fff;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-trigger-text {
            font-weight: 600;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-menu {
            margin-top: 2px;
            border-radius: 16px;
            padding: 6px;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-option {
            border-radius: 10px;
            padding: 11px 12px;
        }

        .schemes-index-page .schemes-type-field .ui-filter-select-option.is-selected {
            background: #fff3d6;
            color: #9a5b06;
        }

        .schemes-index-page .schemes-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-self: end;
        }

        .schemes-index-page .schemes-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 16px;
            border-radius: 18px;
            border: 1px solid var(--schemes-border);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
            white-space: nowrap;
        }

        .schemes-index-page .schemes-btn:hover {
            transform: translateY(-1px);
        }

        .schemes-index-page .schemes-btn--primary {
            border-color: #0f172a;
            background: #0f172a;
            color: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .schemes-index-page .schemes-btn--primary:hover {
            background: #1e293b;
        }

        .schemes-index-page .schemes-btn--ghost {
            background: #fff;
            color: var(--schemes-text);
        }

        .schemes-index-page .schemes-btn--ghost:hover {
            background: var(--schemes-surface-soft);
        }

        .schemes-index-page .schemes-list-card {
            overflow: hidden;
        }

        .schemes-index-page .schemes-list-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 22px 24px 18px;
            border-bottom: 1px solid var(--schemes-border);
        }

        .schemes-index-page .schemes-list-title {
            margin: 0;
            color: var(--schemes-text);
            font-size: 20px;
            font-weight: 700;
        }

        .schemes-index-page .schemes-list-copy {
            margin: 6px 0 0;
            color: var(--schemes-text-soft);
            font-size: 14px;
        }

        .schemes-index-page .schemes-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--schemes-border);
            background: var(--schemes-surface-soft);
            color: var(--schemes-text);
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .schemes-index-page .schemes-table-wrap {
            overflow-x: auto;
        }

        .schemes-index-page .schemes-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 900px;
        }

        .schemes-index-page .schemes-table thead th {
            padding: 14px 24px;
            border-bottom: 1px solid var(--schemes-border);
            background: #f8fafc;
            color: var(--schemes-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .schemes-index-page .schemes-table tbody td {
            padding: 18px 24px;
            border-bottom: 1px solid #e8eef7;
            vertical-align: middle;
        }

        .schemes-index-page .schemes-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .schemes-index-page .schemes-table tbody tr {
            transition: background-color 0.18s ease;
        }

        .schemes-index-page .schemes-table tbody tr:hover {
            background: #fbfcff;
        }

        .schemes-index-page .schemes-name {
            margin: 0;
            color: var(--schemes-text);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.4;
        }

        .schemes-index-page .schemes-subtext {
            margin: 4px 0 0;
            color: var(--schemes-text-soft);
            font-size: 13px;
            line-height: 1.45;
        }

        .schemes-index-page .schemes-muted {
            color: var(--schemes-text-soft);
            font-size: 14px;
        }

        .schemes-index-page .schemes-type-badge,
        .schemes-index-page .schemes-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 32px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .schemes-index-page .schemes-type-badge--gold {
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
        }

        .schemes-index-page .schemes-type-badge--festival {
            background: rgba(244, 63, 94, 0.12);
            color: #be185d;
        }

        .schemes-index-page .schemes-type-badge--discount {
            background: rgba(59, 130, 246, 0.12);
            color: #2563eb;
        }

        .schemes-index-page .schemes-status-badge--active {
            background: var(--schemes-success-soft);
            color: #0f7b59;
        }

        .schemes-index-page .schemes-status-badge--inactive {
            background: #eef2f7;
            color: #5f6f8a;
        }

        .schemes-index-page .schemes-table-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .schemes-index-page .schemes-link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid var(--schemes-border);
            background: #fff;
            color: var(--schemes-text);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.18s ease, border-color 0.18s ease;
        }

        .schemes-index-page .schemes-link-btn:hover {
            background: var(--schemes-surface-soft);
        }

        .schemes-index-page .schemes-link-btn--primary {
            border-color: rgba(15, 23, 42, 0.14);
            background: #f8fafc;
        }

        .schemes-index-page .schemes-mobile-list {
            display: none;
            padding: 0 16px 16px;
        }

        .schemes-index-page .scheme-mobile-card {
            padding: 16px;
        }

        .schemes-index-page .scheme-mobile-card + .scheme-mobile-card {
            margin-top: 12px;
        }

        .schemes-index-page .scheme-mobile-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .schemes-index-page .scheme-mobile-name {
            display: inline-block;
            color: var(--schemes-text);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.35;
            text-decoration: none;
        }

        .schemes-index-page .scheme-mobile-name:hover {
            color: #0f172a;
        }

        .schemes-index-page .scheme-mobile-copy {
            margin: 4px 0 0;
            color: var(--schemes-text-soft);
            font-size: 13px;
            line-height: 1.45;
        }

        .schemes-index-page .scheme-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin: 0;
        }

        .schemes-index-page .scheme-mobile-grid > div {
            border: 1px solid #e8eef7;
            border-radius: 16px;
            background: #fbfcff;
            padding: 12px;
        }

        .schemes-index-page .scheme-mobile-grid dt {
            margin: 0 0 4px;
            color: var(--schemes-text-soft);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .schemes-index-page .scheme-mobile-grid dd {
            margin: 0;
            color: var(--schemes-text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
            word-break: break-word;
        }

        .schemes-index-page .scheme-mobile-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .schemes-index-page .scheme-mobile-actions .schemes-link-btn {
            width: 100%;
        }

        .schemes-index-page .schemes-empty {
            padding: 48px 24px;
            text-align: center;
        }

        .schemes-index-page .schemes-empty-title {
            margin: 0 0 6px;
            color: var(--schemes-text);
            font-size: 20px;
            font-weight: 700;
        }

        .schemes-index-page .schemes-empty-copy {
            margin: 0;
            color: var(--schemes-text-soft);
            font-size: 14px;
        }

        .schemes-index-page .schemes-pagination {
            padding: 18px 24px 24px;
            border-top: 1px solid var(--schemes-border);
        }

        .schemes-page-header .schemes-add-label-full {
            display: inline;
        }

        .schemes-page-header .schemes-add-label-short {
            display: none;
        }

        @media (max-width: 1024px) {
            .schemes-index-page .schemes-filter-form {
                grid-template-columns: minmax(0, 1fr) minmax(180px, 220px) auto;
            }

            .schemes-index-page .schemes-search-field {
                grid-column: 1 / -1;
            }

            .schemes-index-page .schemes-type-field {
                grid-column: 1 / 2;
            }

            .schemes-index-page .schemes-actions {
                grid-column: 2 / 4;
            }
        }

        @media (max-width: 767px) {
            .schemes-page-header .page-subtitle {
                display: none;
            }

            .schemes-page-header .schemes-add-label-full {
                display: none !important;
            }

            .schemes-page-header .schemes-add-label-short {
                display: inline !important;
            }

            .schemes-index-page .schemes-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .schemes-index-page .schemes-stats-grid .schemes-stat-card:last-child {
                grid-column: 1 / -1;
            }

            .schemes-index-page .schemes-stat-card {
                padding: 14px;
                gap: 12px;
                border-radius: 20px;
            }

            .schemes-index-page .schemes-stat-icon {
                width: 42px;
                height: 42px;
                border-radius: 14px;
            }

            .schemes-index-page .schemes-stat-value {
                font-size: 24px;
            }

            .schemes-index-page .schemes-stat-note {
                display: none;
            }

            .schemes-index-page .schemes-toolbar-card,
            .schemes-index-page .schemes-list-head {
                padding-left: 16px;
                padding-right: 16px;
            }

            .schemes-index-page .schemes-toolbar-card {
                padding-top: 18px;
                padding-bottom: 18px;
                border-radius: 20px;
            }

            .schemes-index-page .schemes-toolbar-head {
                flex-direction: row;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 14px;
                flex-wrap: nowrap;
            }

            .schemes-index-page .schemes-toolbar-title {
                font-size: 18px;
            }

            .schemes-index-page .schemes-toolbar-meta {
                justify-content: flex-end;
                margin-left: auto;
                flex-shrink: 0;
            }

            .schemes-index-page .schemes-filter-form {
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 10px;
            }

            .schemes-index-page .schemes-search-field {
                grid-column: 1 / -1;
            }

            .schemes-index-page .schemes-type-field {
                grid-column: 1 / 2;
            }

            .schemes-index-page .schemes-actions {
                grid-column: 2 / 3;
            }

            .schemes-index-page .schemes-field-label {
                display: none;
            }

            .schemes-index-page .schemes-search-input,
            .schemes-index-page .schemes-filter-select,
            .schemes-index-page .schemes-type-field .ui-filter-select-trigger,
            .schemes-index-page .schemes-btn {
                min-height: 40px;
                border-radius: 14px;
                font-size: 12px;
            }

            .schemes-index-page .schemes-search-input {
                padding-left: 34px !important;
                padding-right: 12px;
            }

            .schemes-index-page .schemes-search-input::placeholder {
                font-size: 12px;
            }

            .schemes-index-page .schemes-search-icon {
                left: 13px;
            }

            .schemes-index-page .schemes-search-icon svg {
                width: 13px;
                height: 13px;
            }

            .schemes-index-page .schemes-type-field .ui-filter-select-trigger {
                min-width: 126px;
                padding: 0 11px;
                gap: 8px;
            }

            .schemes-index-page .schemes-actions {
                display: flex;
                align-items: center;
                justify-self: end;
                gap: 6px;
                justify-content: flex-end;
                flex-wrap: wrap;
            }

            .schemes-index-page .schemes-btn {
                gap: 6px;
                padding: 0 12px;
            }

            .schemes-index-page .schemes-btn svg {
                width: 12px;
                height: 12px;
            }

            .schemes-index-page .schemes-list-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                padding-top: 18px;
                padding-bottom: 16px;
            }

            .schemes-index-page .schemes-list-title {
                font-size: 18px;
            }

            .schemes-index-page .schemes-list-copy {
                font-size: 13px;
            }

            .schemes-index-page .schemes-count-badge {
                min-height: 34px;
                padding: 0 12px;
                font-size: 12px;
            }

            .schemes-index-page .schemes-table-wrap {
                display: none;
            }

            .schemes-index-page .schemes-mobile-list {
                display: block;
            }

            .schemes-index-page .schemes-pagination {
                padding: 16px;
            }
        }

        @media (max-width: 420px) {
            .schemes-index-page .schemes-toolbar-title {
                font-size: 17px;
            }

            .schemes-index-page .schemes-meta-pill {
                min-height: 30px;
                padding: 0 10px;
                font-size: 11px;
            }

            .schemes-index-page .schemes-filter-form {
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
            }

            .schemes-index-page .schemes-type-field .ui-filter-select-trigger {
                min-width: 114px;
                padding: 0 10px;
            }

            .schemes-index-page .schemes-btn {
                padding: 0 10px;
            }

            .schemes-index-page .schemes-btn svg {
                display: none;
            }

            .schemes-index-page .scheme-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <x-page-header class="schemes-page-header ops-treatment-header">
        <div>
            <h1 class="page-title">Schemes & Offers</h1>
            <p class="text-sm text-gray-600 mt-1 page-subtitle">Gold savings schemes, festival sales & discount offers</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('schemes.create') }}" class="inline-flex items-center px-4 py-2 rounded-full transition-colors text-sm font-semibold shadow-sm schemes-create-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                <span class="schemes-add-label-full">Create Scheme</span>
                <span class="schemes-add-label-short">Create</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page schemes-index-page">
        @if(session('success'))
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="schemes-stats-grid">
            <div class="schemes-stat-card">
                <div class="schemes-stat-icon">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 0v8m0 5v-1" />
                    </svg>
                </div>
                <div>
                    <p class="schemes-stat-label">Gold Savings</p>
                    <p class="schemes-stat-value">{{ number_format($stats->gold_savings_count ?? 0) }}</p>
                    <p class="schemes-stat-note">Customer savings plans available in your store.</p>
                </div>
            </div>

            <div class="schemes-stat-card">
                <div class="schemes-stat-icon schemes-stat-icon--festival">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                </div>
                <div>
                    <p class="schemes-stat-label">Offers / Sales</p>
                    <p class="schemes-stat-value">{{ number_format($stats->offers_count ?? 0) }}</p>
                    <p class="schemes-stat-note">Festival campaigns and promotional schemes in one list.</p>
                </div>
            </div>

            <div class="schemes-stat-card">
                <div class="schemes-stat-icon schemes-stat-icon--success">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="schemes-stat-label">Active</p>
                    <p class="schemes-stat-value">{{ number_format($stats->active_count ?? 0) }}</p>
                    <p class="schemes-stat-note">Schemes currently visible and in use.</p>
                </div>
            </div>
        </div>

        <section class="schemes-toolbar-card ui-filter-enhanced-wrap">
            <div class="schemes-toolbar-head">
                <div>
                    <p class="schemes-toolbar-kicker">Catalog</p>
                    <h2 class="schemes-toolbar-title">Scheme Directory</h2>
                </div>
                <div class="schemes-toolbar-meta">
                    <span class="schemes-meta-pill">{{ number_format($schemeTotal) }} records</span>
                    @if($hasFilters)
                        <span class="schemes-meta-pill schemes-meta-pill--accent">Filtered view</span>
                    @endif
                </div>
            </div>

            <form method="GET" action="{{ route('schemes.index') }}" class="schemes-filter-form" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="schemes-search-field">
                    <label class="schemes-field-label" for="schemes-search">Search</label>
                    <div class="schemes-search-wrap">
                        <span class="schemes-search-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                        </span>
                        <input
                            id="schemes-search"
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            placeholder="Search scheme or description"
                            class="schemes-search-input"
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="schemes-type-field">
                    <label class="schemes-field-label" for="schemes-type">Type</label>
                    <select id="schemes-type" name="type" class="schemes-filter-select">
                        <option value="">All types</option>
                        <option value="gold_savings" {{ request('type') === 'gold_savings' ? 'selected' : '' }}>Gold Savings</option>
                        <option value="festival_sale" {{ request('type') === 'festival_sale' ? 'selected' : '' }}>Festival Sale</option>
                        <option value="discount_offer" {{ request('type') === 'discount_offer' ? 'selected' : '' }}>Discount Offer</option>
                    </select>
                </div>

                <div class="schemes-actions">
                    <button type="submit" class="schemes-btn schemes-btn--primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z" />
                        </svg>
                        Filter
                    </button>
                    @if($hasFilters)
                        <a href="{{ route('schemes.index') }}" class="schemes-btn schemes-btn--ghost">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="schemes-list-card">
            <div class="schemes-list-head">
                <div>
                    <h3 class="schemes-list-title">All Schemes</h3>
                    <p class="schemes-list-copy">Savings plans and promotional offers in one manageable list.</p>
                </div>
                <span class="schemes-count-badge">{{ number_format($schemeTotal) }} total</span>
            </div>

            @if($schemes->count())
                <div class="schemes-table-wrap">
                    <table class="schemes-table">
                        <thead>
                            <tr>
                                <th class="text-left">Scheme</th>
                                <th class="text-left">Type</th>
                                <th class="text-left">Period</th>
                                <th class="text-center">Enrollments</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($schemes as $scheme)
                                <tr>
                                    <td>
                                        <p class="schemes-name">{{ $scheme->name }}</p>
                                        @if($scheme->description)
                                            <p class="schemes-subtext">{{ $scheme->description }}</p>
                                        @else
                                            <p class="schemes-subtext">No description added yet.</p>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="schemes-type-badge {{ $typeColors[$scheme->type] ?? 'schemes-type-badge--discount' }}">
                                            {{ $typeLabels[$scheme->type] ?? $scheme->type }}
                                        </span>
                                    </td>
                                    <td class="schemes-muted">
                                        {{ $scheme->start_date->format('d M Y') }}
                                        @if($scheme->end_date)
                                            — {{ $scheme->end_date->format('d M Y') }}
                                        @endif
                                    </td>
                                    <td class="text-center"><span class="schemes-name">{{ number_format($scheme->enrollments_count ?? 0) }}</span></td>
                                    <td class="text-center">
                                        <span class="schemes-status-badge {{ $scheme->is_active ? 'schemes-status-badge--active' : 'schemes-status-badge--inactive' }}">
                                            {{ $scheme->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="schemes-table-actions">
                                            <a href="{{ route('schemes.show', $scheme) }}" class="schemes-link-btn schemes-link-btn--primary">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                                View
                                            </a>
                                            @if($scheme->isGoldSavings())
                                                <a href="{{ route('schemes.enroll.form', $scheme) }}" class="schemes-link-btn">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                                        <circle cx="8.5" cy="7" r="4" />
                                                        <line x1="20" y1="8" x2="20" y2="14" />
                                                        <line x1="23" y1="11" x2="17" y2="11" />
                                                    </svg>
                                                    Enroll
                                                </a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="schemes-mobile-list">
                    @foreach($schemes as $scheme)
                        <article class="scheme-mobile-card">
                            <div class="scheme-mobile-top">
                                <div>
                                    <a href="{{ route('schemes.show', $scheme) }}" class="scheme-mobile-name">{{ $scheme->name }}</a>
                                    <p class="scheme-mobile-copy">{{ $scheme->description ?: 'No description added yet.' }}</p>
                                </div>
                                <span class="schemes-status-badge {{ $scheme->is_active ? 'schemes-status-badge--active' : 'schemes-status-badge--inactive' }}">
                                    {{ $scheme->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>

                            <dl class="scheme-mobile-grid">
                                <div>
                                    <dt>Type</dt>
                                    <dd>{{ $typeLabels[$scheme->type] ?? $scheme->type }}</dd>
                                </div>
                                <div>
                                    <dt>Enrollments</dt>
                                    <dd>{{ number_format($scheme->enrollments_count ?? 0) }}</dd>
                                </div>
                                <div>
                                    <dt>Starts</dt>
                                    <dd>{{ $scheme->start_date->format('d M Y') }}</dd>
                                </div>
                                <div>
                                    <dt>Ends</dt>
                                    <dd>{{ $scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open ended' }}</dd>
                                </div>
                            </dl>

                            <div class="scheme-mobile-actions">
                                <a href="{{ route('schemes.show', $scheme) }}" class="schemes-link-btn schemes-link-btn--primary">View Scheme</a>
                                @if($scheme->isGoldSavings())
                                    <a href="{{ route('schemes.enroll.form', $scheme) }}" class="schemes-link-btn">Enroll</a>
                                @else
                                    <span class="schemes-link-btn" style="opacity:.65; pointer-events:none;">No Enroll</span>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="schemes-empty">
                    <p class="schemes-empty-title">No schemes yet</p>
                    <p class="schemes-empty-copy">Create a gold savings scheme or promotional offer to get started.</p>
                </div>
            @endif

            @if($schemes->hasPages())
                <div class="schemes-pagination">{{ $schemes->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
