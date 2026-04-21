<x-app-layout>
    @php
        $shop = auth()->user()->shop;
        $shopName = $shop->name ?? 'Your Jewellery Store';
        $shopPhone = $shop->phone ?? '';
    @endphp

    <style>
        .wa-catalog-root {
            --wa-bg-1: #eef6f5;
            --wa-bg-2: #d8ebe8;
            --wa-accent: #0f766e;
            --wa-accent-dark: #115e59;
            --wa-ink: #0f172a;
            --wa-muted: #475569;
            --wa-olive: #b45309;
        }
        .wa-hero {
            border: 1px solid #cfe3df;
            border-radius: 16px;
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--wa-bg-1), var(--wa-bg-2));
            margin-bottom: 18px;
            display: grid;
            gap: 12px;
        }
        .wa-hero-copy {
            min-width: 0;
            max-width: 760px;
        }
        .wa-hero-title {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--wa-ink);
            letter-spacing: -0.02em;
        }
        .wa-hero-sub {
            margin-top: 4px;
            color: var(--wa-muted);
            font-size: 0.93rem;
        }
        .wa-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 2px;
        }
        .wa-template-chip {
            border: 1px solid #c7d7d3;
            background: #fff;
            color: #1f2937;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all .15s ease;
            min-height: 32px;
            white-space: nowrap;
            flex: 0 0 auto;
        }
        .wa-template-chip.active {
            background: var(--wa-accent);
            border-color: var(--wa-accent);
            color: #fff;
            box-shadow: 0 6px 16px rgba(15,118,110,.18);
        }
        .wa-tools {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
            align-items: end;
        }
        .wa-custom-grid {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 10px;
        }
        .wa-custom-field {
            grid-column: span 12;
        }
        .wa-custom-hint {
            margin-top: 8px;
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }
        .wa-textarea {
            width: 100%;
            border: 1px solid #ced8d5;
            border-radius: 12px;
            padding: 9px 11px;
            font-size: 13px;
            color: #0f172a;
            background: #fff;
            min-height: 124px;
            resize: vertical;
            outline: none;
            font-family: inherit;
        }
        .wa-textarea:focus {
            border-color: var(--wa-accent);
            box-shadow: 0 0 0 3px rgba(15,118,110,.12);
        }
        @media (min-width: 768px) {
            .wa-custom-field.header { grid-column: span 6; }
            .wa-custom-field.footer { grid-column: span 6; }
            .wa-custom-field.body { grid-column: span 12; }
        }
        .wa-field {
            grid-column: span 12;
        }
        .wa-field-actions {
            display: flex;
            justify-content: flex-end;
            align-items: end;
            gap: 8px;
        }
        .wa-filter-btn {
            width: 100%;
            max-width: 170px;
        }
        .wa-filter-btn-clear {
            background: #475569;
        }
        .wa-field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .wa-input, .wa-select {
            width: 100%;
            border: 1px solid #ced8d5;
            border-radius: 12px;
            padding: 9px 11px;
            font-size: 14px;
            color: #0f172a;
            background: #fff;
            outline: none;
        }
        .wa-input:focus, .wa-select:focus {
            border-color: var(--wa-accent);
            box-shadow: 0 0 0 3px rgba(15,118,110,.12);
        }
        .wa-select-all-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #d8e2ef;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all .15s ease;
            white-space: nowrap;
            height: 38px;
        }
        .wa-select-all-label:hover {
            border-color: var(--wa-accent);
            background: #edf8f6;
        }
        .wa-select-all-label.all-selected {
            background: var(--wa-accent);
            border-color: var(--wa-accent);
            color: #fff;
        }
        .wa-select-all-checkbox {
            accent-color: var(--wa-accent);
            width: 16px;
            height: 16px;
        }
        @media (min-width: 640px) {
            .wa-field.search { grid-column: span 6; }
            .wa-field.category { grid-column: span 6; }
            .wa-field.phone { grid-column: span 6; }
            .wa-field-select-all { grid-column: span 6; }
            .wa-field-actions { grid-column: span 12; }
        }
        @media (min-width: 900px) {
            .wa-field.search { grid-column: span 5; }
            .wa-field.category { grid-column: span 3; }
            .wa-field.phone { grid-column: span 4; }
            .wa-field-select-all { grid-column: span 7; }
            .wa-field-actions { grid-column: span 5; }
        }
        @media (min-width: 1200px) {
            .wa-field.search { grid-column: span 4; }
            .wa-field.category { grid-column: span 2; }
            .wa-field.phone { grid-column: span 2; }
            .wa-field-select-all { grid-column: span 2; }
            .wa-field-actions { grid-column: span 2; }
            .wa-filter-btn {
                min-width: 120px;
                max-width: none;
            }
        }
        .wa-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 14px;
        }
        @media (max-width: 767px) {
            .wa-grid {
                grid-template-columns: 1fr;
            }
        }
        .wa-item-card {
            border: 1px solid #dbe5e2;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(2, 8, 23, .04);
            padding: 0;
            position: relative;
            overflow: visible;
        }
        .wa-item-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            min-height: 258px;
            border-radius: inherit;
            overflow: hidden;
        }
        .wa-media-pane {
            position: relative;
            min-height: 100%;
            border-right: 1px solid #dbe5e2;
            background: #f8fafc;
        }
        .wa-content-pane {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 40px 12px 12px;
        }
        .wa-item-top {
            min-width: 0;
        }
        .wa-thumb, .wa-thumb-empty {
            width: 100%;
            height: 100%;
            border-radius: 0;
            overflow: hidden;
            border: 0;
            background: #f1f5f9;
        }
        .wa-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .wa-thumb-empty {
            display: grid;
            place-items: center;
            color: #64748b;
            font-weight: 800;
            font-size: 18px;
            letter-spacing: .08em;
        }
        .wa-name {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.25;
            letter-spacing: -.01em;
        }
        .wa-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            color: #334155;
            margin-top: 2px;
        }
        .wa-badges {
            margin-top: 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .wa-badge {
            font-size: 11px;
            font-weight: 700;
            border-radius: 9999px;
            padding: 4px 8px;
            border: 1px solid #d8dee6;
            color: #334155;
            background: #f8fafc;
        }
        .wa-badge.gold {
            background: #fff7ed;
            color: #9a3412;
            border-color: #fed7aa;
        }
        .wa-badge.green {
            background: #ecfdf5;
            color: #166534;
            border-color: #bbf7d0;
        }
        .wa-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .wa-metric {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #f8fafc;
            padding: 8px 9px;
        }
        .wa-metric:last-child {
            grid-column: 1 / -1;
        }
        .wa-metric-label {
            font-size: 10px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-weight: 700;
        }
        .wa-metric-value {
            margin-top: 2px;
            font-weight: 800;
            color: #0f172a;
            font-size: 13px;
        }
        .wa-preview {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 9px;
            font-size: 12px;
            line-height: 1.45;
            color: #0f172a;
            max-height: 138px;
            overflow: auto;
            white-space: pre-wrap;
        }
        .wa-actions {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 8px;
            align-items: start;
            margin-top: auto;
        }
        .wa-item-select-wrap {
            display: flex;
            justify-content: flex-end;
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 4;
        }
        .wa-item-select {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            font-weight: 700;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #d8e2ef;
            padding: 5px 6px;
            border-radius: 8px;
            cursor: pointer;
        }
        .wa-item-select input {
            accent-color: #0f766e;
            margin: 0;
        }
        .wa-item-card.selected {
            border-color: #0f766e;
            box-shadow: 0 0 0 1px rgba(15,118,110,.25), 0 8px 20px rgba(15,118,110,.12);
        }
        .wa-selection-shell[hidden] {
            display: none !important;
        }
        .wa-selection-shell {
            margin: 0 0 14px;
        }
        .wa-selection-bar {
            border: 1px solid #c9ddd9;
            background: linear-gradient(135deg, #f8fdfc, #edf8f6);
            padding: 12px 14px;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .wa-selection-meta {
            min-width: 0;
        }
        .wa-selection-count {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
        }
        .wa-selection-note {
            margin-top: 4px;
            font-size: 12px;
            color: #475569;
            line-height: 1.5;
        }
        .wa-selection-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            width: 100%;
        }
        .wa-selection-actions .wa-btn {
            width: 100%;
            min-width: 0;
            padding: 8px 10px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .wa-selection-link {
            display: none;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            font-size: 12px;
            color: #475569;
        }
        .wa-selection-link.active {
            display: flex;
        }
        .wa-selection-link a {
            color: #0f766e;
            font-weight: 700;
            text-decoration: none;
        }
        .wa-selection-url {
            max-width: 420px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        @media (min-width: 900px) {
            .wa-selection-bar {
                flex-direction: row;
                align-items: flex-start;
                justify-content: space-between;
            }
            .wa-selection-meta {
                min-width: 0;
                flex: 1 1 auto;
            }
            .wa-selection-actions {
                flex: 0 0 auto;
                width: auto;
                grid-template-columns: repeat(3, minmax(126px, max-content));
                justify-content: end;
            }
            .wa-selection-actions .wa-btn {
                min-width: 126px;
                white-space: nowrap;
            }
        }
        .wa-btn {
            border: 0;
            border-radius: 10px;
            padding: 9px 11px;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            transition: transform .08s ease, opacity .15s ease;
        }
        .wa-btn:hover { transform: translateY(-1px); }
        .wa-btn:active { transform: translateY(0); }
        .wa-btn-share {
            flex: 1;
            background: #15803d;
            color: #fff;
        }
        .wa-btn-page {
            flex: 1;
            background: #0f172a;
            color: #fff;
        }
        .wa-btn-copy {
            flex: 1;
            background: #1e293b;
            color: #fff;
        }
        .wa-btn-menu {
            width: 100%;
            min-width: 0;
            background: #fff;
            color: #0f172a;
            border: 1px solid #d8dee6;
            padding-inline: 0;
            font-size: 16px;
            line-height: 1;
        }
        .wa-menu-wrap {
            position: relative;
            grid-column: 1 / -1;
            z-index: 10;
        }
        .wa-menu {
            position: absolute;
            top: auto;
            bottom: calc(100% + 6px);
            right: 0;
            min-width: 180px;
            display: none;
            background: #fff;
            border: 1px solid #dbe5e2;
            box-shadow: 0 10px 24px rgba(15,23,42,.12);
            z-index: 60;
        }
        .wa-menu.open {
            display: block;
        }
        .wa-menu button {
            width: 100%;
            text-align: left;
            border: 0;
            background: #fff;
            color: #0f172a;
            font-size: 12px;
            font-weight: 700;
            padding: 11px 12px;
            cursor: pointer;
        }
        .wa-menu button + button {
            border-top: 1px solid #edf2f7;
        }
        .wa-menu button:hover {
            background: #f8fafc;
        }
        .wa-empty {
            border: 1px dashed #cbd5e1;
            border-radius: 16px;
            background: #fff;
            padding: 34px 20px;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .wa-catalog-root {
                gap: 10px;
            }

            .wa-hero-copy {
                max-width: none;
            }

            .wa-selection-bar {
                padding: 10px;
                gap: 10px;
            }

            .wa-selection-count {
                font-size: 12px;
            }

            .wa-selection-note {
                font-size: 11px;
                line-height: 1.35;
            }

            .wa-selection-link {
                font-size: 11px;
                gap: 6px;
            }

            .wa-selection-url {
                max-width: 100%;
                white-space: normal;
                overflow-wrap: anywhere;
            }

            .wa-selection-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }

            .wa-selection-actions .wa-btn {
                min-height: 32px;
                padding: 7px 8px;
                font-size: 11px;
            }

            .wa-selection-actions .wa-btn:last-child {
                grid-column: 1 / -1;
            }

            .wa-item-layout {
                grid-template-columns: minmax(0, 44%) minmax(0, 56%);
                min-height: 186px;
            }

            .wa-media-pane {
                border-right: 1px solid #dbe5e2;
            }

            .wa-content-pane {
                padding: 34px 8px 8px;
                gap: 6px;
            }

            .wa-name {
                font-size: 12.5px;
                line-height: 1.2;
            }

            .wa-code {
                font-size: 10px;
            }

            .wa-badges {
                margin-top: 4px;
                gap: 4px;
            }

            .wa-badge {
                font-size: 9.5px;
                padding: 3px 5px;
            }

            .wa-metrics {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 4px;
            }

            .wa-metric {
                padding: 5px 5px;
                border-radius: 7px;
            }

            .wa-metric:last-child {
                grid-column: auto;
            }

            .wa-metric-label {
                font-size: 8.5px;
            }

            .wa-metric-value {
                margin-top: 1px;
                font-size: 10.5px;
            }

            .wa-actions {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 34px;
                gap: 4px;
            }

            .wa-menu-wrap {
                grid-column: auto;
            }

            .wa-btn {
                min-height: 28px;
                padding: 0 6px;
                font-size: 10px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .wa-btn-menu {
                width: 34px;
                min-width: 34px;
                font-size: 14px;
            }

            .wa-hero {
                border-radius: 14px;
                padding: 12px;
                margin-bottom: 10px;
                background: linear-gradient(165deg, #ecf7f5 0%, #d8ebe8 100%);
            }

            .wa-hero-title {
                font-size: 1.05rem;
                line-height: 1.2;
            }

            .wa-hero-sub {
                display: none;
            }

            .wa-chip-row {
                margin-top: 8px;
                gap: 6px;
                flex-wrap: nowrap;
                overflow-x: auto;
                overflow-y: hidden;
                padding-bottom: 2px;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
            }

            .wa-chip-row::-webkit-scrollbar {
                display: none;
            }

            .wa-template-chip {
                padding: 5px 9px;
                border-radius: 7px;
                font-size: 10.5px;
            }

            .wa-tools {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                margin-top: 10px;
                padding: 8px;
                border: 1px solid #d3e2df;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.72);
                backdrop-filter: blur(2px);
                -webkit-backdrop-filter: blur(2px);
            }

            .wa-field.search {
                grid-column: 1 / -1;
            }

            .wa-field.category {
                grid-column: 1;
            }

            .wa-field.phone {
                grid-column: 2;
            }

            .wa-field-select-all {
                grid-column: 1 / -1;
            }

            .wa-field label {
                font-size: 10px;
                margin-bottom: 3px;
            }

            .wa-input,
            .wa-select {
                border-radius: 10px;
                padding: 7px 9px;
                font-size: 12px;
                line-height: 1.25;
            }

            .wa-field-actions {
                grid-column: 1 / -1;
                justify-content: flex-end;
                gap: 6px;
            }

            .wa-filter-btn {
                min-height: 32px;
                max-width: 168px;
                padding: 0 10px;
                font-size: 11px;
            }
        }

        @media (max-width: 480px) {
            .wa-tools {
                grid-template-columns: 1fr;
                gap: 7px;
                padding: 7px;
            }

            .wa-field.category,
            .wa-field.phone {
                grid-column: 1 / -1;
            }

            .wa-field-actions {
                justify-content: stretch;
            }

            .wa-filter-btn {
                max-width: none;
                width: 100%;
            }

            .wa-selection-actions {
                grid-template-columns: 1fr;
            }

            .wa-selection-actions .wa-btn:last-child {
                grid-column: auto;
            }

            .wa-selection-actions .wa-btn {
                min-height: 30px;
                font-size: 10.5px;
            }

            .wa-item-layout {
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                min-height: 176px;
            }

            .wa-content-pane {
                padding: 32px 7px 7px;
            }

            .wa-name {
                font-size: 11.5px;
            }

            .wa-badge {
                font-size: 9px;
                padding: 2px 5px;
            }

            .wa-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 4px;
            }

            .wa-metric:last-child {
                grid-column: 1 / -1;
            }

            .wa-metric-label {
                font-size: 8px;
            }

            .wa-metric-value {
                font-size: 10px;
            }

            .wa-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 4px;
            }

            .wa-menu-wrap {
                grid-column: 1 / -1;
            }

            .wa-btn {
                min-height: 27px;
                font-size: 9.5px;
            }

            .wa-btn-menu {
                width: 100%;
                min-width: 0;
                font-size: 13px;
            }

            .wa-thumb-empty {
                font-size: 15px;
            }

            .wa-hero {
                padding: 10px;
            }

            .wa-hero-title {
                font-size: 1rem;
            }

            .wa-tools {
                gap: 7px;
            }

            .wa-template-chip {
                padding: 4px 8px;
                font-size: 10px;
            }

            .wa-filter-btn {
                min-height: 30px;
                font-size: 10.5px;
            }

            .wa-input,
            .wa-select {
                padding: 6px 8px;
                font-size: 11px;
            }
        }
    </style>

    <x-page-header>
        <div>
            <h1 class="page-title">WhatsApp Catalog</h1>
        </div>
    </x-page-header>

    <div class="content-inner wa-catalog-root" id="wa-catalog-root"
         data-shop-name="{{ e($shopName) }}"
         data-shop-phone="{{ e($shopPhone) }}"
         data-collection-endpoint="{{ route('catalog.collections.store') }}"
         data-all-matching-ids="{{ json_encode($allMatchingIds) }}">

        <section class="wa-hero">
            <div class="wa-hero-copy">
                <div class="wa-hero-title">Share Product Catalog</div>
                <div class="wa-hero-sub">Send a single product directly, or create one clean collection page when you select multiple products.</div>
            </div>

            <div class="wa-chip-row">
                <button type="button" class="wa-template-chip active" data-template="premium">Premium Pitch</button>
                <button type="button" class="wa-template-chip" data-template="quick">Quick Quote</button>
                <button type="button" class="wa-template-chip" data-template="newdrop">New Arrival</button>
            </div>

            <form method="GET" action="{{ route('catalog.index') }}" class="wa-tools">
                <div class="wa-field search">
                    <label for="search">Search</label>
                    <input id="search" name="search" type="text" class="wa-input"
                           value="{{ request('search') }}"
                           placeholder="Barcode, design, category, HUID">
                </div>
                <div class="wa-field category">
                    <label for="category">Category</label>
                    <select id="category" name="category" class="wa-select" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="wa-field phone">
                    <label for="wa-phone">Customer Number (optional)</label>
                    <input id="wa-phone" type="text" class="wa-input" value="{{ $shopPhone }}" placeholder="e.g. 9198XXXXXX">
                </div>
                <div class="wa-field wa-field-select-all">
                    <label>&nbsp;</label>
                    <label class="wa-select-all-label" id="wa-select-all-label">
                        <input type="checkbox" id="wa-select-all" class="wa-select-all-checkbox">
                        <span id="wa-select-all-text">Select All ({{ count($allMatchingIds) }})</span>
                    </label>
                </div>
                <div class="wa-field wa-field-actions">
                    <button type="submit" class="wa-btn wa-btn-copy wa-filter-btn">Apply</button>
                    @if(request()->hasAny(['search', 'category']))
                        <a href="{{ route('catalog.index') }}" class="wa-btn wa-btn-copy wa-filter-btn wa-filter-btn-clear">Clear</a>
                    @endif
                </div>
            </form>
        </section>

        <section class="wa-selection-shell" id="wa-selection-shell" hidden>
            <div class="wa-selection-bar">
                <div class="wa-selection-meta">
                    <div class="wa-selection-count"><span id="wa-selected-count">0</span> selected</div>
                    <div class="wa-selection-note" id="wa-bulk-note">Create one clean collection page for these selected products, then send that collection on WhatsApp.</div>
                    <div class="wa-selection-link" id="wa-collection-row">
                        <span>Collection ready:</span>
                        <a href="#" id="wa-view-collection" target="_blank" rel="noopener noreferrer">Open collection</a>
                        <span class="wa-selection-url" id="wa-collection-url"></span>
                    </div>
                </div>
                <div class="wa-selection-actions">
                    <button type="button" id="wa-create-collection" class="wa-btn wa-btn-copy" style="background:#0f766e;">Create Collection</button>
                    <button type="button" id="wa-share-selected" class="wa-btn wa-btn-share">Send on WhatsApp</button>
                    <button type="button" id="wa-clear-selected" class="wa-btn wa-btn-copy" style="background:#475569;">Clear</button>
                </div>
            </div>
        </section>

        <section class="wa-grid">
            @forelse($items as $item)
                @php
                    $displayName = $item->design ?: trim(($item->category ?? 'Jewellery') . ' ' . ($item->sub_category ?? ''));
                    $displayPurity = $item->purity ? rtrim(rtrim(number_format((float) $item->purity, 2, '.', ''), '0'), '.') . 'K' : '—';
                    $displayWeight = number_format((float) ($item->gross_weight ?? 0), 3, '.', '');
                    $displayPrice = number_format((float) ($item->selling_price ?? 0), 2, '.', '');
                    $displayImage = null;
                    if ($item->image) {
                        $rawImage = trim((string) $item->image);
                        if (\Illuminate\Support\Str::startsWith($rawImage, ['http://', 'https://'])) {
                            $displayImage = $rawImage;
                        } else {
                            $normalizedImagePath = preg_replace('/^storage\//', '', ltrim($rawImage, '/'));
                            $storageRelativeUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($normalizedImagePath);
                            $storagePathOnly = parse_url($storageRelativeUrl, PHP_URL_PATH) ?: $storageRelativeUrl;
                            $storagePathOnly = '/' . ltrim((string) $storagePathOnly, '/');
                            $displayImage = request()->getSchemeAndHttpHost() . $storagePathOnly;
                        }
                    }
                    $fallback = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($item->barcode ?? 'NA')), 0, 2)) ?: 'NA';
                @endphp
                <article class="wa-item-card"
                         data-item-id="{{ $item->id }}"
                         data-design="{{ e($displayName) }}"
                         data-barcode="{{ e((string) $item->barcode) }}"
                         data-category="{{ e((string) ($item->category ?? 'Jewellery')) }}"
                         data-subcategory="{{ e((string) ($item->sub_category ?? '')) }}"
                         data-purity="{{ e($displayPurity) }}"
                         data-weight="{{ e($displayWeight) }}"
                         data-price="{{ e($displayPrice) }}"
                         data-huid="{{ e((string) ($item->huid ?? '')) }}"
                         data-share-url="{{ (string) ($item->public_share_url ?? '') }}">
                    <div class="wa-item-select-wrap">
                        <label class="wa-item-select">
                            <input type="checkbox" class="js-item-select" value="{{ $item->id }}" aria-label="Select item {{ $displayName }}">
                        </label>
                    </div>
                    <div class="wa-item-layout">
                        <div class="wa-media-pane">
                            @if($displayImage)
                                <div class="wa-thumb"><img src="{{ $displayImage }}" alt="{{ $displayName }}"></div>
                            @else
                                <div class="wa-thumb-empty">{{ $fallback }}</div>
                            @endif
                        </div>
                        <div class="wa-content-pane">
                            <div class="wa-item-top">
                                <div class="wa-name">{{ $displayName }}</div>
                                <div class="wa-code">{{ $item->barcode }}</div>
                                <div class="wa-badges">
                                    <span class="wa-badge gold">{{ $displayPurity }}</span>
                                    <span class="wa-badge">{{ $item->category }}{{ $item->sub_category ? ' · ' . $item->sub_category : '' }}</span>
                                    @if($item->huid)
                                        <span class="wa-badge green">BIS HUID</span>
                                    @endif
                                </div>
                            </div>

                            <div class="wa-metrics">
                                <div class="wa-metric">
                                    <div class="wa-metric-label">Gross Wt</div>
                                    <div class="wa-metric-value">{{ number_format((float) ($item->gross_weight ?? 0), 3) }}g</div>
                                </div>
                                <div class="wa-metric">
                                    <div class="wa-metric-label">Purity</div>
                                    <div class="wa-metric-value">{{ $displayPurity }}</div>
                                </div>
                                <div class="wa-metric">
                                    <div class="wa-metric-label">Price</div>
                                    <div class="wa-metric-value">₹{{ number_format((float) ($item->selling_price ?? 0), 0) }}</div>
                                </div>
                            </div>

                            <div class="wa-actions">
                                <a class="wa-btn wa-btn-share js-wa-share" target="_blank" rel="noopener noreferrer" href="#">WhatsApp</a>
                                <a class="wa-btn wa-btn-page js-item-page" target="_blank" rel="noopener noreferrer" href="#">Public Page</a>
                                <div class="wa-menu-wrap">
                                    <button type="button" class="wa-btn wa-btn-menu js-item-menu-toggle" aria-expanded="false" aria-label="More actions">⋯</button>
                                    <div class="wa-menu js-item-menu">
                                        <button type="button" class="js-link-copy">Copy Public Link</button>
                                        <button type="button" class="js-custom-msg">Custom Message</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="wa-empty" style="grid-column: 1 / -1;">
                    <div style="font-size:15px;font-weight:700;color:#334155;">No in-stock items found.</div>
                    <div style="font-size:13px;margin-top:4px;">
                        {{ request()->hasAny(['search', 'category']) ? 'Try clearing filters or search text.' : 'Add stock items first, then share catalog messages.' }}
                    </div>
                </div>
            @endforelse
        </section>

        @if($items->hasPages())
            <div class="mt-4">
                {{ $items->withQueryString()->links() }}
            </div>
        @endif

        <div id="wa-custom-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(15,23,42,0.5);">
            <div style="background-color: #f8fafc; margin: 5% auto; padding: 20px; border: 1px solid #dbe5e2; width: 90%; max-width: 700px; border-radius: 14px; box-shadow: 0 10px 24px rgba(15,23,42,0.1);">
                <h3 id="modal-title" class="wa-hero-title" style="margin-bottom: 1rem; font-size: 1.2rem;">Custom Message for...</h3>

                <div class="wa-custom-grid">
                    <div class="wa-custom-field header">
                        <label for="modal-custom-header">Header (optional)</label>
                        <input id="modal-custom-header" type="text" class="wa-input" value="*{shop}*">
                    </div>
                    <div class="wa-custom-field footer">
                        <label for="modal-custom-footer">Footer (optional)</label>
                        <input id="modal-custom-footer" type="text" class="wa-input" value="Reply on WhatsApp to book now.">
                    </div>
                    <div class="wa-custom-field body">
                        <label for="modal-custom-body">Message body template</label>
                        <textarea id="modal-custom-body" class="wa-textarea" rows="8">*{design}*
