@php
    $ruleCount = $rules->count();
    $activeRuleCount = $rules->where('is_active', true)->count();
    $alertCount = $alerts->count();
@endphp

<x-app-layout>
    <style>
        .reorder-index-page {
            --reorder-border: #d8e1ef;
            --reorder-border-strong: #c7d4e6;
            --reorder-surface: #ffffff;
            --reorder-surface-soft: #f6f8fc;
            --reorder-text: #16213d;
            --reorder-text-soft: #60708f;
            --reorder-accent: #0d9488;
            --reorder-accent-soft: rgba(13, 148, 136, 0.1);
            --reorder-warn: #dc2626;
            --reorder-warn-soft: rgba(220, 38, 38, 0.08);
            --reorder-success: #15803d;
            --reorder-success-soft: rgba(21, 128, 61, 0.08);
            --reorder-shadow: 0 18px 42px rgba(15, 23, 42, 0.06);
        }

        .reorder-index-page .reorder-index-flash,
        .reorder-index-page .reorder-stats-grid,
        .reorder-index-page .reorder-alerts-card,
        .reorder-index-page .reorder-rules-card,
        .reorder-index-page .reorder-mobile-card {
            border: 1px solid var(--reorder-border);
            border-radius: 24px;
            background: var(--reorder-surface);
            box-shadow: var(--reorder-shadow);
        }

        .reorder-index-page .reorder-index-flash {
            margin-bottom: 18px;
            padding: 14px 16px;
            border-color: rgba(21, 128, 61, 0.16);
            background: #f3fbf6;
            color: #166534;
            font-size: 14px;
            font-weight: 600;
        }

        .reorder-index-page .reorder-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
        }

        .reorder-index-page .reorder-stat-card {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            padding: 18px;
            border: 1px solid var(--reorder-border);
            border-radius: 24px;
            background: var(--reorder-surface);
            box-shadow: var(--reorder-shadow);
        }

        .reorder-index-page .reorder-stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: 16px;
            border: 1px solid var(--reorder-border);
            background: linear-gradient(180deg, #f1fcfb 0%, #fff 100%);
            color: var(--reorder-accent);
        }

        .reorder-index-page .reorder-stat-icon--warn {
            background: linear-gradient(180deg, #fff7f7 0%, #fff 100%);
            color: var(--reorder-warn);
        }

        .reorder-index-page .reorder-stat-label {
            margin: 0 0 6px;
            color: var(--reorder-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .reorder-index-page .reorder-stat-value {
            margin: 0;
            color: var(--reorder-text);
            font-size: 30px;
            font-weight: 700;
            line-height: 1;
        }

        .reorder-index-page .reorder-stat-note {
            margin: 6px 0 0;
            color: var(--reorder-text-soft);
            font-size: 13px;
            line-height: 1.5;
        }

        .reorder-index-page .reorder-alerts-card,
        .reorder-index-page .reorder-rules-card {
            padding: 18px;
        }

        .reorder-index-page .reorder-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .reorder-index-page .reorder-section-kicker {
            margin: 0 0 6px;
            color: var(--reorder-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .reorder-index-page .reorder-section-title {
            margin: 0;
            color: var(--reorder-text);
            font-size: 20px;
            font-weight: 700;
            line-height: 1.15;
        }

        .reorder-index-page .reorder-section-copy {
            margin: 6px 0 0;
            color: var(--reorder-text-soft);
            font-size: 14px;
            line-height: 1.55;
        }

        .reorder-index-page .reorder-meta-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--reorder-border);
            background: var(--reorder-surface-soft);
            color: var(--reorder-text);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .reorder-index-page .reorder-meta-pill--warn {
            border-color: rgba(220, 38, 38, 0.15);
            background: var(--reorder-warn-soft);
            color: #b91c1c;
        }

        .reorder-index-page .reorder-meta-pill--success {
            border-color: rgba(21, 128, 61, 0.16);
            background: var(--reorder-success-soft);
            color: #166534;
        }

        .reorder-index-page .reorder-alerts-card {
            margin-bottom: 20px;
        }

        .reorder-index-page .reorder-alerts-state {
            border: 1px dashed #d7e3f1;
            border-radius: 20px;
            padding: 18px;
            background: #fbfcfe;
        }

        .reorder-index-page .reorder-alerts-state--warn {
            border-style: solid;
            border-color: rgba(220, 38, 38, 0.14);
            background: linear-gradient(180deg, #fff8f8 0%, #ffffff 100%);
        }

        .reorder-index-page .reorder-alerts-state--success {
            border-color: rgba(21, 128, 61, 0.14);
            background: linear-gradient(180deg, #f7fcf8 0%, #ffffff 100%);
        }

        .reorder-index-page .reorder-alert-list {
            display: grid;
            gap: 12px;
        }

        .reorder-index-page .reorder-alert-item {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) repeat(3, minmax(0, auto));
            gap: 12px;
            align-items: center;
            padding: 14px 16px;
            border: 1px solid rgba(220, 38, 38, 0.12);
            border-radius: 18px;
            background: #fff;
        }

        .reorder-index-page .reorder-alert-name {
            margin: 0;
            color: var(--reorder-text);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.4;
        }

        .reorder-index-page .reorder-alert-sub {
            margin: 4px 0 0;
            color: var(--reorder-text-soft);
            font-size: 12px;
            line-height: 1.5;
        }

        .reorder-index-page .reorder-metric {
            min-width: 86px;
            padding: 10px 12px;
            border-radius: 14px;
            background: #fbfcfe;
            border: 1px solid #e5edf6;
            text-align: center;
        }

        .reorder-index-page .reorder-metric-label {
            display: block;
            margin-bottom: 4px;
            color: var(--reorder-text-soft);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .reorder-index-page .reorder-metric-value {
            display: block;
            color: var(--reorder-text);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.2;
        }

        .reorder-index-page .reorder-metric-value--warn {
            color: #b91c1c;
        }

        .reorder-index-page .reorder-table-wrap {
            overflow-x: auto;
        }

        .reorder-index-page .reorder-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .reorder-index-page .reorder-table th {
            padding: 0 16px 14px;
            border-bottom: 1px solid #e4ebf5;
            color: var(--reorder-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            text-align: left;
            white-space: nowrap;
        }

        .reorder-index-page .reorder-table td {
            padding: 16px;
            border-bottom: 1px solid #edf2f8;
            color: var(--reorder-text);
            font-size: 14px;
            vertical-align: top;
        }

        .reorder-index-page .reorder-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .reorder-index-page .reorder-cell-title {
            margin: 0;
            color: var(--reorder-text);
            font-size: 14px;
            font-weight: 700;
            line-height: 1.4;
        }

        .reorder-index-page .reorder-cell-sub {
            display: block;
            margin-top: 4px;
            color: var(--reorder-text-soft);
            font-size: 12px;
            line-height: 1.45;
        }

        .reorder-index-page .reorder-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .reorder-index-page .reorder-status-pill--active {
            background: rgba(13, 148, 136, 0.11);
            color: #0f766e;
        }

        .reorder-index-page .reorder-status-pill--inactive {
            background: rgba(148, 163, 184, 0.16);
            color: #475569;
        }

        .reorder-index-page .reorder-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .reorder-index-page .reorder-action-link,
        .reorder-index-page .reorder-action-btn,
        .reorder-index-add {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 14px;
            border: 1px solid var(--reorder-border);
            background: #fff;
            color: var(--reorder-text);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.18s ease, background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
        }

        .reorder-index-page .reorder-action-link:hover,
        .reorder-index-page .reorder-action-btn:hover,
        .reorder-index-add:hover {
            transform: translateY(-1px);
            background: var(--reorder-surface-soft);
            color: var(--reorder-text);
        }

        .reorder-index-page .reorder-action-btn {
            cursor: pointer;
        }

        .reorder-index-page .reorder-action-btn--danger {
            border-color: rgba(220, 38, 38, 0.16);
            color: #b91c1c;
            background: #fff8f8;
        }

        .reorder-index-page .reorder-mobile-list {
            display: none;
        }

        .reorder-index-page .reorder-mobile-card {
            padding: 16px;
        }

        .reorder-index-page .reorder-mobile-card + .reorder-mobile-card {
            margin-top: 12px;
        }

        .reorder-index-page .reorder-mobile-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .reorder-index-page .reorder-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .reorder-index-page .reorder-mobile-metric {
            padding: 12px;
            border-radius: 16px;
            border: 1px solid #e5edf6;
            background: #fbfcfe;
        }

        .reorder-index-page .reorder-empty {
            padding: 22px;
            border: 1px dashed #d7e3f1;
            border-radius: 20px;
            background: #fbfcfe;
            color: var(--reorder-text-soft);
            font-size: 14px;
            line-height: 1.6;
            text-align: center;
        }

        .reorder-index-page .reorder-index-add-short {
            display: none;
        }

        @media (max-width: 980px) {
            .reorder-index-page .reorder-alert-item {
                grid-template-columns: 1fr;
            }

            .reorder-index-page .reorder-alert-item .reorder-metric {
                text-align: left;
            }
        }

        @media (max-width: 767px) {
            .reorder-index-page .reorder-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .reorder-index-page .reorder-stat-card:last-child {
                grid-column: 1 / -1;
            }

            .reorder-index-page .reorder-stat-card,
            .reorder-index-page .reorder-alerts-card,
            .reorder-index-page .reorder-rules-card,
            .reorder-index-page .reorder-mobile-card,
            .reorder-index-page .reorder-index-flash {
                border-radius: 20px;
            }

            .reorder-index-page .reorder-stat-card {
                align-items: flex-start;
                padding: 15px;
            }

            .reorder-index-page .reorder-stat-icon {
                width: 42px;
                height: 42px;
                border-radius: 14px;
            }

            .reorder-index-page .reorder-stat-value {
                font-size: 24px;
            }

            .reorder-index-page .reorder-stat-note {
                display: none;
            }

            .reorder-index-page .reorder-alerts-card,
            .reorder-index-page .reorder-rules-card {
                padding: 16px;
            }

            .reorder-index-page .reorder-section-head {
                margin-bottom: 14px;
            }

            .reorder-index-page .reorder-section-title {
                font-size: 18px;
            }

            .reorder-index-page .reorder-section-copy {
                font-size: 13px;
            }

            .reorder-index-page .reorder-meta-pill {
                min-height: 30px;
                padding: 0 10px;
                font-size: 11px;
            }

            .reorder-index-page .reorder-table-wrap {
                display: none;
            }

            .reorder-index-page .reorder-mobile-list {
                display: block;
            }

            .reorder-index-page .reorder-mobile-grid {
                grid-template-columns: 1fr;
            }

            .reorder-index-page .reorder-actions {
                width: 100%;
            }

            .reorder-index-page .reorder-action-link,
            .reorder-index-page .reorder-action-btn {
                flex: 1 1 0;
                min-height: 40px;
                border-radius: 12px;
                padding: 0 12px;
                font-size: 12px;
            }

            .reorder-index-page .reorder-index-add {
                min-height: 40px;
                padding: 0 14px;
                border-radius: 14px;
            }

            .reorder-index-page .reorder-index-add-full {
                display: none;
            }

            .reorder-index-page .reorder-index-add-short {
                display: inline;
            }
        }
    </style>

    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Reorder Rules</h1>
            <p class="page-subtitle">Track low-stock thresholds and keep preferred supplier links ready for restocking.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('reorder.create') }}" class="reorder-index-add">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>
                </svg>
                <span class="reorder-index-add-full">Add Rule</span>
                <span class="reorder-index-add-short">Add</span>
            </a>
        </div>
    </x-page-header>

    <div class="content-inner reorder-index-page">
        @if (session('success'))
            <div class="reorder-index-flash">{{ session('success') }}</div>
        @endif

        <section class="reorder-stats-grid" aria-label="Reorder overview">
            <article class="reorder-stat-card">
                <span class="reorder-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 7.5 12 3l9 4.5-9 4.5L3 7.5Z"/>
                        <path d="M3 12l9 4.5 9-4.5"/>
                        <path d="M3 16.5 12 21l9-4.5"/>
                    </svg>
                </span>
                <div>
                    <p class="reorder-stat-label">Rules</p>
                    <p class="reorder-stat-value">{{ $ruleCount }}</p>
                    <p class="reorder-stat-note">Configured reorder checks across your stock categories.</p>
                </div>
            </article>
            <article class="reorder-stat-card">
                <span class="reorder-stat-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m5 13 4 4L19 7"/>
                    </svg>
                </span>
                <div>
                    <p class="reorder-stat-label">Active</p>
                    <p class="reorder-stat-value">{{ $activeRuleCount }}</p>
                    <p class="reorder-stat-note">{{ $ruleCount - $activeRuleCount }} paused rule{{ $ruleCount - $activeRuleCount === 1 ? '' : 's' }} still kept for later use.</p>
                </div>
            </article>
            <article class="reorder-stat-card">
                <span class="reorder-stat-icon reorder-stat-icon--warn" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
                        <path d="M12 9v4"/>
                        <path d="M12 17h.01"/>
                    </svg>
                </span>
                <div>
                    <p class="reorder-stat-label">Alerts</p>
                    <p class="reorder-stat-value">{{ $alertCount }}</p>
                    <p class="reorder-stat-note">Rules currently below threshold and ready for follow-up.</p>
                </div>
            </article>
        </section>

        <section class="reorder-alerts-card">
            <div class="reorder-section-head">
                <div>
                    <p class="reorder-section-kicker">Alerts</p>
                    <h2 class="reorder-section-title">Low stock watch</h2>
                    <p class="reorder-section-copy">Quickly review which rules need vendor follow-up before the gap gets larger.</p>
                </div>
                <span class="reorder-meta-pill {{ $alertCount > 0 ? 'reorder-meta-pill--warn' : 'reorder-meta-pill--success' }}">
                    {{ $alertCount > 0 ? $alertCount . ' active' : 'All clear' }}
                </span>
            </div>

            @if ($alertCount > 0)
                <div class="reorder-alerts-state reorder-alerts-state--warn">
                    <div class="reorder-alert-list">
                        @foreach ($alerts as $alert)
                            <article class="reorder-alert-item">
                                <div>
                                    <p class="reorder-alert-name">{{ $alert['category'] ?: 'All categories' }}</p>
                                    <p class="reorder-alert-sub">
                                        {{ $alert['sub_category'] ?: 'All sub-categories' }}
                                        <span aria-hidden="true">•</span>
                                        {{ optional($alert['vendor'])->name ?: 'No preferred vendor linked' }}
                                    </p>
                                </div>
                                <div class="reorder-metric">
                                    <span class="reorder-metric-label">Current</span>
                                    <span class="reorder-metric-value reorder-metric-value--warn">{{ $alert['current_stock'] }}</span>
                                </div>
                                <div class="reorder-metric">
                                    <span class="reorder-metric-label">Threshold</span>
                                    <span class="reorder-metric-value">{{ $alert['threshold'] }}</span>
                                </div>
                                <div class="reorder-metric">
                                    <span class="reorder-metric-label">Gap</span>
                                    <span class="reorder-metric-value reorder-metric-value--warn">{{ max($alert['threshold'] - $alert['current_stock'], 0) }}</span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="reorder-alerts-state reorder-alerts-state--success">
                    <p class="reorder-section-copy" style="margin: 0;">All current stock levels are sitting within the thresholds you configured.</p>
                </div>
            @endif
        </section>

        <section class="reorder-rules-card">
            <div class="reorder-section-head">
                <div>
                    <p class="reorder-section-kicker">Directory</p>
                    <h2 class="reorder-section-title">Rule list</h2>
                    <p class="reorder-section-copy">Review threshold coverage, preferred vendor links, and activation state in one place.</p>
                </div>
                <span class="reorder-meta-pill">{{ $ruleCount }} record{{ $ruleCount === 1 ? '' : 's' }}</span>
            </div>

            @if ($ruleCount > 0)
                <div class="reorder-table-wrap">
                    <table class="reorder-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Sub-Category</th>
                                <th>Min Threshold</th>
                                <th>Preferred Vendor</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $rule)
                                <tr data-deletable-row>
                                    <td>
                                        <p class="reorder-cell-title">{{ $rule->category ?: 'All categories' }}</p>
                                        <span class="reorder-cell-sub">Covers every matching in-stock item.</span>
                                    </td>
                                    <td>{{ $rule->sub_category ?: 'All sub-categories' }}</td>
                                    <td>{{ $rule->min_stock_threshold }}</td>
                                    <td>{{ optional($rule->vendor)->name ?: 'Not linked' }}</td>
                                    <td>
                                        <span class="reorder-status-pill {{ $rule->is_active ? 'reorder-status-pill--active' : 'reorder-status-pill--inactive' }}">
                                            {{ $rule->is_active ? 'Active' : 'Paused' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="reorder-actions">
                                            <a href="{{ route('reorder.edit', $rule) }}" class="reorder-action-link">Edit</a>
                                            <form method="POST" action="{{ route('reorder.destroy', $rule) }}" data-confirm-message="Delete this reorder rule?" data-ajax-delete>
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="reorder-action-btn reorder-action-btn--danger">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="reorder-mobile-list">
                    @foreach ($rules as $rule)
                        <article class="reorder-mobile-card" data-deletable-row>
                            <div class="reorder-mobile-top">
                                <div>
                                    <p class="reorder-cell-title">{{ $rule->category ?: 'All categories' }}</p>
                                    <span class="reorder-cell-sub">{{ $rule->sub_category ?: 'All sub-categories' }}</span>
                                </div>
                                <span class="reorder-status-pill {{ $rule->is_active ? 'reorder-status-pill--active' : 'reorder-status-pill--inactive' }}">
                                    {{ $rule->is_active ? 'Active' : 'Paused' }}
                                </span>
                            </div>

                            <div class="reorder-mobile-grid">
                                <div class="reorder-mobile-metric">
                                    <span class="reorder-metric-label">Threshold</span>
                                    <span class="reorder-metric-value">{{ $rule->min_stock_threshold }}</span>
                                </div>
                                <div class="reorder-mobile-metric">
                                    <span class="reorder-metric-label">Vendor</span>
                                    <span class="reorder-metric-value">{{ optional($rule->vendor)->name ?: 'Not linked' }}</span>
                                </div>
                            </div>

                            <div class="reorder-actions">
                                <a href="{{ route('reorder.edit', $rule) }}" class="reorder-action-link">Edit</a>
                                <form method="POST" action="{{ route('reorder.destroy', $rule) }}" data-confirm-message="Delete this reorder rule?" data-ajax-delete style="flex: 1 1 0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="reorder-action-btn reorder-action-btn--danger" style="width: 100%;">Delete</button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @else
                <div class="reorder-empty">
                    No reorder rules are configured yet. Add your first rule to start tracking stock thresholds by category or sub-category.
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
