<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closure Certificate - {{ $loan->loan_number }}</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #dbe2ec;
            --line-soft: #e7edf5;
            --surface: #ffffff;
            --bg: #f1f5fb;
            --brand: #0f3a78;
            --brand-soft: #e7f0ff;
            --accent: #0f766e;
            --accent-soft: #ecfdf5;
        }

        body {
            font-family: "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 26px 16px;
            color: var(--ink);
        }

        .receipt-page {
            max-width: 760px;
            margin: 0 auto;
        }

        .receipt {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.07);
        }

        .receipt-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            padding: 18px 20px 16px;
            border-bottom: 1px solid var(--line-soft);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 10px;
            border: 1px solid #cfe0ff;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-mark svg { width: 14px; height: 14px; }

        .shop-name {
            margin: 10px 0 0;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .shop-meta {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--muted);
        }

        .receipt-title {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -0.02em;
            text-align: right;
        }

        .receipt-sub {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
            text-align: right;
        }

        .receipt-pill {
            margin-top: 9px;
            display: inline-flex;
            float: right;
            align-items: center;
            gap: 6px;
            border: 1px solid #bbf7d0;
            background: #ecfdf5;
            color: #065f46;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .receipt-body { padding: 18px 20px 20px; }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        .detail-card {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            padding: 12px 13px;
            background: #fff;
        }

        .detail-label {
            margin: 0 0 8px;
            font-size: 11px;
            font-weight: 700;
            color: #325387;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .detail-name {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .detail-text {
            margin: 4px 0 0;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.45;
        }

        .meta-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin: 0 0 14px;
        }

        .meta-box {
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            background: #fff;
            padding: 9px 10px 10px;
            min-width: 0;
        }

        .meta-box-label {
            margin: 0;
            font-size: 10px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .meta-box-value {
            margin: 4px 0 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cert-statement {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            background: var(--accent-soft);
            padding: 16px;
            margin-bottom: 14px;
            font-size: 14px;
            line-height: 1.65;
            color: #065f46;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            overflow: hidden;
        }

        .items-table th {
            background: #f8fafc;
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--line-soft);
        }

        .items-table td {
            padding: 9px 12px;
            font-size: 13px;
            border-bottom: 1px solid var(--line-soft);
        }

        .items-table tr:last-child td { border-bottom: none; }

        .items-table .text-right { text-align: right; }
        .items-table .text-center { text-align: center; }

        .ledger {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            margin-bottom: 14px;
        }

        .ledger-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 10px 14px;
            border-top: 1px solid var(--line-soft);
            font-size: 13px;
        }

        .ledger-row:first-child { border-top: none; }

        .ledger-row strong {
            font-weight: 700;
            color: var(--ink);
        }

        .ledger-total {
            background: linear-gradient(180deg, #ecfdf5 0%, #d1fae5 100%);
        }

        .ledger-total span {
            font-size: 12px;
            font-weight: 700;
            color: #065f46;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .ledger-total strong {
            font-size: 23px;
            color: var(--accent);
            letter-spacing: -0.02em;
        }

        .cert-footer-text {
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            background: #fff;
            padding: 12px 14px;
            font-size: 12px;
            line-height: 1.5;
            color: #334155;
            margin-bottom: 14px;
        }

        .signature-row {
            display: flex;
            justify-content: space-between;
            gap: 40px;
            margin-top: 40px;
            padding-top: 14px;
        }

        .signature-block {
            text-align: center;
            min-width: 160px;
        }

        .signature-line {
            border-top: 1px solid var(--ink);
            padding-top: 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
        }

        .actions {
            max-width: 760px;
            margin: 12px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn {
            border: 1px solid #c8d1dd;
            background: #fff;
            color: var(--ink);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
            transition: background 140ms ease, border-color 140ms ease;
        }

        .btn:hover {
            background: #f8fafc;
            border-color: #9aa8bb;
        }

        @media (max-width: 760px) {
            body { padding: 12px; }

            .receipt-head {
                padding: 14px;
                flex-direction: column;
                gap: 12px;
            }

            .receipt-title, .receipt-sub { text-align: left; }
            .receipt-pill { float: none; }
            .receipt-body { padding: 14px; }
            .detail-grid { grid-template-columns: 1fr; gap: 10px; }
            .meta-strip { grid-template-columns: 1fr 1fr; }
            .ledger-total strong { font-size: 21px; }
        }

        @media print {
            body { background: #fff; padding: 0 !important; }
            .receipt-page { max-width: none; }
            .receipt { border: none; border-radius: 0; padding: 0; box-shadow: none; }
            .receipt-head, .detail-card, .meta-box, .ledger, .items-table, .cert-statement { break-inside: avoid; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: config('app.name', 'JewelFlow');
        $shopAddress = $shop?->address ?? null;
        $shopContact = $shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile ?? null;
        $shopEmail = auth()->user()?->email;
        $shopGstin = $shop?->gst_number ?? null;
        $customer = $loan->customer;
        $settings = \App\Models\Dhiran\DhiranSettings::getForShop($shop->id);
        $closedDate = $loan->closed_at ? $loan->closed_at->format('d M Y') : now()->format('d M Y');
        $loanDate = $loan->loan_date ? $loan->loan_date->format('d M Y') : '-';
        $totalCollected = (float) $loan->total_principal_collected
            + (float) $loan->total_interest_collected
            + (float) $loan->total_penalty_collected
            + (float) $loan->processing_fee;
    @endphp

    <div class="receipt-page">
        <div class="receipt">
            <header class="receipt-head">
                <div>
                    <div class="brand-mark">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2 4 9l8 13 8-13-8-7Zm0 3.01L16.2 8H7.8L12 5.01ZM8.92 10h6.16L12 15.04 8.92 10Z"/>
                        </svg>
                        CLOSURE CERTIFICATE
                    </div>
                    <p class="shop-name">{{ $shopName }}</p>
                    @if($shopAddress)
                        <p class="shop-meta">{{ $shopAddress }}</p>
                    @endif
                    <p class="shop-meta">
                        @if($shopContact) {{ $shopContact }} @endif
                        @if($shopContact && $shopEmail) &middot; @endif
                        @if($shopEmail) {{ $shopEmail }} @endif
                    </p>
                    @if($shopGstin)
                        <p class="shop-meta">GSTIN: {{ $shopGstin }}</p>
                    @endif
                </div>
                <div>
                    <h1 class="receipt-title">Closure Certificate</h1>
                    <div class="receipt-sub">Generated on {{ now()->format('d M Y') }}</div>
                    <span class="receipt-pill">{{ $loan->loan_number }}</span>
                </div>
            </header>

            <div class="receipt-body">
                <section class="detail-grid">
                    <article class="detail-card">
                        <p class="detail-label">Lender</p>
                        <p class="detail-name">{{ $shopName }}</p>
                        @if($shopAddress)
                            <p class="detail-text">{{ $shopAddress }}</p>
                        @endif
                    </article>
                    <article class="detail-card">
                        <p class="detail-label">Borrower</p>
                        <p class="detail-name">{{ $customer?->name ?? 'Walk-in Customer' }}</p>
                        <p class="detail-text">
                            {{ $customer?->mobile ?? 'No mobile available' }}
                            @if($customer?->address)
                                <br>{{ $customer->address }}
                            @endif
                        </p>
                    </article>
                </section>

                <section class="meta-strip">
                    <div class="meta-box">
                        <p class="meta-box-label">Loan No.</p>
                        <p class="meta-box-value">{{ $loan->loan_number }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Loan Date</p>
                        <p class="meta-box-value">{{ $loanDate }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Principal</p>
                        <p class="meta-box-value">{{ number_format((float) $loan->principal_amount, 2) }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Closed On</p>
                        <p class="meta-box-value">{{ $closedDate }}</p>
                    </div>
                </section>

                <section class="cert-statement">
                    This is to certify that Gold Loan <strong>{{ $loan->loan_number }}</strong>
                    dated <strong>{{ $loanDate }}</strong>, sanctioned in favour of
                    <strong>{{ $customer?->name ?? 'Walk-in Customer' }}</strong>,
                    for an amount of <strong>{{ number_format((float) $loan->principal_amount, 2) }}</strong>,
                    has been fully settled and closed on <strong>{{ $closedDate }}</strong>.
                    All pledged items have been returned to the borrower and no further obligations remain.
                </section>

                @if($loan->items->count())
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Description</th>
                            <th>Metal</th>
                            <th>Qty</th>
                            <th class="text-right">Gross Wt (g)</th>
                            <th class="text-right">Net Wt (g)</th>
                            <th class="text-center">Purity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($loan->items as $index => $item)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td><strong>{{ $item->description }}</strong></td>
                            <td>{{ ucfirst($item->metal_type ?? '-') }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-right">{{ number_format((float) $item->gross_weight, 3) }}</td>
                            <td class="text-right">{{ number_format((float) $item->net_metal_weight, 3) }}</td>
                            <td class="text-center">{{ $item->purity ? number_format((float) $item->purity, 2) . 'K' : '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif

                <section class="ledger">
                    <div class="ledger-row"><span>Principal Amount</span><strong>{{ number_format((float) $loan->principal_amount, 2) }}</strong></div>
                    <div class="ledger-row"><span>Interest Collected</span><strong>{{ number_format((float) $loan->total_interest_collected, 2) }}</strong></div>
                    <div class="ledger-row"><span>Penalty Collected</span><strong>{{ number_format((float) $loan->total_penalty_collected, 2) }}</strong></div>
                    <div class="ledger-row"><span>Processing Fee</span><strong>{{ number_format((float) $loan->processing_fee, 2) }}</strong></div>
                    <div class="ledger-row ledger-total"><span>Total Collected</span><strong>{{ number_format($totalCollected, 2) }}</strong></div>
                </section>

                @if($settings->closure_certificate_text)
                    <div class="cert-footer-text">
                        {!! nl2br(e($settings->closure_certificate_text)) !!}
                    </div>
                @endif

                @if($loan->closure_notes)
                    <div class="cert-footer-text">
                        <strong>Closure Notes:</strong> {{ $loan->closure_notes }}
                    </div>
                @endif

                <div class="signature-row">
                    <div class="signature-block">
                        <div class="signature-line">Borrower's Signature</div>
                    </div>
                    <div class="signature-block">
                        <div class="signature-line">Date</div>
                    </div>
                    <div class="signature-block">
                        <div class="signature-line">For {{ $shopName }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn" onclick="window.print()">Print</button>
            <a class="btn" href="{{ route('dhiran.show', $loan) }}">Back to Loan</a>
        </div>
    </div>
</body>
</html>
