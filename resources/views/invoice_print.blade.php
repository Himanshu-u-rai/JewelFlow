<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
@php
    $shop    = auth()->user()->shop;
    $billing = $shop?->billingSettings;

    // ── Appearance ────────────────────────────────────────────────────────
    $accent    = $billing->theme_color   ?? '#111111';
    // Font-size tiers: body / shop-name / subtitle / invoice-kind / meta / terms / top-line
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

    // ── Column widths (redistribute hidden cols to Description) ───────────
    $showHuid    = $billing->show_huid          ?? true;
    $showStone   = $billing->show_stone_columns ?? true;
    $showPurity  = $billing->show_purity        ?? true;
    $showGstin   = $billing->show_gstin         ?? true;
    $showAddr    = $billing->show_customer_address ?? true;
    $showIdPan   = $billing->show_customer_id_pan  ?? true;
    $igstMode    = $billing->igst_mode          ?? false;
    $copyCount   = (int) ($billing->copy_count  ?? 1);
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
    $defaultTerms = [
        'Goods once sold will not be taken back or exchanged.',
        'Please verify product details before leaving the counter.',
        'All disputes are subject to local jurisdiction.',
        'Invoice is valid only with authorized signature.',
    ];
    $terms = $termsRaw !== ''
        ? array_slice(array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $termsRaw)))), 0, 6)
        : $defaultTerms;

    // HSN helper: map item category to HSN code
    $hsnFor = function (?string $category) use ($billing): string {
        $cat = strtolower((string) $category);
        if (str_contains($cat, 'silver'))                                          return $billing->hsn_silver  ?? '7113';
        if (str_contains($cat, 'diamond') || str_contains($cat, 'stone') || str_contains($cat, 'gem')) return $billing->hsn_diamond ?? '7114';
        return $billing->hsn_gold ?? '7113';
    };

    $subtitle  = $billing->shop_subtitle   ?: 'GOLD • SILVER • DIAMOND';
    $tagline   = $billing->custom_tagline  ?? '';
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
        .invoice-body { flex: 1; }
        .invoice-footer { margin-top: auto; padding-top: 4px; }

        .copy-break { page-break-before: always; margin-top: 0; }

        .row { display: flex; width: 100%; }
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

        .bottom-wrap { display: flex; gap: 10px; margin-top: 6px; }
        .left-notes  { flex: 1; border-top: 1px solid #111; padding-top: 6px; min-height: 80px; }
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

            .terms-title { font-size: 10.5px; }
            .terms-list {
                font-size: 10px;
                line-height: 1.4;
            }
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
                <p class="invoice-kind">GST INVOICE</p>
                <h1 class="shop-title">{{ $shop?->name ?? 'Jewellery Store' }}</h1>
                <p class="shop-subtitle">{{ $subtitle }}</p>
                @if($tagline)<p class="shop-tagline">{{ $tagline }}</p>@endif
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
                <div class="kv"><div class="k">ID</div><div class="v">: {{ $customer?->id_number ?: '—' }}</div></div>
                <div class="kv"><div class="k">PAN</div><div class="v">: {{ $customer?->pan ?: '—' }}</div></div>
                @endif
            </div>
            <div class="bill-col right">
                <div class="kv"><div class="k">Bill No.</div><div class="v">: {{ $invoice->invoice_number }}</div></div>
                <div class="kv"><div class="k">Date</div><div class="v">: {{ $invoice->created_at?->format('d/m/Y') }}</div></div>
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
                        <td class="text-right">{{ number_format((float) $invoice->subtotal, 2) }}</td>
                        <td class="text-right strong">{{ number_format((float) $invoice->total, 2) }}</td>
                    </tr>
                @else
                    @forelse($invoice->items as $idx => $line)
                        @php
                            $invItem = $line->item;
                            $grossWt = (float) ($invItem->gross_weight    ?? $line->weight ?? 0);
                            $stoneWt = (float) ($invItem->stone_weight    ?? 0);
                            $netWt   = (float) ($invItem->net_metal_weight ?? $line->weight ?? 0);
                            $hsn     = $hsnFor($invItem?->category);
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
                    @empty
                        <tr><td colspan="{{ $colCount }}" class="text-center">No items found.</td></tr>
                    @endforelse
                @endif
            </tbody>
        </table>

        <div class="bottom-wrap">
            <div class="left-notes">
                <div class="amount-words"><span class="strong">₹:</span> {{ $amountToWords((float) $invoice->total) }}</div>
                <div class="strong" style="margin-bottom: 4px;">Total Receipt / Payment Note:</div>
                <div class="payment-note"></div>
            </div>

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
                @if($discount > 0)
                <tr>
                    <td>Less: Discount</td>
                    <td class="text-right">-{{ number_format($discount, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td>Total Amount After Tax</td>
                    <td class="text-right">{{ number_format($afterTax, 2) }}</td>
                </tr>
                <tr>
                    <td>Receipt Amount</td>
                    <td class="text-right">{{ number_format($receiptAmount, 2) }}</td>
                </tr>
                @if($roundOff != 0)
                <tr>
                    <td>Round Off</td>
                    <td class="text-right">{{ $roundOff > 0 ? '+' : '' }}{{ number_format($roundOff, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td class="strong">Net Amount</td>
                    <td class="text-right strong">₹{{ number_format((float) $invoice->total, 2) }}</td>
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
                <div class="strong">FOR {{ $shop?->name ?? 'Jewellery Store' }}</div>
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
            @if(!empty($billing?->upi_id) || !empty($billing?->bank_name) || !empty($billing?->bank_account_number) || !empty($billing?->bank_details))
            <div style="flex:1; border-left:1px dashed #aaa; padding-left:14px; font-size:10.5px;">
                <div class="strong" style="margin-bottom:4px;">Payment Details:</div>
                @if(!empty($billing?->upi_id))
                    <div style="margin:2px 0;"><span class="strong">UPI:</span> {{ $billing->upi_id }}</div>
                @endif
                @if(!empty($billing?->bank_name) || !empty($billing?->bank_account_number))
                    @if(!empty($billing->bank_account_holder))
                        <div style="margin:2px 0;"><span class="strong">A/C Holder:</span> {{ $billing->bank_account_holder }}</div>
                    @endif
                    @if(!empty($billing->bank_name))
                        <div style="margin:2px 0;"><span class="strong">Bank:</span> {{ $billing->bank_name }}</div>
                    @endif
                    @if(!empty($billing->bank_account_number))
                        <div style="margin:2px 0;"><span class="strong">A/C No:</span> {{ $billing->bank_account_number }}</div>
                    @endif
                    @if(!empty($billing->bank_ifsc))
                        <div style="margin:2px 0;"><span class="strong">IFSC:</span> {{ $billing->bank_ifsc }}</div>
                    @endif
                    @if(!empty($billing->bank_account_type))
                        <div style="margin:2px 0;"><span class="strong">Type:</span> {{ ucfirst($billing->bank_account_type) }}</div>
                    @endif
                    @if(!empty($billing->bank_branch))
                        <div style="margin:2px 0;"><span class="strong">Branch:</span> {{ $billing->bank_branch }}</div>
                    @endif
                @elseif(!empty($billing?->bank_details))
                    <div style="margin:2px 0; white-space:pre-line;"><span class="strong">Bank:</span> {{ $billing->bank_details }}</div>
                @endif
            </div>
            @endif
        </div>
    </div>{{-- end .invoice-footer --}}
</div>
@endfor

</body>
</html>
