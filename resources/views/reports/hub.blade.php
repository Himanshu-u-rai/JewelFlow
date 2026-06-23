<x-app-layout>
    <x-page-header class="reports-hub-header" title="Reports" subtitle="Tax, receivables, reconciliation, and operational reports — all in one place" />

    @php
        // [title, description, route-name, edition] — edition: null (any) | 'retailer' | 'manufacturer'
        $sections = [
            'Tax & Compliance (for your CA)' => [
                ['GSTR-1', 'Outward sales — B2B/B2CS, rate-wise, HSN, with credit notes', 'report.gstr1', null],
                ['GSTR-3B Support', 'Output tax, inter/intra-state split, net liability', 'report.gstr3b', null],
                ['Credit / Debit Note Register', 'All credit notes for the period with original-invoice reference', 'report.cn-register', null],
                ['GST Summary', 'GST collected vs reversed, net of credit notes', 'report.gst', null],
            ],
            'Receivables & Liability' => [
                ['Customer Dues', 'Outstanding on finalized invoices, aged 0–30 / 31–60 / 61–90 / 90+', 'report.dues-aging', null],
                ['Pending EMI', 'Active installment plans, overdue and upcoming dues', 'report.emi', 'retailer'],
                ['Scheme Liability', 'Gold-savings balances you owe customers (with accrued bonus)', 'report.scheme-liability', 'retailer'],
                ['Metal Liability', 'Customer-advance gold owed vs gold on hand', 'report.metal-liability', null],
            ],
            'Ledger & Reconciliation' => [
                ['Sales Register', 'Complete list of all sales for the period with line-item detail', 'report.sales-register', null],
                ['Daily Summary', 'Day-level summary of sales, returns, and cash movements', 'report.daily', null],
                ['Payment Reconciliation', 'Invoice totals vs collected payments; flags mismatches', 'report.payment-reconciliation', null],
                ['Day Book', 'Chronological journal of invoices, credit notes, cash — for the CA', 'report.day-book', null],
                ['Inventory Valuation', 'On-hand stock value at cost and at tag price', 'report.inventory-valuation', null],
                ['Cash Flow', 'Cash in vs out over the period', 'report.cash', null],
                ['Daily Closing', 'End-of-day close figures', 'report.closing', null],
            ],
            'Operational' => [
                ['Dead Stock', 'Stock not turning over, aged and valued at cost', 'report.dead-stock', null],
                ['Stock Aging', 'How long each item has been in stock, grouped by age band', 'report.stock-aging', 'retailer'],
                ['Karigar Settlement', 'Gold out vs in (open jobs) and money owed to karigars', 'report.karigar-settlement', null],
                ['Metal Loss / Shrinkage', 'Gold that went out for making vs what came back — wastage and unaccounted grams', 'report.shrinkage', null],
                ['Purchase Efficiency', 'Rate paid on stock purchases vs your market rate', 'report.purchase-efficiency', null],
                ['Operator Performance', 'Sales, discounts and returns by who handled them', 'report.operator-performance', null],
                ['Sellers', 'Sales count and value by seller or staff member', 'report.sellers', 'retailer'],
                ['Occasions', 'Sales broken down by occasion or event category', 'report.occasions', 'retailer'],
                ['Suspicious Activity', 'Compliance alerts to review — split bills, missing PAN, threshold breaches', 'report.suspicious-activity', null],
                ['Profit & Loss', 'Gross margin over the period', 'report.pnl', null],
                ['Gold Balances', 'Vault fine-weight by metal and purity', 'report.gold', null],
                ['Reference Prices', 'Current and historical gold and stone reference prices on record', 'report.reference-prices', null],
                ['Metal Exchange', 'Old-gold exchange activity', 'report.metal-exchange', 'retailer'],
                ['Repairs', 'Repair jobs and revenue', 'report.repairs', null],
            ],
        ];

        // One category icon per section (path-only SVGs, safe in the body loop).
        $sectionIcons = [
            'Tax & Compliance (for your CA)' => '<path d="M9 7h6M9 11h6M9 15h4M6 3h12a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/>',
            'Receivables & Liability'        => '<path d="M3 6.5A2.5 2.5 0 0 1 5.5 4H18v3"/><path d="M3 6.5V18a2 2 0 0 0 2 2h15v-5"/><path d="M17 12.5a2 2 0 0 0 0 4h4v-4z"/>',
            'Ledger & Reconciliation'        => '<path d="M6.5 3H19a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6.5A2.5 2.5 0 0 1 4 18.5v-13A2.5 2.5 0 0 1 6.5 3z"/><path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20"/>',
            'Operational'                    => '<path d="M22 12h-4l-3 8L9 4l-3 8H2"/>',
        ];
    @endphp

    <div class="content-inner rh-page">
        <div class="rh-flow">
            @foreach($sections as $heading => $cards)
                @php
                    $visible = collect($cards)->filter(fn ($c) =>
                        \Illuminate\Support\Facades\Route::has($c[2])
                        && ($c[3] === null
                            || ($c[3] === 'retailer' && $isRetailer)
                            || ($c[3] === 'manufacturer' && $isManufacturer))
                    );
                @endphp
                @if($visible->isNotEmpty())
                <section class="rh-section">
                    <div class="rh-section-head">
                        <span class="rh-section-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $sectionIcons[$heading] ?? '<circle cx="12" cy="12" r="9"/>' !!}</svg>
                        </span>
                        <h2 class="rh-section-title">{{ $heading }}</h2>
                        <span class="rh-section-count">{{ $visible->count() }} {{ Str::plural('report', $visible->count()) }}</span>
                    </div>
                    <div class="rh-grid">
                        @foreach($visible as $c)
                            <a href="{{ route($c[2]) }}" class="rh-card">
                                <div class="rh-card-top">
                                    <h3 class="rh-card-title">{{ $c[0] }}</h3>
                                    <svg class="rh-card-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
                                </div>
                                <p class="rh-card-desc">{{ $c[1] }}</p>
                            </a>
                        @endforeach
                    </div>
                </section>
                @endif
            @endforeach
        </div>
    </div>

    <style>
        /* Reports hub: flat JewelFlow ERP directory, scoped to this page. */
        .rh-page {
            --rh-border: #cbd5e1;
            --rh-border-soft: #e2e8f0;
            --rh-surface: #ffffff;
            --rh-muted-surface: #f8fafc;
            --rh-ink: #0f172a;
            --rh-muted: #475569;
            --rh-soft: #64748b;
            --rh-gold: #b45309;
            --rh-gold-soft: #fff7ed;
            max-width: none;
        }

        .rh-flow {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .reports-hub-header .page-title {
            font-weight: 650;
            letter-spacing: 0;
        }

        .reports-hub-header .page-subtitle {
            color: #475569;
        }

        .rh-section {
            border: 1px solid var(--rh-border-soft);
            border-radius: 12px;
            background: var(--rh-surface);
            overflow: hidden;
        }

        .rh-section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--rh-border-soft);
            background: var(--rh-surface);
        }

        .rh-section-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            flex-shrink: 0;
            border-radius: 8px;
            background: var(--rh-gold-soft);
            color: var(--rh-gold);
            border: 1px solid #fed7aa;
        }

        .rh-section-icon svg { width: 16px; height: 16px; }

        .rh-section-title {
            margin: 0;
            color: var(--rh-ink);
            font-size: 14px;
            font-weight: 650;
            letter-spacing: 0;
        }

        .rh-section-count {
            margin-left: auto;
            color: var(--rh-soft);
            font-size: 12px;
            font-weight: 500;
            flex-shrink: 0;
        }

        .rh-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(262px, 1fr));
            gap: 10px;
            padding: 12px;
            background: #f8fafc;
        }

        .rh-card {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-height: 94px;
            padding: 14px 16px;
            border: 1px solid var(--rh-border-soft);
            border-radius: 10px;
            background: #ffffff;
            box-shadow: none;
            text-decoration: none;
            transition: background-color .14s ease, color .14s ease;
        }

        .rh-card:hover {
            background: var(--rh-muted-surface);
        }

        .rh-card:focus-visible {
            outline: 2px solid #111827;
            outline-offset: -2px;
        }

        .rh-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .rh-card-title {
            margin: 0;
            color: var(--rh-ink);
            font-size: 13.5px;
            font-weight: 650;
            line-height: 1.3;
            letter-spacing: 0;
        }

        .rh-card-arrow {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            margin-top: 1px;
            color: #94a3b8;
            transition: color .14s ease;
        }

        .rh-card:hover .rh-card-arrow {
            color: var(--rh-gold);
        }

        .rh-card-desc {
            margin: 0;
            color: var(--rh-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        @media (min-width: 1280px) {
            .rh-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .reports-hub-header {
                flex-wrap: nowrap;
                align-items: center;
            }

            .reports-hub-header > :nth-child(2) {
                min-width: 0;
            }

            .reports-hub-header .page-subtitle {
                display: none;
            }

            .rh-page {
                padding-top: 12px;
            }

            .rh-flow {
                gap: 14px;
            }

            .rh-section {
                border-radius: 10px;
            }

            .rh-section-head {
                padding: 11px 12px;
                gap: 8px;
            }

            .rh-section-icon {
                width: 28px;
                height: 28px;
            }

            .rh-section-title {
                font-size: 13px;
                line-height: 1.2;
            }

            .rh-section-count {
                font-size: 11px;
            }

            .rh-grid { grid-template-columns: 1fr; }

            .rh-card {
                min-height: 72px;
                padding: 11px 12px;
                gap: 3px;
                border-radius: 8px;
            }

            .rh-card-title {
                font-size: 13px;
            }

            .rh-card-desc {
                display: -webkit-box;
                overflow: hidden;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                font-size: 11.5px;
                line-height: 1.4;
            }
        }
    </style>
</x-app-layout>
