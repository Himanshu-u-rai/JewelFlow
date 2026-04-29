<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Karigar Invoice {{ $invoice->karigar_invoice_number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: 'Helvetica', sans-serif; font-size: 10px; color: #000; }
        h1 { font-size: 18px; margin: 0; }
        .small { font-size: 9px; color: #555; }
        .invoice-sheet { min-height: 277mm; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 6px; margin-bottom: 6px; }
        .invoice-title { text-align: center; font-size: 12px; font-weight: bold; padding: 4px; background: #f3f4f6; border: 1px solid #333; margin-bottom: 6px; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px; }
        .meta-block { border: 1px solid #888; padding: 6px; }
        .meta-block .label { font-size: 8px; text-transform: uppercase; color: #555; }
        table { width: 100%; border-collapse: collapse; }
        .items-table { table-layout: fixed; }
        th, td { border: 1px solid #333; padding: 4px 6px; vertical-align: middle; font-size: 10px; }
        th { background: #f3f4f6; font-size: 9px; }
        .line-row td { height: 26px; }
        .filler-row td { height: 28px; }
        .footer-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 6px; }
        .totals-table td { padding: 3px 6px; }
        .word-row { font-style: italic; }
        .sign { margin-top: 24px; text-align: right; }
        .print-btn { position: fixed; top: 8px; right: 8px; padding: 6px 12px; background: #14b8a6; color: white; border: none; border-radius: 4px; cursor: pointer; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Print</button>

    <div class="invoice-sheet">
    <div class="header">
        <div>
            <h1>{{ $invoice->karigar?->name ?? 'Karigar' }}</h1>
            <div class="small">{{ $invoice->karigar?->address }}{{ $invoice->karigar?->city ? ', ' . $invoice->karigar->city : '' }}</div>
            <div class="small">
                @if($invoice->karigar?->mobile) Mo: {{ $invoice->karigar->mobile }} @endif
                @if($invoice->karigar?->gst_number) · GST: {{ $invoice->karigar->gst_number }} @endif
            </div>
        </div>
        <div style="text-align:right;">
            <div class="small" style="text-align:right;">Original / Duplicate</div>
            <div style="font-size:11px; font-weight:bold; margin-top: 4px;">{{ $invoice->isJobWorkMode() ? 'JOB WORK INVOICE' : 'TAX INVOICE' }}</div>
        </div>
    </div>

    <div class="meta-grid">
        <div class="meta-block">
            <div class="label">Invoice Date</div><div style="font-weight:bold;">{{ $invoice->karigar_invoice_date->format('d/m/Y') }}</div>
            <div class="label" style="margin-top:4px;">Invoice No</div><div style="font-weight:bold;">{{ $invoice->karigar_invoice_number }}</div>
            <div class="label" style="margin-top:4px;">State &amp; Code</div><div>{{ $invoice->state_code ?? '24' }} - {{ $invoice->karigar?->state ?? 'Gujarat' }}</div>
        </div>
        <div class="meta-block">
            <div class="label">Details of Receiver | Billed to</div>
            <div style="font-weight:bold;">{{ $shop?->name }}</div>
            <div class="small">{{ $shop?->address }}{{ $shop?->city ? ', ' . $shop->city : '' }}</div>
            <div class="small">
                @if($shop?->phone) Mo: {{ $shop->phone }} @endif
                @if($shop?->pan_number) · PAN: {{ $shop->pan_number }} @endif
            </div>
            @if($shop?->gst_number)
                <div class="small">GST No: {{ $shop->gst_number }}</div>
            @endif
        </div>
    </div>

    @php
        $lineCount = $invoice->lines->count();
        $minimumLineRows = 16;
        $fillerRows = max(0, $minimumLineRows - $lineCount);
    @endphp

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:5%;">Sr.</th>
                <th>Item Name</th>
                <th style="width:8%;">HSN Code</th>
                <th style="width:5%; text-align:right;">PCS</th>
                <th style="width:9%; text-align:right;">Gross Wt.</th>
                <th style="width:8%; text-align:right;">Gem. Wt.</th>
                <th style="width:9%; text-align:right;">Net. Wt.</th>
                <th style="width:9%; text-align:right;">Metal Rate</th>
                <th style="width:11%; text-align:right;">Metal Rs.</th>
                <th style="width:9%; text-align:right;">Extra Rs.</th>
                <th style="width:11%; text-align:right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $idx => $line)
                <tr class="line-row">
                    <td style="text-align:center;">{{ $idx + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->hsn_code }}</td>
                    <td style="text-align:right;">{{ $line->pieces }}</td>
                    <td style="text-align:right;">{{ number_format($line->gross_weight, 3) }}</td>
                    <td style="text-align:right;">{{ number_format($line->stone_weight, 3) }}</td>
                    <td style="text-align:right;">{{ number_format($line->net_weight, 3) }}</td>
                    <td style="text-align:right;">{{ number_format($line->rate_per_gram, 0) }}</td>
                    <td style="text-align:right;">{{ number_format($line->metal_amount, 0) }}</td>
                    <td style="text-align:right;">{{ number_format($line->extra_amount, 0) }}</td>
                    <td style="text-align:right; font-weight:bold;">{{ number_format($line->line_total, 0) }}</td>
                </tr>
            @endforeach
            @for($fillerIndex = 0; $fillerIndex < $fillerRows; $fillerIndex++)
                <tr class="filler-row">
                    <td style="text-align:center;">&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                    <td style="text-align:right;">&nbsp;</td>
                </tr>
            @endfor
            <tr style="background:#f9fafb;">
                <td colspan="3" style="font-weight:bold;">Note: {{ $invoice->lines->first()?->note ?: '—' }}</td>
                <td style="text-align:right; font-weight:bold;">{{ $invoice->total_pieces }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_gross_weight, 3) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_stone_weight, 3) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_net_weight, 3) }}</td>
                <td></td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_metal_amount, 0) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_extra_amount, 0) }}</td>
                <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_before_tax, 0) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer-grid">
        <div>
            @if($invoice->amount_in_words)
                <div class="word-row"><strong>Rs. Word:</strong> {{ $invoice->amount_in_words }}</div>
            @endif
            @if($invoice->tax_amount_in_words)
                <div class="word-row" style="margin-top:2px;"><strong>TAX Word:</strong> {{ $invoice->tax_amount_in_words }}</div>
            @endif
            <div style="margin-top:6px; border:1px solid #888; padding:6px;">
                <div class="label" style="font-size:8px; text-transform:uppercase;">Payment Details</div>
                @if($invoice->payment_terms)
                    <div style="margin-top:2px;">{{ $invoice->payment_terms }}</div>
                @endif
                <div style="margin-top:6px; font-size:9px;">
                    Jama (Credit): {{ number_format($invoice->total_after_tax, 0) }}<br>
                    Udhar (Debit):
                </div>
            </div>
            <div style="margin-top:6px;">
                <div class="small"><strong>: Terms and Conditions :</strong></div>
                <div class="small">Subject {{ $invoice->jurisdiction ?: 'court' }} to Jurisdiction.</div>
            </div>
        </div>
        <div>
            <table class="totals-table" style="width:100%;">
                <tr>
                    <td>Total Amount Before Tax</td>
                    <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_before_tax, 0) }}</td>
                </tr>
                @if($invoice->cgst_amount > 0)
                    <tr>
                        <td>Add CGST @ {{ rtrim(rtrim(number_format($invoice->cgst_rate, 2), '0'), '.') }}%</td>
                        <td style="text-align:right;">{{ number_format($invoice->cgst_amount, 1) }}</td>
                    </tr>
                @endif
                @if($invoice->sgst_amount > 0)
                    <tr>
                        <td>Add SGST @ {{ rtrim(rtrim(number_format($invoice->sgst_rate, 2), '0'), '.') }}%</td>
                        <td style="text-align:right;">{{ number_format($invoice->sgst_amount, 1) }}</td>
                    </tr>
                @endif
                @if($invoice->igst_amount > 0)
                    <tr>
                        <td>Add IGST @ {{ rtrim(rtrim(number_format($invoice->igst_rate, 2), '0'), '.') }}%</td>
                        <td style="text-align:right;">{{ number_format($invoice->igst_amount, 1) }}</td>
                    </tr>
                @endif
                <tr>
                    <td><strong>Tax Amount GST</strong></td>
                    <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total_tax, 1) }}</td>
                </tr>
                <tr style="background:#f3f4f6;">
                    <td><strong>Total Amount After Tax</strong></td>
                    <td style="text-align:right; font-weight:bold; font-size:11px;">{{ number_format($invoice->total_after_tax, 0) }}</td>
                </tr>
            </table>
            <div class="sign">
                <div class="small" style="margin-bottom:30px;">Certified that the perticulars given above are true and correct</div>
                <div style="border-top: 1px solid #333; display: inline-block; padding-top: 4px; min-width: 180px;">
                    <div class="small">For, {{ $invoice->karigar?->name }}</div>
                    <div class="small" style="margin-top: 2px;">Authorised Signature</div>
                </div>
            </div>
        </div>
    </div>
    </div>
</body>
</html>
