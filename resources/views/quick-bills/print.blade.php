<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quickBill->bill_number }}</title>
@php
    $snapshot = $quickBill->shop_snapshot ?? [];
    $shopName = $snapshot['name'] ?? auth()->user()->shop?->name ?? 'Jewellery Store';
    $billing  = auth()->user()->shop?->billingSettings;
    $billDate = $quickBill->bill_date?->format('d/m/Y');
    $gstRate  = (float) $quickBill->gst_rate;
    $halfRate = $gstRate / 2;

    // ── Appearance ────────────────────────────────────────────────────────
    $accent    = $billing->theme_color   ?? '#111111';
    $fontTiers = [
        'compact' => ['body'=>'8.5px',  'title'=>'17px', 'sub'=>'9px',  'kind'=>'10px', 'meta'=>'8px',   'terms'=>'7.5px', 'top'=>'8px'  ],
        'normal'  => ['body'=>'9.5px',  'title'=>'20px', 'sub'=>'10px', 'kind'=>'11px', 'meta'=>'9px',   'terms'=>'8px',   'top'=>'8.5px'],
        'large'   => ['body'=>'10.5px', 'title'=>'23px', 'sub'=>'11px', 'kind'=>'12px', 'meta'=>'10px',  'terms'=>'9px',   'top'=>'9.5px'],
    ];
    $tier      = $fontTiers[$billing->font_size ?? 'normal'] ?? $fontTiers['normal'];
    $fontSize  = $tier['body'];
    $pageSizes = ['a4' => 'A4', 'a5' => 'A5', 'thermal' => '80mm auto'];
    $margins   = ['a4' => '12mm', 'a5' => '8mm', 'thermal' => '3mm'];
    $minHts    = ['a4' => '273mm', 'a5' => '190mm', 'thermal' => '0'];
    $paperKey  = $billing->paper_size ?? 'a4';
    $pageSize  = $pageSizes[$paperKey] ?? 'A4';
    $margin    = $margins[$paperKey]   ?? '12mm';
    $minHeight = $minHts[$paperKey]    ?? '273mm';

    // ── Column & display toggles ──────────────────────────────────────────
    $showStone  = $billing->show_stone_columns   ?? true;
    $showPurity = $billing->show_purity          ?? true;
    $showGstin  = $billing->show_gstin           ?? true;
    $showAddr   = $billing->show_customer_address ?? true;
    $igstMode   = $billing->igst_mode            ?? false;
    $copyCount  = (int) ($billing->copy_count    ?? 1);
    $copyCount  = max(1, min(2, $copyCount));

    // Quick bill items table has no HUID or Stone Val columns
    $descW = 28;
    if (!$showStone)  $descW += 9; // stone weight col
    if (!$showPurity) $descW += 8;

    $colCount = 7; // S.No, Desc, HSN, Pc, GrossWt, NetWt, Rate, Amount
    if ($showStone)  $colCount++; // StoneWt
    if ($showPurity) $colCount++; // Purity

    // ── Terms ─────────────────────────────────────────────────────────────
    $termsRaw = trim((string) ($quickBill->terms ?? ($snapshot['terms_and_conditions'] ?? '')));
    $defaultTerms = [
        'Goods once sold will not be taken back or exchanged.',
        'Please verify product details before leaving the counter.',
        'All disputes are subject to local jurisdiction.',
        'Invoice is valid only with authorized signature.',
    ];
    $terms = $termsRaw !== ''
        ? array_slice(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $termsRaw)))), 0, 6)
        : $defaultTerms;

    $amountToWords = function (float $amount): string {
        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
            $integer   = (int) floor($amount);
            $decimal   = (int) round(($amount - $integer) * 100);
            $words     = ucfirst((string) $formatter->format($integer));
            if ($decimal > 0) {
                $paiseWords = ucfirst((string) $formatter->format($decimal));
                return "Rupees {$words} and {$paiseWords} paise only";
            }
            return "Rupees {$words} only";
        }
        return 'Rupees ' . number_format($amount, 2, '.', '') . ' only';
    };

    $subtitle  = $billing->shop_subtitle  ?: 'GOLD • SILVER • DIAMOND';
    $tagline   = $billing->custom_tagline ?? '';
    $copyLabel = $billing->invoice_copy_label ?? 'Original';
    $secondSig = $billing->second_signature_label ?? '';
