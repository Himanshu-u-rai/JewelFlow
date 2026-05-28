<x-app-layout>
    <style>
        .pending-stock-page {
            color: #111827;
            font-size: 14px;
            font-weight: 500;
        }

        .pending-stock-page :where(.font-black, .font-extrabold, .font-bold) {
            font-weight: 650 !important;
        }

        .pending-stock-header :where(h1, .page-title) {
            font-weight: 650;
            letter-spacing: 0;
        }

        .pending-stock-header-action,
        .pending-stock-action,
        .pending-stock-danger-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            box-shadow: none !important;
            transition: background-color 140ms ease, border-color 140ms ease, color 140ms ease;
            white-space: nowrap;
        }

        .pending-stock-header-action,
        .pending-stock-action-secondary {
            background: #ffffff;
            color: #111827;
        }

        .pending-stock-header-action:hover,
        .pending-stock-action-secondary:hover {
            background: #f3f5f8;
            color: #111827;
        }

        .pending-stock-action-primary {
            border-color: #111827;
            background: #111827;
            color: #ffffff;
        }

        .pending-stock-action-primary:hover {
            background: #1f2937;
            color: #ffffff;
        }

        .pending-stock-danger-action {
            border-color: #fecdd3;
            background: #fff1f2;
            color: #be123c;
        }

        .pending-stock-danger-action:hover {
            background: #ffe4e6;
            color: #9f1239;
        }

        body.app-shell .content-header .page-actions .pending-stock-header-action {
            border-color: #cbd5e1 !important;
            background: #ffffff !important;
            color: #111827 !important;
        }

        body.app-shell .content-header .page-actions .pending-stock-header-action:hover {
            background: #f3f5f8 !important;
            color: #111827 !important;
        }

        .pending-stock-kpis {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            overflow: hidden;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none !important;
            margin-bottom: 14px;
        }

        .pending-stock-kpi {
            min-width: 0;
            border-left: 1px solid #e2e8f0;
            padding: 13px 16px;
        }

        .pending-stock-kpi:first-child {
            border-left: 0;
        }

        .pending-stock-label,
        .pending-stock-kpi-label,
        .pending-stock-mobile-label {
            display: block;
            color: #475569;
            font-size: 11px;
            font-weight: 650;
            letter-spacing: 0.04em;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .pending-stock-kpi-value {
            display: flex;
            align-items: baseline;
            gap: 4px;
            margin-top: 6px;
            color: #111827;
            font-size: 21px;
            font-weight: 650;
            line-height: 1;
            letter-spacing: 0;
        }

        .pending-stock-kpi-value span {
            color: #64748b;
            font-size: 12px;
            font-weight: 500;
        }

        .pending-stock-kpi-meta {
            margin-top: 5px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
        }

        .pending-stock-value-warn {
            color: #9a3412;
        }

        .pending-stock-value-ok {
            color: #047857;
        }

        .pending-stock-panel,
        .pending-stock-empty,
        .pending-stock-mobile-card {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none !important;
        }

        .pending-stock-panel {
            overflow: hidden;
        }

        .pending-stock-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 1px solid #e2e8f0;
            padding: 13px 16px;
            position: relative;
        }

        .pending-stock-panel-title {
            color: #111827;
            font-size: 14px;
            font-weight: 650;
            line-height: 1.25;
            margin: 0;
        }

        .pending-stock-panel-copy {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
        }

        .pending-stock-help {
            position: relative;
            flex: none;
        }

        .pending-stock-help-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 34px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #111827;
            padding: 7px 10px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            box-shadow: none !important;
        }

        .pending-stock-help-button:hover,
        .pending-stock-help-button[aria-expanded="true"] {
            background: #f3f5f8;
            color: #111827;
        }

        .pending-stock-help-icon {
            display: inline-grid;
            place-items: center;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            color: #475569;
            font-size: 11px;
            font-weight: 650;
            line-height: 1;
        }

        .pending-stock-help-popover {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            z-index: 30;
            width: 320px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            color: #475569;
            padding: 12px 13px;
            font-size: 13px;
            font-weight: 500;
            line-height: 1.45;
            box-shadow: none !important;
        }

        .pending-stock-help-title {
            color: #111827;
            font-size: 13px;
            font-weight: 650;
            line-height: 1.25;
            margin-bottom: 5px;
        }

        .pending-stock-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .pending-stock-table {
            width: 100%;
            border-collapse: collapse;
            color: #111827;
            font-size: 14px;
        }

        .pending-stock-table thead tr {
            background: #f3f5f8;
            color: #475569;
            font-size: 11px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .pending-stock-table th {
            padding: 11px 16px;
            font-weight: 650;
            white-space: nowrap;
        }

        .pending-stock-table td {
            border-top: 1px solid #e2e8f0;
            padding: 13px 16px;
            font-weight: 500;
            vertical-align: middle;
        }

        .pending-stock-strong {
            color: #111827;
            font-weight: 650;
        }

        .pending-stock-muted {
            color: #64748b;
            font-size: 12px;
            line-height: 1.35;
            margin-top: 3px;
        }

        .pending-stock-job-link {
            color: #0f766e;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .pending-stock-job-link:hover {
            color: #115e59;
            text-decoration: underline;
        }

        .pending-stock-row-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .pending-stock-mobile-list {
            display: none;
        }

        .pending-stock-mobile-card {
            overflow: hidden;
        }

        .pending-stock-mobile-card + .pending-stock-mobile-card {
            margin-top: 10px;
        }

        .pending-stock-mobile-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #e2e8f0;
            padding: 13px 14px;
        }

        .pending-stock-mobile-card-body {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            padding: 12px 14px;
        }

        .pending-stock-mobile-stat {
            min-width: 0;
            border: 1px solid #d7e0ea;
            border-radius: 8px;
            background: #f8fafc;
            padding: 9px 10px;
        }

        .pending-stock-mobile-value {
            margin-top: 6px;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }

        .pending-stock-mobile-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            border-top: 1px solid #e2e8f0;
            padding: 12px 14px;
        }

        .pending-stock-mobile-actions .pending-stock-action,
        .pending-stock-mobile-actions .pending-stock-danger-action,
        .pending-stock-mobile-actions form {
            width: 100%;
        }

        .pending-stock-mobile-actions button,
        .pending-stock-mobile-actions a {
            width: 100%;
        }

        .pending-stock-empty {
            padding: 34px 18px;
            text-align: center;
        }

        .pending-stock-empty-icon {
            display: inline-grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #475569;
            margin-bottom: 12px;
        }

        .pending-stock-empty-title {
            color: #111827;
            font-size: 16px;
            font-weight: 650;
            line-height: 1.25;
            margin: 0;
        }

        .pending-stock-empty-copy {
            color: #64748b;
            font-size: 13px;
            line-height: 1.45;
            margin: 5px auto 0;
            max-width: 420px;
        }

        .pending-stock-pagination {
            margin-top: 14px;
        }

        @media (max-width: 1024px) {
            .pending-stock-kpis {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .pending-stock-kpi:nth-child(3) {
                border-left: 0;
            }

            .pending-stock-kpi:nth-child(n+3) {
                border-top: 1px solid #e2e8f0;
            }

        }

        @media (max-width: 767px) {
            .pending-stock-header {
                display: grid;
                grid-template-columns: 38px minmax(0, 1fr) 38px;
                align-items: center;
                column-gap: 8px;
                min-height: 64px;
                padding: 14px 12px;
            }

            .pending-stock-header .content-header-nav {
                grid-column: 1;
                grid-row: 1;
                margin-right: 0;
                padding-top: 0;
            }

            .pending-stock-header > :nth-child(2) {
                grid-column: 2;
                grid-row: 1;
                min-width: 0;
                text-align: center;
            }

            .pending-stock-header .page-title {
                margin: 0;
                overflow: hidden;
                text-align: center;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .pending-stock-header .page-subtitle {
                display: none;
            }

            .pending-stock-header .page-actions {
                grid-column: 3;
                grid-row: 1;
                justify-self: end;
                width: auto;
            }

            .pending-stock-header-action {
                width: 34px;
                height: 34px;
                min-height: 34px;
                padding: 0;
            }

            body.app-shell .content-header .page-actions .pending-stock-header-action {
                width: 34px !important;
                height: 34px !important;
                min-height: 34px !important;
                padding: 0 !important;
            }

            .pending-stock-back-label {
                display: none;
            }

            .pending-stock-page {
                padding: 16px 10px 28px;
            }

            .pending-stock-kpis {
                margin-bottom: 12px;
            }

            .pending-stock-kpi {
                min-height: 78px;
                padding: 11px 12px;
            }

            .pending-stock-kpi-label,
            .pending-stock-mobile-label {
                font-size: 10px;
            }

            .pending-stock-kpi-value {
                font-size: 18px;
            }

            .pending-stock-kpi-meta {
                font-size: 11px;
            }

            .pending-stock-panel {
                margin-bottom: 12px;
            }

            .pending-stock-panel-head {
                align-items: flex-start;
                flex-wrap: wrap;
                padding: 13px 14px;
            }

            .pending-stock-table-wrap {
                display: none;
            }

            .pending-stock-help {
                width: 100%;
            }

            .pending-stock-help-button {
                justify-content: space-between;
                width: 100%;
            }

            .pending-stock-help-popover {
                position: static;
                width: 100%;
                margin-top: 8px;
                font-size: 12px;
            }

            .pending-stock-mobile-list {
                display: block;
            }

            .pending-stock-mobile-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $visibleItems = $items->getCollection();
        $pendingCount = $items->total();
        $visibleGross = $visibleItems->sum(fn ($item) => (float) $item->gross_weight);
        $karigarCount = $visibleItems
            ->map(fn ($item) => $item->jobOrder?->karigar?->id)
            ->filter()
            ->unique()
            ->count();
        $jobOrderCount = $visibleItems
            ->map(fn ($item) => $item->job_order_id)
            ->filter()
            ->unique()
            ->count();
    @endphp

    <x-page-header
        class="pending-stock-header"
        title="Pending Stock"
        subtitle="Review job-work receipts before listing them for sale"
    >
        <x-slot:actions>
            <a href="{{ route('inventory.items.index') }}" class="pending-stock-header-action" aria-label="Back to inventory">
                <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m15 18-6-6 6-6"/>
                </svg>
                <span class="pending-stock-back-label">Inventory</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner pending-stock-page">
        <section class="pending-stock-kpis" aria-label="Pending stock summary">
            <div class="pending-stock-kpi">
                <span class="pending-stock-kpi-label">Pending items</span>
                <div class="pending-stock-kpi-value pending-stock-value-warn">{{ number_format($pendingCount) }}</div>
                <div class="pending-stock-kpi-meta">Hidden from sale</div>
            </div>
            <div class="pending-stock-kpi">
                <span class="pending-stock-kpi-label">Visible gross</span>
                <div class="pending-stock-kpi-value">{{ number_format($visibleGross, 3) }}<span>g</span></div>
                <div class="pending-stock-kpi-meta">Current page total</div>
            </div>
            <div class="pending-stock-kpi">
                <span class="pending-stock-kpi-label">Karigars</span>
                <div class="pending-stock-kpi-value pending-stock-value-ok">{{ number_format($karigarCount) }}</div>
                <div class="pending-stock-kpi-meta">On this page</div>
            </div>
            <div class="pending-stock-kpi">
                <span class="pending-stock-kpi-label">Job orders</span>
                <div class="pending-stock-kpi-value">{{ number_format($jobOrderCount) }}</div>
                <div class="pending-stock-kpi-meta">Linked receipts</div>
            </div>
        </section>

        @if($items->isEmpty())
            <section class="pending-stock-empty">
                <div class="pending-stock-empty-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                </div>
                <h3 class="pending-stock-empty-title">No pending stock</h3>
                <p class="pending-stock-empty-copy">New karigar receipts will appear here for review before they move into sale-ready inventory.</p>
            </section>
        @else
            <section class="pending-stock-panel"
                     x-data="{ helpOpen: false }"
                     @keydown.escape.window="helpOpen = false"
                     @click.outside="helpOpen = false">
                <div class="pending-stock-panel-head">
                    <div>
                        <h3 class="pending-stock-panel-title">Review queue</h3>
                        <div class="pending-stock-panel-copy">{{ number_format($pendingCount) }} item{{ $pendingCount === 1 ? '' : 's' }} waiting for release or reversal.</div>
                    </div>
                    <div class="pending-stock-help">
                        <button type="button"
                                class="pending-stock-help-button"
                                aria-controls="pending-stock-help-popover"
                                :aria-expanded="helpOpen.toString()"
                                @click="helpOpen = !helpOpen">
                            <span class="pending-stock-help-icon" aria-hidden="true">i</span>
                            <span>How this works</span>
                        </button>
                        <div id="pending-stock-help-popover"
                             class="pending-stock-help-popover"
                             x-cloak
                             x-show="helpOpen"
                             x-transition>
                            <div class="pending-stock-help-title">Review before sale</div>
                            Items from job-work receipts stay hidden until reviewed. Release opens the item form for category and pricing. Reverse returns metal if the receipt was entered by mistake.
                        </div>
                    </div>
                </div>
                <div class="pending-stock-table-wrap">
                    <table class="pending-stock-table">
                        <thead>
                            <tr>
                                <th class="text-left">Barcode</th>
                                <th class="text-left">Description</th>
                                <th class="text-left">Karigar / Job order</th>
                                <th class="text-right">Gross wt</th>
                                <th class="text-right">Purity</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $item)
                                <tr>
                                    <td>
                                        <div class="pending-stock-strong">{{ $item->barcode }}</div>
                                        <div class="pending-stock-muted">{{ ucfirst($item->metal_type ?? 'Metal') }}</div>
                                    </td>
                                    <td>
                                        <div class="pending-stock-strong">{{ $item->design ?: 'Unlabeled item' }}</div>
                                        <div class="pending-stock-muted">Needs category and pricing review</div>
                                    </td>
                                    <td>
                                        <div class="pending-stock-strong">{{ $item->jobOrder?->karigar?->name ?? 'Not assigned' }}</div>
                                        @if($item->job_order_id)
                                            <a class="pending-stock-job-link" href="{{ route('job-orders.show', $item->job_order_id) }}">
                                                Job order #{{ $item->job_order_id }}
                                            </a>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <span class="pending-stock-strong">{{ number_format((float) $item->gross_weight, 3) }}g</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="pending-stock-strong">{{ $item->purity }}{{ $item->metal_type === 'silver' ? '/1000' : 'K' }}</span>
                                    </td>
                                    <td>
                                        <div class="pending-stock-row-actions">
                                            <a href="{{ route('inventory.items.edit', $item) }}" class="pending-stock-action pending-stock-action-primary">Release</a>
                                            <form action="{{ route('inventory.items.destroy', $item) }}"
                                                  method="POST"
                                                  onsubmit="return confirm('Reverse {{ $item->barcode }}? The fine metal will be credited back to the source lot.');">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="reason" value="Reversed from Pending Stock review.">
                                                <button type="submit" class="pending-stock-danger-action">Reverse</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="pending-stock-mobile-list" aria-label="Pending stock mobile review queue">
                @foreach($items as $item)
                    <article class="pending-stock-mobile-card">
                        <div class="pending-stock-mobile-card-head">
                            <div>
                                <div class="pending-stock-strong">{{ $item->barcode }}</div>
                                <div class="pending-stock-muted">{{ ucfirst($item->metal_type ?? 'Metal') }}</div>
                            </div>
                            <div class="text-right">
                                <div class="pending-stock-strong">{{ $item->purity }}{{ $item->metal_type === 'silver' ? '/1000' : 'K' }}</div>
                                <div class="pending-stock-muted">Purity</div>
                            </div>
                        </div>
                        <div class="pending-stock-mobile-card-body">
                            <div class="pending-stock-mobile-stat">
                                <span class="pending-stock-mobile-label">Description</span>
                                <div class="pending-stock-mobile-value">{{ $item->design ?: 'Unlabeled item' }}</div>
                            </div>
                            <div class="pending-stock-mobile-stat">
                                <span class="pending-stock-mobile-label">Gross wt</span>
                                <div class="pending-stock-mobile-value">{{ number_format((float) $item->gross_weight, 3) }}g</div>
                            </div>
                            <div class="pending-stock-mobile-stat">
                                <span class="pending-stock-mobile-label">Karigar</span>
                                <div class="pending-stock-mobile-value">{{ $item->jobOrder?->karigar?->name ?? 'Not assigned' }}</div>
                            </div>
                            <div class="pending-stock-mobile-stat">
                                <span class="pending-stock-mobile-label">Job order</span>
                                <div class="pending-stock-mobile-value">
                                    @if($item->job_order_id)
                                        <a class="pending-stock-job-link" href="{{ route('job-orders.show', $item->job_order_id) }}">#{{ $item->job_order_id }}</a>
                                    @else
                                        -
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="pending-stock-mobile-actions">
                            <a href="{{ route('inventory.items.edit', $item) }}" class="pending-stock-action pending-stock-action-primary">Release</a>
                            <form action="{{ route('inventory.items.destroy', $item) }}"
                                  method="POST"
                                  onsubmit="return confirm('Reverse {{ $item->barcode }}? The fine metal will be credited back to the source lot.');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="reason" value="Reversed from Pending Stock review.">
                                <button type="submit" class="pending-stock-danger-action">Reverse</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>

            @if($items->hasPages())
                <div class="pending-stock-pagination">{{ $items->links() }}</div>
            @endif
        @endif
    </div>
</x-app-layout>
