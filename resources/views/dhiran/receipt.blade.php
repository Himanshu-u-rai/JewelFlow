<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pledge Receipt &mdash; {{ $loan->loan_number }}</title>
    @vite(['resources/css/dhiran-documents.css'])
</head>
<body class="dh-doc-body dh-doc-pledge-receipt">
    <div class="receipt-print-bar receipt-no-print">
        <button type="button" class="receipt-print-btn" onclick="window.print()">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Print Receipt
        </button>
    </div>

    <div class="receipt-container">
        {{-- Shop Header --}}
        <div class="receipt-header">
            <div class="receipt-shop-name">{{ $shop->name ?? 'Dhiran' }}</div>
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
                <span class="receipt-detail-value">{{ \App\Support\Mobile::forDisplay($loan->customer?->mobile) ?: '---' }}</span>
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
                            <br><span class="dh-doc-item-meta">{{ ucfirst($item->metal_type ?? 'gold') }}</span>
                            @if($item->huid)
                                <span class="dh-doc-item-meta"> · HUID: {{ $item->huid }}</span>
                            @endif
                        </td>
                        <td class="text-right">{{ number_format($item->gross_weight, 3) }}</td>
                        <td class="text-right">{{ number_format($item->net_metal_weight, 3) }}</td>
                        <td class="text-center">@if(($item->metal_type ?? "gold") === "other")—@else{{ rtrim(rtrim((string) $item->purity, "0"), ".") }}{{ ($item->metal_type ?? "gold") === "silver" ? "" : "K" }}@endif</td>
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
                <span class="dh-doc-sign-meta">{{ $loan->customer->name ?? '' }}</span>
            </div>
            <div class="receipt-sig-line">
                Authorized Signatory<br>
                <span class="dh-doc-sign-meta">{{ $shop->name ?? 'Dhiran' }}</span>
            </div>
        </div>

        {{-- Footer --}}
        <div class="receipt-footer">
            This is a computer-generated document. &nbsp;|&nbsp; Generated on {{ now()->format('d M Y, h:i A') }}
        </div>
    </div>
</body>
</html>