Code: {barcode}
Category: {category}{subcategory_suffix}
Purity: {purity}
Weight: {weight} g
Price: ₹{price}
{offer_line}
{share_url_line}</textarea>
                    </div>
                </div>
                <div class="wa-custom-hint">
                    Placeholders: <code>{shop}</code> <code>{design}</code> <code>{barcode}</code> <code>{category}</code> <code>{subcategory}</code> <code>{subcategory_suffix}</code> <code>{purity}</code> <code>{weight}</code> <code>{price}</code> <code>{huid}</code> <code>{offer}</code> <code>{offer_line}</code> <code>{contact}</code> <code>{share_url}</code> <code>{share_url_line}</code>
                </div>

                <div style="margin-top: 1rem;">
                  <label class="wa-metric-label">Live Preview</label>
                  <pre id="modal-preview" class="wa-preview" style="margin-top: 4px; max-height: 150px;"></pre>
                </div>

                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 1.5rem;">
                    <a id="modal-wa-share" class="wa-btn wa-btn-share" target="_blank" rel="noopener noreferrer" href="#">Open WhatsApp</a>
                    <button type="button" id="modal-wa-copy" class="wa-btn wa-btn-copy">Copy Message</button>
                    <button type="button" id="modal-close" class="wa-btn wa-btn-copy" style="background:#64748b;">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const root = document.getElementById('wa-catalog-root');
            if (!root) return;

            const templateButtons = Array.from(root.querySelectorAll('.wa-template-chip'));
            const cards = Array.from(root.querySelectorAll('.wa-item-card'));
            const phoneInput = root.querySelector('#wa-phone');
            const offerInput = root.querySelector('#wa-offer');
            const shopName = (root.dataset.shopName || 'Our Jewellery Store').trim();
            const collectionEndpoint = (root.dataset.collectionEndpoint || '').trim();
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const money = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });

            const selectionShell = root.querySelector('#wa-selection-shell');
            const selectedCountEl = root.querySelector('#wa-selected-count');
            const clearSelectedBtn = root.querySelector('#wa-clear-selected');
            const shareSelectedBtn = root.querySelector('#wa-share-selected');
            const createCollectionBtn = root.querySelector('#wa-create-collection');
            const bulkNoteEl = root.querySelector('#wa-bulk-note');
            const collectionRow = root.querySelector('#wa-collection-row');
            const collectionLinkEl = root.querySelector('#wa-view-collection');
            const collectionUrlEl = root.querySelector('#wa-collection-url');

            const modal = document.getElementById('wa-custom-modal');
            const modalTitle = document.getElementById('modal-title');
            const modalHeaderInput = document.getElementById('modal-custom-header');
            const modalBodyInput = document.getElementById('modal-custom-body');
            const modalFooterInput = document.getElementById('modal-custom-footer');
            const modalPreview = document.getElementById('modal-preview');
            const modalShareBtn = document.getElementById('modal-wa-share');
            const modalCopyBtn = document.getElementById('modal-wa-copy');
            const modalCloseBtn = document.getElementById('modal-close');

            const state = {
                template: 'premium',
            };

            const modalState = {
                itemData: null,
            };

            const STORAGE_KEY = 'wa_catalog_selected';
            const selectedIds = new Set((() => {
                try {
                    const raw = sessionStorage.getItem(STORAGE_KEY);
                    return raw ? JSON.parse(raw) : [];
                } catch (_) { return []; }
            })());
            const persistSelection = () => {
                try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify([...selectedIds])); } catch (_) {}
            };
            const allMatchingIds = (() => {
                try { return JSON.parse(root.dataset.allMatchingIds || '[]').map(String); }
                catch (_) { return []; }
            })();
            const selectAllCheckbox = document.getElementById('wa-select-all');
            const selectAllLabel = document.getElementById('wa-select-all-label');
            const selectAllText = document.getElementById('wa-select-all-text');

            const syncSelectAll = () => {
                if (!selectAllCheckbox) return;
                const allSelected = allMatchingIds.length > 0 && allMatchingIds.every(id => selectedIds.has(id));
                const someSelected = allMatchingIds.some(id => selectedIds.has(id));
                selectAllCheckbox.checked = allSelected;
                selectAllCheckbox.indeterminate = someSelected && !allSelected;
                selectAllLabel?.classList.toggle('all-selected', allSelected);
                if (selectAllText) {
                    selectAllText.textContent = allSelected
                        ? `All selected (${allMatchingIds.length})`
                        : `Select All (${allMatchingIds.length})`;
                }
            };

            let latestCollectionLink = '';
            let latestCollectionKey = '';

            const normalizePhone = (raw) => (raw || '').replace(/\D+/g, '');

            const applyCustomTemplate = (templateText, vars) => {
                if (!templateText) return '';
                return templateText.replace(/\{([a-zA-Z_]+)\}/g, (_, key) => {
                    const value = vars[key];
                    return value === undefined || value === null ? '' : String(value);
                });
            };

            const normalizeLines = (text) =>
                (text || '')
                    .replace(/\n{3,}/g, '\n\n')
                    .split('\n')
                    .map((line) => line.trimEnd())
                    .join('\n')
                    .trim();

            const getSelectedCards = () =>
                cards.filter((card) => selectedIds.has(String(card.dataset.itemId || '')));

            const currentSelectionKey = () =>
                Array.from(selectedIds)
                    .map((id) => String(id))
                    .sort((a, b) => Number(a) - Number(b))
                    .join(',');

            const resetCollectionState = () => {
                latestCollectionLink = '';
                latestCollectionKey = '';
            };

            const setBulkNote = (message, isError = false) => {
                if (!bulkNoteEl) return;
                bulkNoteEl.textContent = message;
                bulkNoteEl.style.color = isError ? '#b91c1c' : '#475569';
            };

            const closeAllMenus = () => {
                root.querySelectorAll('.js-item-menu').forEach((menu) => {
                    menu.classList.remove('open');
                });
                root.querySelectorAll('.js-item-menu-toggle').forEach((button) => {
                    button.setAttribute('aria-expanded', 'false');
                });
            };

            async function copyToClipboard(text, buttonEl) {
                if (!text || !buttonEl) return;

                try {
                    await navigator.clipboard.writeText(text);
                    const old = buttonEl.textContent;
                    buttonEl.textContent = 'Copied';
                    window.setTimeout(() => {
                        buttonEl.textContent = old;
                    }, 1200);
                } catch (_) {
                    setBulkNote('Copy failed. Please try again.', true);
                }
            }

            const buildMessage = (item, template, offer, contactNumber, shareUrl) => {
                const design = (item.design || 'Jewellery Item').trim();
                const category = (item.category || 'Jewellery').trim();
                const sub = (item.subcategory || '').trim();
                const categoryLine = sub ? `${category} · ${sub}` : category;
                const purity = (item.purity || '').trim();
                const weight = (item.weight || '').trim();
                const huid = (item.huid || '').trim();
                const priceRaw = parseFloat(item.price || '0');
                const hasPrice = Number.isFinite(priceRaw) && priceRaw > 0;

                const lines = [];

                if (template === 'premium') {
                    lines.push(`*${shopName}*`);
                    lines.push('*Premium Catalogue Selection*');
                    lines.push('');
                    lines.push(`*${design}*`);
                    lines.push(`Code: ${item.barcode}`);
                    lines.push(`Category: ${categoryLine}`);
                    if (purity) lines.push(`Purity: ${purity}`);
                    if (weight) lines.push(`Gross Weight: ${weight} g`);
                    if (huid) lines.push(`Hallmark HUID: ${huid}`);
                    if (hasPrice) lines.push(`Offer Price: ₹${money.format(priceRaw)}`);
                } else if (template === 'quick') {
                    lines.push(`*${design}*`);
                    lines.push(`Code: ${item.barcode} | ${categoryLine}`);
                    if (purity || weight) {
                        lines.push(`${purity || 'Purity —'} | ${weight ? `${weight} g` : 'Weight —'}`);
                    }
                    if (hasPrice) lines.push(`Price: ₹${money.format(priceRaw)}`);
                } else if (template === 'newdrop') {
                    lines.push(`*New Arrival - ${shopName}*`);
                    lines.push('');
                    lines.push(`Design: ${design}`);
                    lines.push(`Ref: ${item.barcode}`);
                    lines.push(`Category: ${categoryLine}`);
                    if (purity) lines.push(`Purity: ${purity}`);
                    if (weight) lines.push(`Weight: ${weight} g`);
                    if (hasPrice) lines.push(`Special Price: ₹${money.format(priceRaw)}`);
                    lines.push('Limited availability. Book your piece today.');
                } else {
                    lines.push(`*${design}*`);
                    if (hasPrice) lines.push(`Price: ₹${money.format(priceRaw)}`);
                }

                if (offer) {
                    lines.push('');
                    lines.push(`Offer: ${offer}`);
                }

                lines.push('');
                lines.push('Reply on WhatsApp for booking.');
                if (contactNumber) lines.push(`Contact: ${contactNumber}`);
                if (shareUrl) lines.push(`View Product: ${shareUrl}`);

                return lines.join('\n');
            };

            const buildCollectionMessage = (link, count) => {
                const offer = (offerInput?.value || '').trim();
                const contactDisplay = (phoneInput?.value || '').trim();
                const lines = [];

                if (state.template === 'premium') {
                    lines.push(`*${shopName}*`);
                    lines.push('*Curated Jewellery Collection*');
                    lines.push(`${count} handpicked designs are ready for you.`);
                } else if (state.template === 'quick') {
                    lines.push(`*${count} jewellery picks from ${shopName}*`);
                    lines.push('Open the collection link below for the full selection.');
                } else {
                    lines.push(`*New arrivals from ${shopName}*`);
                    lines.push(`${count} selected designs are now available.`);
                }

                if (offer) {
                    lines.push(`Offer: ${offer}`);
                }

                lines.push('');
                lines.push(`View collection: ${link}`);
                lines.push('Reply on WhatsApp to shortlist or reserve any design.');

                if (contactDisplay) {
                    lines.push(`Contact: ${contactDisplay}`);
                }

                return lines.join('\n');
            };

            const updateSelectionUI = () => {
                const count = selectedIds.size;
                const selectionKey = currentSelectionKey();
                const hasCollectionForCurrentSelection = !!latestCollectionLink && latestCollectionKey === selectionKey;
                syncSelectAll();

                cards.forEach((card) => {
                    const id = String(card.dataset.itemId || '');
                    const checked = selectedIds.has(id);
                    card.classList.toggle('selected', checked);
                    const checkbox = card.querySelector('.js-item-select');
                    if (checkbox && checkbox.checked !== checked) {
                        checkbox.checked = checked;
                    }
                });

                if (selectionShell) {
                    selectionShell.hidden = count === 0;
                }

                if (selectedCountEl) {
                    selectedCountEl.textContent = String(count);
                }

                if (createCollectionBtn) {
                    createCollectionBtn.disabled = count === 0;
                    createCollectionBtn.textContent = hasCollectionForCurrentSelection ? 'View Collection' : 'Create Collection';
                }

                if (shareSelectedBtn) {
                    shareSelectedBtn.disabled = count === 0;
                }

                if (collectionRow) {
                    collectionRow.classList.toggle('active', hasCollectionForCurrentSelection);
                }

                if (collectionLinkEl) {
                    collectionLinkEl.href = hasCollectionForCurrentSelection ? latestCollectionLink : '#';
                }

                if (collectionUrlEl) {
                    collectionUrlEl.textContent = hasCollectionForCurrentSelection ? latestCollectionLink : '';
                }

                if (count === 0) {
                    setBulkNote('Select one or more products to create a clean collection page for sharing.');
                } else if (hasCollectionForCurrentSelection) {
                    setBulkNote(`Collection ready for ${count} selected product${count > 1 ? 's' : ''}.`);
                } else {
                    setBulkNote(`Create one clean collection page for these ${count} selected product${count > 1 ? 's' : ''}, then share it on WhatsApp.`);
                }
            };

            const renderGlobal = () => {
                const offer = (offerInput?.value || '').trim();
                const contactDisplay = (phoneInput?.value || '').trim();
                const waPhone = normalizePhone(contactDisplay);

                cards.forEach((card) => {
                    const item = { ...card.dataset };
                    const shareUrl = (item.shareUrl || '').trim();
                    const message = buildMessage(item, state.template, offer, contactDisplay, shareUrl);

                    const shareBtn = card.querySelector('.js-wa-share');
                    if (shareBtn) {
                        const encoded = encodeURIComponent(message);
                        shareBtn.href = waPhone ? `https://wa.me/${waPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
                    }

                    const linkBtn = card.querySelector('.js-link-copy');
                    if (linkBtn) {
                        linkBtn.dataset.link = shareUrl;
                    }

                    const pageLink = card.querySelector('.js-item-page');
                    if (pageLink) {
                        pageLink.href = shareUrl || '#';
                        pageLink.style.pointerEvents = shareUrl ? 'auto' : 'none';
                        pageLink.style.opacity = shareUrl ? '1' : '0.6';
                    }
                });

                if (modalState.itemData) {
                    renderModalPreview();
                }

                updateSelectionUI();
            };

            const renderModalPreview = () => {
                if (!modalState.itemData) return;

                const item = modalState.itemData;
                const offerText = (offerInput?.value || '').trim();
                const contactDisplay = (phoneInput?.value || '').trim();
                const shareLink = (item.shareUrl || '').trim();
                const priceRaw = parseFloat(item.price || '0');
                const hasPrice = Number.isFinite(priceRaw) && priceRaw > 0;
                const formattedPrice = hasPrice ? money.format(priceRaw) : '';
                const subSuffix = item.subcategory ? ` · ${item.subcategory}` : '';

                const vars = {
                    shop: shopName,
                    design: item.design || '',
                    barcode: item.barcode || '',
                    category: item.category || '',
                    subcategory: item.subcategory || '',
                    subcategory_suffix: subSuffix,
                    purity: item.purity || '',
                    weight: item.weight || '',
                    price: formattedPrice,
                    huid: item.huid || '',
                    offer: offerText,
                    offer_line: offerText ? `Offer: ${offerText}` : '',
                    contact: contactDisplay,
                    share_url: shareLink,
                    share_url_line: shareLink ? `View Product: ${shareLink}` : '',
                };

                const head = applyCustomTemplate(modalHeaderInput.value, vars);
                const body = applyCustomTemplate(modalBodyInput.value, vars);
                const foot = applyCustomTemplate(modalFooterInput.value, vars);
                const finalMessage = normalizeLines([head, body, foot].filter(Boolean).join('\n\n'));

                modalPreview.textContent = finalMessage;
                modalCopyBtn.dataset.message = finalMessage;

                const waPhone = normalizePhone(contactDisplay);
                const encoded = encodeURIComponent(finalMessage);
                modalShareBtn.href = waPhone ? `https://wa.me/${waPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
            };

            const openModal = (itemCard) => {
                modalState.itemData = { ...itemCard.dataset };
                modalTitle.textContent = `Custom Message for ${modalState.itemData.design || 'Item'}`;
                renderModalPreview();
                closeAllMenus();
                modal.style.display = 'block';
            };

            const closeModal = () => {
                modal.style.display = 'none';
                modalState.itemData = null;
            };

            const createOrReuseCollectionLink = async () => {
                const selectionKey = currentSelectionKey();
                if (!selectionKey) {
                    throw new Error('Select products first.');
                }

                if (latestCollectionLink && latestCollectionKey === selectionKey) {
                    return latestCollectionLink;
                }

                if (!collectionEndpoint) {
                    throw new Error('Collection endpoint is unavailable.');
                }

                const selectedCards = getSelectedCards();
                const itemIds = selectedCards
                    .map((card) => Number(card.dataset.itemId || 0))
                    .filter((id) => id > 0);

                const response = await fetch(collectionEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        item_ids: itemIds,
                        title: `${shopName} Collection`,
                    }),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(data.message || 'Failed to create collection link.');
                }

                latestCollectionLink = (data.url || '').trim();
                latestCollectionKey = selectionKey;
                updateSelectionUI();

                if (!latestCollectionLink) {
                    throw new Error('Collection created but link is missing.');
                }

                return latestCollectionLink;
            };

            templateButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    state.template = button.dataset.template || 'premium';
                    templateButtons.forEach((btn) => btn.classList.toggle('active', btn === button));
                    renderGlobal();
                });
            });

            if (offerInput) {
                offerInput.addEventListener('input', renderGlobal);
            }

            if (phoneInput) {
                phoneInput.addEventListener('input', renderGlobal);
            }

            cards.forEach((card) => {
                card.querySelector('.js-link-copy')?.addEventListener('click', (event) => {
                    copyToClipboard(event.currentTarget.dataset.link, event.currentTarget);
                    closeAllMenus();
                });

                card.querySelector('.js-custom-msg')?.addEventListener('click', () => {
                    openModal(card);
                });

                card.querySelector('.js-item-select')?.addEventListener('change', (event) => {
                    const id = String(card.dataset.itemId || '');
                    const selectionBefore = currentSelectionKey();

                    if (!id) return;

                    if (event.currentTarget.checked) {
                        selectedIds.add(id);
                    } else {
                        selectedIds.delete(id);
                    }
                    persistSelection();

                    if (selectionBefore !== currentSelectionKey()) {
                        resetCollectionState();
                    }

                    updateSelectionUI();
                });

                card.querySelector('.js-item-menu-toggle')?.addEventListener('click', (event) => {
                    const button = event.currentTarget;
                    const menu = card.querySelector('.js-item-menu');
                    const isOpen = menu?.classList.contains('open');

                    closeAllMenus();

                    if (menu && !isOpen) {
                        menu.classList.add('open');
                        button.setAttribute('aria-expanded', 'true');
                    }
                });
            });

            selectAllCheckbox?.addEventListener('change', (e) => {
                if (e.currentTarget.checked) {
                    allMatchingIds.forEach(id => selectedIds.add(id));
                } else {
                    allMatchingIds.forEach(id => selectedIds.delete(id));
                }
                persistSelection();
                resetCollectionState();
                updateSelectionUI();
            });

            clearSelectedBtn?.addEventListener('click', () => {
                selectedIds.clear();
                persistSelection();
                resetCollectionState();
                updateSelectionUI();
                closeAllMenus();
            });

            createCollectionBtn?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                const selectionKey = currentSelectionKey();

                if (!selectionKey) {
                    setBulkNote('Select products first.', true);
                    return;
                }

                if (latestCollectionLink && latestCollectionKey === selectionKey) {
                    window.open(latestCollectionLink, '_blank', 'noopener,noreferrer');
                    return;
                }

                const oldLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Creating...';

                try {
                    await createOrReuseCollectionLink();
                    setBulkNote(`Collection created for ${selectedIds.size} selected product${selectedIds.size > 1 ? 's' : ''}.`);
                } catch (error) {
                    setBulkNote(error.message || 'Could not create collection link.', true);
                } finally {
                    button.disabled = false;
                    updateSelectionUI();
                    button.textContent = createCollectionBtn?.textContent || oldLabel;
                }
            });

            shareSelectedBtn?.addEventListener('click', async (event) => {
                const button = event.currentTarget;
                const oldLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Preparing...';

                try {
                    const link = await createOrReuseCollectionLink();
                    const message = buildCollectionMessage(link, selectedIds.size);
                    const waPhone = normalizePhone(phoneInput?.value || '');
                    const encoded = encodeURIComponent(message);
                    const url = waPhone ? `https://wa.me/${waPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`;
                    window.location.assign(url);
                } catch (error) {
                    setBulkNote(error.message || 'Could not prepare the WhatsApp share.', true);
                } finally {
                    button.disabled = false;
                    button.textContent = oldLabel;
                }
            });

            modalCloseBtn?.addEventListener('click', closeModal);
            modalCopyBtn?.addEventListener('click', (event) => {
                copyToClipboard(event.currentTarget.dataset.message, event.currentTarget);
            });

            [modalHeaderInput, modalBodyInput, modalFooterInput].forEach((element) => {
                element?.addEventListener('input', renderModalPreview);
            });

            document.addEventListener('click', (event) => {
                if (!event.target.closest('.wa-menu-wrap')) {
                    closeAllMenus();
                }

                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeAllMenus();
                    if (modal?.style.display === 'block') {
                        closeModal();
                    }
                }
            });

            renderGlobal();
            updateSelectionUI();
        })();
    </script>
</x-app-layout>
