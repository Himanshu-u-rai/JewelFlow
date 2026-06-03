<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Tax, receivables, reconciliation, and operational reports — all in one place</p>
        </div>
    </x-page-header>

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
                ['Payment Reconciliation', 'Invoice totals vs collected payments; flags mismatches', 'report.payment-reconciliation', null],
                ['Day Book', 'Chronological journal of invoices, credit notes, cash — for the CA', 'report.day-book', null],
                ['Inventory Valuation', 'On-hand stock value at cost and at tag price', 'report.inventory-valuation', null],
                ['Cash Flow', 'Cash in vs out over the period', 'report.cash', null],
                ['Daily Closing', 'End-of-day close figures', 'report.closing', null],
            ],
            'Operational' => [
                ['Dead Stock', 'Stock not turning over, aged and valued at cost', 'report.dead-stock', null],
                ['Karigar Settlement', 'Gold out vs in (open jobs) and money owed to karigars', 'report.karigar-settlement', null],
                ['Metal Loss / Shrinkage', 'Gold that went out for making vs what came back — wastage and unaccounted grams', 'report.shrinkage', null],
                ['Purchase Efficiency', 'Rate paid on stock purchases vs your market rate', 'report.purchase-efficiency', null],
                ['Operator Performance', 'Sales, discounts and returns by who handled them', 'report.operator-performance', null],
                ['Suspicious Activity', 'Compliance alerts to review — split bills, missing PAN, threshold breaches', 'report.suspicious-activity', null],
                ['Profit & Loss', 'Gross margin over the period', 'report.pnl', null],
                ['Gold Balances', 'Vault fine-weight by metal and purity', 'report.gold', null],
                ['Metal Exchange', 'Old-gold exchange activity', 'report.metal-exchange', 'retailer'],
                ['Repairs', 'Repair jobs and revenue', 'report.repairs', null],
            ],
        ];
    @endphp

    <div class="content-inner space-y-8">
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
            <div>
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">{{ $heading }}</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($visible as $c)
                        <a href="{{ route($c[2]) }}" class="block bg-white rounded-xl border border-gray-200 p-4 hover:border-amber-300 hover:shadow-sm transition">
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="font-semibold text-gray-900">{{ $c[0] }}</h3>
                                <svg class="w-4 h-4 text-gray-300 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </div>
                            <p class="text-xs text-gray-500 mt-1 leading-relaxed">{{ $c[1] }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
            @endif
        @endforeach
    </div>
</x-app-layout>
