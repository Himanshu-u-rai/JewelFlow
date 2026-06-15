<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
@php
    $shop    = auth()->user()->shop;
    $billing = $shop?->billingSettings;

    // ── Appearance ────────────────────────────────────────────────────────
    $accent    = $billing?->theme_color   ?? '#111111';
    // Font-size tiers: body / shop-name / subtitle / invoice-kind / meta / terms / top-line
    $fontTiers = [
        'compact' => ['body'=>'8.5px',  'title'=>'17px', 'sub'=>'9px',  'kind'=>'10px', 'meta'=>'8px',   'terms'=>'7.5px', 'top'=>'8px'  ],
        'normal'  => ['body'=>'9.5px',  'title'=>'20px', 'sub'=>'10px', 'kind'=>'11px', 'meta'=>'9px',   'terms'=>'8px',   'top'=>'8.5px'],
        'large'   => ['body'=>'10.5px', 'title'=>'23px', 'sub'=>'11px', 'kind'=>'12px', 'meta'=>'10px',  'terms'=>'9px',   'top'=>'9.5px'],
    ];
    $tier      = $fontTiers[$billing?->font_size ?? 'normal'] ?? $fontTiers['normal'];
    $fontSize  = $tier['body'];
    $pageSizes = ['a4' => 'A4', 'a5' => 'A5', 'thermal' => '80mm auto'];
    $margins   = ['a4' => '12mm', 'a5' => '8mm', 'thermal' => '3mm'];
    // Shell min-height kept below the raw page-content area so the invoice
    // still fits when the browser adds its default print headers/footers
    // (URL + page number — ~24mm on Chrome with "Headers and footers" ON).
    // A4 raw content: 297 − 24mm margins = 273mm; we use 250mm to absorb the
    // browser strip. A5 raw: 210 − 16mm = ~190mm; we use 175mm.
    $minHts    = ['a4' => '250mm', 'a5' => '175mm', 'thermal' => '0'];
    $paperKey  = $billing?->paper_size ?? 'a4';
    $pageSize  = $pageSizes[$paperKey] ?? 'A4';
    $margin    = $margins[$paperKey]   ?? '12mm';
    $minHeight = $minHts[$paperKey]    ?? '273mm';

    // ── Column widths (redistribute hidden cols to Description) ───────────
    $showHuid    = $billing?->show_huid          ?? true;
    $showStone   = $billing?->show_stone_columns ?? true;
    $showPurity  = $billing?->show_purity        ?? true;
    $showGstin   = $billing?->show_gstin         ?? true;
    $showAddr    = $billing?->show_customer_address ?? true;
    $showIdPan   = $billing?->show_customer_id_pan  ?? true;
    $igstMode    = $billing?->igst_mode          ?? false;
    $copyCount   = (int) ($billing?->copy_count  ?? 1);
    $copyCount   = max(1, min(2, $copyCount));

    $descW = 26;
    if (!$showHuid)  $descW += 8;
    if (!$showStone) $descW += 16;
    if (!$showPurity) $descW += 7;

    $colCount = 5; // S.No, Desc, Pc, NetWt, Rate, Amount — wait, always present:
    // S.No(1) + Desc(1) + Pc(1) + GrossWt(1) + NetWt(1) + Rate(1) + Amount(1) = 7
    $colCount = 7;
    if ($showHuid)  $colCount++;
    if ($showStone) $colCount += 2; // StoneWt + StoneVal
    if ($showPurity) $colCount++;
    // Items table minimum padded rows per paper size. Tuned so the items
    // table + new bottom structure (9 payment slots + 3-column footer) all
    // fit on one printed page even with the browser's default headers/footers
    // enabled. Anything taller spills onto a second page in print preview.
    $minimumRowsByPaper = [
        'a4' => 26,
        'a5' => 16,
        'thermal' => 0,
    ];
    $minimumPrintableRows = $minimumRowsByPaper[$paperKey] ?? 26;

    // ── Invoice calculations ───────────────────────────────────────────────
    $customer       = $invoice->customer;
    $isRepairInvoice = str_starts_with((string) $invoice->invoice_number, 'REP-') || $invoice->items->isEmpty();
    $repair = null;
    if ($isRepairInvoice) {
        $repairLog = \App\Models\AuditLog::where('shop_id', auth()->user()->shop_id)
            ->where('action', 'repair_deliver')
            ->where('model_type', 'repair')
            ->latest()
            ->take(300)
            ->get()
            ->first(function ($log) use ($invoice) {
                return (int) data_get($log->data, 'invoice_id') === (int) $invoice->id;
            });
        if ($repairLog) {
            $repair = \App\Models\Repair::where('shop_id', auth()->user()->shop_id)->find($repairLog->model_id);
        }
    }

    $payments      = $invoice->payments ?? collect();
    $receiptAmount = (float) $payments->sum('amount');
    $extraCharges  = (float) ($invoice->wastage_charge ?? 0);
    $discount      = (float) ($invoice->discount       ?? 0);
    $subtotal      = (float) ($invoice->subtotal        ?? 0);
    $gst           = (float) ($invoice->gst             ?? 0);
    $roundOff      = (float) ($invoice->round_off       ?? 0);
    $beforeTax     = $subtotal + $extraCharges - $discount;
    $afterTax      = $beforeTax + $gst;
    $cgst          = $gst / 2;
    $sgst          = $gst / 2;
    $gstHalfRate   = (float) ($invoice->gst_rate        ?? 0) / 2;

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

    $termsRaw     = trim((string) ($billing?->terms_and_conditions ?? ''));
    $defaultTerms = \App\Models\ShopBillingSettings::defaultTerms();
    $terms = $termsRaw !== ''
        ? array_slice(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $termsRaw)))), 0, 6)
        : $defaultTerms;

    $subtitle  = $billing?->shop_subtitle   ?: 'GOLD • SILVER • DIAMOND';
    $tagline   = $billing?->custom_tagline  ?? '';
    $copyLabel = $billing?->invoice_copy_label ?? 'Original';
    $secondSig = $billing?->second_signature_label ?? '';
    $showDigitalSignature = (bool) ($billing?->show_digital_signature && !empty($billing?->digital_signature_path));
    $stateCode = trim((string) ($shop?->state_code ?? ''));
    $stateName = trim((string) ($shop?->state ?? ''));
    $stateAndCode = $stateCode !== '' && $stateName !== ''
        ? "{$stateCode} - {$stateName}"
        : ($stateName !== '' ? $stateName : ($stateCode !== '' ? $stateCode : '—'));

    $hasPaymentDetails = !empty($billing?->upi_id)
        || !empty($billing?->bank_name)
        || !empty($billing?->bank_account_number)
        || !empty($billing?->bank_details);

    // HSN helper: resolve per metal type (with a legacy category fallback) via the
    // single source of truth on ShopBillingSettings, so platinum/copper get their
    // own HSN instead of silently inheriting gold's.
    $hsnFor = function (?string $metalType, ?string $category = null) use ($billing): string {
        return $billing
            ? $billing->hsnForMetal($metalType, $category)
            : (\App\Models\ShopBillingSettings::HSN_DEFAULTS[strtolower((string) $metalType)] ?? '7113');
    };

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
        /* Bottom 3-column footer row. Locked at the bottom of the page via
           .invoice-shell's flex layout (.invoice-body grows to fill the gap).
           Each column is a dedicated, always-rendered section. */
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
        .footer-col + .footer-col {
            border-left: 1px solid #ddd;
            padding-left: 10px;
        }
        .footer-title {
            margin: 0 0 4px;
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .footer-body {
            font-size: {{ $tier['terms'] }};
            line-height: 1.3;
        }
        .footer-body > div { margin: 0 0 2px; }
        /* Numbered T&C points with a hanging indent so wrapped lines align
           under the text, not the number. */
        .footer-term { display: flex; gap: 4px; align-items: baseline; }
        .footer-term-num { flex: 0 0 auto; color: #555; font-weight: 600; }
        .footer-empty { color: #999; font-style: italic; }
        .footer-col--sign { text-align: right; }
        .footer-sign-second { margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #ddd; }
        .footer-sign-image { margin: 6px 0 2px; }
        .footer-sign-image img { max-height: 48px; max-width: 100%; }
        .footer-sign-spacer { min-height: 32px; }
        .footer-sign-line { margin-top: 4px; font-weight: 700; }
        .footer-sign-role { font-size: calc({{ $tier['terms'] }} - 0.5px); color: #555; }

        .copy-break { page-break-before: always; margin-top: 0; }

        .row { display: flex; width: 100%; }
        .between { justify-content: space-between; align-items: flex-start; }

        .top-line { font-size: {{ $tier['top'] }}; margin-bottom: 2px; min-height: 12px; }

        .shop-head {
            text-align: center;
            margin-bottom: 6px;
            border-bottom: 2px solid {{ $accent }};
            padding-bottom: 6px;
        }
        /* Cap shop title to one line so an unusually long shop name (or
           a misconfigured value) can't push the entire header down and
           force a page break. */
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

        .kv   { display: flex; margin-bottom: 2px; }
        .kv .k { min-width: 64px; font-weight: 600; }
        .kv .v { flex: 1; word-break: break-word; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
        .items-table th,
        .items-table td { border: 1px solid {{ $accent }}; padding: 4px; vertical-align: top; overflow-wrap: break-word; }
        .items-table th { font-weight: 700; background: #f3f3f3; text-align: center; white-space: nowrap; }
        .items-spacer-row td {
            height: 10px;
            padding-top: 0;
            padding-bottom: 0;
            border-top: 0;
            border-bottom: 0;
        }
        .items-spacer-row.is-last td {
            border-bottom: 1px solid {{ $accent }};
        }

        .text-left   { text-align: left; }
        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .strong      { font-weight: 700; }

        .bottom-wrap { display: flex; gap: 10px; margin-top: 6px; }
        .left-notes  { flex: 1; border-top: 1px solid #111; padding-top: 4px; min-height: 60px; }
        /* Dedicated area for Amount in Words. Reserves vertical space so even
           the shortest amount leaves room for a long amount (two-line wrap)
           without shifting the layout below. Clean — no background or border
           chrome; just a small uppercase label with the body text under it. */
        .amount-words-block {
            margin-bottom: 8px;
            padding-bottom: 4px;
            min-height: 36px;
        }
        .amount-words-title {
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
            margin-bottom: 3px;
        }
        .amount-words-body { font-size: {{ $fontSize }}; line-height: 1.35; }
        /* Legacy single-line amount-words rule retained for any caller that
           still uses the older class — new template uses .amount-words-block. */
        .amount-words { font-size: {{ $fontSize }}; margin-bottom: 8px; }
        /* Payment Note block — reserves space for every POS payment mode
           (cash, upi, bank, wallet, old_gold, old_silver, emi,
           scheme_redemption, other = 9). Real payments fill from the top;
           remaining rows render blank but height-preserved so the bottom-wrap
           stays the same shape across all invoices. */
        .payment-note { min-height: 135px; margin-bottom: 6px; }
        .payment-note-title {
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
            margin-bottom: 3px;
        }
        /* Each payment row gets a locked height so 1-payment invoices and
           4-payment invoices have identical bottom-wrap heights. */
        .payment-row { min-height: 14px; line-height: 14px; margin-bottom: 2px; }
        .payment-row-blank { color: transparent; }
        .note-meta {
            border-top: 1px solid #111;
            padding-top: 4px;
            margin-top: 4px;
            page-break-inside: avoid;
            break-inside: avoid-page;
        }
        .note-meta-block { margin-bottom: 4px; }
        .note-meta-block:last-child { margin-bottom: 0; }
        .note-meta-title {
            margin: 0 0 3px;
            font-size: {{ $tier['terms'] }};
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .note-meta-body {
            min-height: 14px;
            font-size: {{ $tier['terms'] }};
            line-height: 1.15;
            padding-left: 2px;
        }
        .note-meta-body > div { margin: 0 0 2px; }
        .note-meta-body > div:last-child { margin-bottom: 0; }
        /* Bank / UPI details slot stays at a fixed minimum height even when
           the shop has only configured one or two of these fields, so the
           overall bottom-wrap section keeps the same shape across shops. */
        .note-meta-body.payment { min-height: 70px; }

        .totals-box { width: 42%; border: 1px solid #111; border-collapse: collapse; align-self: flex-start; }
        .totals-box td { border: 1px solid #111; padding: 5px 6px; font-size: {{ $fontSize }}; }

        /* Legacy .sign-row was replaced by the 3-column .invoice-footer. */

        .header-center { flex: 1; text-align: center; }
        .invoice-kind  { font-weight: 700; font-size: {{ $tier['kind'] }}; margin: 0 0 2px; letter-spacing: 0.5px; }

        /* Mobile screen view only: improve readability without affecting print output */
        @media screen and (max-width: 768px) {
            body {
                font-size: 12px;
                line-height: 1.45;
                background: #f5f7fb;
                padding: 8px;
            }

            .invoice-shell {
                min-height: 0;
                border-width: 1px;
                padding: 10px;
            }

            .top-line { font-size: 11px; }

            .shop-title { font-size: 22px; }
            .shop-subtitle { font-size: 11px; }
            .shop-meta,
            .shop-tagline,
            .invoice-kind { font-size: 10.5px; }

            .bill-block {
                display: block;
                padding-bottom: 8px;
                margin-bottom: 10px;
            }

            .bill-col,
            .bill-col.right {
                width: 100%;
                padding-left: 0;
            }

            .bill-col + .bill-col {
                margin-top: 6px;
            }

            .kv { margin-bottom: 3px; }
            .kv .k {
                min-width: 72px;
                font-size: 11px;
            }
            .kv .v {
                font-size: 12px;
                line-height: 1.4;
            }

            .items-table {
                display: block;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                white-space: nowrap;
            }

            .items-table th,
            .items-table td {
                padding: 6px 5px;
                font-size: 11.5px;
                line-height: 1.35;
            }

            .items-table th {
                white-space: nowrap;
            }

            .bottom-wrap {
                flex-direction: column;
                gap: 8px;
            }

            .totals-box {
                width: 100%;
            }

            .totals-box td {
                font-size: 12px;
                padding: 6px;
            }

            .amount-words {
                font-size: 12px;
                line-height: 1.45;
            }

            .note-meta-body {
                min-height: 28px;
                font-size: 10px;
                line-height: 1.35;
            }
            /* Release the fixed-skeleton heights on small screens so the
               scrolling preview doesn't show huge blank gaps. The locked
               layout only applies to the print/desktop view. */
            .note-meta-body.payment { min-height: 0; }
            .payment-note { min-height: 0; }
            .payment-row-blank { display: none; }
            .top-line { min-height: 0; }
            .shop-tagline:empty { display: none; }
            /* Stack the 3-column footer vertically on phones for readability. */
            .invoice-footer {
                flex-direction: column;
                gap: 12px;
            }
            .footer-col + .footer-col {
                border-left: 0;
                border-top: 1px solid #eee;
                padding-left: 0;
                padding-top: 8px;
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
        <div class="row between top-line">
            <div>@if($showGstin && $shop?->gst_number)GSTIN: {{ $shop->gst_number }}@endif</div>
            <div class="strong">{{ $copyCount > 1 ? ($copy === 1 ? 'Customer Copy' : 'Shop Copy') : $copyLabel }}</div>
        </div>

        <div class="row shop-head" style="position: relative; align-items: flex-start;">
            @if($billing?->show_bis_logo)
            <div style="position: absolute; right: 0; top: 0;">
                <img src="{{ asset('images/bis_hallmark_logo.svg') }}" style="height: 60px; width: auto;" alt="BIS Hallmark">
            </div>
            @endif
            <div class="header-center">
                <h1 class="shop-title">{{ $shop?->name ?? 'Jewellery Store' }}</h1>
                <p class="shop-subtitle">{{ $subtitle }}</p>
                {{-- Always render the tagline line so the shop-head block has
                     a fixed height. Non-breaking space keeps the line visible
                     when the shop hasn't configured a tagline. --}}
                <p class="shop-tagline">{!! $tagline !== '' ? e($tagline) : '&nbsp;' !!}</p>
                <p class="shop-meta">
                    {{ $shop?->address_line1 ?: ($shop?->address ?: '') }}
                    @if($shop?->address_line2), {{ $shop->address_line2 }}@endif
                    @if($shop?->city), {{ $shop->city }}@endif
                    @if($shop?->state), @if($shop->state_code){{ $shop->state_code }}-@endif{{ $shop->state }}@endif
                    @if($shop?->pincode) - {{ $shop->pincode }}@endif
                </p>
                <p class="shop-meta">
                    Phone: {{ $shop?->phone ?: '-' }}
                    @if($shop?->shop_whatsapp) &nbsp;|&nbsp; WhatsApp: {{ $shop->shop_whatsapp }}@endif
                    @if($shop?->shop_email) &nbsp;|&nbsp; {{ $shop->shop_email }}@endif
                </p>
                @if($shop?->shop_registration_number)<p class="shop-meta">Reg: {{ $shop->shop_registration_number }}</p>@endif
            </div>
        </div>

        <div class="bill-block row">
            <div class="bill-col">
                <div class="kv"><div class="k">To</div><div class="v">: {{ $customer?->name ?? 'Walk-in Customer' }}</div></div>
                @if($showAddr)
                <div class="kv"><div class="k">Address</div><div class="v">: {{ $customer?->address ?: '—' }}</div></div>
                @endif
                <div class="kv"><div class="k">Mobile</div><div class="v">: {{ $customer?->mobile ?: '—' }}</div></div>
                @if($showIdPan)
                @php $snap = $invoice->complianceSnapshot; @endphp
                <div class="kv"><div class="k">ID</div><div class="v">: {{ ($snap?->snapshot_id_number ?: $customer?->id_number) ?: '—' }}</div></div>
                <div class="kv"><div class="k">PAN</div><div class="v">: {{ ($snap?->snapshot_pan ?: $customer?->pan) ?: '—' }}</div></div>
                @endif
            </div>
            <div class="bill-col right">
                <div class="kv"><div class="k">Invoice No.</div><div class="v">: {{ $invoice->invoice_number }}</div></div>
                <div class="kv"><div class="k">Invoice Date</div><div class="v">: {{ $invoice->created_at?->format('d/m/Y') }}</div></div>
                <div class="kv"><div class="k">State & Code</div><div class="v">: {{ $stateAndCode }}</div></div>
                <div class="kv"><div class="k">Time</div><div class="v">: {{ $invoice->created_at?->format('h:i A') }}</div></div>
                <div class="kv"><div class="k">Mode</div><div class="v">: {{ $invoice->status === \App\Models\Invoice::STATUS_CANCELLED ? 'Cancelled' : 'Sale' }}</div></div>
            </div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 4%;">S.No</th>
                    <th class="text-left" style="width: {{ $descW }}%;">Description</th>
                    @if($showHuid)<th style="width: 8%;">HUID</th>@endif
                    <th style="width: 4%;">Pc</th>
                    <th style="width: 8%;">Gross Wt.</th>
                    @if($showStone)
                    <th style="width: 8%;">Stone Wt.</th>
                    <th style="width: 8%;">Stone Val.</th>
                    @endif
                    <th style="width: 8%;">Net Wt.</th>
                    @if($showPurity)<th style="width: 7%;">Purity</th>@endif
                    <th style="width: 9%;">Rate</th>
                    <th style="width: 10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @if($isRepairInvoice)
                    <tr>
                        <td class="text-center">1</td>
                        <td class="text-left">
                            {{ $repair?->item_description ?? 'Repair Service' }}
                            <div style="font-size: 10px; color: #444;">(Service Invoice)</div>
                        </td>
                        @if($showHuid)<td class="text-center">—</td>@endif
                        <td class="text-center">1</td>
                        <td class="text-right">{{ $repair ? number_format((float) $repair->gross_weight, 3) : '0.000' }}</td>
                        @if($showStone)
                        <td class="text-right">0.000</td>
                        <td class="text-right">{{ number_format(0, 2) }}</td>
                        @endif
                        <td class="text-right">{{ $repair ? number_format((float) $repair->gross_weight, 3) : '0.000' }}</td>
                        @if($showPurity)<td class="text-center">{{ $repair ? number_format((float) $repair->purity, 2) . 'K' : '—' }}</td>@endif
                        <td class="text-right">—</td>
                        <td class="text-right strong">{{ number_format((float) $invoice->subtotal, 2) }}</td>
                    </tr>
                    @php
                        $fillerRows = max(0, $minimumPrintableRows - 1);
                    @endphp
                    @for($fillerIndex = 0; $fillerIndex < $fillerRows; $fillerIndex++)
                    <tr class="items-spacer-row{{ $fillerIndex === ($fillerRows - 1) ? ' is-last' : '' }}">
                        <td class="text-center">&nbsp;</td>
                        <td class="text-left">&nbsp;</td>
                        @if($showHuid)<td class="text-center">&nbsp;</td>@endif
                        <td class="text-center">&nbsp;</td>
                        <td class="text-right">&nbsp;</td>
                        @if($showStone)
                        <td class="text-right">&nbsp;</td>
                        <td class="text-right">&nbsp;</td>
                        @endif
                        <td class="text-right">&nbsp;</td>
                        @if($showPurity)<td class="text-center">&nbsp;</td>@endif
                        <td class="text-right">&nbsp;</td>
                        <td class="text-right">&nbsp;</td>
                    </tr>
                    @endfor
                @else
                    @forelse($invoice->items as $idx => $line)
                        @php
                            $invItem = $line->item;
                            $grossWt = (float) ($invItem->gross_weight    ?? $line->weight ?? 0);
                            $stoneWt = (float) ($invItem->stone_weight    ?? 0);
                            $netWt   = (float) ($invItem->net_metal_weight ?? $line->weight ?? 0);
                            $hsn     = $hsnFor($invItem?->metal_type ?? $line->metal_type ?? null, $invItem?->category);
                        @endphp
                        <tr>
                            <td class="text-center">{{ $idx + 1 }}</td>
                            <td class="text-left">
                                <div class="strong">{{ $invItem?->design ?? 'Item' }}</div>
                                <div style="font-size: 10px; color: #444;">(HSN: {{ $hsn }})</div>
                            </td>
                            @if($showHuid)<td class="text-center">{{ $invItem?->huid ?: '—' }}</td>@endif
                            <td class="text-center">1</td>
                            <td class="text-right">{{ number_format($grossWt, 3) }}</td>
                            @if($showStone)
                            <td class="text-right">{{ number_format($stoneWt, 3) }}</td>
                            <td class="text-right">{{ number_format((float) $line->stone_amount, 2) }}</td>
                            @endif
                            <td class="text-right">{{ number_format($netWt, 3) }}</td>
                            @if($showPurity)<td class="text-center">{{ $invItem?->purity ? number_format((float) $invItem->purity, 2) . 'K' : '—' }}</td>@endif
                            <td class="text-right">{{ number_format((float) $line->rate, 2) }}</td>
                            <td class="text-right strong">{{ number_format((float) $line->line_total, 2) }}</td>
                        </tr>
                        @php
                            // When items.count() ≤ $minimumPrintableRows we pad
                            // with filler rows to keep the table visually full.
                            // When items.count() > $minimumPrintableRows we add
                            // ZERO fillers (max(0, ...)) and the items themselves
                            // overflow naturally onto a 2nd page — the
                            // .invoice-footer is page-break-inside:avoid, so the
                            // 3-column footer always stays whole on whichever
                            // page it lands on.
                            $isLastItemRow = $idx === ($invoice->items->count() - 1);
                            $fillerRows = $isLastItemRow ? max(0, $minimumPrintableRows - $invoice->items->count()) : 0;
                        @endphp
                        @if($isLastItemRow && $fillerRows > 0)
                            @for($fillerIndex = 0; $fillerIndex < $fillerRows; $fillerIndex++)
                            <tr class="items-spacer-row{{ $fillerIndex === ($fillerRows - 1) ? ' is-last' : '' }}">
                                <td class="text-center">&nbsp;</td>
                                <td class="text-left">&nbsp;</td>
                                @if($showHuid)<td class="text-center">&nbsp;</td>@endif
                                <td class="text-center">&nbsp;</td>
                                <td class="text-right">&nbsp;</td>
                                @if($showStone)
                                <td class="text-right">&nbsp;</td>
                                <td class="text-right">&nbsp;</td>
                                @endif
                                <td class="text-right">&nbsp;</td>
                                @if($showPurity)<td class="text-center">&nbsp;</td>@endif
                                <td class="text-right">&nbsp;</td>
                                <td class="text-right">&nbsp;</td>
                            </tr>
                            @endfor
                        @endif
                    @empty
                        <tr><td colspan="{{ $colCount }}" class="text-center">No items found.</td></tr>
                        @php
                            $fillerRows = max(0, $minimumPrintableRows - 1);
                        @endphp
                        @for($fillerIndex = 0; $fillerIndex < $fillerRows; $fillerIndex++)
                        <tr class="items-spacer-row{{ $fillerIndex === ($fillerRows - 1) ? ' is-last' : '' }}">
                            <td class="text-center">&nbsp;</td>
                            <td class="text-left">&nbsp;</td>
                            @if($showHuid)<td class="text-center">&nbsp;</td>@endif
                            <td class="text-center">&nbsp;</td>
                            <td class="text-right">&nbsp;</td>
                            @if($showStone)
                            <td class="text-right">&nbsp;</td>
                            <td class="text-right">&nbsp;</td>
                            @endif
                            <td class="text-right">&nbsp;</td>
                            @if($showPurity)<td class="text-center">&nbsp;</td>@endif
                            <td class="text-right">&nbsp;</td>
                            <td class="text-right">&nbsp;</td>
                        </tr>
                        @endfor
                    @endforelse
                @endif
            </tbody>
        </table>

        <div class="bottom-wrap">
            <div class="left-notes">
                <div class="amount-words-block">
                    <div class="amount-words-title">Amount in Words</div>
                    <div class="amount-words-body">{{ $amountToWords((float) $invoice->total) }}</div>
                </div>
                <div class="payment-note-title">Total Receipt / Payment Note</div>
                <div class="payment-note">
                    @php
                        $modeLabels = [
                            'cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank Transfer',
                            'wallet' => 'Wallet', 'old_gold' => 'Old Gold',
                            'old_silver' => 'Old Silver', 'emi' => 'EMI',
                            'scheme_redemption' => 'Scheme Redemption', 'other' => 'Other',
                        ];
                        $fmt    = fn ($v) => '₹ ' . number_format((float) $v, 2);
                        $fmtWt  = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
                        // Fixed-skeleton: always render exactly $maxPaymentRows
                        // rows. Reserved to fit every distinct POS payment
                        // mode (cash, upi, bank, wallet, old_gold, old_silver,
                        // emi, scheme_redemption, other = 9) so even an invoice
                        // paid in every mode has the same shape as one paid
                        // entirely in cash.
                        $maxPaymentRows = 9;
                        $paymentsToShow = $payments->take($maxPaymentRows);
                        $blankSlots     = max(0, $maxPaymentRows - $paymentsToShow->count());
                    @endphp
                    @foreach($paymentsToShow as $payment)
                        <div class="payment-row">
                            <span class="strong">{{ $modeLabels[$payment->mode] ?? ucfirst((string) $payment->mode) }}:</span>
                            {{ $fmt($payment->amount) }}
                            @if(in_array($payment->mode, ['old_gold', 'old_silver'], true) && (float) ($payment->metal_gross_weight ?? 0) > 0)
                                <span style="font-size: 11px; color: #444;">
                                    ({{ $fmtWt($payment->metal_gross_weight) }}g
                                    @if((float) ($payment->metal_purity ?? 0) > 0)
                                        · {{ rtrim(rtrim(number_format((float) $payment->metal_purity, 2, '.', ''), '0'), '.') }}{{ $payment->mode === 'old_gold' ? 'K' : '' }}
                                    @endif
                                    @if((float) ($payment->metal_test_loss ?? 0) > 0)
                                        · loss {{ rtrim(rtrim(number_format((float) $payment->metal_test_loss, 2, '.', ''), '0'), '.') }}%
                                    @endif
                                    @if((float) ($payment->metal_fine_weight ?? 0) > 0)
                                        · {{ $fmtWt($payment->metal_fine_weight) }}g fine
                                    @endif
                                    @if((float) ($payment->metal_rate_per_gram ?? 0) > 0)
                                        · @ ₹{{ number_format((float) $payment->metal_rate_per_gram, 2) }}/g
                                    @endif)
                                </span>
                            @endif
                            @if(!empty($payment->reference) && ! in_array($payment->mode, ['cash', 'old_gold', 'old_silver'], true))
                                <span style="font-size: 11px; color: #444;">— {{ $payment->reference }}</span>
                            @endif
                        </div>
                    @endforeach
                    @for($i = 0; $i < $blankSlots; $i++)
                        <div class="payment-row payment-row-blank">&nbsp;</div>
                    @endfor
                </div>
                {{-- Payment Details + T&C moved to the bottom 3-column footer
                     row so the left-notes column now only carries Amount in
                     Words + Payment Note. Keeps the .bottom-wrap compact. --}}
            </div>

            {{-- Fixed-skeleton totals box: every row renders on every invoice
                 (zero values shown explicitly) so two sales from the same shop
                 produce identically-shaped invoices. The `$igstMode` flag is a
                 shop-wide setting (intra- vs inter-state) — IGST shape (1 tax
                 row) and CGST/SGST shape (2 tax rows) are each fixed within a
                 shop, just different between shops. --}}
            <table class="totals-box">
                <tr>
                    <td>Total Amount Before Tax</td>
                    <td class="text-right">{{ number_format($beforeTax, 2) }}</td>
                </tr>
                @if($igstMode)
                <tr>
                    <td>Add: IGST {{ number_format($gstHalfRate * 2, 2) }}%</td>
                    <td class="text-right">{{ number_format($gst, 2) }}</td>
                </tr>
                @else
                <tr>
                    <td>Add: CGST {{ number_format($gstHalfRate, 2) }}%</td>
                    <td class="text-right">{{ number_format($cgst, 2) }}</td>
                </tr>
                <tr>
                    <td>Add: SGST {{ number_format($gstHalfRate, 2) }}%</td>
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
                    <td class="text-right strong">₹{{ number_format((float) $invoice->total, 2) }}</td>
                </tr>
            </table>
        </div>
    </div>{{-- end .invoice-body --}}

    {{-- Bottom 3-column footer row. Each column is a dedicated, always-visible
         section so the bottom of the invoice has the same shape across every
         sale — no matter whether the shop has bank details set up, how many
         terms are configured, or whether a second signatory exists. --}}
    <div class="invoice-footer">
        <div class="footer-col footer-col--payment">
            <h4 class="footer-title">Payment Details</h4>
            <div class="footer-body">
                @if(!empty($billing?->upi_id))
                    <div><span class="strong">UPI:</span> {{ $billing?->upi_id }}</div>
                @endif
                @if(!empty($billing?->bank_name) || !empty($billing?->bank_account_number))
                    @if(!empty($billing?->bank_account_holder))
                        <div><span class="strong">A/C Holder:</span> {{ $billing?->bank_account_holder }}</div>
                    @endif
                    @if(!empty($billing?->bank_name))
                        <div><span class="strong">Bank:</span> {{ $billing?->bank_name }}</div>
                    @endif
                    @if(!empty($billing?->bank_account_number))
                        <div><span class="strong">A/C No:</span> {{ $billing?->bank_account_number }}</div>
                    @endif
                    @if(!empty($billing?->bank_ifsc))
                        <div><span class="strong">IFSC:</span> {{ $billing?->bank_ifsc }}</div>
                    @endif
                    @if(!empty($billing?->bank_account_type))
                        <div><span class="strong">Type:</span> {{ ucfirst($billing?->bank_account_type) }}</div>
                    @endif
                    @if(!empty($billing?->bank_branch))
                        <div><span class="strong">Branch:</span> {{ $billing?->bank_branch }}</div>
                    @endif
                @elseif(!empty($billing?->bank_details))
                    <div style="white-space: pre-line;"><span class="strong">Bank:</span> {{ $billing?->bank_details }}</div>
                @endif
                @if(empty($billing?->upi_id) && empty($billing?->bank_name) && empty($billing?->bank_account_number) && empty($billing?->bank_details))
                    <div class="footer-empty">—</div>
                @endif
            </div>
        </div>

        <div class="footer-col footer-col--terms">
            <h4 class="footer-title">Terms &amp; Conditions</h4>
            <div class="footer-body">
                @foreach($terms as $i => $term)
                    <div class="footer-term"><span class="footer-term-num">{{ $i + 1 }}.</span><span>{{ $term }}</span></div>
                @endforeach
            </div>
        </div>

        <div class="footer-col footer-col--sign">
            <h4 class="footer-title">For {{ $shop?->name ?? 'Jewellery Store' }}</h4>
            <div class="footer-body footer-sign-body">
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
