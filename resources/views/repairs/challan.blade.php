{{-- Repair intake challan: the customer's receipt for an item left for repair.
     Read-only view of the existing repair, shared by the print page and the PDF.
     Shows only what was recorded; never internal costs, invoices or photos. --}}
@php
    $tz = config('app.timezone');
    $customer = $repair->customer;
    $mobile = $customer?->mobile ? \App\Support\Mobile::forDisplay($customer->mobile) : null;
    $shopAddress = collect([$shop->address, $shop->city, $shop->state, $shop->pincode])->filter()->implode(', ');
    $statusLabels = ['received' => 'Received', 'in_repair' => 'In repair', 'ready' => 'Ready for collection', 'delivered' => 'Delivered'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Repair Intake Challan {{ $reference }}</title>
    <style>
        @page { size: A5; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Helvetica', Arial, sans-serif; font-size: 11px; color: #111; margin: 0; }
        .sheet { max-width: 148mm; margin: 0 auto; padding: 0; }
        @media screen { body { background: #f3f4f6; } .sheet { background: #fff; padding: 10mm; margin: 12px auto; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
        h1 { font-size: 15px; margin: 0; }
        .small { font-size: 9.5px; color: #444; }
        .head { text-align: center; border-bottom: 2px solid #222; padding-bottom: 6px; margin-bottom: 8px; }
        .doc-title { margin-top: 6px; font-size: 12.5px; font-weight: bold; letter-spacing: .06em; text-transform: uppercase; }
        .ref { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px; }
        .ref strong { font-size: 16px; letter-spacing: .03em; }
        .label { font-size: 8.5px; text-transform: uppercase; letter-spacing: .05em; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #555; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { width: 34%; background: #f3f4f6; font-weight: 600; font-size: 10px; }
        td { overflow-wrap: anywhere; white-space: pre-line; }
        .money td { font-weight: 600; }
        .note { font-size: 9.5px; color: #333; margin: 2px 0 0; }
        .notice { border: 1px solid #b45309; background: #fffbeb; padding: 5px 7px; margin-bottom: 8px; font-size: 10px; }
        .write-in { border-bottom: 1px solid #888; height: 20px; }
        .terms { font-size: 9px; color: #333; white-space: pre-line; border-top: 1px dashed #999; padding-top: 5px; margin-top: 14px; }
        .signs { display: flex; gap: 16px; margin-top: 22px; }
        .signs > div { flex: 1; text-align: center; font-size: 9.5px; }
        .sign-line { border-top: 1px solid #333; margin-bottom: 3px; }
        .foot { margin-top: 10px; font-size: 9px; color: #555; text-align: center; }
        .print-btn { position: fixed; top: 8px; right: 8px; padding: 6px 12px; background: #b45309; color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 12px; }
        @media print { .print-btn { display: none; } body { background: #fff; } .sheet { box-shadow: none; padding: 0; margin: 0; } }
    </style>
</head>
<body>
@unless($forPdf)
    <button type="button" class="print-btn" onclick="window.print()">Print</button>
@endunless
<div class="sheet">
    <div class="head">
        <h1>{{ $shop->name }}</h1>
        @if($shopAddress)<div class="small">{{ $shopAddress }}</div>@endif
        <div class="small">
            @if($shop->phone) Ph: {{ \App\Support\Mobile::forDisplay($shop->phone) }} @endif
            @if($shop->gst_number) · GSTIN: {{ $shop->gst_number }} @endif
        </div>
        <div class="doc-title">Repair Intake Challan</div>
        <div class="small">Customer copy · Not a bill</div>
    </div>

    <div class="ref">
        <div><div class="label">Repair reference</div><strong>{{ $reference }}</strong></div>
        <div style="text-align:right"><div class="label">Received on</div>{{ $repair->created_at?->timezone($tz)->format('d M Y, h:i A') }}</div>
    </div>

    @if($changedAfterIntake)
        <div class="notice">
            This repair record was changed after intake (last change {{ $repair->updated_at->timezone($tz)->format('d M Y') }}).
            The details below are as recorded now and may differ from the first copy.
            @if($repair->status !== 'received') Current status: {{ $statusLabels[$repair->status] ?? ucfirst(str_replace('_', ' ', $repair->status)) }}. @endif
        </div>
    @endif

    <table>
        <tr><th>Customer</th><td>{{ $customer?->name ?? '—' }}@if($mobile)<br>{{ $mobile }}@endif</td></tr>
        <tr><th>Item</th><td>{{ $repair->item_description }}</td></tr>
        <tr><th>Metal</th><td>{{ \App\Models\Repair::METAL_LABELS[$repair->metal_type] ?? ucfirst((string) $repair->metal_type) }}</td></tr>
        @if($repair->purity !== null)
            <tr><th>Purity (as declared)</th><td>{{ $repair->purity_label }}</td></tr>
        @endif
        <tr><th>Gross weight</th><td>{{ number_format((float) $repair->gross_weight, 3) }} g</td></tr>
        @if(filled($repair->description))
            <tr><th>Work requested</th><td>{{ $repair->description }}</td></tr>
        @endif
        @if($repair->due_date)
            <tr><th>Expected ready by</th><td>{{ $repair->due_date->format('d M Y') }}</td></tr>
        @endif
    </table>

    <table class="money">
        @if($repair->estimated_cost !== null)
            <tr><th>Estimated charge</th><td>₹{{ number_format((float) $repair->estimated_cost, 2) }}
                <div class="note" style="font-weight:normal">This is an estimate, not a payment. The final amount is billed at collection.</div></td></tr>
        @endif
        <tr><th>Payment received</th><td>None recorded</td></tr>
    </table>

    <div class="label">Pieces and condition at intake (write by hand)</div>
    <div class="write-in"></div>
    <div class="write-in"></div>

    @if($terms !== '')
        <div class="terms"><strong>Shop terms</strong>
{{ $terms }}</div>
    @endif

    <div class="signs">
        <div><div class="sign-line"></div>Customer signature</div>
        <div><div class="sign-line"></div>Received by, for {{ $shop->name }}</div>
    </div>

    <div class="foot">
        Bring this challan when you collect the item. Reference {{ $reference }}.<br>
        Printed {{ now()->timezone($tz)->format('d M Y, h:i A') }}
    </div>
</div>
</body>
</html>
