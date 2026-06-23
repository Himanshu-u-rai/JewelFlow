<x-app-layout>
    @php
        $importReference = $import->import_reference ?? 'PENDING-REF';
        $importTypeLabel = ucfirst($import->type);
        $importStatusLabel = ucfirst($import->status);
    @endphp

    <x-page-header
        class="import-show-header"
        :title="'Import ' . $importReference"
        :subtitle="$importTypeLabel . ' import - Status: ' . $importStatusLabel"
        :badge="$importStatusLabel"
    >
        <x-slot:actions>
            @if($import->error_file_path)
                <a href="{{ route('imports.errors', $import) }}" class="btn import-show-header-action import-show-header-action--errors" data-turbo="false">Errors</a>
            @endif
            <a href="{{ route('imports.index') }}" class="btn import-show-header-action">Back</a>
        </x-slot:actions>
    </x-page-header>

    <style>
        .import-show-page {
            --import-ink: #111827;
            --import-muted: #475569;
            --import-soft: #f8fafc;
            --import-panel: #ffffff;
            --import-border: #e2e8f0;
            --import-border-strong: #cbd5e1;
            --import-gold: #c65a1e;
            --import-gold-soft: #fff7ed;
            --import-success: #047857;
            --import-success-soft: #ecfdf5;
            --import-danger: #b91c1c;
            --import-danger-soft: #fef2f2;
            --import-info: #1e3a8a;
            color: var(--import-ink);
        }

        .import-show-page,
        .import-show-page * {
            box-shadow: none !important;
        }

        .import-show-header .page-title {
            font-weight: 650;
            letter-spacing: 0;
        }

        .import-show-header .page-subtitle {
            color: #64748b;
            font-weight: 500;
        }

        .import-show-header .header-badge {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            font-weight: 650;
        }

        .import-show-header-action {
            min-height: 38px;
            border: 1px solid var(--import-border-strong, #cbd5e1) !important;
            border-radius: 10px !important;
            background: #ffffff !important;
            color: #334155 !important;
            padding: 0 14px !important;
            font-size: 13px !important;
            font-weight: 650 !important;
            transition: background-color 160ms ease, border-color 160ms ease, transform 120ms ease;
        }

        .import-show-header-action:hover {
            background: #f8fafc !important;
            border-color: #94a3b8 !important;
            color: #111827 !important;
        }

        .import-show-header-action:active {
            transform: scale(0.98);
        }

        .import-show-header-action--errors {
            background: #fff7ed !important;
            border-color: #fed7aa !important;
            color: #9a3412 !important;
        }

        .import-show-alert {
            border: 1px solid #fecaca;
            background: var(--import-danger-soft);
            color: var(--import-danger);
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.55;
        }

        .import-show-stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }

        .import-show-stat {
            min-width: 0;
            min-height: 78px;
            display: grid;
            align-content: center;
            gap: 8px;
            border: 1px solid var(--import-border);
            border-radius: 12px;
            background: var(--import-panel);
            padding: 12px 14px;
        }

        .import-show-stat__label {
            color: #64748b;
            font-size: 11px;
            font-weight: 650;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .import-show-stat__value {
            min-width: 0;
            color: var(--import-ink);
            font-size: clamp(20px, 2vw, 26px);
            font-weight: 650;
            line-height: 1;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .import-show-stat--valid .import-show-stat__value {
            color: var(--import-success);
        }

        .import-show-stat--invalid .import-show-stat__value {
            color: var(--import-danger);
        }

        .import-show-card {
            overflow: hidden;
            border: 1px solid var(--import-border);
            border-radius: 12px;
            background: var(--import-panel);
        }

        .import-show-card__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px solid var(--import-border);
            padding: 16px 18px;
        }

        .import-show-card__title {
            margin: 0;
            color: var(--import-ink);
            font-size: 16px;
            font-weight: 650;
            letter-spacing: 0;
        }

        .import-show-card__subtitle {
            margin-top: 4px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.45;
        }

        .import-show-execute {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 18px;
            padding: 18px;
        }

        .import-show-execute__forms,
        .import-show-execute__form {
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 10px;
        }

        .import-show-field {
            display: grid;
            gap: 6px;
        }

        .import-show-field label {
            color: #334155;
            font-size: 12px;
            font-weight: 650;
        }

        .import-show-field select {
            min-width: 220px;
            height: 42px;
            border: 1px solid var(--import-border-strong);
            border-radius: 10px;
            background-color: #ffffff;
            color: var(--import-ink);
            padding: 0 36px 0 12px;
            font-size: 13px;
            font-weight: 600;
        }

        .import-show-field select:focus {
            border-color: var(--import-ink);
            outline: 2px solid rgba(17, 24, 39, .12);
            outline-offset: 1px;
        }

        .import-show-primary {
            min-height: 42px;
            border: 1px solid var(--import-gold) !important;
            border-radius: 10px !important;
            background: var(--import-gold) !important;
            color: #ffffff !important;
            padding: 0 16px !important;
            font-size: 13px !important;
            font-weight: 650 !important;
            transition: background-color 160ms ease, transform 120ms ease;
        }

        .import-show-primary:hover {
            background: #a94716 !important;
        }

        .import-show-primary:active {
            transform: scale(0.98);
        }

        .import-show-danger {
            min-height: 42px;
            border: 1px solid #fecaca !important;
            border-radius: 10px !important;
            background: #ffffff !important;
            color: var(--import-danger) !important;
            padding: 0 14px !important;
            font-size: 13px !important;
            font-weight: 650 !important;
        }

        .import-show-danger:hover {
            background: var(--import-danger-soft) !important;
            border-color: #fca5a5 !important;
        }

        .import-show-table-wrap {
            overflow-x: auto;
        }

        .import-show-table {
            width: 100%;
            min-width: 980px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .import-show-table th {
            border-bottom: 1px solid var(--import-border);
            background: var(--import-soft);
            color: #475569;
            padding: 12px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 650;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .import-show-table td {
            border-bottom: 1px solid #eef2f7;
            color: #334155;
            padding: 14px;
            vertical-align: top;
            font-size: 13px;
        }

        .import-show-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .import-show-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            border-radius: 999px;
            border: 1px solid var(--import-border);
            background: #f8fafc;
            color: #334155;
            padding: 0 10px;
            font-size: 12px;
            font-weight: 650;
            white-space: nowrap;
        }

        .import-show-status--valid {
            border-color: #bbf7d0;
            background: var(--import-success-soft);
            color: var(--import-success);
        }

        .import-show-status--invalid {
            border-color: #fecaca;
            background: var(--import-danger-soft);
            color: var(--import-danger);
        }

        .import-show-error {
            max-width: 420px;
            border: 1px solid #fecaca;
            border-radius: 10px;
            background: var(--import-danger-soft);
            color: var(--import-danger);
            padding: 9px 10px;
            line-height: 1.45;
        }

        .import-show-muted {
            color: #94a3b8;
        }

        .import-show-payload {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            min-width: 360px;
        }

        .import-show-payload__field {
            min-width: 0;
            border: 1px solid #eef2f7;
            border-radius: 10px;
            background: #ffffff;
            padding: 8px 10px;
        }

        .import-show-payload__field span {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 650;
            letter-spacing: .03em;
        }

        .import-show-payload__field strong {
            display: block;
            min-width: 0;
            margin-top: 4px;
            color: var(--import-ink);
            font-size: 13px;
            font-weight: 650;
            overflow-wrap: anywhere;
        }

        .import-show-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .import-show-chip {
            display: inline-flex;
            align-items: center;
            min-height: 26px;
            border-radius: 999px;
            border: 1px solid #fed7aa;
            background: var(--import-gold-soft);
            color: #9a3412;
            padding: 0 9px;
            font-size: 11px;
            font-weight: 650;
        }

        .import-show-chip--blue {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: var(--import-info);
        }

        .import-show-details {
            margin-top: 10px;
        }

        .import-show-details summary {
            color: #64748b;
            cursor: pointer;
            font-size: 12px;
            font-weight: 650;
        }

        .import-show-details summary:hover {
            color: var(--import-ink);
        }

        .import-show-details pre {
            margin-top: 8px;
            max-width: 640px;
            overflow-x: auto;
            border: 1px solid var(--import-border);
            border-radius: 10px;
            background: #f8fafc;
            color: #334155;
            padding: 10px;
            font-size: 12px;
            line-height: 1.5;
            white-space: pre-wrap;
        }

        .import-show-row-cards {
            display: none;
        }

        .import-show-empty {
            padding: 36px 16px;
            color: #64748b;
            text-align: center;
            font-size: 13px;
        }

        .import-show-pagination {
            border-top: 1px solid var(--import-border);
            padding: 14px 16px;
        }

        @media (max-width: 768px) {
            .import-show-header {
                min-height: 62px;
                display: grid !important;
                grid-template-columns: 34px minmax(0, 1fr) auto;
                gap: 8px;
                align-items: center;
                padding-inline: 12px !important;
            }

            .import-show-header .min-w-0 {
                text-align: center;
            }

            .import-show-header .page-title {
                font-size: 16px;
                line-height: 1.15;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .import-show-header .page-subtitle {
                display: none;
            }

            .import-show-header .header-badge {
                display: none;
            }

            .import-show-header .page-actions {
                gap: 6px;
            }

            .import-show-header-action {
                min-height: 34px;
                padding-inline: 10px !important;
                font-size: 12px !important;
            }

            .import-show-page {
                padding-inline: 12px !important;
            }

            .import-show-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .import-show-stat {
                min-height: 72px;
                padding: 10px 12px;
            }

            .import-show-stat--mode {
                grid-column: 1 / -1;
            }

            .import-show-stat__value {
                font-size: clamp(19px, 7vw, 24px);
            }

            .import-show-execute {
                grid-template-columns: 1fr;
                gap: 14px;
                padding: 14px;
            }

            .import-show-execute__forms,
            .import-show-execute__form {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr;
            }

            .import-show-field select,
            .import-show-primary,
            .import-show-danger {
                width: 100%;
            }

            .import-show-card__head {
                padding: 14px;
            }

            .import-show-table-wrap {
                display: none;
            }

            .import-show-row-cards {
                display: grid;
                gap: 10px;
                padding: 12px;
            }

            .import-show-row-card {
                border: 1px solid var(--import-border);
                border-radius: 12px;
                background: #ffffff;
                padding: 12px;
            }

            .import-show-row-card__top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 10px;
            }

            .import-show-row-card__row {
                color: var(--import-ink);
                font-size: 14px;
                font-weight: 650;
            }

            .import-show-payload {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                min-width: 0;
                gap: 7px;
            }

            .import-show-payload__field {
                padding: 8px;
            }

            .import-show-error {
                max-width: none;
                margin-bottom: 10px;
            }
        }
    </style>

    <div class="content-inner space-y-5 ops-treatment-page import-show-page">
        @if($errors->any())
            <div class="import-show-alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="import-show-stats" aria-label="Import summary">
            <div class="import-show-stat">
                <span class="import-show-stat__label">Total</span>
                <strong class="import-show-stat__value">{{ $import->total_rows }}</strong>
            </div>
            <div class="import-show-stat import-show-stat--valid">
                <span class="import-show-stat__label">Valid</span>
                <strong class="import-show-stat__value">{{ $import->valid_rows }}</strong>
            </div>
            <div class="import-show-stat import-show-stat--invalid">
                <span class="import-show-stat__label">Invalid</span>
                <strong class="import-show-stat__value">{{ $import->invalid_rows }}</strong>
            </div>
            <div class="import-show-stat">
                <span class="import-show-stat__label">Processed</span>
                <strong class="import-show-stat__value">{{ $import->processed_rows }}</strong>
            </div>
            <div class="import-show-stat import-show-stat--mode">
                <span class="import-show-stat__label">Mode</span>
                <strong class="import-show-stat__value">{{ $import->mode ?: '-' }}</strong>
            </div>
        </section>

        @if($import->status === \App\Models\Import::STATUS_PREVIEW)
            <section class="import-show-card">
                <div class="import-show-execute">
                    <div>
                        <h2 class="import-show-card__title">Execute import</h2>
                        <p class="import-show-card__subtitle">Dry-run completed. Choose execution mode, then run or cancel this import.</p>
                    </div>
                    <div class="import-show-execute__forms">
                        <form method="POST" action="{{ route('imports.execute', $import) }}" class="import-show-execute__form" x-data="{ submitting: false }" @submit="submitting = true">
                            @csrf
                            <div class="import-show-field">
                                <label for="import-execute-mode">Mode</label>
                                <select id="import-execute-mode" name="mode" required>
                                    <option value="strict">Strict (all-or-nothing)</option>
                                    <option value="row">Row level (partial with errors)</option>
                                </select>
                            </div>
                            <button type="submit" class="btn import-show-primary" :disabled="submitting">
                                <template x-if="!submitting"><span>Execute</span></template>
                                <template x-if="submitting"><span>Importing...</span></template>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('imports.cancel', $import) }}" data-confirm-message="Cancel this import? This cannot be undone.">
                            @csrf
                            <button type="submit" class="btn import-show-danger">Cancel import</button>
                        </form>
                    </div>
                </div>
            </section>
        @endif

        @if($import->type === \App\Models\Import::TYPE_MANUFACTURE && !empty(data_get($import->preview_summary, 'lot_summary')))
            <section class="import-show-card">
                <div class="import-show-card__head">
                    <div>
                        <h2 class="import-show-card__title">Lot impact preview</h2>
                        <p class="import-show-card__subtitle">Fine balance after manufacture import.</p>
                    </div>
                </div>
                <div class="import-show-table-wrap">
                    <table class="import-show-table">
                        <thead>
                            <tr>
                                <th>Lot</th>
                                <th class="text-right">Required fine</th>
                                <th class="text-right">Available</th>
                                <th class="text-right">After import</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(data_get($import->preview_summary, 'lot_summary', []) as $lot)
                                <tr>
                                    <td>#{{ $lot['lot_number'] ?? '-' }}</td>
                                    <td class="text-right">{{ number_format($lot['required_fine'], 6) }}</td>
                                    <td class="text-right">{{ number_format($lot['available_fine'], 6) }}</td>
                                    <td class="text-right">{{ number_format($lot['after_import_fine'], 6) }}</td>
                                    <td class="text-center">
                                        <span @class([
                                            'import-show-status',
                                            'import-show-status--valid' => (bool) $lot['sufficient'],
                                            'import-show-status--invalid' => ! (bool) $lot['sufficient'],
                                        ])>{{ $lot['sufficient'] ? 'OK' : 'Insufficient' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="import-show-card">
            <div class="import-show-card__head">
                <div>
                    <h2 class="import-show-card__title">Rows</h2>
                    <p class="import-show-card__subtitle">{{ $rows->total() }} rows in this import preview.</p>
                </div>
            </div>

            <div class="import-show-table-wrap">
                <table class="import-show-table">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Status</th>
                            <th>Error</th>
                            <th>Payload</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            @php
                                $payload = (array) ($row->payload ?? []);
                                $computed = (array) ($row->computed ?? []);
                                $payloadFields = $import->type === \App\Models\Import::TYPE_CATALOG
                                    ? [
                                        'Design' => $payload['design_code'] ?? '-',
                                        'Name' => $payload['name'] ?? '-',
                                        'Category' => $payload['category'] ?? '-',
                                        'Sub Category' => $payload['sub_category'] ?? '-',
                                        'Purity' => $payload['default_purity'] ?? '-',
                                        'Approx Weight' => $payload['approx_weight'] ?? '-',
                                        'Default Making' => $payload['default_making'] ?? '-',
                                        'Stone Type' => $payload['stone_type'] ?? '-',
                                    ]
                                    : [
                                        'Barcode' => $payload['barcode'] ?? '-',
                                        'Design Code' => $payload['design_code'] ?? '-',
                                        'Lot' => $payload['lot_number'] ?? '-',
                                        'Gross Weight' => $payload['gross_weight'] ?? '-',
                                        'Stone Weight' => $payload['stone_weight'] ?? '-',
                                        'Purity' => $payload['purity'] ?? '-',
                                        'Wastage %' => $payload['wastage_percent'] ?? '-',
                                        'Making' => $payload['making_charge'] ?? '-',
                                    ];
                            @endphp
                            <tr>
                                <td>{{ $row->row_number }}</td>
                                <td>
                                    <span @class([
                                        'import-show-status',
                                        'import-show-status--valid' => $row->status === 'valid',
                                        'import-show-status--invalid' => $row->status === 'invalid',
                                    ])>{{ ucfirst($row->status) }}</span>
                                </td>
                                <td>
                                    @if($row->error_message)
                                        <div class="import-show-error">{{ $row->error_message }}</div>
                                    @else
                                        <span class="import-show-muted">No error</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="import-show-payload">
                                        @foreach($payloadFields as $label => $value)
                                            <div class="import-show-payload__field">
                                                <span>{{ $label }}</span>
                                                <strong>{{ $value }}</strong>
                                            </div>
                                        @endforeach
                                    </div>

                                    @if(!empty($computed['will_create_category']) || !empty($computed['will_create_sub_category']))
                                        <div class="import-show-chip-row">
                                            @if(!empty($computed['will_create_category']))
                                                <span class="import-show-chip">Will create category</span>
                                            @endif
                                            @if(!empty($computed['will_create_sub_category']))
                                                <span class="import-show-chip import-show-chip--blue">Will create sub-category</span>
                                            @endif
                                        </div>
                                    @endif

                                    <details class="import-show-details">
                                        <summary>View raw payload</summary>
                                        <pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="import-show-empty">No rows</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="import-show-row-cards">
                @forelse($rows as $row)
                    @php
                        $payload = (array) ($row->payload ?? []);
                        $computed = (array) ($row->computed ?? []);
                        $payloadFields = $import->type === \App\Models\Import::TYPE_CATALOG
                            ? [
                                'Design' => $payload['design_code'] ?? '-',
                                'Name' => $payload['name'] ?? '-',
                                'Category' => $payload['category'] ?? '-',
                                'Sub Category' => $payload['sub_category'] ?? '-',
                                'Purity' => $payload['default_purity'] ?? '-',
                                'Approx Weight' => $payload['approx_weight'] ?? '-',
                                'Default Making' => $payload['default_making'] ?? '-',
                                'Stone Type' => $payload['stone_type'] ?? '-',
                            ]
                            : [
                                'Barcode' => $payload['barcode'] ?? '-',
                                'Design Code' => $payload['design_code'] ?? '-',
                                'Lot' => $payload['lot_number'] ?? '-',
                                'Gross Weight' => $payload['gross_weight'] ?? '-',
                                'Stone Weight' => $payload['stone_weight'] ?? '-',
                                'Purity' => $payload['purity'] ?? '-',
                                'Wastage %' => $payload['wastage_percent'] ?? '-',
                                'Making' => $payload['making_charge'] ?? '-',
                            ];
                    @endphp
                    <article class="import-show-row-card">
                        <div class="import-show-row-card__top">
                            <span class="import-show-row-card__row">Row {{ $row->row_number }}</span>
                            <span @class([
                                'import-show-status',
                                'import-show-status--valid' => $row->status === 'valid',
                                'import-show-status--invalid' => $row->status === 'invalid',
                            ])>{{ ucfirst($row->status) }}</span>
                        </div>

                        @if($row->error_message)
                            <div class="import-show-error">{{ $row->error_message }}</div>
                        @endif

                        <div class="import-show-payload">
                            @foreach($payloadFields as $label => $value)
                                <div class="import-show-payload__field">
                                    <span>{{ $label }}</span>
                                    <strong>{{ $value }}</strong>
                                </div>
                            @endforeach
                        </div>

                        @if(!empty($computed['will_create_category']) || !empty($computed['will_create_sub_category']))
                            <div class="import-show-chip-row">
                                @if(!empty($computed['will_create_category']))
                                    <span class="import-show-chip">Will create category</span>
                                @endif
                                @if(!empty($computed['will_create_sub_category']))
                                    <span class="import-show-chip import-show-chip--blue">Will create sub-category</span>
                                @endif
                            </div>
                        @endif

                        <details class="import-show-details">
                            <summary>View raw payload</summary>
                            <pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </article>
                @empty
                    <div class="import-show-empty">No rows</div>
                @endforelse
            </div>

            <div class="import-show-pagination">{{ $rows->links() }}</div>
        </section>
    </div>
</x-app-layout>
