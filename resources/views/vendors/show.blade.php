@php
    $itemCount = $vendor->items_count ?? $items->count();
    $locationLabel = trim(implode(', ', array_filter([$vendor->city, $vendor->state])));
@endphp

<x-app-layout>
    <style>
        .vendors-show-page {
            --vendors-show-border: #d8e1ef;
            --vendors-show-border-strong: #c8d5e7;
            --vendors-show-surface: #ffffff;
            --vendors-show-surface-soft: #f7f9fc;
            --vendors-show-text: #16213d;
            --vendors-show-text-soft: #64748b;
            --vendors-show-accent: #0f766e;
            --vendors-show-accent-soft: rgba(15, 118, 110, 0.1);
            --vendors-show-warm-soft: rgba(245, 158, 11, 0.12);
            --vendors-show-shadow: 0 18px 44px rgba(15, 23, 42, 0.06);
        }

        .vendors-show-page .vendors-show-shell {
            display: grid;
            grid-template-columns: minmax(0, 1.55fr) minmax(300px, 0.85fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .vendors-show-page .vendors-show-card,
        .vendors-show-page .vendors-show-items-card,
        .vendors-show-page .vendors-show-item-mobile {
            border: 1px solid var(--vendors-show-border);
            border-radius: 24px;
            background: var(--vendors-show-surface);
            box-shadow: var(--vendors-show-shadow);
        }

        .vendors-show-page .vendors-show-card {
            padding: 22px;
        }

        .vendors-show-page .vendors-show-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .vendors-show-page .vendors-show-kicker {
            margin: 0 0 6px;
            color: var(--vendors-show-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .vendors-show-page .vendors-show-title {
            margin: 0;
            color: var(--vendors-show-text);
            font-size: 24px;
            font-weight: 700;
            line-height: 1.15;
        }

        .vendors-show-page .vendors-show-copy {
            margin: 8px 0 0;
            color: var(--vendors-show-text-soft);
            font-size: 14px;
            line-height: 1.6;
        }

        .vendors-show-page .vendors-show-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--vendors-show-border);
            background: var(--vendors-show-surface-soft);
            color: var(--vendors-show-text);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .vendors-show-page .vendors-show-pill--active {
            border-color: rgba(15, 118, 110, 0.16);
            background: var(--vendors-show-accent-soft);
            color: var(--vendors-show-accent);
        }

        .vendors-show-page .vendors-show-pill--inactive {
            background: #eef2f7;
            color: #64748b;
        }

        .vendors-show-page .vendors-show-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .vendors-show-page .vendors-show-meta-card {
            border: 1px solid #e7edf6;
            border-radius: 18px;
            background: #fbfcfe;
            padding: 14px;
            min-width: 0;
        }

        .vendors-show-page .vendors-show-meta-card--full {
            grid-column: 1 / -1;
        }

        .vendors-show-page .vendors-show-meta-label {
            margin: 0 0 6px;
            color: var(--vendors-show-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .vendors-show-page .vendors-show-meta-value {
            margin: 0;
            color: var(--vendors-show-text);
            font-size: 15px;
            font-weight: 600;
            line-height: 1.5;
            word-break: break-word;
        }

        .vendors-show-page .vendors-show-meta-value--mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 14px;
        }

        .vendors-show-page .vendors-show-summary-list {
            display: grid;
            gap: 12px;
        }

        .vendors-show-page .vendors-show-summary-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid #e7edf6;
        }

        .vendors-show-page .vendors-show-summary-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .vendors-show-page .vendors-show-summary-row:first-child {
            padding-top: 0;
        }

        .vendors-show-page .vendors-show-summary-key {
            color: var(--vendors-show-text-soft);
            font-size: 13px;
            font-weight: 600;
        }

        .vendors-show-page .vendors-show-summary-value {
            color: var(--vendors-show-text);
            font-size: 15px;
            font-weight: 700;
            text-align: right;
        }

        .vendors-show-page .vendors-show-items-card {
            overflow: hidden;
        }

        .vendors-show-page .vendors-show-items-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 20px 22px 16px;
            border-bottom: 1px solid var(--vendors-show-border);
        }

        .vendors-show-page .vendors-show-items-title {
            margin: 0;
            color: var(--vendors-show-text);
            font-size: 20px;
            font-weight: 700;
        }

        .vendors-show-page .vendors-show-items-copy {
            margin: 6px 0 0;
            color: var(--vendors-show-text-soft);
            font-size: 14px;
        }

        .vendors-show-page .vendors-show-count-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid rgba(245, 158, 11, 0.2);
            background: var(--vendors-show-warm-soft);
            color: #b45309;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .vendors-show-page .vendors-show-table-wrap {
            overflow-x: auto;
        }

        .vendors-show-page .vendors-show-table {
            width: 100%;
            min-width: 760px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .vendors-show-page .vendors-show-table thead th {
            padding: 14px 22px;
            border-bottom: 1px solid var(--vendors-show-border);
            background: #f8fafc;
            color: var(--vendors-show-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .vendors-show-page .vendors-show-table tbody td {
            padding: 16px 22px;
            border-bottom: 1px solid #e7edf6;
            color: var(--vendors-show-text);
            font-size: 14px;
            vertical-align: middle;
        }

        .vendors-show-page .vendors-show-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .vendors-show-page .vendors-show-table tbody tr:hover {
            background: #fbfcfe;
        }

        .vendors-show-page .vendors-show-text-mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        .vendors-show-page .vendors-show-mobile-items {
            display: none;
            padding: 0 16px 16px;
        }

        .vendors-show-page .vendors-show-item-mobile {
            padding: 16px;
        }

        .vendors-show-page .vendors-show-item-mobile + .vendors-show-item-mobile {
            margin-top: 12px;
        }

        .vendors-show-page .vendors-show-item-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .vendors-show-page .vendors-show-item-title {
            margin: 0;
            color: var(--vendors-show-text);
            font-size: 15px;
            font-weight: 700;
            line-height: 1.35;
            word-break: break-word;
        }

        .vendors-show-page .vendors-show-item-price {
            color: var(--vendors-show-text);
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        .vendors-show-page .vendors-show-item-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .vendors-show-page .vendors-show-item-grid > div {
            border: 1px solid #e7edf6;
            border-radius: 16px;
            background: #fbfcfe;
            padding: 12px;
            min-width: 0;
        }

        .vendors-show-page .vendors-show-item-grid dt {
            margin: 0 0 4px;
            color: var(--vendors-show-text-soft);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .vendors-show-page .vendors-show-item-grid dd {
            margin: 0;
            color: var(--vendors-show-text);
            font-size: 13px;
            font-weight: 600;
            line-height: 1.45;
            word-break: break-word;
        }

        @media (max-width: 1024px) {
            .vendors-show-page .vendors-show-shell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .vendors-show-page .vendors-show-card,
            .vendors-show-page .vendors-show-item-mobile {
                border-radius: 20px;
            }

            .vendors-show-page .vendors-show-card {
                padding: 16px;
            }

            .vendors-show-page .vendors-show-card-head {
                gap: 10px;
                margin-bottom: 14px;
            }

            .vendors-show-page .vendors-show-title {
                font-size: 20px;
            }

            .vendors-show-page .vendors-show-copy {
                display: none;
            }

            .vendors-show-page .vendors-show-pill {
                min-height: 30px;
                padding: 0 10px;
                font-size: 11px;
            }

            .vendors-show-page .vendors-show-meta-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .vendors-show-page .vendors-show-meta-card {
                padding: 12px;
                border-radius: 16px;
            }

            .vendors-show-page .vendors-show-meta-label {
                font-size: 10px;
            }

            .vendors-show-page .vendors-show-meta-value {
                font-size: 14px;
            }

            .vendors-show-page .vendors-show-summary-key {
                font-size: 12px;
            }

            .vendors-show-page .vendors-show-summary-value {
                font-size: 14px;
            }

            .vendors-show-page .vendors-show-items-head {
                padding: 16px;
                gap: 10px;
            }

            .vendors-show-page .vendors-show-items-title {
                font-size: 18px;
            }

            .vendors-show-page .vendors-show-items-copy {
                font-size: 13px;
            }

            .vendors-show-page .vendors-show-count-badge {
                min-height: 32px;
                padding: 0 10px;
                font-size: 11px;
            }

            .vendors-show-page .vendors-show-table-wrap {
                display: none;
            }

            .vendors-show-page .vendors-show-mobile-items {
                display: block;
            }
        }

        @media (max-width: 420px) {
            .vendors-show-page .vendors-show-item-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <x-page-header class="vendors-show-header ops-treatment-header" :title="$vendor->name" subtitle="Vendor details & associated items">
        <x-slot:actions>
            <a href="{{ route('vendors.edit', $vendor) }}" class="btn btn-secondary btn-sm vendors-show-edit-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                    <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" />
                </svg>
                <span class="vendors-show-edit-label-full">Edit Vendor</span>
                <span class="vendors-show-edit-label-short">Edit</span>
            </a>
            <a href="{{ route('vendors.index') }}" class="btn btn-secondary btn-sm vendors-show-back-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5">
                    <line x1="19" y1="12" x2="5" y2="12" />
                    <polyline points="12 19 5 12 12 5" />
                </svg>
                <span class="vendors-show-back-label-full">Back to Vendors</span>
                <span class="vendors-show-back-label-short">Back</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ops-treatment-page vendors-show-page">
        @if(session('success'))
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="vendors-show-shell">
            <section class="vendors-show-card">
                <div class="vendors-show-card-head">
                    <div>
                        <p class="vendors-show-kicker">Vendor Details</p>
                        <h2 class="vendors-show-title">{{ $vendor->name }}</h2>
                        <p class="vendors-show-copy">Supplier contact, billing, and registration details in one place.</p>
                    </div>
                    <span class="vendors-show-pill {{ $vendor->is_active ? 'vendors-show-pill--active' : 'vendors-show-pill--inactive' }}">
                        {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </div>

                <div class="vendors-show-meta-grid">
                    <div class="vendors-show-meta-card">
                        <p class="vendors-show-meta-label">Contact Person</p>
                        <p class="vendors-show-meta-value">{{ $vendor->contact_person ?: 'Not added' }}</p>
                    </div>

                    <div class="vendors-show-meta-card">
                        <p class="vendors-show-meta-label">Mobile</p>
                        <p class="vendors-show-meta-value">{{ $vendor->mobile ?: 'Not available' }}</p>
                    </div>

                    <div class="vendors-show-meta-card">
                        <p class="vendors-show-meta-label">Email</p>
                        <p class="vendors-show-meta-value">{{ $vendor->email ?: 'Not available' }}</p>
                    </div>

                    <div class="vendors-show-meta-card">
                        <p class="vendors-show-meta-label">City / State</p>
                        <p class="vendors-show-meta-value">{{ $locationLabel !== '' ? $locationLabel : 'Not added' }}</p>
                    </div>

                    <div class="vendors-show-meta-card vendors-show-meta-card--full">
                        <p class="vendors-show-meta-label">GST Number</p>
                        <p class="vendors-show-meta-value vendors-show-meta-value--mono">{{ $vendor->gst_number ?: 'Not available' }}</p>
                    </div>

                    @if($vendor->address)
                        <div class="vendors-show-meta-card vendors-show-meta-card--full">
                            <p class="vendors-show-meta-label">Address</p>
                            <p class="vendors-show-meta-value">{{ $vendor->address }}</p>
                        </div>
                    @endif

                    @if($vendor->notes)
                        <div class="vendors-show-meta-card vendors-show-meta-card--full">
                            <p class="vendors-show-meta-label">Notes</p>
                            <p class="vendors-show-meta-value">{{ $vendor->notes }}</p>
                        </div>
                    @endif
                </div>
            </section>

            <aside class="vendors-show-card">
                <div class="vendors-show-card-head">
                    <div>
                        <p class="vendors-show-kicker">Summary</p>
                        <h2 class="vendors-show-title">Overview</h2>
                        <p class="vendors-show-copy">Quick indicators for this vendor record.</p>
                    </div>
                </div>

                <div class="vendors-show-summary-list">
                    <div class="vendors-show-summary-row">
                        <span class="vendors-show-summary-key">Status</span>
                        <span class="vendors-show-summary-value">{{ $vendor->is_active ? 'Active' : 'Inactive' }}</span>
                    </div>
                    <div class="vendors-show-summary-row">
                        <span class="vendors-show-summary-key">In-Stock Items</span>
                        <span class="vendors-show-summary-value">{{ number_format($itemCount) }}</span>
                    </div>
                    <div class="vendors-show-summary-row">
                        <span class="vendors-show-summary-key">Added On</span>
                        <span class="vendors-show-summary-value">{{ $vendor->created_at->format('d M Y') }}</span>
                    </div>
                    <div class="vendors-show-summary-row">
                        <span class="vendors-show-summary-key">Vendor Since</span>
                        <span class="vendors-show-summary-value">{{ $vendor->created_at->format('Y') }}</span>
                    </div>
                </div>
            </aside>
        </div>

        @if($items->count())
            <section class="vendors-show-items-card">
                <div class="vendors-show-items-head">
                    <div>
                        <h3 class="vendors-show-items-title">In-Stock Items</h3>
                        <p class="vendors-show-items-copy">Items currently tagged to this vendor.</p>
                    </div>
                    <span class="vendors-show-count-badge">{{ number_format($items->count()) }} items</span>
                </div>

                <div class="vendors-show-table-wrap">
                    <table class="vendors-show-table">
                        <thead>
                            <tr>
                                <th class="text-left">Barcode</th>
                                <th class="text-left">Category</th>
                                <th class="text-right">Weight (g)</th>
                                <th class="text-left">HUID</th>
                                <th class="text-right">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                                <tr>
                                    <td class="vendors-show-text-mono">{{ $item->barcode }}</td>
                                    <td>{{ $item->category }}</td>
                                    <td class="text-right">{{ number_format($item->gross_weight, 3) }}</td>
                                    <td class="vendors-show-text-mono">{{ $item->huid ?? '—' }}</td>
                                    <td class="text-right">₹{{ number_format($item->selling_price, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="vendors-show-mobile-items">
                    @foreach($items as $item)
                        <article class="vendors-show-item-mobile">
                            <div class="vendors-show-item-top">
                                <div>
                                    <p class="vendors-show-item-title">{{ $item->barcode }}</p>
                                </div>
                                <span class="vendors-show-item-price">₹{{ number_format($item->selling_price, 2) }}</span>
                            </div>

                            <dl class="vendors-show-item-grid">
                                <div>
                                    <dt>Category</dt>
                                    <dd>{{ $item->category }}</dd>
                                </div>
                                <div>
                                    <dt>Weight</dt>
                                    <dd>{{ number_format($item->gross_weight, 3) }} g</dd>
                                </div>
                                <div>
                                    <dt>HUID</dt>
                                    <dd>{{ $item->huid ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt>Barcode</dt>
                                    <dd class="vendors-show-text-mono">{{ $item->barcode }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
