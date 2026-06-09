<x-app-layout>
    <x-page-header title="Reports" subtitle="Tax, receivables, reconciliation, and operational reports — all in one place" />

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
        /* ── Reports hub — calm teal/hairline system (matches the report pages) ── */
        .rh-page {
            --rh-border:        #e7ebf1;
            --rh-border-soft:   #eef1f6;
            --rh-border-strong: #d9dfe8;
            --rh-ink:           #0f172a;
            --rh-ink-2:         #3d4861;
            --rh-muted:         #6a7588;
            --rh-accent:        #0d9488;
            --rh-accent-deep:   #0f766e;
            --rh-accent-soft:   rgba(13,148,136,.09);
            --rh-shadow:        0 1px 2px rgba(16,24,40,.04), 0 14px 28px -18px rgba(16,24,40,.20);
            --rh-ease:          cubic-bezier(0.23,1,0.32,1);
            max-width: 1280px;
        }

        .rh-flow { display: flex; flex-direction: column; gap: 28px; }

        /* Keep the page-header title beside the menu button on mobile.
           The default .content-header wraps (flex-wrap: wrap), which drops a
           long-subtitle title below the hamburger; nowrap keeps it inline and
           lets the subtitle wrap within its own block. */
        @media (max-width: 767px) {
            .content-header { flex-wrap: nowrap; align-items: center; }
            .content-header > :nth-child(2) { min-width: 0; }
        }

        @media (prefers-reduced-motion: no-preference) {
            .rh-section { animation: rhRise .5s var(--rh-ease) both; }
            .rh-section:nth-child(2) { animation-delay: .05s; }
            .rh-section:nth-child(3) { animation-delay: .1s; }
            .rh-section:nth-child(4) { animation-delay: .15s; }
            @keyframes rhRise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        }

        .rh-section-head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .rh-section-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; flex-shrink: 0;
            border-radius: 9px; background: var(--rh-accent-soft); color: var(--rh-accent-deep);
        }
        .rh-section-icon svg { width: 16px; height: 16px; }
        .rh-section-title { margin: 0; color: var(--rh-ink); font-size: 14px; font-weight: 650; letter-spacing: -.01em; }
        .rh-section-count { margin-left: auto; color: var(--rh-muted); font-size: 12.5px; font-weight: 500; flex-shrink: 0; }

        .rh-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(272px, 1fr));
            gap: 14px;
        }

        .rh-card {
            display: flex; flex-direction: column; gap: 6px;
            padding: 16px 17px;
            border: 1px solid var(--rh-border); border-radius: 14px;
            background: #ffffff; box-shadow: 0 1px 2px rgba(16,24,40,.04);
            text-decoration: none;
            transition: border-color .16s var(--rh-ease), box-shadow .16s var(--rh-ease), transform .16s var(--rh-ease);
        }
        .rh-card:hover { border-color: #bfe6e0; box-shadow: var(--rh-shadow); }
        .rh-card:active { transform: scale(.99); }

        .rh-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .rh-card-title { margin: 0; color: var(--rh-ink); font-size: 14px; font-weight: 650; line-height: 1.3; letter-spacing: -.01em; }
        .rh-card-arrow { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; color: #cbd5e1; transition: color .16s var(--rh-ease), transform .16s var(--rh-ease); }
        .rh-card-desc { margin: 0; color: var(--rh-muted); font-size: 12px; line-height: 1.55; }

        @media (hover: hover) and (pointer: fine) {
            .rh-card:hover { transform: translateY(-1px); }
            .rh-card:hover .rh-card-arrow { color: var(--rh-accent-deep); transform: translateX(2px); }
        }

        @media (max-width: 480px) {
            .rh-flow { gap: 22px; }
            .rh-grid { grid-template-columns: 1fr; }
        }
    </style>
</x-app-layout>
