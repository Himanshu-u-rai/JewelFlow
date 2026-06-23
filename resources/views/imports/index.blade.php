<x-app-layout>
    <style>
        .imports-page {
            --import-ink: #111827;
            --import-muted: #475569;
            --import-border: #e2e8f0;
            --import-border-strong: #cbd5e1;
            --import-border-soft: #eef2f7;
            --import-surface: #ffffff;
            --import-page: #f6f7f9;
            --import-gold: #f59e0b;
            --import-gold-dark: #b45309;
            --import-gold-deep: #d97706;
            --import-gold-soft: #fef9ee;
            --import-gold-border: #fde68a;
        }

        .imports-page .btn,
        .imports-page button,
        .imports-page a {
            transition: background-color .15s ease-out, border-color .15s ease-out, color .15s ease-out, transform .12s ease-out;
        }

        .imports-page .btn:active,
        .imports-page button:active,
        .imports-page a:active {
            transform: scale(.98);
        }

        .import-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .import-grid--single {
            grid-template-columns: 1fr;
        }

        .import-card {
            background: var(--import-surface);
            border: 1px solid var(--import-border);
            border-radius: 12px;
            padding: 18px;
            box-shadow: none !important;
        }

        .import-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .import-heading {
            margin: 0;
            color: var(--import-ink);
            font-size: 18px;
            font-weight: 650;
            letter-spacing: -0.01em;
            line-height: 1.25;
        }

        .import-heading-line {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .import-help-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
        }

        .import-help-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border: 1px solid var(--import-gold-border);
            border-radius: 8px;
            background: var(--import-gold-soft);
            color: var(--import-gold-dark);
            font-size: 13px;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
        }

        .import-help-btn:hover {
            border-color: var(--import-gold);
            background: #fffbeb;
        }

        .import-help-panel {
            position: absolute;
            z-index: 30;
            top: calc(100% + 8px);
            left: 0;
            width: min(340px, calc(100vw - 48px));
            border: 1px solid var(--import-border);
            border-radius: 12px;
            background: #fff;
            padding: 12px;
            box-shadow: none;
        }

        .import-help-panel::before {
            content: "";
            position: absolute;
            top: -6px;
            left: 11px;
            width: 10px;
            height: 10px;
            border-left: 1px solid var(--import-border);
            border-top: 1px solid var(--import-border);
            background: #fff;
            transform: rotate(45deg);
        }

        .import-help-title {
            margin: 0 0 8px;
            color: var(--import-ink);
            font-size: 13px;
            font-weight: 650;
            line-height: 1.25;
        }

        .import-help-list {
            display: grid;
            gap: 7px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .import-help-list li {
            display: grid;
            grid-template-columns: 20px minmax(0, 1fr);
            gap: 8px;
            align-items: start;
            color: var(--import-muted);
            font-size: 12px;
            line-height: 1.35;
        }

        .import-help-list b {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 7px;
            border: 1px solid var(--import-gold-border);
            background: var(--import-gold-soft);
            color: var(--import-gold-dark);
            font-size: 11px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }

        [x-cloak] {
            display: none !important;
        }

        .import-desc {
            margin-top: 5px;
            color: var(--import-muted);
            font-size: 13.5px;
            line-height: 1.45;
        }

        .import-tag {
            font-size: 11px;
            font-weight: 650;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border-radius: 8px;
            padding: 3px 10px;
            border: 1px solid;
            white-space: nowrap;
        }

        .tag-safe {
            color: var(--import-gold-dark);
            background: var(--import-gold-soft);
            border-color: var(--import-gold-border);
        }

        .tag-ledger {
            color: var(--import-gold-dark);
            background: #fffbeb;
            border-color: var(--import-gold-border);
        }

        .upload-box {
            padding: 14px;
            border: 1px solid var(--import-border-strong);
            border-radius: 12px;
            background: #f8fafc;
        }

        .import-console-row {
            display: block;
            margin-top: 16px;
        }

        .import-template-actions {
            margin-top: 14px;
            min-width: 0;
        }

        .import-console-row .import-template-actions .btn {
            min-height: 48px;
            width: 100%;
            justify-content: center;
        }

        .import-upload-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: end;
            margin: 0 !important;
            min-width: 0;
        }

        .import-upload-form .upload-box {
            margin: 0;
            min-width: 0;
            padding: 10px 12px;
        }

        .upload-box-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .upload-box-head label {
            margin-bottom: 0 !important;
        }

        .upload-box-head .btn {
            min-height: 34px;
            width: auto;
            flex: 0 0 auto;
            justify-content: center;
        }

        .import-upload-form .btn-dark {
            min-height: 48px;
            white-space: nowrap;
            padding-inline: 18px;
        }

        .import-file-input {
            width: 100%;
            border-radius: 10px;
            border: 1px solid var(--import-border);
            background: #fff;
            padding: 9px 10px;
            color: var(--import-ink);
            font-size: 13px;
        }

        .import-file-input::file-selector-button {
            margin-right: 12px;
            border: 0;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            padding: 9px 13px;
            font-weight: 650;
            cursor: pointer;
        }

        .import-file-input::file-selector-button:hover {
            background: #0f172a;
        }

        .columns-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .columns-strip span {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 7px;
            border: 1px solid var(--import-border-soft);
            color: #475569;
            background: #fff;
        }

        .import-columns-disclosure {
            margin-top: 14px;
            border: 1px solid var(--import-border-soft);
            border-radius: 10px;
            background: #fff;
        }

        .import-columns-disclosure > summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            cursor: pointer;
            padding: 10px 12px;
            color: var(--import-ink);
            font-size: 13px;
            font-weight: 650;
            list-style: none;
        }

        .import-columns-disclosure > summary::-webkit-details-marker {
            display: none;
        }

        .import-columns-disclosure > summary span {
            color: var(--import-muted);
            font-size: 12px;
            font-weight: 500;
        }

        .import-columns-disclosure > summary::after {
            content: "";
            width: 8px;
            height: 8px;
            border-right: 1.5px solid #64748b;
            border-bottom: 1.5px solid #64748b;
            transform: rotate(45deg);
            transition: transform .15s ease-out;
        }

        .import-columns-disclosure[open] > summary::after {
            transform: rotate(225deg);
        }

        .import-columns-disclosure .columns-strip {
            padding: 0 12px 12px;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 9999px;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .status-preview, .status-queued {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .status-running {
            background: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-cancelled {
            background: #e5e7eb;
            color: #374151;
        }

        .import-history-card {
            overflow: hidden;
            border: 1px solid var(--import-border);
            border-radius: 12px;
            background: var(--import-surface);
            box-shadow: none !important;
        }

        .imports-page .upload-box,
        .imports-page .import-mobile-card,
        .imports-page .import-mobile-metric,
        .imports-page .import-help-panel {
            box-shadow: none !important;
        }

        .import-history-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px;
            border-bottom: 1px solid var(--import-border-soft);
        }

        .import-history-title {
            margin: 0;
            color: var(--import-ink);
            font-size: 18px;
            font-weight: 650;
            letter-spacing: -0.01em;
        }

        .import-history-subtitle {
            color: var(--import-muted);
            font-size: 12px;
        }

        .imports-history-mobile {
            display: none;
        }

        .import-mobile-card {
            padding: 14px 16px;
            border-bottom: 1px solid var(--import-border-soft);
        }

        .import-mobile-card:last-child {
            border-bottom: 0;
        }

        .import-mobile-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .import-mobile-ref {
            color: var(--import-ink);
            font-size: 14px;
            font-weight: 650;
            line-height: 1.25;
        }

        .import-mobile-type {
            margin-top: 3px;
            color: var(--import-muted);
            font-size: 12px;
        }

        .import-mobile-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-top: 12px;
        }

        .import-mobile-metric {
            min-width: 0;
            border: 1px solid var(--import-border-soft);
            border-radius: 10px;
            background: #f8fafc;
            padding: 9px 10px;
        }

        .import-mobile-metric span {
            display: block;
            color: #64748b;
            font-size: 11px;
            line-height: 1.2;
        }

        .import-mobile-metric strong {
            display: block;
            margin-top: 3px;
            color: var(--import-ink);
            font-size: 15px;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .import-mobile-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 12px;
            color: var(--import-muted);
            font-size: 12px;
        }

        .imports-page table {
            font-size: 14px;
        }

        .imports-page thead th {
            color: #475569;
            font-weight: 650;
            letter-spacing: .05em;
            background: #f8fafc;
        }

        .imports-page tbody td {
            color: #1f2937;
        }

        .imports-page .btn-dark {
            background: var(--import-gold-deep) !important;
            border-color: var(--import-gold-deep) !important;
            color: #fff !important;
            box-shadow: none !important;
        }

        .imports-page .btn-dark:hover {
            background: var(--import-gold-dark) !important;
            border-color: var(--import-gold-dark) !important;
        }

        .imports-page .btn-secondary {
            border-color: var(--import-border-strong) !important;
            color: #334155 !important;
            background: #fff !important;
            box-shadow: none !important;
        }

        .imports-page .btn-secondary:hover {
            border-color: var(--import-gold-border) !important;
            color: var(--import-gold-dark) !important;
            background: var(--import-gold-soft) !important;
        }

        @media (min-width: 1025px) {
            .import-card--wide {
                display: block;
            }

            .import-grid:not(.import-grid--single) .import-upload-form {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1024px) {
            .import-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .imports-page {
                gap: 14px;
            }

            .import-card {
                padding: 14px;
                border-radius: 12px;
            }

            .import-title-row {
                align-items: flex-start;
            }

            .import-heading,
            .import-history-title {
                font-size: 16px;
            }

            .import-help-panel {
                position: fixed;
                top: 92px;
                left: 14px;
                right: 14px;
                width: auto;
            }

            .import-help-panel::before {
                display: none;
            }

            .columns-strip {
                gap: 5px;
            }

            .columns-strip span {
                font-size: 10.5px;
                padding: 3px 7px;
            }

            .upload-box {
                padding: 12px;
            }

            .import-upload-form {
                grid-template-columns: 1fr;
            }

            .import-template-actions .btn,
            .import-upload-form .btn-dark {
                width: 100%;
                justify-content: center;
            }

            .import-file-input {
                padding: 8px;
            }

            .import-file-input::file-selector-button {
                padding: 8px 10px;
            }

            .imports-history-table {
                display: none;
            }

            .imports-history-mobile {
                display: block;
            }

            .import-history-head {
                padding: 14px;
            }

            .import-mobile-card {
                padding: 14px;
            }
        }
    </style>

    <x-page-header
        class="ops-treatment-header"
        title="Bulk Imports"
        subtitle="Upload in the right CSV format, preview results, then execute safely."
        badge="CSV Templates Ready"
    />

    <div class="content-inner space-y-6 ops-treatment-page imports-page">
        @php $isRetailer = auth()->user()->shop?->isRetailer(); @endphp

        <div class="import-grid {{ $isRetailer ? 'import-grid--single' : '' }}">
            @if($isRetailer)
            {{-- ========== STOCK IMPORT (Retailers) ========== --}}
            <div class="import-card import-card--wide">
                <div class="import-title-row">
                    <div>
                        <div class="import-heading-line">
                            <h2 class="import-heading">Stock Import</h2>
                            <span class="import-help-wrap" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                                <button type="button" class="import-help-btn" @click="open = !open" :aria-expanded="open.toString()" aria-label="How stock import works">i</button>
                                <span class="import-help-panel" x-cloak x-show="open">
                                    <p class="import-help-title">How stock import works</p>
                                    <ol class="import-help-list">
                                        <li><b>1</b><span>Download sample CSV and keep same columns.</span></li>
                                        <li><b>2</b><span>Upload file to create a preview. No stock changes happen yet.</span></li>
                                        <li><b>3</b><span>Review valid and invalid rows, then execute only if data is correct.</span></li>
                                    </ol>
                                </span>
                            </span>
                        </div>
                        <p class="import-desc">Bulk-add purchased items to your stock. No gold lot deduction.</p>
                    </div>
                    <span class="import-tag tag-safe">Safe</span>
                </div>

                <div class="import-console-row">
                    <form method="POST" action="{{ route('imports.stock.preview') }}" enctype="multipart/form-data" class="import-upload-form" x-data="{ uploading: false }" @submit="uploading = true">
                        @csrf
                        <div class="upload-box">
                            <div class="upload-box-head">
                                <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Upload CSV</label>
                                <a href="{{ route('imports.template', ['type' => 'stock']) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Sample CSV</a>
                            </div>
                            <input
                                type="file"
                                name="file"
                                required
                                accept=".csv,text/csv"
                                class="import-file-input"
                            >
                            <p class="text-xs text-gray-500 mt-2">Barcode must be unique. Vendors will be auto-created if not found.</p>
                        </div>
                        <button type="submit" class="btn btn-dark" :disabled="uploading">
                            <template x-if="!uploading"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Create Preview</span></template>
                            <template x-if="uploading"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Uploading &amp; Validating...</span></template>
                        </button>
                    </form>
                </div>

                <details class="import-columns-disclosure">
                    <summary>Required columns <span>7 required · 2 optional</span></summary>
                    <div class="columns-strip">
                        <span>barcode</span>
                        <span>category</span>
                        <span>sub_category</span>
                        <span>gross_weight</span>
                        <span>purity</span>
                        <span>making_charge</span>
                        <span>huid</span>
                        <span class="!border-dashed !text-gray-400">vendor_name</span>
                        <span class="!border-dashed !text-gray-400">cost_price</span>
                    </div>
                </details>
            </div>
            @endif

            @if(!$isRetailer)
            {{-- ========== CATALOG IMPORT (Manufacturers) ========== --}}
            <div class="import-card">
                <div class="import-title-row">
                    <div>
                        <div class="import-heading-line">
                            <h2 class="import-heading">Catalog Import</h2>
                            <span class="import-help-wrap" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                                <button type="button" class="import-help-btn" @click="open = !open" :aria-expanded="open.toString()" aria-label="How catalog import works">i</button>
                                <span class="import-help-panel" x-cloak x-show="open">
                                    <p class="import-help-title">How catalog import works</p>
                                    <ol class="import-help-list">
                                        <li><b>1</b><span>Download sample CSV and keep same columns.</span></li>
                                        <li><b>2</b><span>Upload file to preview catalog changes.</span></li>
                                        <li><b>3</b><span>Execute only after row validation looks right.</span></li>
                                    </ol>
                                </span>
                            </span>
                        </div>
                        <p class="import-desc">Adds or updates product templates. No ledger movement.</p>
                    </div>
                    <span class="import-tag tag-safe">Safe</span>
                </div>

                <div class="columns-strip">
                    <span>design_code</span>
                    <span>name</span>
                    <span>category</span>
                    <span>sub_category</span>
                    <span>default_purity</span>
                </div>

                <div class="import-template-actions mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('imports.template', ['type' => 'catalog']) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download Sample CSV</a>
                </div>

                <form method="POST" action="{{ route('imports.catalog.preview') }}" enctype="multipart/form-data" class="mt-4" x-data="{ uploading: false }" @submit="uploading = true">
                    @csrf
                    <div class="upload-box">
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Upload CSV</label>
                        <input
                            type="file"
                            name="file"
                            required
                            accept=".csv,text/csv"
                            class="import-file-input"
                        >
                        <p class="text-xs text-gray-500 mt-2">Max file size: 10 MB. Only CSV allowed.</p>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3" :disabled="uploading">
                        <template x-if="!uploading"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Create Preview</span></template>
                        <template x-if="uploading"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Uploading &amp; Validating...</span></template>
                    </button>
                </form>
            </div>

            {{-- ========== MANUFACTURE IMPORT (Manufacturers) ========== --}}
            <div class="import-card">
                <div class="import-title-row">
                    <div>
                        <div class="import-heading-line">
                            <h2 class="import-heading">Manufacture Import</h2>
                            <span class="import-help-wrap" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                                <button type="button" class="import-help-btn" @click="open = !open" :aria-expanded="open.toString()" aria-label="How manufacture import works">i</button>
                                <span class="import-help-panel" x-cloak x-show="open">
                                    <p class="import-help-title">How manufacture import works</p>
                                    <ol class="import-help-list">
                                        <li><b>1</b><span>Download sample CSV and keep lot numbers accurate.</span></li>
                                        <li><b>2</b><span>Upload file to preview stock and ledger impact.</span></li>
                                        <li><b>3</b><span>Execute only after valid rows and lot deductions are reviewed.</span></li>
                                    </ol>
                                </span>
                            </span>
                        </div>
                        <p class="import-desc">Creates stock items and deducts lot gold on execution.</p>
                    </div>
                    <span class="import-tag tag-ledger">Ledger Impact</span>
                </div>

                <div class="columns-strip">
                    <span>barcode</span>
                    <span>design_code</span>
                    <span>lot_number</span>
                    <span>gross_weight</span>
                    <span>purity</span>
                    <span>making_charge</span>
                </div>

                <div class="import-template-actions mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('imports.template', ['type' => 'manufacture']) }}" class="btn btn-secondary btn-sm" data-turbo="false"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Download Sample CSV</a>
                </div>

                <form method="POST" action="{{ route('imports.manufacture.preview') }}" enctype="multipart/form-data" class="mt-4" x-data="{ uploading: false }" @submit="uploading = true">
                    @csrf
                    <div class="upload-box">
                        <label class="block text-xs font-semibold text-gray-600 uppercase tracking-wide mb-2">Upload CSV</label>
                        <input
                            type="file"
                            name="file"
                            required
                            accept=".csv,text/csv"
                            class="import-file-input"
                        >
                        <p class="text-xs text-gray-500 mt-2">Make sure lot_number belongs to your shop and barcode is unique.</p>
                    </div>
                    <button type="submit" class="btn btn-dark mt-3" :disabled="uploading">
                        <template x-if="!uploading"><span><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1 inline"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>Create Preview</span></template>
                        <template x-if="uploading"><span><svg class="animate-spin -ml-1 mr-2 h-4 w-4 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>Uploading &amp; Validating...</span></template>
                    </button>
                </form>
            </div>
            @endif
        </div>

        <div class="import-history-card">
            <div class="import-history-head">
                <h2 class="import-history-title">Import History</h2>
                <span class="import-history-subtitle">Latest first</span>
            </div>
            <div class="imports-history-table overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Reference</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Rows</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Valid</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Invalid</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Created</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($imports as $import)
                            @php
                                $statusClass = match($import->status) {
                                    'preview' => 'status-preview',
                                    'queued' => 'status-queued',
                                    'running' => 'status-running',
                                    'completed' => 'status-completed',
                                    'failed' => 'status-failed',
                                    'cancelled' => 'status-cancelled',
                                    default => 'status-preview'
                                };
                            @endphp
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 text-sm text-gray-900 font-semibold">{{ $import->import_reference ?? 'PENDING-REF' }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ ucfirst($import->type) }}</td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="status-pill {{ $statusClass }}">{{ $import->status }}</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-right text-gray-700">{{ $import->total_rows }}</td>
                                <td class="px-4 py-3 text-sm text-right text-green-700 font-semibold">{{ $import->valid_rows }}</td>
                                <td class="px-4 py-3 text-sm text-right text-red-700 font-semibold">{{ $import->invalid_rows }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700">{{ $import->created_at?->format('d M Y, h:i A') }}</td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <a href="{{ route('imports.show', $import) }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8">
                                    <x-empty-state
                                        compact
                                        title="No imports yet"
                                        description="Start with a sample template above."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="imports-history-mobile">
                @forelse($imports as $import)
                    @php
                        $statusClass = match($import->status) {
                            'preview' => 'status-preview',
                            'queued' => 'status-queued',
                            'running' => 'status-running',
                            'completed' => 'status-completed',
                            'failed' => 'status-failed',
                            'cancelled' => 'status-cancelled',
                            default => 'status-preview'
                        };
                    @endphp
                    <article class="import-mobile-card">
                        <div class="import-mobile-top">
                            <div>
                                <div class="import-mobile-ref">{{ $import->import_reference ?? 'PENDING-REF' }}</div>
                                <div class="import-mobile-type">{{ ucfirst($import->type) }} · {{ $import->created_at?->format('d M Y, h:i A') }}</div>
                            </div>
                            <span class="status-pill {{ $statusClass }}">{{ $import->status }}</span>
                        </div>
                        <div class="import-mobile-metrics">
                            <div class="import-mobile-metric">
                                <span>Rows</span>
                                <strong>{{ $import->total_rows }}</strong>
                            </div>
                            <div class="import-mobile-metric">
                                <span>Valid</span>
                                <strong>{{ $import->valid_rows }}</strong>
                            </div>
                            <div class="import-mobile-metric">
                                <span>Invalid</span>
                                <strong>{{ $import->invalid_rows }}</strong>
                            </div>
                        </div>
                        <div class="import-mobile-foot">
                            <span>Preview and execution details</span>
                            <a href="{{ route('imports.show', $import) }}" class="btn btn-secondary btn-sm">Open</a>
                        </div>
                    </article>
                @empty
                    <div class="px-4 py-8">
                        <x-empty-state
                            compact
                            title="No imports yet"
                            description="Start with a sample template above."
                        />
                    </div>
                @endforelse
            </div>
            <div class="p-4 border-t border-gray-200">{{ $imports->links() }}</div>
        </div>
    </div>
</x-app-layout>