@endphp
    <style>
        @page { size: {{ $pageSize }}; margin: {{ $margin }}; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #111; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: {{ $fontSize }}; line-height: 1.35; }

        .invoice-shell {
            border: 2px solid {{ $accent }};
            padding: 10px;
            min-height: {{ $minHeight }};
            display: flex;
            flex-direction: column;
        }
        .invoice-body  { flex: 1; }
        .invoice-footer { margin-top: auto; padding-top: 4px; }
        .copy-break { page-break-before: always; margin-top: 0; }

        .row     { display: flex; width: 100%; }
        .between { justify-content: space-between; align-items: flex-start; }

        .top-line { font-size: {{ $tier['top'] }}; margin-bottom: 2px; }

        .shop-head {
            text-align: center;
            margin-bottom: 6px;
            border-bottom: 2px solid {{ $accent }};
            padding-bottom: 6px;
        }
        .shop-title    { margin: 0; font-size: {{ $tier['title'] }}; line-height: 1.15; font-weight: 800; letter-spacing: 0.3px; }
        .shop-subtitle { margin: 3px 0 2px; font-size: {{ $tier['sub'] }}; font-weight: 700; letter-spacing: 0.5px; }
        .shop-meta     { margin: 2px 0; font-size: {{ $tier['meta'] }}; }
        .shop-tagline  { margin: 2px 0; font-size: {{ $tier['meta'] }}; color: #555; font-style: italic; }

        .bill-block { border-bottom: 1px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
        .bill-col   { width: 50%; }
        .bill-col.right { padding-left: 14px; }

        .kv   { display: flex; margin-bottom: 2px; }
        .kv .k { min-width: 64px; font-weight: 600; }
        .kv .v { flex: 1; word-break: break-word; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .items-table th,
        .items-table td { border: 1px solid {{ $accent }}; padding: 4px; vertical-align: top; overflow-wrap: break-word; }
        .items-table th { font-weight: 700; background: #f3f3f3; text-align: center; white-space: nowrap; }

        .text-left   { text-align: left; }
        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .strong      { font-weight: 700; }

        .bottom-wrap  { display: flex; gap: 10px; margin-top: 6px; }
        .left-notes   { flex: 1; border-top: 1px solid #111; padding-top: 6px; min-height: 80px; }
        .amount-words { font-size: {{ $fontSize }}; margin-bottom: 8px; }
        .payment-note { min-height: 34px; border-bottom: 1px dotted #111; margin-bottom: 8px; }

        .totals-box { width: 42%; border: 1px solid #111; border-collapse: collapse; align-self: flex-start; }
        .totals-box td { border: 1px solid #111; padding: 5px 6px; font-size: {{ $fontSize }}; }

        .sign-row   { display: flex; gap: 10px; margin-top: 14px; font-size: {{ $fontSize }}; }
        .sign-block { flex: 1; text-align: right; }
        .sign-line  { margin-top: 28px; font-weight: 700; }

        .terms-title { margin: 0 0 3px; font-size: {{ $tier['terms'] }}; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .terms-list  { margin: 0; padding-left: 14px; font-size: {{ $tier['terms'] }}; }
        .terms-list li { margin: 1px 0; }

        .header-center { flex: 1; text-align: center; }
        .invoice-kind  { font-weight: 700; font-size: {{ $tier['kind'] }}; margin: 0 0 2px; letter-spacing: 0.5px; }
        .badge { display: inline-block; border: 2px solid #111; padding: 1px 6px; font-size: 10px; font-weight: 700; margin-left: 4px; }

        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body onload="window.print()">

@for($copy = 1; $copy <= $copyCount; $copy++)
<div class="invoice-shell{{ $copy > 1 ? ' copy-break' : '' }}">
    <div class="invoice-body">
        <div class="row between top-line">
            <div>@if($showGstin && !empty($snapshot['gst_number']))GSTIN: {{ $snapshot['gst_number'] }}@endif</div>
            <div class="strong">{{ $copyCount > 1 ? ($copy === 1 ? 'Customer Copy' : 'Shop Copy') : $copyLabel }}</div>
        </div>

        <div class="row shop-head" style="position: relative; align-items: flex-start;">
            @if($billing?->show_bis_logo)
            <div style="position: absolute; right: 0; top: 0;">
                <img src="{{ asset('images/bis_hallmark_logo.svg') }}" style="height: 60px; width: auto;" alt="BIS Hallmark">
            </div>
            @endif
            <div class="header-center">
                <p class="invoice-kind">GST INVOICE</p>
                <h1 class="shop-title">{{ $shopName }}</h1>
                <p class="shop-subtitle">{{ $subtitle }}</p>
                @if($tagline)<p class="shop-tagline">{{ $tagline }}</p>@endif
                <p class="shop-meta">
                    {{ $snapshot['address_line1'] ?? '' }}
                    @if(!empty($snapshot['address_line2'])), {{ $snapshot['address_line2'] }}@endif
                    @if(!empty($snapshot['city'])), {{ $snapshot['city'] }}@endif
                    @if(!empty($snapshot['state'])), @if(!empty($snapshot['state_code'])){{ $snapshot['state_code'] }}-@endif{{ $snapshot['state'] }}@endif
                    @if(!empty($snapshot['pincode'])) - {{ $snapshot['pincode'] }}@endif
                </p>
                <p class="shop-meta">
                    Phone: {{ $snapshot['phone'] ?? '-' }}
                    @if(!empty($snapshot['shop_whatsapp'])) &nbsp;|&nbsp; WhatsApp: {{ $snapshot['shop_whatsapp'] }}@endif
                    @if(!empty($snapshot['shop_email'])) &nbsp;|&nbsp; {{ $snapshot['shop_email'] }}@endif
                </p>
                @if(!empty($snapshot['shop_registration_number']))<p class="shop-meta">Reg: {{ $snapshot['shop_registration_number'] }}</p>@endif
            </div>
        </div>

        <div class="bill-block row">
            <div class="bill-col">
                <div class="kv"><div class="k">To</div><div class="v">: {{ $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in Customer') }}</div></div>
                @if($showAddr)
                <div class="kv"><div class="k">Address</div><div class="v">: {{ $quickBill->customer_address ?: ($quickBill->customer?->address ?: '—') }}</div></div>
                @endif
                <div class="kv"><div class="k">Mobile</div><div class="v">: {{ $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: '—') }}</div></div>
            </div>
            <div class="bill-col right">
                <div class="kv"><div class="k">Bill No.</div><div class="v">: {{ $quickBill->bill_number }}</div></div>
                <div class="kv"><div class="k">Date</div><div class="v">: {{ $billDate }}</div></div>
                <div class="kv"><div class="k">Mode</div><div class="v">: {{ ucwords(str_replace('_', ' ', $quickBill->pricing_mode)) }}</div></div>
                @if($quickBill->status === \App\Models\QuickBill::STATUS_VOID)
                    <div class="kv"><div class="k">Status</div><div class="v">: <span class="badge">VOID</span></div></div>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 4%;">S.No</th>
                    <th class="text-left" style="width: {{ $descW }}%;">Description</th>
                    <th style="width: 8%;">HSN</th>
                    <th style="width: 6%;">Pc</th>
                    <th style="width: 9%;">Gross Wt.</th>
                    @if($showStone)<th style="width: 9%;">Stone Wt.</th>@endif
                    <th style="width: 9%;">Net Wt.</th>
                    @if($showPurity)<th style="width: 8%;">Purity</th>@endif
                    <th style="width: 9%;">Rate</th>
                    <th style="width: 10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($quickBill->items as $index => $item)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td class="text-left">
                            <div class="strong">{{ $item->description }}</div>
                            <div style="font-size: 10px; color: #444;">{{ $item->metal_type ?: 'Jewellery' }}</div>
                            @php
                                $chargeBreakup = [
                                    'Mk' => (float) ($item->making_charge ?? 0),
                                    'St' => (float) ($item->stone_charge ?? 0),
                                    'Hm' => (float) ($item->hallmark_charge ?? 0),
                                    'Rh' => (float) ($item->rhodium_charge ?? 0),
                                    'Ot' => (float) ($item->other_charge ?? 0),
                                ];
                                $chargeParts = collect($chargeBreakup)
                                    ->filter(fn ($value) => $value > 0)
                                    ->map(fn ($value, $label) => $label . ': ' . number_format($value, 2))
                                    ->values()
                                    ->all();
                            @endphp
                            @if(!empty($chargeParts))
                                <div style="font-size: 9px; color: #444;">{{ implode(' | ', $chargeParts) }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ $item->hsn_code ?: '—' }}</td>
                        <td class="text-center">{{ $item->pcs }}</td>
                        <td class="text-right">{{ number_format((float) $item->gross_weight, 3) }}</td>
                        @if($showStone)<td class="text-right">{{ number_format((float) $item->stone_weight, 3) }}</td>@endif
                        <td class="text-right">{{ number_format((float) $item->net_weight, 3) }}</td>
                        @if($showPurity)<td class="text-center">{{ $item->purity ?: '—' }}</td>@endif
                        <td class="text-right">{{ number_format((float) $item->rate, 2) }}</td>
                        <td class="text-right strong">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        <div class="bottom-wrap">
            <div class="left-notes">
                <div class="amount-words"><span class="strong">₹:</span> {{ $amountToWords((float) $quickBill->total_amount) }}</div>
                <div class="strong" style="margin-bottom: 4px;">Payment Register:</div>
                <div class="payment-note">
                    @forelse($quickBill->payments as $payment)
                        <div>{{ $payment->payment_mode }} — ₹{{ number_format((float) $payment->amount, 2) }}{{ $payment->reference_no ? ' (' . $payment->reference_no . ')' : '' }}</div>
                    @empty
                        <div style="color: #777;">No payment rows recorded.</div>
                    @endforelse
                </div>
            </div>

            <table class="totals-box">
                <tr>
                    <td>Subtotal</td>
                    <td class="text-right">{{ number_format((float) $quickBill->subtotal, 2) }}</td>
                </tr>
                @if((float) $quickBill->discount_amount > 0)
                <tr>
                    <td>Less: Discount</td>
                    <td class="text-right">-{{ number_format((float) $quickBill->discount_amount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td>Taxable Amount</td>
                    <td class="text-right">{{ number_format((float) $quickBill->taxable_amount, 2) }}</td>
                </tr>
                @if($quickBill->pricing_mode !== 'no_gst')
                    @if($igstMode)
                    <tr>
                        <td>Add: IGST {{ number_format($gstRate, 2) }}%</td>
                        <td class="text-right">{{ number_format((float) ($quickBill->cgst_amount + $quickBill->sgst_amount), 2) }}</td>
                    </tr>
                    @else
                    <tr>
                        <td>Add: CGST {{ number_format($halfRate, 2) }}%</td>
                        <td class="text-right">{{ number_format((float) $quickBill->cgst_amount, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Add: SGST {{ number_format($halfRate, 2) }}%</td>
                        <td class="text-right">{{ number_format((float) $quickBill->sgst_amount, 2) }}</td>
                    </tr>
                    @endif
                @endif
                @if((float) $quickBill->round_off != 0)
                <tr>
                    <td>Round Off</td>
                    <td class="text-right">{{ $quickBill->round_off >= 0 ? '+' : '' }}{{ number_format((float) $quickBill->round_off, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td>Receipt Amount</td>
                    <td class="text-right">{{ number_format((float) $quickBill->paid_amount, 2) }}</td>
                </tr>
                <tr>
                    <td class="strong">Net Amount</td>
                    <td class="text-right strong">₹{{ number_format((float) $quickBill->total_amount, 2) }}</td>
                </tr>
            </table>
        </div>
    </div>{{-- end .invoice-body --}}

    <div class="invoice-footer">
        <div class="sign-row">
            @if($secondSig)
            <div class="sign-block" style="text-align: left;">
                <div class="sign-line">{{ $secondSig }}</div>
                <div>(Prepared by)</div>
            </div>
            @endif
            <div class="sign-block">
                <div class="strong">FOR {{ $shopName }}</div>
                @if($billing?->show_digital_signature && $billing?->digital_signature_path)
                <div style="margin-top: 6px;">
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($billing->digital_signature_path) }}"
                         style="max-height: 60px; max-width: 160px;" alt="Signature">
                </div>
                @endif
                <div class="sign-line">Authorised Signatory</div>
                <div>(of selling Dealer/Manager/Agent)</div>
            </div>
        </div>

        <div style="display:flex; gap:16px; border-top:1px solid #111; margin-top:10px; padding-top:8px;">
            <div style="flex:1;">
                <h4 class="terms-title">T &amp; C Apply:</h4>
                <ul class="terms-list">
                    @foreach($terms as $term)
                        <li>{{ $term }}</li>
                    @endforeach
                </ul>
            </div>
            @if(!empty($snapshot['upi_id']) || !empty($snapshot['bank_details']))
            <div style="flex:1; border-left:1px dashed #aaa; padding-left:14px; font-size:10.5px;">
                <div class="strong" style="margin-bottom:4px;">Payment Details:</div>
                @if(!empty($snapshot['upi_id']))
                    <div style="margin:2px 0;"><span class="strong">UPI:</span> {{ $snapshot['upi_id'] }}</div>
                @endif
                @if(!empty($snapshot['bank_details']))
                    <div style="margin:2px 0; white-space:pre-line;"><span class="strong">Bank:</span> {{ $snapshot['bank_details'] }}</div>
                @endif
            </div>
            @endif
        </div>
    </div>{{-- end .invoice-footer --}}
</div>
@endfor

</body>
</html>
