{{-- Repair intake challan: the customer's receipt for an item left for repair.
     Read-only view of the existing repair, shared by the print page and the PDF.
     Shows only what was recorded; never internal costs, invoices or photos. --}}
@php
    $tz = config('app.timezone');
    $customer = $repair->customer;
    $mobile = $customer?->mobile ? \App\Support\Mobile::forDisplay($customer->mobile) : null;
    $shopAddress = collect([$shop->address, $shop->city, $shop->state, $shop->pincode])->filter()->implode(', ');
    $statusLabels = ['in_repair' => 'In repair', 'ready' => 'Ready for collection', 'delivered' => 'Delivered'];
    $statusLabel = $repair->status === 'received' ? null : ($statusLabels[$repair->status] ?? ucfirst(str_replace('_', ' ', $repair->status)));
    $generatedAt = now()->timezone($tz)->format('d M Y, h:i A');
    $labels = [
        'item_description' => 'Item', 'item_quantity' => 'Quantity (pieces)', 'intake_condition' => 'Condition at receipt',
        'metal' => 'Metal', 'purity' => 'Purity (as declared)', 'gross_weight' => 'Gross weight',
        'description' => 'Work requested', 'due_date' => 'Expected ready by', 'estimated_cost' => 'Estimated charge',
    ];
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
        .note { font-size: 9.5px; color: #333; margin: 2px 0 0; }
        .notice { border: 1px solid #b45309; background: #fffbeb; padding: 5px 7px; margin-bottom: 8px; font-size: 10px; }
        .section { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; margin: 2px 0 3px; }
        .changes th, .changes td { border-color: #b45309; }
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
        <div style="text-align:right">
            <div class="label">Intake date</div>{{ $repair->created_at?->timezone($tz)->format('d M Y, h:i A') }}
            <div class="label" style="margin-top:3px">Copy generated</div>{{ $generatedAt }}
        </div>
    </div>

    @unless($hasIntakeCopy)
        <div class="notice">
            This repair was received before JewelFlows kept an intake copy, so the details below are the current record and may include later changes.
            @if($statusLabel) Current status: {{ $statusLabel }}. @endif
        </div>
    @endunless

    <table>
        <tr><th>Customer</th><td>{{ $customer?->name ?? '—' }}@if($mobile)<br>{{ $mobile }}@endif</td></tr>
    </table>

    <div class="section">{{ $hasIntakeCopy ? 'As received at intake' : 'Details as currently recorded' }}</div>
    <table>
        @foreach($labels as $key => $label)
            @php $value = $details[$key] ?? null; @endphp
            @if($value !== null || in_array($key, ['item_quantity', 'intake_condition'], true))
                <tr><th>{{ $label }}</th><td>{{ $value ?? 'Not recorded' }}
                    @if($key === 'estimated_cost')<div class="note">This is an estimate, not a payment. The final amount is billed at collection.</div>@endif
                </td></tr>
            @endif
        @endforeach
    </table>

    @if($hasIntakeCopy && ($changes || $statusLabel))
        <div class="section">Changed after intake <span class="small">(current record when this copy was generated)</span></div>
        <table class="changes">
            @foreach($changes as $key => $value)
                <tr><th>{{ $labels[$key] ?? $key }}</th><td>{{ $value ?? 'Removed' }}</td></tr>
            @endforeach
            @if($statusLabel)
                <tr><th>Current status</th><td>{{ $statusLabel }}</td></tr>
            @endif
        </table>
    @endif

    <p class="note"><strong>Payments:</strong> JewelFlows repair records do not track advances, so this challan does not show any amount paid. Keep the shop's separate receipt for any advance.</p>

    @if($terms !== '')
        <div class="terms"><strong>Shop terms</strong>
{{ $terms }}</div>
    @endif

    <div class="signs">
        <div><div class="sign-line"></div>Customer signature</div>
        <div><div class="sign-line"></div>Received by, for {{ $shop->name }}</div>
    </div>

    <div class="foot">
        Bring this challan when you collect the item. Reference {{ $reference }}.
    </div>
</div>
</body>
</html>
