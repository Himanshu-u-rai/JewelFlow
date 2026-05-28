<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exchange Receipt — {{ $exchange->returnOrder?->creditNote?->credit_note_number ?? '#' . $exchange->id }} / {{ $exchange->newInvoice?->invoice_number }}</title>
    <style>
        @page { margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #111; font-size: 12px; line-height: 1.45; max-width: 720px; margin: 0 auto; padding: 16px; }
        h1 { margin: 0 0 4px; font-size: 20px; font-weight: 700; }
        h2 { margin: 18px 0 8px; font-size: 14px; font-weight: 700; border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; }
        .muted { color: #6b7280; font-size: 11px; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .shop { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 6px 8px; }
        th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        td { border-bottom: 1px solid #f3f4f6; }
        .right { text-align: right; }
        .total-row { font-weight: 700; }
        .net-card { margin: 18px 0; padding: 14px 16px; border: 1px solid #cbd5e1; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; }
        .net-amount { font-size: 22px; font-weight: 800; }
        .net-direction { color: #6b7280; font-size: 11px; margin-top: 2px; }
        .footer { margin-top: 24px; font-size: 10px; color: #9ca3af; text-align: center; }
        .print-button { margin: 12px 0; }
        @media print { .print-button { display: none; } }
    </style>
</head>
<body>
    @php
        $cn = $exchange->returnOrder?->creditNote;
        $newInv = $exchange->newInvoice;
        $net = (float) $exchange->net_amount;
        $netAbs = number_format(abs($net), 2);
        if ($net > 0.005) { $netLabel = 'Customer paid ₹' . $netAbs; $netClass = '#047857'; }
        elseif ($net < -0.005) { $netLabel = 'Shop refunded ₹' . $netAbs; $netClass = '#b91c1c'; }
        else { $netLabel = 'Even swap'; $netClass = '#374151'; }
        $customer = $exchange->customer ?? $exchange->returnOrder?->customer ?? $newInv?->customer;
    @endphp

    <div class="print-button">
        <button onclick="window.print()" style="padding: 6px 14px; background: #0f172a; color: white; border: 0; border-radius: 6px; cursor: pointer;">Print this receipt</button>
        <a href="{{ route('exchanges.show', $exchange) }}" style="margin-left: 8px; color: #6b7280; text-decoration: none;">← back to exchange</a>
    </div>

    <div class="header">
        <div>
            <h1>Exchange Receipt</h1>
            <div class="muted">Exchange #{{ $exchange->id }} · Settled {{ optional($exchange->settled_at)->format('d M Y, h:i A') }}</div>
            @if($customer)
                <div style="margin-top: 4px;">{{ trim($customer->first_name . ' ' . $customer->last_name) }} · {{ $customer->mobile }}</div>
            @endif
        </div>
        <div class="shop">
            <div style="font-weight: 700;">{{ $exchange->shop->name ?? 'Shop' }}</div>
            <div class="muted">{{ $exchange->shop->address ?? '' }}</div>
            @if($exchange->shop->gstin ?? false)
                <div class="muted">GSTIN: {{ $exchange->shop->gstin }}</div>
            @endif
        </div>
    </div>

    {{-- Return half --}}
    @if($cn)
        <h2>Returned — Credit Note {{ $cn->credit_note_number }}</h2>
        <div class="muted">Original invoice: {{ $exchange->returnOrder?->invoice?->invoice_number ?? '—' }}</div>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="right">Subtotal</th>
                    <th class="right">GST</th>
                    <th class="right">Refund</th>
                </tr>
            </thead>
            <tbody>
                @foreach($exchange->returnOrder->lineItems as $rl)
                    <tr>
                        <td>
                            <div style="font-weight: 600;">{{ $rl->item?->barcode ?? '—' }}</div>
                            <div class="muted">{{ $rl->item?->design ?? $rl->item?->category }}</div>
                        </td>
                        <td class="right">₹{{ number_format((float) $rl->refund_subtotal, 2) }}</td>
                        <td class="right">₹{{ number_format((float) $rl->refund_gst, 2) }}</td>
                        <td class="right">₹{{ number_format((float) $rl->refund_total, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3" class="right">Refund Total</td>
                    <td class="right">₹{{ number_format((float) $cn->total, 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- New sale half --}}
    @if($newInv)
        <h2>New Sale — {{ $newInv->invoice_number }}</h2>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="right">Line Total</th>
                    <th class="right">GST</th>
                    <th class="right">Sub-total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($newInv->items as $li)
                    <tr>
                        <td>
                            <div style="font-weight: 600;">{{ $li->item?->barcode ?? '—' }}</div>
                            <div class="muted">{{ $li->item?->design ?? $li->item?->category }}</div>
                        </td>
                        <td class="right">₹{{ number_format((float) $li->line_total, 2) }}</td>
                        <td class="right">₹{{ number_format((float) $li->gst_amount, 2) }}</td>
                        <td class="right">₹{{ number_format((float) $li->line_total + (float) $li->gst_amount, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3" class="right">New Sale Total</td>
                    <td class="right">₹{{ number_format((float) $newInv->total, 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- Net --}}
    <div class="net-card">
        <div>
            <div style="font-weight: 600;">{{ $netLabel }}</div>
            <div class="net-direction">Basis: {{ str_replace('_', ' ', $exchange->valuation_basis_source) }}</div>
        </div>
        <div class="net-amount" style="color: {{ $netClass }};">
            ₹{{ $netAbs }}
        </div>
    </div>

    @if($exchange->reason)
        <div class="muted" style="margin-top: 12px;"><strong>Reason:</strong> {{ $exchange->reason }}</div>
    @endif

    <div class="footer">
        Generated {{ now()->format('d M Y, h:i A') }}
    </div>
</body>
</html>
