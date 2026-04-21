<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pledge Receipt &mdash; {{ $loan->loan_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 12px;
            color: #0f172a;
            background: #fff;
            line-height: 1.5;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 24px;
        }

        /* Header */
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .receipt-shop-name {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .receipt-shop-address {
            font-size: 11px;
            color: #475569;
            margin-top: 4px;
        }
        .receipt-shop-phone {
            font-size: 11px;
            color: #475569;
        }
        .receipt-title {
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-top: 12px;
            color: #0f172a;
        }

        /* Loan Details Grid */
        .receipt-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .receipt-detail-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .receipt-detail-label {
            font-weight: 600;
            color: #475569;
        }
        .receipt-detail-value {
            font-weight: 600;
            color: #0f172a;
            text-align: right;
        }

        /* Items Table */
        .receipt-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 11px;
        }
        .receipt-items-table th {
            background: #f1f5f9;
            padding: 8px 10px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
        }
        .receipt-items-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #0f172a;
        }
        .receipt-items-table .text-right { text-align: right; }
        .receipt-items-table .text-center { text-align: center; }
        .receipt-items-table tfoot td {
            font-weight: 700;
            border-top: 2px solid #cbd5e1;
            border-bottom: none;
        }

        /* Summary */
        .receipt-summary {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }
        .receipt-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 12px;
        }
        .receipt-summary-row.total {
            border-top: 1px solid #cbd5e1;
            margin-top: 6px;
            padding-top: 8px;
            font-size: 14px;
            font-weight: 800;
        }

        /* Terms */
        .receipt-terms {
            margin-bottom: 24px;
        }
        .receipt-terms-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #475569;
            margin-bottom: 6px;
        }
        .receipt-terms-content {
            font-size: 10px;
            color: #64748b;
            line-height: 1.6;
            white-space: pre-line;
        }

        /* Signatures */
        .receipt-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 48px;
            padding-top: 16px;
        }
        .receipt-sig-line {
            border-top: 1px solid #0f172a;
            padding-top: 6px;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #475569;
        }

        /* Footer */
        .receipt-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            font-size: 10px;
            color: #94a3b8;
        }

        /* Print overrides */
        @media print {
            body { background: #fff; }
            .receipt-container { padding: 0; max-width: 100%; }
            .receipt-no-print { display: none !important; }
            @page { margin: 16mm; }
        }

        /* Screen-only print button */
        .receipt-print-bar {
            text-align: center;
            padding: 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 24px;
        }
        .receipt-print-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 24px;
            border: none;
            border-radius: 10px;
            background: #0f172a;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .receipt-print-btn:hover { background: #1e293b; }
        .receipt-print-btn svg { width: 16px; height: 16px; }
    </style>
</head>
<body>
    <div class="receipt-print-bar receipt-no-print">
        <button type="button" class="receipt-print-btn" onclick="window.print()">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Receipt
        </button>
    </div>

    <div class="receipt-container">
        {{-- Shop Header --}}
        <div class="receipt-header">
            <div class="receipt-shop-name">{{ $shop->name ?? 'JewelFlow' }}</div>
            <div class="receipt-shop-address">{{ $shop->address ?? '' }}</div>
            <div class="receipt-shop-phone">
                @if($shop->phone ?? false)Phone: {{ $shop->phone }}@endif
                @if($shop->gstin ?? false) &nbsp;|&nbsp; GSTIN: {{ $shop->gstin }}@endif
            </div>
            <div class="receipt-title">Pledge Receipt / Girvi Parchi</div>
        </div>

        {{-- Loan Details --}}
        <div class="receipt-details">
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Loan Number:</span>
                <span class="receipt-detail-value">{{ $loan->loan_number }}</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Date:</span>
                <span class="receipt-detail-value">{{ $loan->loan_date->format('d M Y') }}</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Customer:</span>
                <span class="receipt-detail-value">{{ $loan->customer->name ?? '---' }}</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Contact:</span>
                <span class="receipt-detail-value">{{ $loan->customer->mobile ?? '---' }}</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Principal Amount:</span>
                <span class="receipt-detail-value">{{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Interest Rate:</span>
                <span class="receipt-detail-value">{{ $loan->interest_rate_monthly }}% / month ({{ ucfirst($loan->interest_type) }})</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Tenure:</span>
                <span class="receipt-detail-value">{{ $loan->tenure_months }} months</span>
            </div>
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Maturity Date:</span>
                <span class="receipt-detail-value">{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</span>
            </div>
            @if($loan->aadhaar)
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">Aadhaar:</span>
                <span class="receipt-detail-value">{{ $loan->aadhaar }}</span>
            </div>
            @endif
            @if($loan->pan)
            <div class="receipt-detail-row">
                <span class="receipt-detail-label">PAN:</span>
                <span class="receipt-detail-value">{{ $loan->pan }}</span>
            </div>
            @endif
        </div>

        {{-- Items Table --}}
        <table class="receipt-items-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Description</th>
                    <th class="text-right">Gross Wt (g)</th>
                    <th class="text-right">Net Wt (g)</th>
                    <th class="text-center">Purity</th>
                    <th class="text-right">Fine Wt (g)</th>
                    <th class="text-right">Value</th>
                </tr>
            </thead>
            <tbody>
                @php $totalGross = 0; $totalNet = 0; $totalFine = 0; $totalValue = 0; @endphp
                @foreach($loan->items ?? [] as $index => $item)
                    @php
                        $totalGross += $item->gross_weight;
                        $totalNet += $item->net_metal_weight;
                        $totalFine += $item->fine_weight;
                        $totalValue += $item->market_value;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>
                            {{ $item->description }}
                            @if($item->huid)
                                <br><span style="font-size:9px;color:#64748b;">HUID: {{ $item->huid }}</span>
                            @endif
                        </td>
                        <td class="text-right">{{ number_format($item->gross_weight, 3) }}</td>
                        <td class="text-right">{{ number_format($item->net_metal_weight, 3) }}</td>
                        <td class="text-center">{{ $item->purity }}K</td>
                        <td class="text-right">{{ number_format($item->fine_weight, 3) }}</td>
                        <td class="text-right">{{ $currencySymbol ?? '₹' }}{{ number_format($item->market_value, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total</td>
                    <td class="text-right">{{ number_format($totalGross, 3) }}</td>
                    <td class="text-right">{{ number_format($totalNet, 3) }}</td>
                    <td></td>
                    <td class="text-right">{{ number_format($totalFine, 3) }}</td>
                    <td class="text-right">{{ $currencySymbol ?? '₹' }}{{ number_format($totalValue, 2) }}</td>
                </tr>
            </tfoot>
        </table>

        {{-- Loan Summary --}}
        <div class="receipt-summary">
            <div class="receipt-summary-row">
                <span>Total Market Value</span>
                <span>{{ $currencySymbol ?? '₹' }}{{ number_format($totalValue, 2) }}</span>
            </div>
            <div class="receipt-summary-row">
                <span>LTV Applied</span>
                <span>{{ number_format($loan->ltv_percent ?? 0, 1) }}%</span>
            </div>
            <div class="receipt-summary-row total">
                <span>Principal Amount Disbursed</span>
                <span>{{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}</span>
            </div>
            @if(($loan->processing_fee ?? 0) > 0)
            <div class="receipt-summary-row">
                <span>Processing Fee</span>
                <span>{{ $currencySymbol ?? '₹' }}{{ number_format($loan->processing_fee, 2) }}</span>
            </div>
            @endif
        </div>

        {{-- Terms and Conditions --}}
        @if($terms ?? false)
        <div class="receipt-terms">
            <div class="receipt-terms-title">Terms & Conditions</div>
            <div class="receipt-terms-content">{{ $terms }}</div>
        </div>
        @endif

        {{-- Signatures --}}
        <div class="receipt-signatures">
            <div class="receipt-sig-line">
                Pledger's Signature<br>
                <span style="font-weight:400;font-size:10px;">{{ $loan->customer->name ?? '' }}</span>
            </div>
            <div class="receipt-sig-line">
                Authorized Signatory<br>
                <span style="font-weight:400;font-size:10px;">{{ $shop->name ?? 'JewelFlow' }}</span>
            </div>
        </div>

        {{-- Footer --}}
        <div class="receipt-footer">
            This is a computer-generated document. &nbsp;|&nbsp; Generated on {{ now()->format('d M Y, h:i A') }}
        </div>
    </div>
</body>
</html>
