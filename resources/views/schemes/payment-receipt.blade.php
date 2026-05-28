<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt {{ $payment->receipt_number }} — {{ $enrollment->customer?->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            background: #e8e8e8;
            color: #1a1a1a;
            font-size: 13px;
            line-height: 1.4;
        }

        /* ── Screen wrapper ───────────────────────────────────────── */
        .page-wrap {
            max-width: 680px;
            margin: 32px auto;
        }

        .no-print-bar {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 10px;
        }

        .no-print-bar button,
        .no-print-bar a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid #ccc;
            background: #fff;
            color: #333;
        }

        .no-print-bar button.primary {
            background: #1a1a1a;
            color: #fff;
            border-color: #1a1a1a;
        }

        /* ── Document ─────────────────────────────────────────────── */
        .doc {
            background: #fff;
            box-shadow: 0 4px 24px rgba(0,0,0,0.13);
        }

        /* Top accent bar */
        .doc-accent {
            height: 6px;
            background: linear-gradient(90deg, #78350f 0%, #d97706 60%, #fbbf24 100%);
        }

        /* ── Header ───────────────────────────────────────────────── */
        .doc-header {
            padding: 28px 36px 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1.5px solid #e5e7eb;
        }

        .shop-block .shop-name {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.3px;
            color: #111;
        }

        .shop-block .shop-line {
            color: #555;
            font-size: 11.5px;
            margin-top: 3px;
            line-height: 1.6;
        }

        .doc-id-block {
            text-align: right;
        }

        .doc-id-block .doc-type {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -0.5px;
            color: #111;
            text-transform: uppercase;
        }

        .doc-id-block .doc-ref {
            font-size: 12px;
            color: #555;
            margin-top: 4px;
        }

        .doc-id-block .doc-ref strong {
            color: #111;
            font-weight: 700;
        }

        /* ── Scheme badge ─────────────────────────────────────────── */
        .scheme-row {
            margin: 0 36px;
            padding: 10px 14px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-top: none;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
        }

        .scheme-row .scheme-icon {
            width: 28px;
            height: 28px;
            background: #fde68a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #92400e;
        }

        .scheme-row .scheme-name {
            font-weight: 700;
            color: #78350f;
            font-size: 12.5px;
        }

        .scheme-row .scheme-sub {
            color: #a16207;
            font-size: 11px;
            margin-top: 1px;
        }

        /* ── Parties ──────────────────────────────────────────────── */
        .parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            margin: 0 36px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }

        .party {
            padding: 14px 16px;
        }

        .party + .party {
            border-left: 1px solid #e5e7eb;
        }

        .party-label {
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #888;
            margin-bottom: 6px;
        }

        .party-name {
            font-size: 14px;
            font-weight: 700;
            color: #111;
        }

        .party-line {
            font-size: 11.5px;
            color: #555;
            margin-top: 2px;
            line-height: 1.5;
        }

        /* ── Meta row ─────────────────────────────────────────────── */
        .meta-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            margin: 0 36px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }

        .meta-cell {
            padding: 10px 14px;
            border-right: 1px solid #e5e7eb;
        }

        .meta-cell:last-child { border-right: none; }

        .meta-cell .mc-label {
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #888;
            margin-bottom: 4px;
        }

        .meta-cell .mc-value {
            font-size: 13px;
            font-weight: 700;
            color: #111;
        }

        /* ── Payment table ────────────────────────────────────────── */
        .payment-table {
            margin: 20px 36px 0;
            width: calc(100% - 72px);
            border-collapse: collapse;
        }

        .payment-table thead tr {
            background: #f3f4f6;
        }

        .payment-table th {
            padding: 9px 12px;
            text-align: left;
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #555;
            border: 1px solid #e5e7eb;
        }

        .payment-table th:last-child,
        .payment-table td:last-child {
            text-align: right;
        }

        .payment-table td {
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            font-size: 12.5px;
            color: #333;
            vertical-align: top;
        }

        .payment-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* ── Total strip ──────────────────────────────────────────── */
        .total-strip {
            margin: 0 36px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            border: 1px solid #e5e7eb;
            border-top: 2px solid #1a1a1a;
            background: #f9fafb;
        }

        .total-strip .total-label {
            padding: 14px 16px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #444;
            flex: 1;
        }

        .total-strip .total-amount {
            padding: 14px 16px;
            font-size: 26px;
            font-weight: 900;
            color: #065f46;
            letter-spacing: -0.5px;
        }

        /* ── Status stamp ─────────────────────────────────────────── */
        .status-stamp {
            margin: 16px 36px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stamp {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border: 2px solid #059669;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #059669;
            transform: rotate(-2deg);
            opacity: 0.85;
        }

        .status-stamp .stamp-note {
            font-size: 11px;
            color: #666;
        }

        /* ── Notes ────────────────────────────────────────────────── */
        .notes-row {
            margin: 14px 36px 0;
            padding: 10px 14px;
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 2px;
            font-size: 11.5px;
            color: #444;
        }

        .notes-row .notes-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #999;
            margin-bottom: 4px;
        }

        /* ── Footer ───────────────────────────────────────────────── */
        .doc-footer {
            margin: 24px 36px 0;
            padding: 16px 0 28px;
            border-top: 1px dashed #d1d5db;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .footer-left {
            font-size: 11px;
            color: #666;
            line-height: 1.7;
        }

        .footer-left strong {
            color: #333;
            font-weight: 700;
        }

        .signature-block {
            text-align: center;
        }

        .signature-line {
            width: 140px;
            border-bottom: 1px solid #555;
            margin-bottom: 5px;
        }

        .signature-label {
            font-size: 10px;
            color: #777;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .doc-footer-bar {
            height: 3px;
            background: linear-gradient(90deg, #78350f 0%, #d97706 60%, #fbbf24 100%);
        }

        /* ── Print ────────────────────────────────────────────────── */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 12mm;
            }

            body {
                background: #fff;
                font-size: 12px;
            }

            .page-wrap {
                max-width: none;
                margin: 0;
            }

            .no-print-bar { display: none; }

            .doc {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    @php
        $shopName  = $shop?->name ?: config('app.name', 'JewelFlow');
        $shopAddr  = $shop?->address ?? null;
        $shopPhone = $shop?->mobile ?? $shop?->phone ?? null;
        $shopEmail = $shop?->email ?? auth()->user()?->email ?? null;
        $customer  = $enrollment->customer;
        $scheme    = $enrollment->scheme;

        $paymentDate = $payment->payment_date
            ? $payment->payment_date->format('d M Y')
            : now()->format('d M Y');

        $totalInstallments     = (int) $enrollment->total_installments;
        $installmentsPaid      = (int) $enrollment->installments_paid;
        $remainingInstallments = max(0, $totalInstallments - $installmentsPaid);

        $amountWords = function(float $amount): string {
            if ($amount <= 0) return 'Zero Rupees Only';
            $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                     'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                     'Seventeen','Eighteen','Nineteen'];
            $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
            $two  = fn($v) => $v < 20 ? $ones[$v] : $tens[(int)($v/10)] . ($v%10 ? ' '.$ones[$v%10] : '');
            $three = fn($v) => !$v ? '' : ($v>99 ? $ones[(int)($v/100)].' Hundred'.($v%100?' '.$two($v%100):'') : $two($v));
            $n = (int) round($amount);
            $words = '';
            if ($cr = (int)($n/10000000)) $words .= $three($cr).' Crore ';
            if ($lk = (int)(($n%10000000)/100000)) $words .= $two($lk).' Lakh ';
            if ($th = (int)(($n%100000)/1000)) $words .= $two($th).' Thousand ';
            if ($rem = $n%1000) $words .= $three($rem);
            return 'Rupees '.trim($words).' Only';
        };
    @endphp

    <div class="page-wrap">

        <div class="no-print-bar">
            <a href="{{ route('schemes.enrollment.show', $enrollment) }}">← Back to Enrollment</a>
            <button class="primary" onclick="window.print()">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <polyline points="6 9 6 2 18 2 18 9"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8"/>
                </svg>
                Print / Save PDF
            </button>
        </div>

        <div class="doc">
            <div class="doc-accent"></div>

            {{-- ── Header ── --}}
            <div class="doc-header">
                <div class="shop-block">
                    <div class="shop-name">{{ $shopName }}</div>
                    @if($shopAddr)
                        <div class="shop-line">{{ $shopAddr }}</div>
                    @endif
                    <div class="shop-line">
                        @if($shopPhone){{ $shopPhone }}@endif
                        @if($shopPhone && $shopEmail) &nbsp;·&nbsp; @endif
                        @if($shopEmail){{ $shopEmail }}@endif
                    </div>
                </div>
                <div class="doc-id-block">
                    <div class="doc-type">Payment Receipt</div>
                    <div class="doc-ref" style="margin-top:6px">Receipt No. &nbsp;<strong>{{ $payment->receipt_number }}</strong></div>
                    <div class="doc-ref">Date &nbsp;<strong>{{ $paymentDate }}</strong></div>
                    <div class="doc-ref">Installment &nbsp;<strong>{{ $payment->installment_number }} of {{ $totalInstallments }}</strong></div>
                </div>
            </div>

            {{-- ── Scheme banner ── --}}
            <div class="scheme-row">
                <div class="scheme-icon">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div>
                    <div class="scheme-name">{{ $scheme?->name ?? 'Gold Savings Scheme' }}</div>
                    <div class="scheme-sub">
                        Enrolled {{ $enrollment->start_date?->format('d M Y') }}
                        &nbsp;·&nbsp;
                        Matures {{ $enrollment->maturity_date?->format('d M Y') }}
                    </div>
                </div>
            </div>

            {{-- ── Parties ── --}}
            <div class="parties">
                <div class="party">
                    <div class="party-label">Receipt From</div>
                    <div class="party-name">{{ $shopName }}</div>
                    @if($shopAddr)<div class="party-line">{{ $shopAddr }}</div>@endif
                </div>
                <div class="party">
                    <div class="party-label">Received From</div>
                    <div class="party-name">{{ $customer?->name ?? 'Customer' }}</div>
                    @if($customer?->mobile)<div class="party-line">{{ $customer->mobile }}</div>@endif
                    @if($customer?->address)<div class="party-line">{{ $customer->address }}</div>@endif
                </div>
            </div>

            {{-- ── Meta strip ── --}}
            <div class="meta-row">
                <div class="meta-cell">
                    <div class="mc-label">Receipt No</div>
                    <div class="mc-value">{{ $payment->receipt_number }}</div>
                </div>
                <div class="meta-cell">
                    <div class="mc-label">Payment Date</div>
                    <div class="mc-value">{{ $paymentDate }}</div>
                </div>
                <div class="meta-cell">
                    <div class="mc-label">Payment Mode</div>
                    <div class="mc-value">{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</div>
                </div>
                <div class="meta-cell">
                    <div class="mc-label">Installment</div>
                    <div class="mc-value">{{ $payment->installment_number }} / {{ $totalInstallments }}</div>
                </div>
            </div>

            {{-- ── Payment detail table ── --}}
            <table class="payment-table">
                <thead>
                    <tr>
                        <th style="width:40%">Particulars</th>
                        <th>Details</th>
                        <th>Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Monthly Contribution</td>
                        <td>{{ $scheme?->name ?? 'Gold Savings Scheme' }}</td>
                        <td>{{ number_format((float) $enrollment->monthly_amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Installments Paid (incl. this)</td>
                        <td>{{ $installmentsPaid }} of {{ $totalInstallments }}</td>
                        <td>{{ number_format((float) $enrollment->total_paid, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Installments Remaining</td>
                        <td>{{ $remainingInstallments }} month{{ $remainingInstallments !== 1 ? 's' : '' }}</td>
                        <td style="color:#666">—</td>
                    </tr>
                    @if((float) $enrollment->bonus_amount > 0)
                    <tr>
                        <td>Bonus on Maturity</td>
                        <td>Credited at scheme completion</td>
                        <td>{{ number_format((float) $enrollment->bonus_amount, 2) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>

            {{-- ── Total ── --}}
            <div class="total-strip">
                <span class="total-label">
                    Amount Received This Payment<br>
                    <span style="font-size:10px;font-weight:400;color:#888">
                        {{ $amountWords((float) $payment->amount) }}
                    </span>
                </span>
                <span class="total-amount">₹{{ number_format((float) $payment->amount, 2) }}</span>
            </div>

            {{-- ── Stamp ── --}}
            <div class="status-stamp">
                <span class="stamp">Received</span>
                <span class="stamp-note">Payment recorded on {{ $payment->created_at?->format('d M Y, h:i A') ?? $paymentDate }}</span>
            </div>

            @if($payment->notes)
            <div class="notes-row">
                <div class="notes-label">Notes</div>
                {{ $payment->notes }}
            </div>
            @endif

            {{-- ── Footer ── --}}
            <div class="doc-footer">
                <div class="footer-left">
                    <strong>Terms</strong><br>
                    This receipt is valid only with the shop seal / authorised signature.<br>
                    For queries, contact us at {{ $shopPhone ?? $shopEmail ?? $shopName }}.<br>
                    <span style="color:#aaa;font-size:10px">Generated by JewelFlow · {{ now()->format('d M Y, h:i A') }}</span>
                </div>
                <div class="signature-block">
                    <div class="signature-line"></div>
                    <div class="signature-label">Authorised Signatory</div>
                    <div style="font-size:10px;color:#999;margin-top:3px">{{ $shopName }}</div>
                </div>
            </div>

            <div class="doc-footer-bar"></div>
        </div>

    </div>
</body>
</html>
