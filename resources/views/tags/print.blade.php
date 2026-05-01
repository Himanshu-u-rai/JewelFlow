<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Labels — {{ $shop->name ?? 'JewelFlow' }}</title>
    <style>
        @page { margin: 5mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', sans-serif; background: #fff; }

        .label-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 4mm;
            padding: 2mm;
        }

        .label {
            border: 1px solid #333;
            padding: 2mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            overflow: hidden;
        }

        /* Standard sizes: width x height */
        .label.small  { width: 50mm; height: 25mm; }
        .label.medium { width: 70mm; height: 40mm; }
        .label.large  { width: 90mm; height: 50mm; }

        /* Folded sizes: width x height */
        .folded-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 2mm;
            padding: 2mm;
        }

        .folded-label {
            border: 1px solid #222;
            display: flex;
            overflow: hidden;
            page-break-inside: avoid;
            background: #fff;
        }

        .folded-label.size-95x12 { width: 95mm; height: 12mm; }
        .folded-label.size-95x15 { width: 95mm; height: 15mm; }

        .folded-half {
            width: 50%;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 0.8mm 1mm;
            overflow: hidden;
        }

        .folded-left {
            border-right: 0.5px dashed #999;
            align-items: center;
            text-align: center;
        }

        .folded-right {
            text-align: left;
            align-items: flex-start;
        }

        .folded-truncate {
            width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .folded-shop-name {
            font-weight: 700;
            color: #111;
            letter-spacing: 0.2px;
            line-height: 1.05;
        }

        .folded-barcode-area {
            flex: 1;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 0;
        }

        .folded-barcode-svg {
            width: 100%;
            max-width: 100%;
            display: block;
            flex: 1;
            min-height: 0;
        }

        .folded-barcode-value {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            letter-spacing: 0.4px;
            color: #111;
            text-align: center;
            line-height: 1;
            margin-top: 0.2mm;
        }

        .folded-code {
            font-weight: 700;
            color: #111;
            line-height: 1;
        }

        .folded-name {
            color: #111;
            line-height: 1.05;
        }

        .folded-price {
            font-weight: 700;
            color: #111;
            line-height: 1;
        }

        .folded-label.size-95x12 .folded-shop-name,
        .folded-label.size-95x12 .folded-code,
        .folded-label.size-95x12 .folded-name,
        .folded-label.size-95x12 .folded-price,
        .folded-label.size-95x12 .folded-barcode-value {
            font-size: 6pt;
        }

        .folded-label.size-95x12 .folded-price { font-size: 8pt; }
        .folded-label.size-95x12 .folded-barcode-value { font-size: 5.5pt; }

        .folded-label.size-95x15 .folded-shop-name,
        .folded-label.size-95x15 .folded-code,
        .folded-label.size-95x15 .folded-name,
        .folded-label.size-95x15 .folded-price,
        .folded-label.size-95x15 .folded-barcode-value {
            font-size: 7pt;
        }

        .folded-label.size-95x15 .folded-price { font-size: 10pt; }
        .folded-label.size-95x15 .folded-barcode-value { font-size: 6.5pt; }

        /* Standard label: shop name */
        .shop-name {
            font-weight: bold;
            text-align: center;
            line-height: 1.2;
            padding-bottom: 1mm;
            border-bottom: 0.5px solid #999;
        }

        .label.small  .shop-name { font-size: 7pt; }
        .label.medium .shop-name { font-size: 10pt; }
        .label.large  .shop-name { font-size: 12pt; }

        /* Standard label: barcode */
        .barcode-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1mm 0;
            min-height: 0;
        }

        .barcode-svg {
            width: 100%;
            max-width: 100%;
            flex: 1;
            display: block;
        }

        .barcode-text {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            letter-spacing: 0.8px;
            color: #111;
            text-align: center;
            white-space: nowrap;
            margin-top: 0.5mm;
        }

        .label.small  .barcode-text { font-size: 6.5pt; }
        .label.medium .barcode-text { font-size: 8pt; }
        .label.large  .barcode-text { font-size: 9pt; }

        /* Barcode-off fallback */
        .barcode-text-only {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            letter-spacing: 1px;
            color: #111;
            text-align: center;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .label.small  .barcode-text-only { font-size: 8pt; }
        .label.medium .barcode-text-only { font-size: 11pt; }
        .label.large  .barcode-text-only { font-size: 13pt; }

        /* Barcode error */
        .barcode-error {
            color: #b91c1c;
            font-size: 6pt;
            font-family: 'Courier New', monospace;
            text-align: center;
            padding: 1mm 0;
        }

        /* Standard details line */
        .details-line {
            text-align: center;
            color: #666;
            border-top: 0.5px solid #999;
            padding-top: 1mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .label.small  .details-line { font-size: 5.5pt; }
        .label.medium .details-line { font-size: 7pt; }
        .label.large  .details-line { font-size: 8pt; }

        .hallmark-badge {
            display: inline-block;
            background: #333;
            color: #fff;
            padding: 0.3mm 1mm;
            font-weight: bold;
            vertical-align: baseline;
        }

        .label.small  .hallmark-badge { font-size: 4.5pt; }
        .label.medium .hallmark-badge { font-size: 5.5pt; }
        .label.large  .hallmark-badge { font-size: 6pt; }

        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    @php
        $isFoldedPrint = ($printFormat ?? 'standard') === 'folded';
        $requestedFoldedSize = $foldedSize ?? '95x12';
        $activeFoldedSize = in_array($requestedFoldedSize, ['95x12', '95x15'], true) ? $requestedFoldedSize : '95x12';
    @endphp

    <div class="no-print" style="padding:10px;background:#f5f5f5;border-bottom:1px solid #ddd;text-align:center;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;">
        <button onclick="window.print()" style="padding:8px 24px;background:#0d9488;color:white;border:none;cursor:pointer;font-size:14px;">
            Print Labels
        </button>
        <button onclick="window.close()" style="padding:8px 24px;background:#e5e7eb;color:#333;border:none;cursor:pointer;font-size:14px;">
            Close
        </button>
        <span style="color:#555;font-size:13px;">
            {{ $items->count() }} label(s)
            &middot; Format: {{ $isFoldedPrint ? 'Folded' : 'Standard' }}
            &middot; Size: {{ $isFoldedPrint ? $activeFoldedSize . ' mm' : ucfirst($labelSize) }}
            &middot; Barcode: {{ $includeBarcodeImage ? 'On' : 'Off' }}
        </span>
        <span id="barcode-error-summary" style="color:#b91c1c;font-size:13px;display:none;">
            Some barcodes could not be rendered - check items with a red warning.
        </span>
    </div>

    @if($isFoldedPrint)
        <div class="folded-grid">
            @foreach($items as $item)
                @php
                    $foldedName = $item->design ?: trim(($item->category ?? '') . ($item->sub_category ? ' / ' . $item->sub_category : ''));
                    if ($foldedName === '') {
                        $foldedName = 'Item';
                    }
                @endphp
                <div class="folded-label size-{{ $activeFoldedSize }}">
                    <div class="folded-half folded-left">
                        <div class="folded-shop-name folded-truncate">{{ $shop->name ?? 'JewelFlow' }}</div>
                        <div class="folded-barcode-area">
                            @if($includeBarcodeImage)
                                <svg class="folded-barcode-svg js-barcode"
                                     data-barcode="{{ $item->barcode }}"
                                     data-size="{{ $activeFoldedSize }}"
                                     data-mode="folded"
                                     aria-label="Barcode {{ $item->barcode }}"></svg>
                            @endif
                            <div class="folded-barcode-value folded-truncate">{{ $item->barcode }}</div>
                        </div>
                    </div>
                    <div class="folded-half folded-right">
                        <div class="folded-code folded-truncate">{{ $item->barcode }}</div>
                        <div class="folded-name folded-truncate">{{ $foldedName }}</div>
                        <div class="folded-price folded-truncate">Rs {{ number_format((float) $item->selling_price, 2) }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="label-grid">
            @foreach($items as $item)
            @php
                // purity_label accessor handles both retailer (karat scale: 22 → "22K")
                // and manufacturer (percentage scale: 91.67 → "22K") correctly.
                $purityLabel = $item->purity_label;

                $segments = array_filter([
                    $item->design ?: null,
                    $purityLabel,
                    number_format((float) $item->gross_weight, 3) . 'g',
                ]);
                $detailsText = implode(' | ', $segments);
            @endphp
            <div class="label {{ $labelSize }}">

                {{-- 1. Shop name - always visible, scales with label size --}}
                <div class="shop-name">{{ $shop->name ?? 'JewelFlow' }}</div>

                {{-- 2. Barcode - dominant centre area --}}
                <div class="barcode-area">
                    @if($includeBarcodeImage)
                        <svg class="barcode-svg js-barcode"
                             data-barcode="{{ $item->barcode }}"
                             data-size="{{ $labelSize }}"
                             data-mode="standard"
                             aria-label="Barcode {{ $item->barcode }}"></svg>
                    @endif
                    <div class="{{ $includeBarcodeImage ? 'barcode-text' : 'barcode-text-only' }}">{{ $item->barcode }}</div>
                </div>

                {{-- 3. Details line - design, karat, weight --}}
                <div class="details-line">
                    {{ $detailsText }}
                    @if($item->huid)
                        &nbsp;<span class="hallmark-badge">BIS</span> {{ $item->huid }}
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    @endif

    @if($includeBarcodeImage)
    {{--
        JsBarcode served locally from public/js/jsbarcode.min.js.
        No CDN dependency - works offline and is not affected by CDN outages.
    --}}
    <script src="{{ asset('js/jsbarcode.min.js') }}"></script>
    <script>
    (() => {
        const standardOptionsBySize = {
            small:  { width: 1.2, height: 35, fontSize: 0, margin: 0 },
            medium: { width: 1.5, height: 55, fontSize: 0, margin: 0 },
            large:  { width: 1.8, height: 75, fontSize: 0, margin: 0 },
        };

        const foldedOptionsBySize = {
            '95x12': { width: 0.85, height: 15, fontSize: 0, margin: 0 },
            '95x15': { width: 1.05, height: 21, fontSize: 0, margin: 0 },
        };

        let anyError = false;

        document.querySelectorAll('.js-barcode').forEach(svg => {
            const code = (svg.dataset.barcode || '').trim();
            if (!code) return;

            const mode = (svg.dataset.mode || 'standard').toLowerCase();
            const size = (svg.dataset.size || 'medium').toLowerCase();
            const options = mode === 'folded'
                ? (foldedOptionsBySize[size] || foldedOptionsBySize['95x12'])
                : (standardOptionsBySize[size] || standardOptionsBySize.medium);

            try {
                JsBarcode(svg, code, { format: 'CODE128', displayValue: false, ...options });
            } catch (_) {
                const errDiv = document.createElement('div');
                errDiv.className = 'barcode-error';
                errDiv.textContent = 'Barcode error: ' + code;
                svg.replaceWith(errDiv);
                anyError = true;
            }
        });

        if (anyError) {
            const summary = document.getElementById('barcode-error-summary');
            if (summary) summary.style.display = 'inline';
        }
    })();
    </script>
    @endif

</body>
</html>
