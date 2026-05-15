<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quickBill->bill_number }}</title>
@php
    $snapshot = $quickBill->shop_snapshot ?? [];
    $shop     = auth()->user()->shop;
    $shopName = $snapshot['name'] ?? $shop?->name ?? 'Jewellery Store';
    $billing  = $shop?->billingSettings;
    $billDate = $quickBill->bill_date?->format('d/m/Y');
    $gstRate  = (float) $quickBill->gst_rate;
    $halfRate = $gstRate / 2;

    // ── Appearance (matches invoice_print.blade.php) ──────────────────────
    $accent    = $billing?->theme_color   ?? '#111111';
    $fontTiers = [
        'compact' => ['body'=>'8.5px',  'title'=>'17px', 'sub'=>'9px',  'kind'=>'10px', 'meta'=>'8px',   'terms'=>'7.5px', 'top'=>'8px'  ],
        'normal'  => ['body'=>'9.5px',  'title'=>'20px', 'sub'=>'10px', 'kind'=>'11px', 'meta'=>'9px',   'terms'=>'8px',   'top'=>'8.5px'],
        'large'   => ['body'=>'10.5px', 'title'=>'23px', 'sub'=>'11px', 'kind'=>'12px', 'meta'=>'10px',  'terms'=>'9px',   'top'=>'9.5px'],
    ];
    $tier      = $fontTiers[$billing?->font_size ?? 'normal'] ?? $fontTiers['normal'];
    $fontSize  = $tier['body'];
    $pageSizes = ['a4' => 'A4', 'a5' => 'A5', 'thermal' => '80mm auto'];
    $margins   = ['a4' => '12mm', 'a5' => '8mm', 'thermal' => '3mm'];
    // See invoice_print.blade.php for the rationale: shell min-height kept
    // below raw page content area so browser print headers/footers don't
    // push the layout onto a second page.
    $minHts    = ['a4' => '250mm', 'a5' => '175mm', 'thermal' => '0'];
    $paperKey  = $billing?->paper_size ?? 'a4';
    $pageSize  = $pageSizes[$paperKey] ?? 'A4';
    $margin    = $margins[$paperKey]   ?? '12mm';
    $minHeight = $minHts[$paperKey]    ?? '273mm';

    // ── Display toggles (shop-wide settings, same as invoice_print) ──────
    $showStone  = $billing?->show_stone_columns   ?? true;
    $showPurity = $billing?->show_purity          ?? true;
    $showAddr   = $billing?->show_customer_address ?? true;
    $igstMode   = $billing?->igst_mode            ?? false;
    $copyCount  = (int) ($billing?->copy_count    ?? 1);
    $copyCount  = max(1, min(2, $copyCount));

    // Items table column widths — redistribute hidden cols to Description.
    $descW = 26;
    if (!$showStone)  $descW += 9;
    if (!$showPurity) $descW += 8;

    // Items table padded to a fixed minimum-row count per paper so the
    // skeleton doesn't shrink on short bills. Tuned to fit single-page
    // alongside the 9 payment slots + 3-column footer.
    $minimumRowsByPaper = [
        'a4' => 26,
        'a5' => 16,
        'thermal' => 0,
    ];
    $minimumPrintableRows = $minimumRowsByPaper[$paperKey] ?? 26;

    // ── Totals (mirror the invoice's 8-row fixed-skeleton box) ───────────
    $subtotal      = (float) ($quickBill->subtotal       ?? 0);
    $discount      = (float) ($quickBill->discount_amount ?? 0);
    $taxable       = (float) ($quickBill->taxable_amount  ?? 0);
    $cgst          = (float) ($quickBill->cgst_amount     ?? 0);
    $sgst          = (float) ($quickBill->sgst_amount     ?? 0);
    $gstTotal      = $cgst + $sgst;
    $extraCharges  = 0.0; // Quick bills bake per-item charges into line_total; this row stays 0 for skeleton parity.
    $beforeTax     = $subtotal + $extraCharges - $discount;
    $afterTax      = $beforeTax + $gstTotal;
    $roundOff      = (float) ($quickBill->round_off       ?? 0);
    $receiptAmount = (float) ($quickBill->paid_amount     ?? 0);
    $netTotal      = (float) ($quickBill->total_amount    ?? 0);
    $noGst         = ($quickBill->pricing_mode ?? null) === 'no_gst';

    // ── Terms ────────────────────────────────────────────────────────────
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

    $subtitle  = $billing?->shop_subtitle  ?: 'GOLD • SILVER • DIAMOND';
    $tagline   = $billing?->custom_tagline ?? '';
    $copyLabel = $billing?->invoice_copy_label ?? 'Original';
    $secondSig = $billing?->second_signature_label ?? '';
    $showDigitalSignature = (bool) ($billing?->show_digital_signature && !empty($billing?->digital_signature_path));

    // Payment mode labels (mirror invoice_print payment-note rendering).
    $modeLabels = [
        'cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank Transfer',
        'wallet' => 'Wallet', 'old_gold' => 'Old Gold',
        'old_silver' => 'Old Silver', 'emi' => 'EMI',
        'scheme_redemption' => 'Scheme Redemption', 'other' => 'Other',
    ];
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
        .invoice-body { flex: 1; }
        .copy-break   { page-break-before: always; margin-top: 0; }

        /* Bottom 3-column footer row. */
        .invoice-footer {
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid {{ $accent }};
            display: flex;
            gap: 10px;
            page-break-inside: avoid;
            break-inside: avoid-page;
        }
        .footer-col { flex: 1; min-width: 0; min-height: 80px; }
        .footer-col + .footer-col { border-left: 1px solid #ddd; padding-left: 10px; }
        .footer-title {
            margin: 0 0 4px;
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .footer-body { font-size: {{ $tier['terms'] }}; line-height: 1.3; }
        .footer-body > div { margin: 0 0 2px; }
        .footer-empty { color: #999; font-style: italic; }
        .footer-col--sign { text-align: right; }
        .footer-sign-second { margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #ddd; }
        .footer-sign-image { margin: 6px 0 2px; }
        .footer-sign-image img { max-height: 48px; max-width: 100%; }
        .footer-sign-spacer { min-height: 32px; }
        .footer-sign-line { margin-top: 4px; font-weight: 700; }
        .footer-sign-role { font-size: calc({{ $tier['terms'] }} - 0.5px); color: #555; }

        .row     { display: flex; width: 100%; }
        .between { justify-content: space-between; align-items: flex-start; }

        .top-line { font-size: {{ $tier['top'] }}; margin-bottom: 2px; min-height: 12px; }

        .shop-head {
            text-align: center;
            margin-bottom: 6px;
            border-bottom: 2px solid {{ $accent }};
            padding-bottom: 6px;
        }
        /* Cap shop title to one line so a long shop name can't push the
           header down and force a page break. */
        .shop-title    {
            margin: 0; font-size: calc({{ $tier['title'] }} + 6px);
            line-height: 1.15; font-weight: 900; letter-spacing: 0.3px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .shop-subtitle { margin: 3px 0 2px; font-size: {{ $tier['sub'] }}; font-weight: 700; letter-spacing: 0.5px; }
        .shop-meta     { margin: 2px 0; font-size: {{ $tier['meta'] }}; }
        .shop-tagline  { margin: 2px 0; font-size: {{ $tier['meta'] }}; color: #555; font-style: italic; }

        .bill-block { border-bottom: 1px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
        .bill-col   { width: 50%; }
        .bill-col.right { padding-left: 14px; }

        .kv    { display: flex; margin-bottom: 2px; }
        .kv .k { min-width: 64px; font-weight: 600; }
        .kv .v { flex: 1; word-break: break-word; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .items-table th,
        .items-table td { border: 1px solid {{ $accent }}; padding: 4px; vertical-align: top; overflow-wrap: break-word; }
        .items-table th { font-weight: 700; background: #f3f3f3; text-align: center; white-space: nowrap; }
        .items-spacer-row td {
            height: 10px; padding-top: 0; padding-bottom: 0;
            border-top: 0; border-bottom: 0;
        }
        .items-spacer-row.is-last td { border-bottom: 1px solid {{ $accent }}; }

        .text-left   { text-align: left; }
        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .strong      { font-weight: 700; }

        .bottom-wrap { display: flex; gap: 10px; margin-top: 6px; }
        .left-notes  { flex: 1; border-top: 1px solid #111; padding-top: 4px; min-height: 60px; }

        /* Dedicated reserved area for Amount in Words. */
        .amount-words-block { margin-bottom: 8px; padding-bottom: 4px; min-height: 36px; }
        .amount-words-title {
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
            margin-bottom: 3px;
        }
        .amount-words-body { font-size: {{ $fontSize }}; line-height: 1.35; }

        /* Total Receipt / Payment Note — reserves space for all POS payment
           modes (9) so the bottom-wrap height is the same on every bill. */
        .payment-note { min-height: 135px; margin-bottom: 6px; }
        .payment-note-title {
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
            margin-bottom: 3px;
        }
        .payment-row { min-height: 14px; line-height: 14px; margin-bottom: 2px; }
        .payment-row-blank { color: transparent; }

        .totals-box    { width: 42%; border: 1px solid #111; border-collapse: collapse; align-self: flex-start; }
        .totals-box td { border: 1px solid #111; padding: 5px 6px; font-size: {{ $fontSize }}; }

        .header-center { flex: 1; text-align: center; }
        /* Heading-kind class removed — quick bills carry no document-type title. */
        .badge { display: inline-block; border: 2px solid #111; padding: 1px 6px; font-size: 10px; font-weight: 700; margin-left: 4px; }

        /* Mobile screen: relax fixed heights so the preview is scrollable. */
        @media screen and (max-width: 768px) {
            body { font-size: 12px; line-height: 1.45; background: #f5f7fb; padding: 8px; }
            .invoice-shell { min-height: 0; border-width: 1px; padding: 10px; }
            .top-line     { min-height: 0; font-size: 11px; }
            .shop-title   { font-size: 22px; }
            .shop-subtitle{ font-size: 11px; }
            .shop-meta, .shop-tagline { font-size: 10.5px; }
            .bill-block   { display: block; padding-bottom: 8px; margin-bottom: 10px; }
            .bill-col, .bill-col.right { width: 100%; padding-left: 0; }
            .bill-col + .bill-col { margin-top: 6px; }
            .kv { margin-bottom: 3px; }
            .kv .k { min-width: 72px; font-size: 11px; }
            .kv .v { font-size: 12px; line-height: 1.4; }
            .items-table {
                display: block; width: 100%; overflow-x: auto;
                -webkit-overflow-scrolling: touch; white-space: nowrap;
            }
            .items-table th, .items-table td { padding: 6px 5px; font-size: 11.5px; line-height: 1.35; }
            .bottom-wrap { flex-direction: column; gap: 8px; }
            .totals-box  { width: 100%; }
            .totals-box td { font-size: 12px; padding: 6px; }
            .amount-words-block { min-height: 0; }
            .payment-note       { min-height: 0; }
            .payment-row-blank  { display: none; }
            .invoice-footer { flex-direction: column; gap: 12px; }
            .footer-col + .footer-col {
                border-left: 0; border-top: 1px solid #eee;
                padding-left: 0; padding-top: 8px;
            }
            .footer-col { min-height: 0; }
            .footer-col--sign { text-align: left; }
        }

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
        {{-- Top line: copy label only. GSTIN deliberately omitted on Quick Bill. --}}
        <div class="row between top-line">
            <div></div>
            <div class="strong">{{ $copyCount > 1 ? ($copy === 1 ? 'Customer Copy' : 'Shop Copy') : $copyLabel }}</div>
        </div>

        <div class="row shop-head" style="position: relative; align-items: flex-start;">
            @if($billing?->show_bis_logo)
            <div style="position: absolute; right: 0; top: 0;">
                <img src="{{ asset('images/bis_hallmark_logo.svg') }}" style="height: 60px; width: auto;" alt="BIS Hallmark">
            </div>
            @endif
            <div class="header-center">
                <h1 class="shop-title">{{ $shopName }}</h1>
                <p class="shop-subtitle">{{ $subtitle }}</p>
                <p class="shop-tagline">{!! $tagline !== '' ? e($tagline) : '&nbsp;' !!}</p>
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
                <div class="kv"><div class="k">Mode</div><div class="v">: {{ ucwords(str_replace('_', ' ', $quickBill->pricing_mode)) }}@if($quickBill->status === \App\Models\QuickBill::STATUS_VOID) <span class="badge">VOID</span>@endif</div></div>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 4%;">S.No</th>
                    <th class="text-left" style="width: {{ $descW }}%;">Description</th>
                    <th style="width: 8%;">HSN</th>
                    <th style="width: 5%;">Pc</th>
                    <th style="width: 9%;">Gross Wt.</th>
                    @if($showStone)
                    <th style="width: 9%;">Stone Wt.</th>
                    <th style="width: 9%;">Stone Val.</th>
                    @endif
                    <th style="width: 9%;">Net Wt.</th>
                    @if($showPurity)<th style="width: 7%;">Purity</th>@endif
                    <th style="width: 9%;">Rate</th>
                    <th style="width: 10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($quickBill->items as $index => $item)
                    @php
                        $chargeBreakup = [
                            'Mk' => (float) ($item->making_charge   ?? 0),
                            'St' => (float) ($item->stone_charge    ?? 0),
                            'Hm' => (float) ($item->hallmark_charge ?? 0),
                            'Rh' => (float) ($item->rhodium_charge  ?? 0),
                            'Ot' => (float) ($item->other_charge    ?? 0),
                        ];
                        $chargeParts = collect($chargeBreakup)
                            ->filter(fn ($value) => $value > 0)
                            ->map(fn ($value, $label) => $label . ': ' . number_format($value, 2))
                            ->values()
                            ->all();
                    @endphp
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td class="text-left">
                            <div class="strong">{{ $item->description }}</div>
                            <div style="font-size: 10px; color: #444;">{{ $item->metal_type ?: 'Jewellery' }}</div>
                            @if(!empty($chargeParts))
                                <div style="font-size: 9px; color: #555;">{{ implode(' | ', $chargeParts) }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ $item->hsn_code ?: '—' }}</td>
                        <td class="text-center">{{ $item->pcs }}</td>
                        <td class="text-right">{{ number_format((float) $item->gross_weight, 3) }}</td>
                        @if($showStone)
                        <td class="text-right">{{ number_format((float) $item->stone_weight, 3) }}</td>
                        <td class="text-right">{{ number_format((float) ($item->stone_charge ?? 0), 2) }}</td>
                        @endif
                        <td class="text-right">{{ number_format((float) $item->net_weight, 3) }}</td>
                        @if($showPurity)<td class="text-center">{{ $item->purity ?: '—' }}</td>@endif
                        <td class="text-right">{{ number_format((float) $item->rate, 2) }}</td>
                        <td class="text-right strong">{{ number_format((float) $item->line_total, 2) }}</td>
                    </tr>
                    @php
                        $isLastItemRow = $index === ($quickBill->items->count() - 1);
                        $fillerRows = $isLastItemRow ? max(0, $minimumPrintableRows - $quickBill->items->count()) : 0;
                    @endphp
                    @if($isLastItemRow && $fillerRows > 0)
                        @for($f = 0; $f < $fillerRows; $f++)
                        <tr class="items-spacer-row{{ $f === ($fillerRows - 1) ? ' is-last' : '' }}">
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            @if($showStone)<td>&nbsp;</td><td>&nbsp;</td>@endif
                            <td>&nbsp;</td>
                            @if($showPurity)<td>&nbsp;</td>@endif
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                        </tr>
                        @endfor
                    @endif
                @empty
                    @php $emptyFiller = max(0, $minimumPrintableRows - 1); @endphp
                    <tr><td colspan="{{ 7 + ($showStone ? 2 : 0) + ($showPurity ? 1 : 0) }}" class="text-center">No items.</td></tr>
                    @for($f = 0; $f < $emptyFiller; $f++)
                    <tr class="items-spacer-row{{ $f === ($emptyFiller - 1) ? ' is-last' : '' }}">
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        @if($showStone)<td>&nbsp;</td><td>&nbsp;</td>@endif
                        <td>&nbsp;</td>
                        @if($showPurity)<td>&nbsp;</td>@endif
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                    @endfor
                @endforelse
            </tbody>
        </table>

        <div class="bottom-wrap">
            <div class="left-notes">
                <div class="amount-words-block">
                    <div class="amount-words-title">Amount in Words</div>
                    <div class="amount-words-body">{{ $amountToWords($netTotal) }}</div>
                </div>
                <div class="payment-note-title">Total Receipt / Payment Note</div>
                <div class="payment-note">
                    @php
                        $maxPaymentRows = 9;
                        $paymentsToShow = $quickBill->payments->take($maxPaymentRows);
                        $blankSlots     = max(0, $maxPaymentRows - $paymentsToShow->count());
                    @endphp
                    @foreach($paymentsToShow as $payment)
                        <div class="payment-row">
                            <span class="strong">{{ $modeLabels[$payment->payment_mode] ?? ucwords(str_replace('_', ' ', (string) $payment->payment_mode)) }}:</span>
                            ₹ {{ number_format((float) $payment->amount, 2) }}
                            @if(!empty($payment->reference_no))
                                <span style="font-size: 11px; color: #444;">— {{ $payment->reference_no }}</span>
                            @endif
                        </div>
                    @endforeach
                    @for($i = 0; $i < $blankSlots; $i++)
                        <div class="payment-row payment-row-blank">&nbsp;</div>
                    @endfor
                </div>
            </div>

            {{-- Fixed-skeleton totals box: matches invoice_print exactly. --}}
            <table class="totals-box">
                <tr>
                    <td>Total Amount Before Tax</td>
                    <td class="text-right">{{ number_format($beforeTax, 2) }}</td>
                </tr>
                @if($igstMode)
                <tr>
                    <td>Add: IGST {{ number_format($gstRate, 2) }}%</td>
                    <td class="text-right">{{ number_format($gstTotal, 2) }}</td>
                </tr>
                @else
                <tr>
                    <td>Add: CGST {{ number_format($halfRate, 2) }}%</td>
                    <td class="text-right">{{ number_format($cgst, 2) }}</td>
                </tr>
                <tr>
                    <td>Add: SGST {{ number_format($halfRate, 2) }}%</td>
                    <td class="text-right">{{ number_format($sgst, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td>Hallmark / Misc. / Other Charges</td>
                    <td class="text-right">{{ number_format($extraCharges, 2) }}</td>
                </tr>
                <tr>
                    <td>Less: Discount</td>
                    <td class="text-right">{{ $discount > 0 ? '-' : '' }}{{ number_format($discount, 2) }}</td>
                </tr>
                <tr>
                    <td>Total Amount</td>
                    <td class="text-right">{{ number_format($afterTax, 2) }}</td>
                </tr>
                <tr>
                    <td>Receipt Amount</td>
                    <td class="text-right">{{ number_format($receiptAmount, 2) }}</td>
                </tr>
                <tr>
                    <td>Round Off</td>
                    <td class="text-right">{{ $roundOff > 0 ? '+' : ($roundOff < 0 ? '' : '') }}{{ number_format($roundOff, 2) }}</td>
                </tr>
                <tr>
                    <td class="strong">Net Amount</td>
                    <td class="text-right strong">₹{{ number_format($netTotal, 2) }}</td>
                </tr>
            </table>
        </div>
    </div>{{-- end .invoice-body --}}

    {{-- Bottom 3-column footer row: Payment Details | T & C | Authorised Signatory. --}}
    <div class="invoice-footer">
        <div class="footer-col footer-col--payment">
            <h4 class="footer-title">Payment Details</h4>
            <div class="footer-body">
                @if(!empty($snapshot['upi_id']))
                    <div><span class="strong">UPI:</span> {{ $snapshot['upi_id'] }}</div>
                @endif
                @if(!empty($snapshot['bank_name']) || !empty($snapshot['bank_account_number']))
                    @if(!empty($snapshot['bank_account_holder']))
                        <div><span class="strong">A/C Holder:</span> {{ $snapshot['bank_account_holder'] }}</div>
                    @endif
                    @if(!empty($snapshot['bank_name']))
                        <div><span class="strong">Bank:</span> {{ $snapshot['bank_name'] }}</div>
                    @endif
                    @if(!empty($snapshot['bank_account_number']))
                        <div><span class="strong">A/C No:</span> {{ $snapshot['bank_account_number'] }}</div>
                    @endif
                    @if(!empty($snapshot['bank_ifsc']))
                        <div><span class="strong">IFSC:</span> {{ $snapshot['bank_ifsc'] }}</div>
                    @endif
                    @if(!empty($snapshot['bank_account_type']))
                        <div><span class="strong">Type:</span> {{ ucfirst($snapshot['bank_account_type']) }}</div>
                    @endif
                    @if(!empty($snapshot['bank_branch']))
                        <div><span class="strong">Branch:</span> {{ $snapshot['bank_branch'] }}</div>
                    @endif
                @elseif(!empty($snapshot['bank_details']))
                    <div style="white-space: pre-line;"><span class="strong">Bank:</span> {{ $snapshot['bank_details'] }}</div>
                @endif
                @if(empty($snapshot['upi_id']) && empty($snapshot['bank_name']) && empty($snapshot['bank_account_number']) && empty($snapshot['bank_details']))
                    <div class="footer-empty">—</div>
                @endif
            </div>
        </div>

        <div class="footer-col footer-col--terms">
            <h4 class="footer-title">Terms &amp; Conditions</h4>
            <div class="footer-body">
                @foreach($terms as $term)
                    <div>{{ $term }}</div>
                @endforeach
            </div>
        </div>

        <div class="footer-col footer-col--sign">
            <h4 class="footer-title">For {{ $shopName }}</h4>
            <div class="footer-body">
                @if($secondSig)
                <div class="footer-sign-second">
                    <div class="strong">{{ $secondSig }}</div>
                    <div class="footer-sign-role">(Prepared by)</div>
                </div>
                @endif
                @if($showDigitalSignature)
                <div class="footer-sign-image">
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($billing?->digital_signature_path) }}"
                         alt="Signature">
                </div>
                @else
                <div class="footer-sign-spacer">&nbsp;</div>
                @endif
                <div class="footer-sign-line">Authorised Signatory</div>
                <div class="footer-sign-role">(of selling Dealer / Manager / Agent)</div>
            </div>
        </div>
    </div>{{-- end .invoice-footer --}}
</div>
@endfor

</body>
</html>
