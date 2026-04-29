@php
    $hasFilters = request()->hasAny(['search', 'status']);
    $vendorTotal = method_exists($vendors, 'total') ? $vendors->total() : $vendors->count();
@endphp

<x-app-layout>
    <style>
        .vendors-index-page {
            --vendors-border: #d8e1ef;
            --vendors-border-strong: #c7d4e6;
            --vendors-surface: #ffffff;
            --vendors-surface-soft: #f6f8fc;
            --vendors-text: #16213d;
            --vendors-text-soft: #60708f;
            --vendors-accent: #f59e0b;
            --vendors-accent-soft: rgba(245, 158, 11, 0.12);
            --vendors-success: #12926a;
            --vendors-success-soft: rgba(18, 146, 106, 0.12);
            --vendors-shadow: 0 18px 42px rgba(15, 23, 42, 0.06);
        }

        .vendors-index-page .vendors-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .vendors-index-page .vendors-stat-card,
        .vendors-index-page .vendors-toolbar-card,
        .vendors-index-page .vendors-list-card,
        .vendors-index-page .vendor-mobile-card {
            border: 1px solid var(--vendors-border);
            border-radius: 24px;
            background: var(--vendors-surface);
            box-shadow: var(--vendors-shadow);
        }

        .vendors-index-page .vendors-stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px;
        }

        .vendors-index-page .vendors-stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 16px;
            border: 1px solid var(--vendors-border);
            background: linear-gradient(180deg, #fffaf1 0%, #fff 100%);
            color: #d97706;
            flex-shrink: 0;
        }

        .vendors-index-page .vendors-stat-icon--success {
            background: linear-gradient(180deg, #f0fdf7 0%, #fff 100%);
            color: var(--vendors-success);
        }

        .vendors-index-page .vendors-stat-label {
            margin: 0 0 6px;
            color: var(--vendors-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .vendors-index-page .vendors-stat-value {
            margin: 0;
            color: var(--vendors-text);
            font-size: 30px;
            font-weight: 700;
            line-height: 1;
        }

        .vendors-index-page .vendors-stat-note {
            margin: 6px 0 0;
            color: var(--vendors-text-soft);
            font-size: 13px;
        }

        .vendors-index-page .vendors-toolbar-card {
            padding: 18px;
            margin-bottom: 20px;
        }

        .vendors-index-page .vendors-toolbar-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .vendors-index-page .vendors-toolbar-kicker {
            margin: 0 0 6px;
            color: var(--vendors-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .vendors-index-page .vendors-toolbar-title {
            margin: 0;
            color: var(--vendors-text);
            font-size: 20px;
            font-weight: 700;
            line-height: 1.15;
        }

        .vendors-index-page .vendors-toolbar-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .vendors-index-page .vendors-meta-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--vendors-border);
            background: var(--vendors-surface-soft);
            color: var(--vendors-text);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .vendors-index-page .vendors-meta-pill--accent {
            border-color: rgba(245, 158, 11, 0.22);
            background: var(--vendors-accent-soft);
            color: #b45309;
        }

        .vendors-index-page .vendors-filter-form {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(180px, 220px) auto;
            gap: 12px;
            align-items: end;
        }

        .vendors-index-page .vendors-field-label {
            display: block;
            margin-bottom: 7px;
            color: var(--vendors-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .vendors-index-page .vendors-search-wrap {
            position: relative;
            display: block;
        }

        .vendors-index-page .vendors-search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #b7791f;
            pointer-events: none;
            z-index: 2;
        }

        .vendors-index-page .vendors-search-icon svg {
            display: block;
        }

        .vendors-index-page .vendors-search-input,
        .vendors-index-page .vendors-status-select {
            width: 100%;
            min-height: 48px;
            border-radius: 18px;
            border: 1px solid #ccd7e7;
            background: #fbfcfe;
            color: var(--vendors-text);
            font-size: 14px;
            box-shadow: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
        }

        .vendors-index-page .vendors-search-input {
            box-sizing: border-box;
            width: 100%;
            padding: 0 16px 0 46px;
        }

        .vendors-index-page .vendors-status-select {
            padding: 0 14px;
        }

        .vendors-index-page .vendors-search-input::placeholder {
            color: #8a9ab3;
        }

        .vendors-index-page .vendors-search-input:focus,
        .vendors-index-page .vendors-status-select:focus {
            border-color: rgba(245, 158, 11, 0.45);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
            outline: none;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-trigger {
            min-height: 48px;
            border-radius: 18px;
            border-color: #ccd7e7;
            background: #fbfcfe;
            padding: 0 14px;
            font-size: 14px;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-trigger:hover {
            background: #fff;
            border-color: #c5d1e2;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-trigger.is-open,
        .vendors-index-page .vendors-status-field .ui-filter-select-trigger:focus-visible {
            border-color: rgba(245, 158, 11, 0.45);
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1);
            background: #fff;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-trigger-text {
            font-weight: 600;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-menu {
            margin-top: 2px;
            border-radius: 16px;
            padding: 6px;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-option {
            border-radius: 10px;
            padding: 11px 12px;
        }

        .vendors-index-page .vendors-status-field .ui-filter-select-option.is-selected {
            background: #fff3d6;
            color: #9a5b06;
        }

        .vendors-index-page .vendors-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-self: end;
        }

        .vendors-index-page .vendors-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 48px;
            padding: 0 16px;
            border-radius: 18px;
            border: 1px solid var(--vendors-border);
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
            white-space: nowrap;
        }

        .vendors-index-page .vendors-btn:hover {
            transform: translateY(-1px);
        }

        .vendors-index-page .vendors-btn--primary {
            border-color: #0f172a;
            background: #0f172a;
            color: #fff;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .vendors-index-page .vendors-btn--primary:hover {
            background: #1e293b;
        }

        .vendors-index-page .vendors-btn--ghost {
            background: #fff;
            color: var(--vendors-text);
        }

        .vendors-index-page .vendors-btn--ghost:hover {
            background: var(--vendors-surface-soft);
        }

        .vendors-index-page .vendors-list-card {
            overflow: hidden;
        }

        .vendors-index-page .vendors-list-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 22px 24px 18px;
            border-bottom: 1px solid var(--vendors-border);
        }

        .vendors-index-page .vendors-list-title {
            margin: 0;
            color: var(--vendors-text);
            font-size: 20px;
            font-weight: 700;
        }

        .vendors-index-page .vendors-list-copy {
            margin: 6px 0 0;
            color: var(--vendors-text-soft);
            font-size: 14px;
        }

        .vendors-index-page .vendors-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--vendors-border);
            background: var(--vendors-surface-soft);
            color: var(--vendors-text);
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .vendors-index-page .vendors-table-wrap {
            overflow-x: auto;
        }

        .vendors-index-page .vendors-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 760px;
        }

        .vendors-index-page .vendors-table thead th {
            padding: 14px 24px;
            border-bottom: 1px solid var(--vendors-border);
            background: #f8fafc;
            color: var(--vendors-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .vendors-index-page .vendors-table tbody td {
            padding: 18px 24px;
            border-bottom: 1px solid #e8eef7;
            vertical-align: middle;
        }

        .vendors-index-page .vendors-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .vendors-index-page .vendors-table tbody tr {
            transition: background-color 0.18s ease;
        }

        .vendors-index-page .vendors-table tbody tr:hover {
            background: #fbfcff;
        }

        .vendors-index-page .vendors-name {
            margin: 0;
            color: var(--vendors-text);
            font-size: 15px;
            font-weight: 700;
        }

        .vendors-index-page .vendors-subtext {
            margin: 4px 0 0;
            color: var(--vendors-text-soft);
            font-size: 13px;
        }

        .vendors-index-page .vendors-muted {
            color: var(--vendors-text-soft);
            font-size: 14px;
        }

        .vendors-index-page .vendors-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 86px;
            min-height: 32px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .vendors-index-page .vendors-status-badge--active {
            background: var(--vendors-success-soft);
            color: #0f7b59;
        }

        .vendors-index-page .vendors-status-badge--inactive {
            background: #eef2f7;
            color: #5f6f8a;
        }

        .vendors-index-page .vendors-table-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .vendors-index-page .vendors-link-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 12px;
            border: 1px solid var(--vendors-border);
            background: #fff;
            color: var(--vendors-text);
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.18s ease, border-color 0.18s ease;
        }

        .vendors-index-page .vendors-link-btn:hover {
            background: var(--vendors-surface-soft);
        }

        .vendors-index-page .vendors-link-btn--primary {
            border-color: rgba(15, 23, 42, 0.14);
            background: #f8fafc;
        }

        .vendors-index-page .vendors-mobile-list {
            display: none;
            padding: 0 16px 16px;
        }

        .vendors-index-page .vendor-mobile-card {
            padding: 16px;
        }

        .vendors-index-page .vendor-mobile-card + .vendor-mobile-card {
            margin-top: 12px;
        }

        .vendors-index-page .vendor-mobile-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .vendors-index-page .vendor-mobile-name {
            display: inline-block;
            color: var(--vendors-text);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.3;
            text-decoration: none;
        }

        .vendors-index-page .vendor-mobile-name:hover {
            color: #0f172a;
        }

        .vendors-index-page .vendor-mobile-location {
            margin: 4px 0 0;
            color: var(--vendors-text-soft);
            font-size: 13px;
        }

        .vendors-index-page .vendor-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin: 0;
        }

        .vendors-index-page .vendor-mobile-grid > div {
            border: 1px solid #e8eef7;
            border-radius: 16px;
            background: #fbfcff;
            padding: 12px;
        }

        .vendors-index-page .vendor-mobile-grid dt {
            margin: 0 0 4px;
            color: var(--vendors-text-soft);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .vendors-index-page .vendor-mobile-grid dd {
            margin: 0;
            color: var(--vendors-text);
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
            word-break: break-word;
        }

        .vendors-index-page .vendor-mobile-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .vendors-index-page .vendor-mobile-actions .vendors-link-btn {
            width: 100%;
        }

        .vendors-index-page .vendors-empty {
            padding: 48px 24px;
            text-align: center;
        }

        .vendors-index-page .vendors-empty-title {
            margin: 0 0 6px;
            color: var(--vendors-text);
            font-size: 20px;
            font-weight: 700;
        }

        .vendors-index-page .vendors-empty-copy {
            margin: 0;
            color: var(--vendors-text-soft);
            font-size: 14px;
        }

        .vendors-index-page .vendors-pagination {
            padding: 18px 24px 24px;
            border-top: 1px solid var(--vendors-border);
        }

        @media (max-width: 1024px) {
            .vendors-index-page .vendors-filter-form {
                grid-template-columns: minmax(0, 1fr) minmax(180px, 220px) auto;
            }

            .vendors-index-page .vendors-search-field {
                grid-column: 1 / -1;
            }

            .vendors-index-page .vendors-status-field {
                grid-column: 1 / 2;
            }

            .vendors-index-page .vendors-actions {
                grid-column: 2 / 4;
            }
        }

        @media (max-width: 767px) {
            .vendors-page-header .page-subtitle {
                display: none;
            }

            .vendors-page-header .vendors-add-label-full {
                display: none !important;
            }

            .vendors-page-header .vendors-add-label-short {
                display: inline !important;
            }

            .vendors-index-page .vendors-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .vendors-index-page .vendors-stats-grid .vendors-stat-card:last-child {
                grid-column: 1 / -1;
            }

            .vendors-index-page .vendors-stat-card {
                padding: 14px;
                gap: 12px;
                border-radius: 20px;
            }

            .vendors-index-page .vendors-stat-icon {
                width: 42px;
                height: 42px;
                border-radius: 14px;
            }

            .vendors-index-page .vendors-stat-value {
                font-size: 24px;
            }

            .vendors-index-page .vendors-stat-note {
                display: none;
            }

            .vendors-index-page .vendors-toolbar-card,
            .vendors-index-page .vendors-list-head {
                padding-left: 16px;
                padding-right: 16px;
            }

            .vendors-index-page .vendors-toolbar-card {
                padding-top: 18px;
                padding-bottom: 18px;
                border-radius: 20px;
            }

            .vendors-index-page .vendors-toolbar-head {
                flex-direction: row;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 14px;
                flex-wrap: nowrap;
            }

            .vendors-index-page .vendors-toolbar-title {
                font-size: 18px;
            }

            .vendors-index-page .vendors-toolbar-meta {
                justify-content: flex-end;
                margin-left: auto;
                flex-shrink: 0;
            }

            .vendors-index-page .vendors-filter-form {
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 10px;
            }

            .vendors-index-page .vendors-search-field {
                grid-column: 1 / -1;
            }

            .vendors-index-page .vendors-status-field {
                grid-column: 1 / 2;
            }

            .vendors-index-page .vendors-actions {
                grid-column: 2 / 3;
            }

            .vendors-index-page .vendors-field-label {
                display: none;
            }

            .vendors-index-page .vendors-search-input,
            .vendors-index-page .vendors-status-select,
            .vendors-index-page .vendors-status-field .ui-filter-select-trigger,
            .vendors-index-page .vendors-btn {
                min-height: 40px;
                border-radius: 14px;
                font-size: 12px;
            }

            .vendors-index-page .vendors-search-input {
                padding-left: 34px !important;
                padding-right: 12px;
            }

            .vendors-index-page .vendors-search-input::placeholder {
                font-size: 12px;
            }

            .vendors-index-page .vendors-search-icon {
                left: 13px;
            }

            .vendors-index-page .vendors-search-icon svg {
                width: 13px;
                height: 13px;
            }

            .vendors-index-page .vendors-status-field .ui-filter-select-trigger {
                min-width: 116px;
                padding: 0 11px;
                gap: 8px;
            }

            .vendors-index-page .vendors-actions {
                display: flex;
                align-items: center;
                justify-self: end;
                gap: 6px;
                justify-content: flex-end;
                flex-wrap: wrap;
            }

            .vendors-index-page .vendors-btn {
                gap: 6px;
                padding: 0 12px;
            }

            .vendors-index-page .vendors-btn svg {
                width: 12px;
                height: 12px;
            }

            .vendors-index-page .vendors-list-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
                padding-top: 18px;
                padding-bottom: 16px;
            }

            .vendors-index-page .vendors-list-title {
                font-size: 18px;
            }

            .vendors-index-page .vendors-list-copy {
                font-size: 13px;
            }

            .vendors-index-page .vendors-count-badge {
                min-height: 34px;
                padding: 0 12px;
                font-size: 12px;
            }

            .vendors-index-page .vendors-table-wrap {
                display: none;
            }

            .vendors-index-page .vendors-mobile-list {
                display: block;
            }

            .vendors-index-page .vendors-pagination {
                padding: 16px;
            }
        }

        @media (max-width: 420px) {
            .vendors-index-page .vendors-toolbar-title {
                font-size: 17px;
            }

            .vendors-index-page .vendors-meta-pill {
                min-height: 30px;
                padding: 0 10px;
                font-size: 11px;
            }

            .vendors-index-page .vendors-filter-form {
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
            }

            .vendors-index-page .vendors-status-field {
                grid-column: 1 / 2;
            }

            .vendors-index-page .vendors-actions {
                grid-column: 2 / 3;
            }

            .vendors-index-page .vendors-status-field .ui-filter-select-trigger {
                min-width: 108px;
                padding: 0 10px;
            }

            .vendors-index-page .vendors-btn {
                padding: 0 10px;
            }

            .vendors-index-page .vendors-btn svg {
                display: none;
            }

            .vendors-index-page .vendor-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <x-page-header class="vendors-page-header ops-treatment-header" title="Vendors / Suppliers" subtitle="Manage your jewellery suppliers and vendors">
        <x-slot:actions>
            <a href="{{ route('vendors.create') }}" class="btn btn-success btn-sm vendors-add-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
                <span class="vendors-add-label-full">Add Vendor</span>
                <span class="vendors-add-label-short">Add</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ops-treatment-page vendors-index-page">
        @if(session('success'))
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="vendors-stats-grid">
            <div class="vendors-stat-card">
                <div class="vendors-stat-icon">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <p class="vendors-stat-label">Total Vendors</p>
                    <p class="vendors-stat-value">{{ number_format($stats->total_count ?? 0) }}</p>
                    <p class="vendors-stat-note">Suppliers available in your directory.</p>
                </div>
            </div>

            <div class="vendors-stat-card">
                <div class="vendors-stat-icon vendors-stat-icon--success">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div>
                    <p class="vendors-stat-label">Active</p>
                    <p class="vendors-stat-value">{{ number_format($stats->active_count ?? 0) }}</p>
                    <p class="vendors-stat-note">Currently enabled for purchasing and stock flow.</p>
                </div>
            </div>

            <div class="vendors-stat-card">
                <div class="vendors-stat-icon">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </div>
                <div>
                    <p class="vendors-stat-label">GST Registered</p>
                    <p class="vendors-stat-value">{{ number_format($stats->gst_count ?? 0) }}</p>
                    <p class="vendors-stat-note">Vendors with GST details already captured.</p>
                </div>
            </div>
        </div>

        <section class="vendors-toolbar-card ui-filter-enhanced-wrap">
            <div class="vendors-toolbar-head">
                <div>
                    <p class="vendors-toolbar-kicker">Directory</p>
                    <h2 class="vendors-toolbar-title">Vendor Directory</h2>
                </div>
                <div class="vendors-toolbar-meta">
                    <span class="vendors-meta-pill">{{ number_format($vendorTotal) }} records</span>
                    @if($hasFilters)
                        <span class="vendors-meta-pill vendors-meta-pill--accent">Filtered view</span>
                    @endif
                </div>
            </div>

            <form method="GET" action="{{ route('vendors.index') }}" class="vendors-filter-form" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="vendors-search-field">
                    <label class="vendors-field-label" for="vendors-search">Search</label>
                    <div class="vendors-search-wrap">
                        <span class="vendors-search-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <circle cx="11" cy="11" r="8" />
                                <line x1="21" y1="21" x2="16.65" y2="16.65" />
                            </svg>
                        </span>
                        <input
                            id="vendors-search"
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            placeholder="Search vendor or GST"
                            class="vendors-search-input"
                            data-suggest="vendors"
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="vendors-status-field">
                    <label class="vendors-field-label" for="vendors-status">Status</label>
                    <select id="vendors-status" name="status" class="vendors-status-select">
                        <option value="">All status</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>

                <div class="vendors-actions">
                    <button type="submit" class="vendors-btn vendors-btn--primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z" />
                        </svg>
                        Filter
                    </button>
                    @if($hasFilters)
                        <a href="{{ route('vendors.index') }}" class="vendors-btn vendors-btn--ghost">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="vendors-list-card">
            <div class="vendors-list-head">
                <div>
                    <h3 class="vendors-list-title">All Vendors</h3>
                    <p class="vendors-list-copy">Contacts, registration details, and quick actions in one place.</p>
                </div>
                <span class="vendors-count-badge">{{ number_format($vendorTotal) }} total</span>
            </div>

            @if($vendors->count())
                <div class="vendors-table-wrap">
                    <table class="vendors-table">
                        <thead>
                            <tr>
                                <th class="text-left">Vendor</th>
                                <th class="text-left">Contact</th>
                                <th class="text-left">GST</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vendors as $vendor)
                                <tr>
                                    <td>
                                        <p class="vendors-name">{{ $vendor->name }}</p>
                                        <p class="vendors-subtext">{{ $vendor->city ? $vendor->city . ($vendor->state ? ', ' . $vendor->state : '') : 'Location not added' }}</p>
                                    </td>
                                    <td>
                                        <p class="vendors-name text-[14px]">{{ $vendor->contact_person ?: 'Not added' }}</p>
                                        <p class="vendors-subtext">{{ $vendor->mobile ?: 'No mobile number' }}</p>
                                    </td>
                                    <td class="vendors-muted">{{ $vendor->gst_number ?: 'Not available' }}</td>
                                    <td class="text-center">
                                        <span class="vendors-status-badge {{ $vendor->is_active ? 'vendors-status-badge--active' : 'vendors-status-badge--inactive' }}">
                                            {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="vendors-table-actions">
                                            <a href="{{ route('vendors.show', $vendor) }}" class="vendors-link-btn vendors-link-btn--primary">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                                    <circle cx="12" cy="12" r="3" />
                                                </svg>
                                                View
                                            </a>
                                            <a href="{{ route('vendors.edit', $vendor) }}" class="vendors-link-btn">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                                                </svg>
                                                Edit
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="vendors-mobile-list">
                    @foreach($vendors as $vendor)
                        <article class="vendor-mobile-card">
                            <div class="vendor-mobile-top">
                                <div>
                                    <a href="{{ route('vendors.show', $vendor) }}" class="vendor-mobile-name">{{ $vendor->name }}</a>
                                    <p class="vendor-mobile-location">{{ $vendor->city ? $vendor->city . ($vendor->state ? ', ' . $vendor->state : '') : 'Location not added' }}</p>
                                </div>
                                <span class="vendors-status-badge {{ $vendor->is_active ? 'vendors-status-badge--active' : 'vendors-status-badge--inactive' }}">
                                    {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>

                            <dl class="vendor-mobile-grid">
                                <div>
                                    <dt>Contact</dt>
                                    <dd>{{ $vendor->contact_person ?: 'Not added' }}</dd>
                                </div>
                                <div>
                                    <dt>Mobile</dt>
                                    <dd>{{ $vendor->mobile ?: 'Not available' }}</dd>
                                </div>
                                <div>
                                    <dt>GST</dt>
                                    <dd>{{ $vendor->gst_number ?: 'Not available' }}</dd>
                                </div>
                                <div>
                                    <dt>State</dt>
                                    <dd>{{ $vendor->state ?: 'Not added' }}</dd>
                                </div>
                            </dl>

                            <div class="vendor-mobile-actions">
                                <a href="{{ route('vendors.show', $vendor) }}" class="vendors-link-btn vendors-link-btn--primary">View Vendor</a>
                                <a href="{{ route('vendors.edit', $vendor) }}" class="vendors-link-btn">Edit Details</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="vendors-empty">
                    <p class="vendors-empty-title">No vendors found</p>
                    <p class="vendors-empty-copy">Add your first vendor to start managing supplier records here.</p>
                </div>
            @endif

            @if($vendors->hasPages())
                <div class="vendors-pagination">{{ $vendors->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
