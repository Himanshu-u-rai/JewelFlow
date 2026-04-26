<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Delivery Challan {{ $jobOrder->challan_number }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .small { font-size: 9.5px; color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-size: 10px; }
        .meta-box { display: flex; gap: 12px; margin-bottom: 12px; }
        .meta-box > div { flex: 1; border: 1px solid #ccc; padding: 8px; border-radius: 4px; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #777; margin-bottom: 4px; }
        .declaration { margin-top: 18px; padding: 8px; border: 1px dashed #888; font-size: 10px; }
        .signs { margin-top: 32px; display: flex; justify-content: space-between; }
        .sign-block { flex: 1; text-align: center; }
        .sign-line { border-top: 1px solid #333; margin: 30px 24px 4px; }
        .print-btn { position: fixed; top: 8px; right: 8px; padding: 6px 12px; background: #14b8a6; color: white; border: none; border-radius: 4px; cursor: pointer; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Print</button>

    <div style="text-align:center; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 12px;">
        <h1>{{ $shop?->name ?? 'Shop' }}</h1>
        <div class="small">{{ $shop?->address ?? '' }}{{ $shop?->city ? ', ' . $shop->city : '' }}{{ $shop?->state ? ', ' . $shop->state : '' }} {{ $shop?->pincode ?? '' }}</div>
        <div class="small">
            @if($shop?->phone) Ph: {{ $shop->phone }} @endif
            @if($shop?->gst_number) · GSTIN: {{ $shop->gst_number }} @endif
        </div>
        <div style="margin-top: 8px; font-size: 14px; font-weight: bold; letter-spacing: 0.05em;">DELIVERY CHALLAN</div>
        <div class="small" style="margin-top: 2px;">For Job Work — Not a Sale (CGST Rule 55)</div>
    </div>

    <div class="meta-box">
        <div>
            <div class="label">Challan No</div>
            <div style="font-size: 13px; font-weight: bold;">{{ $jobOrder->challan_number }}</div>
            <div class="label" style="margin-top:6px;">Job Order Ref</div>
            <div>{{ $jobOrder->job_order_number }}</div>
            <div class="label" style="margin-top:6px;">Date</div>
            <div>{{ $jobOrder->issue_date->format('d M Y') }}</div>
            @if($jobOrder->expected_return_date)
                <div class="label" style="margin-top:6px;">Expected Return</div>
                <div>{{ $jobOrder->expected_return_date->format('d M Y') }}</div>
            @endif
        </div>
        <div>
            <div class="label">Sender (Principal)</div>
            <div style="font-weight: bold;">{{ $shop?->name }}</div>
            <div class="small">{{ $shop?->address }}{{ $shop?->city ? ', ' . $shop->city : '' }}</div>
            @if($shop?->gst_number)
                <div class="small">GSTIN: {{ $shop->gst_number }}</div>
            @endif
        </div>
        <div>
            <div class="label">Recipient (Karigar / Job Worker)</div>
            <div style="font-weight: bold;">{{ $jobOrder->karigar?->name }}</div>
            <div class="small">{{ $jobOrder->karigar?->address }}{{ $jobOrder->karigar?->city ? ', ' . $jobOrder->karigar->city : '' }}</div>
            @if($jobOrder->karigar?->gst_number)
                <div class="small">GSTIN: {{ $jobOrder->karigar->gst_number }}</div>
            @endif
            @if($jobOrder->karigar?->mobile)
                <div class="small">Ph: {{ $jobOrder->karigar->mobile }}</div>
            @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:6%; text-align:center;">Sr.</th>
                <th>Description of Goods</th>
                <th style="width:9%;">HSN</th>
                <th style="width:11%; text-align:right;">Purity</th>
                <th style="width:13%; text-align:right;">Gross Wt (g)</th>
                <th style="width:13%; text-align:right;">Fine Wt (g)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($jobOrder->issuances as $idx => $iss)
                <tr>
                    <td style="text-align:center;">{{ $idx + 1 }}</td>
                    <td>{{ $jobOrder->metal_type === 'gold' ? 'Gold' : 'Silver' }} bullion (raw) — for job work conversion to ornaments</td>
                    <td>7108</td>
                    <td style="text-align:right;">{{ rtrim(rtrim(number_format($iss->purity, 2), '0'), '.') }}{{ $jobOrder->metal_type === 'gold' ? 'K' : '‰' }}</td>
                    <td style="text-align:right;">{{ number_format($iss->gross_weight, 3) }}</td>
                    <td style="text-align:right;">{{ number_format($iss->fine_weight, 3) }}</td>
                </tr>
            @endforeach
            <tr style="background:#f9fafb;">
                <td colspan="4" style="text-align:right; font-weight:bold;">Total</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($jobOrder->issued_gross_weight, 3) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($jobOrder->issued_fine_weight, 3) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="declaration">
        <strong>Declaration:</strong> The goods described above are being sent to the karigar/job worker for the purpose of job work (conversion to finished jewellery). This is not a sale. Goods are dispatched against this challan as required by Rule 55 of the CGST Rules, 2017. The recipient is required to return the finished goods (or equivalent metal weight after permitted wastage of {{ $jobOrder->allowed_wastage_percent }}%) within the time limit stipulated under Section 143 of the CGST Act.
    </div>

    @if($jobOrder->notes)
        <div style="margin-top: 8px; font-size: 10px;"><strong>Notes:</strong> {{ $jobOrder->notes }}</div>
    @endif

    <div class="signs">
        <div class="sign-block">
            <div class="sign-line"></div>
            <div class="small">Issued by ({{ $shop?->name ?? 'Shop' }})</div>
        </div>
        <div class="sign-block">
            <div class="sign-line"></div>
            <div class="small">Received by ({{ $jobOrder->karigar?->name }})</div>
        </div>
    </div>
</body>
</html>
