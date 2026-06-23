<x-app-layout>
    <style>
        :root {
            --r-ink: #111827;
            --r-ink-soft: #334155;
            --r-muted: #64748b;
            --r-border: #cbd5e1;
            --r-border-soft: #e2e8f0;
            --r-bg: #f6f7f9;
            --r-field: #ffffff;
            --r-accent: #c65a1e;
            --r-accent-dark: #9a3412;
            --r-accent-soft: #fff7ed;
            --r-card: #ffffff;
        }
        .repairs-management-page,
        .repairs-management-page *,
        .repairs-page-header * {
            box-shadow: none !important;
        }
        .content-inner.repairs-management-page {
            --app-card-shadow: none;
            --app-card-shadow-hover: none;
        }
        .repairs-page-header .page-title {
            font-weight: 650;
            letter-spacing: 0;
        }
        .repairs-page-header .page-subtitle {
            color: var(--r-muted);
            font-weight: 500;
        }
        .repairs-report-btn {
            min-height: 38px;
            border: 1px solid var(--r-accent) !important;
            border-radius: 10px !important;
            background: var(--r-accent) !important;
            color: #ffffff !important;
            padding-inline: 14px !important;
            font-size: 13px !important;
            font-weight: 650 !important;
            transition: background-color 160ms ease, transform 120ms ease;
        }
        .repairs-report-btn:hover { background: var(--r-accent-dark) !important; }
        .repairs-report-btn:active { transform: scale(0.98); }
        .r-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            color: var(--r-ink-soft); margin-bottom: 6px;
        }
        .r-label svg { width: 14px; height: 14px; color: var(--r-muted); flex-shrink: 0; }
        .r-input, .r-select, .r-textarea {
            width: 100%; padding: 10px 12px; font-size: 13px; font-weight: 500;
            border: 1px solid var(--r-border); border-radius: 10px;
            background: var(--r-field); color: var(--r-ink);
            transition: border-color 0.15s, outline-color 0.15s, background 0.15s;
        }
        .r-input:focus, .r-select:focus, .r-textarea:focus {
            outline: none; border-color: var(--r-accent); background: #fff;
            outline: 2px solid rgba(198, 90, 30, 0.12);
            outline-offset: 1px;
        }
        .r-input::placeholder, .r-textarea::placeholder { color: #94a3b8; font-weight: 400; }
        .r-select {
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            padding-right: 36px; cursor: pointer;
        }
        .r-textarea { resize: vertical; min-height: 72px; line-height: 1.5; }
        .r-checkbox {
            width: 16px; height: 16px; border-radius: 4px;
            border: 1.5px solid var(--r-border); accent-color: var(--r-accent);
            cursor: pointer;
        }
        .r-checkbox:checked { border-color: var(--r-accent); }
        .r-form-group { margin-bottom: 0; }
        .r-modal-card {
            border-radius: 12px;
            border: 1px solid var(--r-border); overflow: hidden;
        }

        /* Custom dropdown */
        .r-dd-wrap { position: relative; z-index: 2; }
        .r-dd-wrap.dd-open { z-index: 34; }
        .r-dd-trigger {
            width: 100%; padding: 10px 36px 10px 12px; font-size: 13px; font-weight: 500;
            border: 1px solid var(--r-border); border-radius: 10px;
            background: var(--r-field); color: var(--r-ink); cursor: pointer;
            transition: border-color 0.15s, outline-color 0.15s, background 0.15s;
            text-align: left; position: relative;
        }
        .r-dd-trigger:focus { outline: 2px solid rgba(198, 90, 30, .12); outline-offset: 1px; border-color: var(--r-accent); background: #fff; }
        .r-dd-trigger.open { border-color: var(--r-accent); background: #fff; outline: 2px solid rgba(198, 90, 30, .12); outline-offset: 1px; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .r-dd-trigger::after {
            content: ''; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            width: 16px; height: 16px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: center; transition: transform .15s;
        }
        .r-dd-trigger.open::after { transform: translateY(-50%) rotate(180deg); }
        .r-dd-trigger .r-dd-placeholder { color: #9ca3af; font-weight: 400; }
        .r-dd-panel {
            display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 18;
            background: #fff; border: 1px solid var(--r-accent); border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 220px; overflow: hidden;
        }
        .r-dd-panel.open { display: block; }
        .r-dd-wrap.dd-dropup .r-dd-trigger.open {
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
        }
        .r-dd-wrap.dd-dropup .r-dd-panel {
            top: auto;
            bottom: 100%;
            border-top: 1px solid var(--r-accent);
            border-bottom: none;
            border-radius: 10px 10px 0 0;
        }
        .r-dd-search {
            width: 100%; padding: 8px 12px 8px 34px; font-size: 12px; font-weight: 400;
            border: none; border-bottom: 1px solid #f1f5f9; background: #f8fafc;
            color: var(--r-ink); outline: none;
        }
        .r-dd-search::placeholder { color: #9ca3af; }
        .r-dd-search-icon { position: absolute; left: 12px; top: 8px; color: var(--r-muted); }
        .r-dd-list { max-height: 170px; overflow-y: auto; }
        .r-dd-opt {
            padding: 8px 12px; cursor: pointer; transition: background .1s;
            border-bottom: 1px solid #f8fafc;
        }
        .r-dd-opt:last-child { border-bottom: none; }
        .r-dd-opt:hover, .r-dd-opt.active { background: var(--r-accent-soft); }
        .r-dd-opt-name { font-size: 13px; font-weight: 600; color: var(--r-ink); }
        .r-dd-opt-sub { font-size: 11px; color: var(--r-muted); margin-top: 1px; }
        .r-dd-empty { padding: 14px; text-align: center; color: var(--r-muted); font-size: 12px; }

        .repairs-table-shell {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-x: contain;
            max-width: 100%;
        }

        .repairs-table {
            min-width: 940px;
        }
        .repairs-kpi-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        }
        .repairs-kpi-card {
            min-height: 74px;
            border-color: var(--r-border-soft) !important;
            border-radius: 12px !important;
            background: #ffffff !important;
            color: var(--r-ink) !important;
            transition: border-color 160ms ease, background-color 160ms ease, transform 120ms ease;
        }
        .repairs-kpi-card:hover {
            border-color: var(--r-border) !important;
            background: #fffdfb !important;
        }
        .repairs-kpi-card:active {
            transform: scale(0.99);
        }
        .repairs-kpi-icon {
            border: 1px solid var(--r-border-soft);
            background: var(--r-accent-soft) !important;
            color: var(--r-accent) !important;
            border-radius: 10px !important;
        }
        .repairs-kpi-title {
            color: #64748b !important;
            font-size: 11px !important;
            font-weight: 650 !important;
            letter-spacing: .04em !important;
        }
        .repairs-kpi-value {
            color: var(--r-ink) !important;
            font-size: clamp(22px, 2vw, 28px) !important;
            font-weight: 650 !important;
            line-height: 1 !important;
            font-variant-numeric: tabular-nums;
        }

        .repairs-layout > * {
            min-width: 0;
        }

        .repairs-form-card,
        .repairs-table-card {
            min-width: 0;
            border-color: var(--r-border-soft) !important;
            border-radius: 12px !important;
            background: #ffffff;
        }

        .repairs-form-card {
            overflow: visible !important;
        }
        .repairs-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px solid var(--r-border-soft);
            padding: 16px 18px;
        }
        .repairs-card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--r-ink);
            font-size: 17px;
            font-weight: 650;
            letter-spacing: 0;
        }
        .repairs-card-title svg {
            width: 17px;
            height: 17px;
            color: var(--r-accent);
        }
        .repairs-card-subtitle {
            margin-top: 4px;
            color: var(--r-muted);
            font-size: 13px;
            line-height: 1.45;
        }
        .repairs-table-card .r-input {
            min-height: 42px;
        }
        .repairs-data-table {
            border-collapse: separate;
            border-spacing: 0;
        }
        .repairs-data-table thead {
            background: #f8fafc !important;
        }
        .repairs-data-table thead th {
            color: #475569 !important;
            font-weight: 650 !important;
            letter-spacing: .05em !important;
            background: #f8fafc !important;
            border-bottom: 1px solid var(--r-border-soft);
        }
        .repairs-data-table tbody tr {
            transition: background-color 140ms ease;
        }
        .repairs-data-table tbody tr:hover {
            background: #fff7ed !important;
        }
        .repairs-chip {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            border-radius: 999px;
            border: 1px solid var(--r-border-soft);
            background: #f8fafc;
            color: #334155;
            padding: 0 10px;
            font-size: 12px;
            font-weight: 650;
            white-space: nowrap;
        }
        .repairs-chip--gold {
            border-color: #fed7aa;
            background: var(--r-accent-soft);
            color: var(--r-accent-dark);
        }
        .repairs-chip--success {
            border-color: #bbf7d0;
            background: #ecfdf5;
            color: #047857;
        }
        .repairs-chip--info {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1e3a8a;
        }
        .repairs-action-row .btn,
        .repairs-mobile-actions .btn {
            border-radius: 9px !important;
            font-weight: 650 !important;
        }
        .repairs-action-row .btn-primary,
        .repairs-mobile-actions .btn-primary {
            border-color: #fed7aa !important;
            background: var(--r-accent-soft) !important;
            color: var(--r-accent-dark) !important;
        }
        .repairs-action-row .btn-secondary,
        .repairs-mobile-actions .btn-secondary {
            border-color: var(--r-border) !important;
            background: #ffffff !important;
            color: #334155 !important;
        }
        .repairs-action-row .btn-success,
        .repairs-mobile-actions .btn-success {
            border-color: var(--r-accent) !important;
            background: var(--r-accent) !important;
            color: #ffffff !important;
        }
        .repairs-status-select {
            min-height: 30px;
            border: 1px solid var(--r-border);
            border-radius: 9px;
            background-color: #ffffff;
            color: #334155;
            padding: 0 28px 0 10px;
            font-size: 12px;
            font-weight: 650;
        }
        .repairs-status-select:focus {
            border-color: var(--r-accent);
            outline: 2px solid rgba(198, 90, 30, .12);
            outline-offset: 1px;
        }
        .repairs-mobile-list {
            display: none;
        }
        .repairs-mobile-card {
            border: 1px solid var(--r-border-soft);
            border-radius: 12px;
            background: #ffffff;
            padding: 12px;
        }
        .repairs-mobile-card + .repairs-mobile-card {
            margin-top: 10px;
        }
        .repairs-mobile-top,
        .repairs-mobile-meta,
        .repairs-mobile-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .repairs-mobile-top {
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .repairs-mobile-title {
            color: var(--r-ink);
            font-size: 14px;
            font-weight: 650;
        }
        .repairs-mobile-sub {
            color: var(--r-muted);
            font-size: 12px;
            line-height: 1.4;
        }
        .repairs-mobile-meta {
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .repairs-mobile-actions {
            flex-wrap: wrap;
            justify-content: flex-start;
            margin-top: 12px;
        }
        .repairs-empty-state {
            display: grid;
            place-items: center;
            min-height: 280px;
            color: var(--r-muted);
            text-align: center;
        }
        .repairs-empty-state svg {
            width: 44px;
            height: 44px;
            color: #cbd5e1;
            margin-bottom: 10px;
        }
        .repairs-delivered-filter {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            font-size: 13px;
        }
        .repairs-delivered-filter a {
            color: #334155;
            font-weight: 650;
        }
        #deliverModal {
            background: rgba(15, 23, 42, .42) !important;
        }
        #deliverModal .btn-success {
            border-color: var(--r-accent) !important;
            background: var(--r-accent) !important;
            color: #ffffff !important;
        }
        #deliverModal .btn-secondary {
            border-color: var(--r-border) !important;
            background: #ffffff !important;
            color: #334155 !important;
        }

        @media (max-width: 768px) {
            .repairs-page-header {
                min-height: 62px;
                display: grid !important;
                grid-template-columns: 34px minmax(0, 1fr) auto;
                gap: 8px;
                align-items: center;
                padding-inline: 12px !important;
            }
            .repairs-page-header .min-w-0 {
                text-align: center;
            }
            .repairs-page-header .page-title {
                font-size: 16px;
                line-height: 1.15;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .repairs-page-header .page-subtitle {
                display: none;
            }
            .repairs-report-btn {
                min-height: 34px;
                padding-inline: 10px !important;
                font-size: 12px !important;
            }
            .repairs-report-btn svg {
                display: none;
            }
            .repairs-management-page {
                padding-inline: 12px !important;
            }
            .content-inner .repairs-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 8px;
                margin-bottom: 14px;
            }

            .repairs-kpi-grid > div {
                padding: 10px 12px;
                border-radius: 12px;
            }

            .repairs-kpi-grid > div .flex {
                gap: 9px;
            }

            .repairs-kpi-grid > div [class*="p-2"] {
                padding: 6px;
                flex-shrink: 0;
            }

            .repairs-kpi-grid > div [class*="p-2"] svg {
                width: 14px;
                height: 14px;
            }

            .repairs-kpi-grid > div .text-xs {
                font-size: 10px;
                letter-spacing: 0.04em;
            }

            .repairs-kpi-grid > div .text-xl {
                font-size: 22px;
                line-height: 1.1;
            }

            .repairs-layout {
                gap: 14px;
                display: flex;
                flex-direction: column;
            }

            .repairs-register-column {
                order: 1;
            }

            .repairs-form-column {
                order: 2;
            }

            .repairs-form-card,
            .repairs-table-card {
                border-radius: 12px;
            }

            .repairs-form-card > .p-6,
            .repairs-table-card > .p-6 {
                padding: 14px;
            }

            .repairs-form {
                padding: 14px;
                gap: 14px;
            }

            .repairs-card-header {
                padding: 14px;
                align-items: flex-start;
            }

            .repairs-table-card .repairs-card-header {
                display: grid;
                grid-template-columns: 1fr;
            }

            .repairs-table-card .repairs-card-header > .flex {
                justify-content: stretch;
            }

            .repairs-table-card .repairs-card-header [style*="max-width"] {
                max-width: none !important;
            }

            .repairs-table-shell {
                display: none;
            }

            .repairs-mobile-list {
                display: block;
                padding: 12px;
            }

            .repairs-mobile-actions .btn {
                min-height: 32px;
                padding: 6px 9px;
                font-size: 12px;
            }

            .repairs-status-select {
                min-height: 32px;
                font-size: 12px;
            }

            .repairs-empty-state {
                min-height: 180px;
                padding: 20px 12px;
            }

            #deliverModal .min-h-full {
                align-items: flex-end;
                padding: 0;
            }

            #deliverModal .r-modal-card {
                max-width: none;
                width: 100%;
                border-radius: 16px 16px 0 0;
                border-bottom: 0;
            }
        }

        .repairs-management-page .repairs-kpi-card,
        .repairs-management-page .repairs-surface-card,
        .repairs-management-page .repairs-mobile-card,
        .repairs-management-page .r-modal-card,
        .repairs-management-page .r-input,
        .repairs-management-page .r-dd-trigger,
        .repairs-management-page .r-dd-panel,
        #deliverModal .r-modal-card {
            box-shadow: none !important;
        }
    </style>
    <x-page-header class="repairs-page-header" title="Repairs" subtitle="Receive items, track progress, and deliver repair work">
        <x-slot:actions>
            <a href="{{ route('report.repairs') }}"
               class="btn btn-success btn-sm repairs-report-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 20v-6m-6 6V4m-6 16v-4"/>
                </svg>
                Repairs Report
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner repairs-management-page">
        @php
            $receivedCount   = ($statusCounts['received']   ?? 0) + ($statusCounts['pending'] ?? 0);
            $inRepairCount   = $statusCounts['in_repair']   ?? 0;
            $readyCount      = $statusCounts['ready']       ?? 0;
            $deliveredCount  = $statusCounts['delivered']   ?? 0;
            $canModifyRepairs = auth()->user()->can('repairs.create')
                || auth()->user()->can('repairs.edit')
                || auth()->user()->can('repairs.delete');
        @endphp
        @unless($canModifyRepairs)
            @include('partials.view-only-banner', ['permission' => 'repairs.edit', 'message' => 'repair management'])
        @endunless

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6 repairs-kpi-grid">
            <div class="bg-white rounded-lg border border-gray-200 p-4 repairs-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-600 rounded-lg p-2 repairs-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 repairs-kpi-title">Received</p>
                        <p class="text-xl font-semibold text-gray-900 repairs-kpi-value">{{ $receivedCount }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4 repairs-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-lg p-2 repairs-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 repairs-kpi-title">In Repair</p>
                        <p class="text-xl font-semibold text-gray-900 repairs-kpi-value">{{ $inRepairCount }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg border border-gray-200 p-4 repairs-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-100 text-blue-700 rounded-lg p-2 repairs-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 repairs-kpi-title">Ready</p>
                        <p class="text-xl font-semibold text-gray-900 repairs-kpi-value">{{ $readyCount }}</p>
                    </div>
                </div>
            </div>
            {{-- Delivered repairs were hidden from the list forever (H4). This
                 KPI card now links to the delivered filter so repair history is
                 reachable after billing. --}}
            <a href="{{ route('repairs.index', ['status' => 'delivered']) }}" class="bg-white rounded-lg border border-gray-200 p-4 repairs-kpi-card block hover:border-emerald-300 transition" title="View delivered repairs">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-lg p-2 repairs-kpi-icon">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500 repairs-kpi-title">Delivered</p>
                        <p class="text-xl font-semibold text-gray-900 repairs-kpi-value">{{ $deliveredCount }}</p>
                    </div>
                </div>
            </a>
        </div>
        @if(request('status') === 'delivered')
            <div class="repairs-delivered-filter">
                <span class="repairs-chip repairs-chip--success">Showing delivered repairs</span>
                <a href="{{ route('repairs.index') }}">Back to active repairs</a>
            </div>
        @endif

        @php $canCreateRepair = auth()->user()->can('repairs.create'); @endphp
        <div class="grid grid-cols-1 {{ $canCreateRepair ? 'lg:grid-cols-3' : 'lg:grid-cols-1' }} gap-6 repairs-layout">
            @can('repairs.create')
            <!-- New Repair Form -->
            <div class="lg:col-span-1 repairs-form-column">
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden repairs-form-card repairs-surface-card">
                    <div class="repairs-card-header">
                        <div>
                        <h2 class="repairs-card-title">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Receive New Repair
                        </h2>
                        <p class="repairs-card-subtitle">Register item for repair work</p>
                        </div>
                    </div>
                    
                    <form method="POST" action="{{ route('repairs.store') }}" class="p-6 space-y-6 repairs-form" enctype="multipart/form-data">
                        @csrf
                        
                        <!-- Customer Selection -->
                        <div class="r-form-group">
                            <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Customer</label>
                            <input type="hidden" name="customer_id" id="customer_id" required>
                            <div class="r-dd-wrap" id="custDdWrap">
                                <button type="button" class="r-dd-trigger" id="custDdTrigger" onclick="toggleDd('cust')">
                                    <span class="r-dd-placeholder">Select Customer</span>
                                </button>
                                <div class="r-dd-panel" id="custDdPanel">
                                    <div style="position:relative">
                                        <span class="r-dd-search-icon"><svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></span>
                                        <input type="text" class="r-dd-search" id="custDdSearch" placeholder="Search customer… (press Enter to add new)" oninput="filterCustDd()" onkeydown="handleCustSearchKey(event)">
                                    </div>
                                    <div class="r-dd-list" id="custDdList">
                                        @foreach($customers as $c)
                                            <div class="r-dd-opt" data-id="{{ $c->id }}" data-name="{{ $c->name }}" data-mobile="{{ $c->mobile }}" onclick="selectCust(this)">
                                                <div class="r-dd-opt-name">{{ $c->name }}</div>
                                                <div class="r-dd-opt-sub">{{ $c->mobile }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @error('customer_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Item Description -->
                        <div class="r-form-group">
                            <label for="item_description" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Item Description</label>
                            <input type="text" name="item_description" id="item_description" 
                                   placeholder="e.g., Gold Ring, Chain, Bracelet"
                                   class="r-input" required>
                            @error('item_description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Repair Description -->
                        <div class="r-form-group">
                            <label for="description" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Repair Description</label>
                            <textarea name="description" id="description" rows="3"
                                      placeholder="e.g., Resize from size 6 to size 7, Broken link in chain needs soldering"
                                      class="r-textarea"></textarea>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Metal type -->
                        <div class="r-form-group">
                            <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>Metal</label>
                            <input type="hidden" name="metal_type" id="metal_type" value="gold">
                            <div class="r-dd-wrap" id="metalDdWrap">
                                <button type="button" class="r-dd-trigger" id="metalDdTrigger" onclick="toggleDd('metal')">
                                    <span style="font-weight:600;color:var(--r-ink)">Gold</span>
                                </button>
                                <div class="r-dd-panel" id="metalDdPanel">
                                    <div class="r-dd-list" id="metalDdList">
                                        <div class="r-dd-opt" data-value="gold" onclick="selectMetal(this)"><div class="r-dd-opt-name">Gold</div><div class="r-dd-opt-sub">Karat purity (24K–14K)</div></div>
                                        <div class="r-dd-opt" data-value="silver" onclick="selectMetal(this)"><div class="r-dd-opt-name">Silver</div><div class="r-dd-opt-sub">Fineness (999 / 925 / 900)</div></div>
                                        <div class="r-dd-opt" data-value="platinum" onclick="selectMetal(this)"><div class="r-dd-opt-name">Platinum</div><div class="r-dd-opt-sub">Fineness (999 / 950 / 900)</div></div>
                                        <div class="r-dd-opt" data-value="other" onclick="selectMetal(this)"><div class="r-dd-opt-name">Other</div><div class="r-dd-opt-sub">Imitation / mixed — purity optional</div></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Gross Weight + Purity row -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="r-form-group">
                                <label for="gross_weight" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>Gross Weight (g)</label>
                                <input type="number" step="0.001" name="gross_weight" id="gross_weight"
                                       placeholder="0.000"
                                       class="r-input" required>
                                @error('gross_weight')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="r-form-group">
                                <label class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg><span id="purityLabelText">Purity</span> <span style="color:var(--muted);font-weight:normal">(optional)</span></label>
                                <input type="hidden" name="purity" id="purity">
                                <div class="r-dd-wrap" id="purityDdWrap">
                                    <button type="button" class="r-dd-trigger" id="purityDdTrigger" onclick="toggleDd('purity')">
                                        <span class="r-dd-placeholder">Select Purity</span>
                                    </button>
                                    <div class="r-dd-panel" id="purityDdPanel">
                                        <div class="r-dd-list" id="purityDdList">
                                            {{-- Options injected by JS based on the selected metal --}}
                                        </div>
                                    </div>
                                </div>

                                <!-- Custom purity input (hidden by default) -->
                                <input type="number" step="0.01" id="custom_purity" placeholder="Enter a custom value"
                                       class="hidden r-input mt-2" oninput="document.getElementById('purity').value = this.value">

                                @error('purity')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>


                        <!-- Item Photo -->
                        <div class="r-form-group">
                            <label for="repair_image" class="r-label">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Item Photo <span class="text-gray-400 text-xs font-normal">(optional)</span>
                            </label>
                            <input type="file" name="image" id="repair_image" accept="image/*" class="r-input" onchange="previewRepairImage(event)">
                            <div id="repair_image_preview" class="mt-2 hidden">
                                <img id="repair_image_preview_img" src="" alt="Preview" style="max-width:100%;max-height:180px;border-radius:8px;border:1px solid #e5e7eb;">
                                <button type="button" class="text-xs text-red-600 mt-1 underline" onclick="clearRepairImage()">Remove</button>
                            </div>
                            @error('image')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Estimated Cost -->
                        <div class="r-form-group">
                            <label for="estimated_cost" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Estimated Cost (₹)</label>
                            <input type="number" step="0.01" name="estimated_cost" id="estimated_cost" 
                                   placeholder="0.00"
                                   class="r-input">
                            @error('estimated_cost')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-dark w-full mt-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            Receive Repair Item
                        </button>
                    </form>
                </div>
            </div>
            @endcan

            <!-- Repairs List -->
            <div class="{{ $canCreateRepair ? 'lg:col-span-2' : 'lg:col-span-1' }} repairs-register-column">
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden repairs-table-card repairs-surface-card">
                    <div class="repairs-card-header">
                        <div>
                        <h2 class="repairs-card-title">
                            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
                            Active Repairs
                        </h2>
                        <p class="repairs-card-subtitle">Search, update status, and bill completed work.</p>
                        </div>
                        <div class="flex items-center gap-2 flex-1 justify-end">
                            <div style="position:relative;flex:1;max-width:360px">
                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                                </span>
                                <input type="text" id="repairsSearchInput"
                                       placeholder="Search repair #, item, customer, mobile…"
                                       class="r-input"
                                       style="padding-left:32px;width:100%"
                                       oninput="filterRepairsList()"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();}">
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="clearRepairsSearch()" id="repairsSearchClear" style="display:none">Clear</button>
                        </div>
                    </div>

                    <div class="overflow-auto repairs-table-shell repairs-data-table-shell" style="height:560px;padding-bottom:12px">
                        <table class="w-full repairs-table repairs-data-table">
                            <thead class="bg-gray-50 border-b border-gray-200" style="position:sticky;top:0;z-index:1;background:#f9fafb">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Repair #</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Weight</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purity</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="repairsTbody">
                                @forelse($repairs as $r)
                                    @php
                                        $searchBlob = strtolower(trim(implode(' ', array_filter([
                                            'rep-'.str_pad($r->repair_number, 3, '0', STR_PAD_LEFT),
                                            (string) $r->repair_number,
                                            $r->customer?->name,
                                            $r->customer?->mobile,
                                            $r->item_description,
                                            $r->description,
                                        ]))));
                                    @endphp
                                    <tr class="hover:bg-gray-50 transition-colors" data-search="{{ $searchBlob }}">
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <span class="repairs-chip repairs-chip--gold font-mono">
                                                REP-{{ str_pad($r->repair_number, 3, '0', STR_PAD_LEFT) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">{{ $r->customer->name }}</div>
                                            <div class="text-sm text-gray-500">{{ $r->customer->mobile }}</div>
                                        </td>
                                        <td class="px-3 py-4 repairs-item-col">
                                            <div class="text-sm font-medium text-gray-900">{{ $r->item_description }}</div>
                                            @if($r->description)
                                                <div class="text-xs text-gray-500 mt-0.5">{{ Str::limit($r->description, 60) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">{{ number_format($r->gross_weight, 3) }} g</div>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <span class="repairs-chip">
                                                {{ $r->purityLabel() ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            @php
                                                $statusBadge = match($r->status) {
                                                    'delivered'  => ['repairs-chip--success', 'M5 13l4 4L19 7', 'Delivered'],
                                                    'ready'      => ['repairs-chip--info',    'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'Ready'],
                                                    'in_repair'  => ['repairs-chip--gold',    'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z', 'In Repair'],
                                                    default      => ['',                    'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4', 'Received'],
                                                };
                                            @endphp
                                            <span class="repairs-chip {{ $statusBadge[0] }}">
                                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $statusBadge[1] }}"/></svg>{{ $statusBadge[2] }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-center">
                                            <div class="flex items-center justify-center gap-2 repairs-action-row">
                                                <a href="{{ route('repairs.show', $r) }}"
                                                   class="btn btn-primary btn-xs">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                    </svg>
                                                    View
                                                </a>
                                                @if($r->status !== 'delivered')
                                                    @can('repairs.edit')
                                                    <form method="POST" action="{{ route('repairs.status', $r) }}" class="inline">
                                                        @csrf
                                                        @method('PATCH')
                                                        <select name="status" onchange="this.form.submit()" title="Change status"
                                                                class="repairs-status-select">
                                                            <option value="received"  @selected($r->status === 'received')>Received</option>
                                                            <option value="in_repair" @selected($r->status === 'in_repair')>In Repair</option>
                                                            <option value="ready"     @selected($r->status === 'ready')>Ready</option>
                                                        </select>
                                                    </form>
                                                    <a href="{{ route('repairs.edit', $r) }}"
                                                       class="btn btn-secondary btn-xs">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                        </svg>
                                                        Edit
                                                    </a>
                                                    <button onclick="openDeliverModal({{ $r->id }}, @js($r->customer->name), @js($r->item_description), {{ $r->estimated_cost ?? 0 }})"
                                                            class="btn btn-success btn-xs" title="Complete & Bill">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Bill
                                                    </button>
                                                    @endcan
                                                @endif
                                                @if($r->status === 'delivered' && $r->invoice_id)
                                                    <a href="{{ route('invoices.show', $r->invoice_id) }}"
                                                       class="btn btn-primary btn-xs">
                                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>View Invoice
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                            <div class="text-sm font-medium">No repairs registered yet</div>
                                            <div class="text-xs mt-1">Receive an item to get started</div>
                                        </td>
                                    </tr>
                                @endforelse
                                <tr id="repairsNoMatchRow" style="display:none">
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-400 text-sm">No repairs match your search.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="repairs-mobile-list" id="repairsMobileList">
                        @forelse($repairs as $r)
                            @php
                                $mobileSearchBlob = strtolower(trim(implode(' ', array_filter([
                                    'rep-'.str_pad($r->repair_number, 3, '0', STR_PAD_LEFT),
                                    (string) $r->repair_number,
                                    $r->customer?->name,
                                    $r->customer?->mobile,
                                    $r->item_description,
                                    $r->description,
                                ]))));
                                $mobileStatusBadge = match($r->status) {
                                    'delivered' => ['repairs-chip--success', 'Delivered'],
                                    'ready' => ['repairs-chip--info', 'Ready'],
                                    'in_repair' => ['repairs-chip--gold', 'In Repair'],
                                    default => ['', 'Received'],
                                };
                            @endphp
                            <article class="repairs-mobile-card" data-search="{{ $mobileSearchBlob }}">
                                <div class="repairs-mobile-top">
                                    <span class="repairs-chip repairs-chip--gold font-mono">REP-{{ str_pad($r->repair_number, 3, '0', STR_PAD_LEFT) }}</span>
                                    <span class="repairs-chip {{ $mobileStatusBadge[0] }}">{{ $mobileStatusBadge[1] }}</span>
                                </div>
                                <div class="repairs-mobile-title">{{ $r->customer->name }}</div>
                                <div class="repairs-mobile-sub">{{ $r->customer->mobile }}</div>
                                <div class="mt-3">
                                    <div class="repairs-mobile-title">{{ $r->item_description }}</div>
                                    @if($r->description)
                                        <div class="repairs-mobile-sub">{{ Str::limit($r->description, 82) }}</div>
                                    @endif
                                </div>
                                <div class="repairs-mobile-meta">
                                    <span class="repairs-chip">{{ number_format($r->gross_weight, 3) }} g</span>
                                    <span class="repairs-chip">{{ $r->purityLabel() ?? 'No purity' }}</span>
                                </div>
                                <div class="repairs-mobile-actions">
                                    <a href="{{ route('repairs.show', $r) }}" class="btn btn-primary btn-xs">View</a>
                                    @if($r->status !== 'delivered')
                                        @can('repairs.edit')
                                            <form method="POST" action="{{ route('repairs.status', $r) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <select name="status" onchange="this.form.submit()" title="Change status" class="repairs-status-select">
                                                    <option value="received"  @selected($r->status === 'received')>Received</option>
                                                    <option value="in_repair" @selected($r->status === 'in_repair')>In Repair</option>
                                                    <option value="ready"     @selected($r->status === 'ready')>Ready</option>
                                                </select>
                                            </form>
                                            <a href="{{ route('repairs.edit', $r) }}" class="btn btn-secondary btn-xs">Edit</a>
                                            <button onclick="openDeliverModal({{ $r->id }}, @js($r->customer->name), @js($r->item_description), {{ $r->estimated_cost ?? 0 }})"
                                                    class="btn btn-success btn-xs" title="Complete and bill">Bill</button>
                                        @endcan
                                    @endif
                                    @if($r->status === 'delivered' && $r->invoice_id)
                                        <a href="{{ route('invoices.show', $r->invoice_id) }}" class="btn btn-primary btn-xs">Invoice</a>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="repairs-empty-state">
                                <div>
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <div class="text-sm font-medium text-slate-700">No repairs registered yet</div>
                                    <div class="text-xs mt-1 text-slate-500">Receive an item below to get started.</div>
                                </div>
                            </div>
                        @endforelse
                        <div id="repairsMobileNoMatch" class="repairs-empty-state" style="display:none">
                            <div>
                                <div class="text-sm font-medium text-slate-700">No repairs match your search</div>
                                <div class="text-xs mt-1 text-slate-500">Try repair number, customer name, mobile, or item.</div>
                            </div>
                        </div>
                    </div>
                    
                    @if($repairs->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">
                            {{ $repairs->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Delivery Modal -->
    <div id="deliverModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 z-50">
        <div class="min-h-full w-full flex items-center justify-center p-4">
            <div class="w-full max-w-md p-5 bg-white r-modal-card">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 flex items-center gap-2"><svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>Deliver Repair Item</h3>
                    <button onclick="closeDeliverModal()" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                
                <div class="mb-4 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <div class="text-sm text-gray-600 flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Customer</div>
                    <div class="font-medium" id="modalCustomerName"></div>
                    <div class="text-sm text-gray-600 mt-2 flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>Item</div>
                    <div class="font-medium" id="modalItemDesc"></div>
                </div>

                <form id="deliverForm" method="POST" action="">
                    @csrf
                    <div class="mb-4">
                        <label for="amount" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Service Amount (Before GST) (₹)</label>
                        <input type="number" step="0.01" name="amount" id="amount" 
                               class="r-input" required>
                    </div>

                    <div class="mb-4 space-y-3">
                        <input type="hidden" name="include_gst" value="0">
                        <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
                            <input type="checkbox" id="include_gst" name="include_gst" value="1" class="r-checkbox">
                            Include GST in Repair Invoice
                        </label>

                        <div id="gstRateWrap" class="hidden">
                            <label for="gst_rate" class="r-label"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/></svg>GST Rate (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="gst_rate" id="gst_rate"
                                   value="{{ auth()->user()->shop->gst_rate ?? 3 }}"
                                   class="r-input">
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="closeDeliverModal()"
                                class="btn btn-secondary flex-1">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>Cancel
                        </button>
                        <button type="submit"
                                class="btn btn-success flex-1">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>Deliver & Bill
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        /* ─── Metal-aware purity (server-driven presets) ── */
        // Single source of truth: App\Models\Repair::PURITY_OPTIONS.
        window.REPAIR_PURITY_OPTIONS = @json(\App\Models\Repair::PURITY_OPTIONS);
        const REPAIR_PURITY_SUBS = {
            gold:     { '24':'99.9% pure', '22':'91.6% pure', '21':'87.5% pure', '18':'75.0% pure', '14':'58.3% pure' },
            silver:   { '999':'99.9% pure', '925':'Sterling', '900':'Coin silver' },
            platinum: { '999':'99.9% pure', '950':'95.0% pure', '900':'90.0% pure' },
            other:    {}
        };

        function selectMetal(el) {
            const val = el.dataset.value;
            const label = el.querySelector('.r-dd-opt-name').textContent;
            document.getElementById('metal_type').value = val;
            document.getElementById('metalDdTrigger').innerHTML =
                '<span style="font-weight:600;color:var(--r-ink)">' + escHtml(label) + '</span>';
            closeAllDd();
            rebuildPurityOptions(val);
        }

        // Rebuild the purity dropdown for the chosen metal and reset the current
        // selection (the old purity scale rarely applies to the new metal).
        function rebuildPurityOptions(metal) {
            const list = document.getElementById('purityDdList');
            const trigger = document.getElementById('purityDdTrigger');
            const hiddenPurity = document.getElementById('purity');
            const customInput = document.getElementById('custom_purity');
            const labelText = document.getElementById('purityLabelText');
            if (!list) return;

            const opts = (window.REPAIR_PURITY_OPTIONS[metal] || []);
            const subs = REPAIR_PURITY_SUBS[metal] || {};
            let html = '';
            opts.forEach((o) => {
                const sub = subs[o.value] ? escHtml(subs[o.value]) : '';
                html += '<div class="r-dd-opt" data-value="' + escHtml(o.value) + '" onclick="selectPurity(this)">'
                      + '<div class="r-dd-opt-name">' + escHtml(o.label) + '</div>'
                      + (sub ? '<div class="r-dd-opt-sub">' + sub + '</div>' : '')
                      + '</div>';
            });
            // Always allow a custom value (and it's the only choice for "other").
            const customSub = metal === 'gold' ? 'Enter a custom karat value'
                            : (metal === 'other' ? 'Enter a value if known' : 'Enter a custom fineness');
            html += '<div class="r-dd-opt" data-value="custom" onclick="selectPurity(this)">'
                  + '<div class="r-dd-opt-name">Custom</div>'
                  + '<div class="r-dd-opt-sub">' + customSub + '</div></div>';
            list.innerHTML = html;

            // Reset current selection for the new metal.
            if (hiddenPurity) hiddenPurity.value = '';
            if (customInput) { customInput.classList.add('hidden'); customInput.required = false; customInput.value = ''; }
            if (trigger) trigger.innerHTML = '<span class="r-dd-placeholder">Select Purity</span>';
            if (labelText) labelText.textContent = (metal === 'gold') ? 'Purity (Karat)'
                                                 : (metal === 'other' ? 'Purity' : 'Purity (Fineness)');
        }

        /* ─── Custom Dropdown Logic ──────── */
        function shouldAutoFocusInlineSearch() {
            const isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
            const isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
            return !(isMobileViewport || isTouchDevice);
        }

        function focusWithoutScroll(el) {
            if (!el) return;
            try {
                el.focus({ preventScroll: true });
            } catch (_) {
                el.focus();
            }
        }

        function toggleDd(type) {
            const trigger = document.getElementById(type + 'DdTrigger');
            const panel = document.getElementById(type + 'DdPanel');
            const isOpen = panel.classList.contains('open');
            const wrap = trigger?.closest('.r-dd-wrap');

            // Close all dropdowns first
            closeAllDd();

            if (!isOpen) {
                trigger.classList.add('open');
                panel.classList.add('open');
                if (wrap) {
                    wrap.classList.add('dd-open');
                }
                updateDdDirection(panel);
                const search = panel.querySelector('.r-dd-search');
                if (search) {
                    search.value = '';
                    filterCustDd();

                    // Avoid mobile viewport jump caused by auto-focusing the search field.
                    if (shouldAutoFocusInlineSearch()) {
                        setTimeout(() => focusWithoutScroll(search), 50);
                    }
                }
            }
        }

        function closeAllDd() {
            document.querySelectorAll('.r-dd-wrap').forEach((wrap) => {
                wrap.classList.remove('dd-open', 'dd-dropup');
            });
            document.querySelectorAll('.r-dd-trigger').forEach(t => t.classList.remove('open'));
            document.querySelectorAll('.r-dd-panel').forEach(p => p.classList.remove('open'));
            const active = document.activeElement;
            if (active && active.classList && active.classList.contains('r-dd-search')) {
                active.blur();
            }
        }

        function updateDdDirection(panel) {
            if (!panel) return;
            const wrap = panel.closest('.r-dd-wrap');
            if (!wrap) return;

            wrap.classList.remove('dd-dropup');

            const panelStyles = window.getComputedStyle(panel);
            const maxHeight = parseFloat(panelStyles.maxHeight) || panel.scrollHeight || 220;
            const panelHeight = Math.min(panel.scrollHeight || maxHeight, maxHeight) + 12;
            const rect = wrap.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;

            if (spaceBelow < panelHeight && spaceAbove > spaceBelow) {
                wrap.classList.add('dd-dropup');
            }
        }

        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.r-dd-wrap')) closeAllDd();
        });

        // Close dropdowns when the page scrolls so panels don't drift into sticky header.
        document.querySelector('.content-body')?.addEventListener('scroll', closeAllDd, { passive: true });
        window.addEventListener('scroll', closeAllDd, { passive: true });
        window.addEventListener('resize', () => {
            document.querySelectorAll('.r-dd-panel.open').forEach(updateDdDirection);
        }, { passive: true });

        /* ─── Customer Dropdown ──────────── */
        function filterCustDd() {
            const q = (document.getElementById('custDdSearch')?.value || '').toLowerCase();
            const opts = document.querySelectorAll('#custDdList .r-dd-opt');
            let visible = 0;
            opts.forEach(opt => {
                const name = (opt.dataset.name || '').toLowerCase();
                const mobile = (opt.dataset.mobile || '').toLowerCase();
                const show = name.includes(q) || mobile.includes(q);
                opt.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            // Show/hide empty state
            let empty = document.getElementById('custDdEmpty');
            if (visible === 0) {
                if (!empty) {
                    empty = document.createElement('div');
                    empty.id = 'custDdEmpty';
                    empty.className = 'r-dd-empty';
                    empty.textContent = 'No customer found';
                    document.getElementById('custDdList').appendChild(empty);
                }
                empty.style.display = '';
            } else if (empty) {
                empty.style.display = 'none';
            }
        }

        function selectCust(el) {
            const id = el.dataset.id;
            const name = el.dataset.name;
            const mobile = el.dataset.mobile;
            document.getElementById('customer_id').value = id;
            const trigger = document.getElementById('custDdTrigger');
            trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">' + escHtml(name) + '</span> <span style="font-size:11px;color:var(--r-muted);margin-left:4px">' + escHtml(mobile) + '</span>';
            closeAllDd();
        }

        /* Enter in customer search with no match → offer quick-add. */
        function handleCustSearchKey(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const input = document.getElementById('custDdSearch');
            const name = (input.value || '').trim();
            if (!name) return;
            const visible = Array.from(document.querySelectorAll('#custDdList .r-dd-opt'))
                .filter(o => o.style.display !== 'none');
            if (visible.length > 0) {
                selectCust(visible[0]);
                return;
            }
            if (!confirm('No matching customer. Add "' + name + '" as a new customer?')) return;
            quickAddCustomer(name);
        }

        function quickAddCustomer(name) {
            const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const fd = new FormData();
            fd.append('name', name);
            fd.append('_token', token);
            fetch('{{ route('customers.quick-store') }}', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd,
                credentials: 'same-origin',
            }).then(r => r.ok ? r.json() : r.json().then(j => Promise.reject(j)))
              .then(c => {
                  const list = document.getElementById('custDdList');
                  const opt = document.createElement('div');
                  opt.className = 'r-dd-opt';
                  opt.dataset.id = c.id;
                  opt.dataset.name = c.name;
                  opt.dataset.mobile = c.mobile || '';
                  opt.onclick = function () { selectCust(opt); };
                  const nameDiv = document.createElement('div');
                  nameDiv.className = 'r-dd-opt-name';
                  nameDiv.textContent = c.name;
                  const subDiv = document.createElement('div');
                  subDiv.className = 'r-dd-opt-sub';
                  subDiv.textContent = c.mobile || '—';
                  opt.appendChild(nameDiv);
                  opt.appendChild(subDiv);
                  list.insertBefore(opt, list.firstChild);
                  const empty = document.getElementById('custDdEmpty');
                  if (empty) empty.style.display = 'none';
                  document.getElementById('custDdSearch').value = '';
                  filterCustDd();
                  selectCust(opt);
              })
              .catch(err => {
                  const msg = (err && err.message) || 'Failed to add customer.';
                  alert(msg);
              });
        }

        function previewRepairImage(e) {
            const file = e.target.files && e.target.files[0];
            const wrap = document.getElementById('repair_image_preview');
            const img = document.getElementById('repair_image_preview_img');
            if (!file) { wrap.classList.add('hidden'); img.src = ''; return; }
            const reader = new FileReader();
            reader.onload = ev => { img.src = ev.target.result; wrap.classList.remove('hidden'); };
            reader.readAsDataURL(file);
        }
        function clearRepairImage() {
            const input = document.getElementById('repair_image');
            input.value = '';
            document.getElementById('repair_image_preview').classList.add('hidden');
            document.getElementById('repair_image_preview_img').src = '';
        }

        function filterRepairsList() {
            const input = document.getElementById('repairsSearchInput');
            const q = (input?.value || '').trim().toLowerCase();
            const rows = document.querySelectorAll('#repairsTbody tr[data-search]');
            const cards = document.querySelectorAll('#repairsMobileList .repairs-mobile-card[data-search]');
            let visible = 0;
            rows.forEach(r => {
                const hay = r.dataset.search || '';
                const show = !q || hay.includes(q);
                r.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            let visibleCards = 0;
            cards.forEach(card => {
                const hay = card.dataset.search || '';
                const show = !q || hay.includes(q);
                card.style.display = show ? '' : 'none';
                if (show) visibleCards++;
            });
            const noMatch = document.getElementById('repairsNoMatchRow');
            if (noMatch) noMatch.style.display = (q && visible === 0) ? '' : 'none';
            const mobileNoMatch = document.getElementById('repairsMobileNoMatch');
            if (mobileNoMatch) mobileNoMatch.style.display = (q && visibleCards === 0) ? '' : 'none';
            const clearBtn = document.getElementById('repairsSearchClear');
            if (clearBtn) clearBtn.style.display = q ? '' : 'none';
        }
        function clearRepairsSearch() {
            const input = document.getElementById('repairsSearchInput');
            if (!input) return;
            input.value = '';
            filterRepairsList();
            input.focus();
        }

        /* ─── Purity Dropdown ────────────── */
        function selectPurity(el) {
            const val = el.dataset.value;
            const label = el.querySelector('.r-dd-opt-name').textContent;
            const trigger = document.getElementById('purityDdTrigger');
            const customInput = document.getElementById('custom_purity');
            const hiddenPurity = document.getElementById('purity');

            if (val === 'custom') {
                trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">Custom</span>';
                customInput.classList.remove('hidden');
                customInput.required = true;
                hiddenPurity.value = '';
                closeAllDd();
                if (shouldAutoFocusInlineSearch()) {
                    setTimeout(() => focusWithoutScroll(customInput), 50);
                }
            } else {
                trigger.innerHTML = '<span style="font-weight:600;color:var(--r-ink)">' + escHtml(label) + '</span>';
                hiddenPurity.value = val;
                customInput.classList.add('hidden');
                customInput.required = false;
                customInput.value = '';
                closeAllDd();
            }
        }

        /* ─── Helpers ────────────────────── */
        function escHtml(str) {
            const d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        /* ─── Delivery Modal ─────────────── */
        function openDeliverModal(repairId, customerName, itemDesc, estimatedCost) {
            document.getElementById('deliverModal').classList.remove('hidden');
            document.getElementById('modalCustomerName').textContent = customerName;
            document.getElementById('modalItemDesc').textContent = itemDesc;
            document.getElementById('amount').value = estimatedCost || '';
            document.getElementById('include_gst').checked = false;
            document.getElementById('gstRateWrap').classList.add('hidden');
            document.getElementById('deliverForm').action = `/repairs/${repairId}/deliver`;
        }

        function closeDeliverModal() {
            document.getElementById('deliverModal').classList.add('hidden');
        }

        document.getElementById('deliverModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeDeliverModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeAllDd(); closeDeliverModal(); }
        });

        document.getElementById('include_gst')?.addEventListener('change', function() {
            document.getElementById('gstRateWrap')?.classList.toggle('hidden', !this.checked);
        });

        // Populate the purity dropdown for the default metal (gold) on load.
        rebuildPurityOptions(document.getElementById('metal_type')?.value || 'gold');
    </script>
</x-app-layout>
