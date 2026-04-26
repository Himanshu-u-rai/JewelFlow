<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Job Work Return — {{ $jobOrder->job_order_number }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .small { font-size: 9.5px; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; font-size: 10px; }
        .meta-box { display: flex; gap: 12px; margin-bottom: 12px; }
        .meta-box > div { flex: 1; border: 1px solid #ccc; padding: 8px; border-radius: 4px; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.05em; color: #777; margin-bottom: 4px; }
        .summary { background: #f9fafb; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
        .print-btn { position: fixed; top: 8px; right: 8px; padding: 6px 12px; background: #14b8a6; color: white; border: none; border-radius: 4px; cursor: pointer; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Print</button>

    <div style="text-align:center; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 12px;">
        <h1>{{ $shop?->name ?? 'Shop' }}</h1>
        <div class="small">{{ $shop?->address }}{{ $shop?->city ? ', ' . $shop->city : '' }}{{ $shop?->state ? ', ' . $shop->state : '' }}</div>
        <div class="small">
            @if($shop?->phone) Ph: {{ $shop->phone }} @endif
            @if($shop?->gst_number) · GSTIN: {{ $shop->gst_number }} @endif
        </div>
        <div style="margin-top: 8px; font-size: 14px; font-weight: bold; letter-spacing: 0.05em;">JOB WORK RETURN ENTRY</div>
        <div class="small" style="margin-top: 2px;">Reference for ITC-04 quarterly reporting</div>
    </div>

    <div class="meta-box">
        <div>
            <div class="label">Job Order #</div>
            <div style="font-size: 13px; font-weight: bold;">{{ $jobOrder->job_order_number }}</div>
            <div class="label" style="margin-top:6px;">Original Challan</div>
            <div>{{ $jobOrder->challan_number }}</div>
            <div class="label" style="margin-top:6px;">Issued On</div>
            <div>{{ $jobOrder->issue_date->format('d M Y') }}</div>
            <div class="label" style="margin-top:6px;">Status</div>
            <div style="font-weight:bold; text-transform: uppercase;">{{ str_replace('_', ' ', $jobOrder->status) }}</div>
        </div>
        <div>
            <div class="label">Karigar</div>
            <div style="font-weight: bold;">{{ $jobOrder->karigar?->name }}</div>
            @if($jobOrder->karigar?->gst_number)
                <div class="small">GSTIN: {{ $jobOrder->karigar->gst_number }}</div>
            @endif
            <div class="small">{{ $jobOrder->karigar?->address }}{{ $jobOrder->karigar?->city ? ', ' . $jobOrder->karigar->city : '' }}</div>
        </div>
    </div>

    <div class="summary">
        <div class="label" style="margin-bottom: 8px;">Reconciliation Summary ({{ $jobOrder->metal_type }} — {{ $jobOrder->purity }} purity)</div>
        <div class="grid-3" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
            <div><div class="small">Issued (fine)</div><div style="font-size:13px; font-weight:bold;">{{ number_format($jobOrder->issued_fine_weight, 3) }}g</div></div>
            <div><div class="small">Returned (fine)</div><div style="font-size:13px; font-weight:bold;">{{ number_format($jobOrder->returned_fine_weight, 3) }}g</div></div>
            <div><div class="small">Leftover Returned</div><div style="font-size:13px; font-weight:bold;">{{ number_format($jobOrder->leftover_returned_fine_weight, 3) }}g</div></div>
            <div><div class="small">Wastage</div><div style="font-size:13px; font-weight:bold;">{{ number_format($jobOrder->actual_wastage_fine, 3) }}g ({{ $jobOrder->issued_fine_weight > 0 ? number_format($jobOrder->actual_wastage_fine / $jobOrder->issued_fine_weight * 100, 2) : '0.00' }}%)</div></div>
        </div>
        @if(!empty($jobOrder->discrepancy_flags))
            <div style="margin-top: 8px; font-size: 10px; color: #b42318;">
                <strong>Flags:</strong> {{ implode(', ', $jobOrder->discrepancy_flags) }}
            </div>
        @endif
    </div>

    <h3 style="font-size: 13px; margin-top: 16px;">Finished Items Received</h3>
    <table>
        <thead>
            <tr>
                <th style="width:6%; text-align:center;">Sr.</th>
                <th>Description</th>
                <th style="width:8%;">HSN</th>
                <th style="width:7%; text-align:right;">Pcs</th>
                <th style="width:11%; text-align:right;">Gross (g)</th>
                <th style="width:10%; text-align:right;">Stone (g)</th>
                <th style="width:11%; text-align:right;">Net (g)</th>
                <th style="width:9%; text-align:right;">Purity</th>
                <th style="width:11%; text-align:right;">Fine (g)</th>
            </tr>
        </thead>
        <tbody>
            @php $sr = 0; @endphp
            @foreach($jobOrder->receipts as $rcpt)
                @foreach($rcpt->items as $i)
                    @php $sr++; @endphp
                    <tr>
                        <td style="text-align:center;">{{ $sr }}</td>
                        <td>{{ $i->description }}</td>
                        <td>{{ $i->hsn_code ?? '7113' }}</td>
                        <td style="text-align:right;">{{ $i->pieces }}</td>
                        <td style="text-align:right;">{{ number_format($i->gross_weight, 3) }}</td>
                        <td style="text-align:right;">{{ number_format($i->stone_weight, 3) }}</td>
                        <td style="text-align:right;">{{ number_format($i->net_weight, 3) }}</td>
                        <td style="text-align:right;">{{ rtrim(rtrim(number_format($i->purity, 2), '0'), '.') }}{{ $jobOrder->metal_type === 'gold' ? 'K' : '‰' }}</td>
                        <td style="text-align:right;">{{ number_format($i->fine_weight, 3) }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr style="background:#f9fafb;">
                <td colspan="3" style="text-align:right; font-weight:bold;">Total</td>
                <td style="text-align:right; font-weight:bold;">{{ $jobOrder->receipts->sum('total_pieces') }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($jobOrder->returned_gross_weight, 3) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($jobOrder->receipts->sum('total_stone_weight'), 3) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($jobOrder->receipts->sum('total_net_weight'), 3) }}</td>
                <td></td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($jobOrder->returned_fine_weight, 3) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
