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

        /* Sizes: width x height */
        .label.small  { width: 50mm; height: 25mm; }
        .label.medium { width: 70mm; height: 40mm; }
        .label.large  { width: 90mm; height: 50mm; }

        /* ── Shop name ── */
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

        /* ── Barcode area — fills available space ── */
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

        /* Barcode error state */
        .barcode-error {
            color: #b91c1c;
            font-size: 6pt;
            font-family: 'Courier New', monospace;
            text-align: center;
            padding: 1mm 0;
        }

        /* ── Details line ── */
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

    <div class="no-print" style="padding:10px;background:#f5f5f5;border-bottom:1px solid #ddd;text-align:center;display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;">
        <button onclick="window.print()" style="padding:8px 24px;background:#0d9488;color:white;border:none;cursor:pointer;font-size:14px;">
            Print Labels
        </button>
        <button onclick="window.close()" style="padding:8px 24px;background:#e5e7eb;color:#333;border:none;cursor:pointer;font-size:14px;">
            Close
        </button>
        <span style="color:#555;font-size:13px;">
            {{ $items->count() }} label(s) &middot; {{ ucfirst($labelSize) }} size
            &middot; Barcode: {{ $includeBarcodeImage ? 'On' : 'Off' }}
        </span>
        <span id="barcode-error-summary" style="color:#b91c1c;font-size:13px;display:none;">
            ⚠ Some barcodes could not be rendered — check items with a red warning.
        </span>
    </div>

    <div class="label-grid">
        @foreach($items as $item)
        @php
            // Convert purity % to karat (e.g. 91.67% → 22.00K)
            $karat = $item->purity ? number_format($item->purity * 24 / 100, 2) . 'K' : null;

            // Build details segments: Design | Karat | Weight
            $segments = array_filter([
                $item->design ?: null,
                $karat,
                number_format($item->gross_weight, 3) . 'g',
            ]);
            $detailsText = implode(' | ', $segments);
        @endphp
        <div class="label {{ $labelSize }}">

            {{-- 1. Shop name — always visible, scales with label size --}}
            <div class="shop-name">{{ $shop->name ?? 'JewelFlow' }}</div>

            {{-- 2. Barcode — dominant centre area --}}
            <div class="barcode-area">
                @if($includeBarcodeImage)
                    <svg class="barcode-svg js-barcode"
                         data-barcode="{{ $item->barcode }}"
                         data-size="{{ $labelSize }}"
                         aria-label="Barcode {{ $item->barcode }}"></svg>
                @endif
                <div class="{{ $includeBarcodeImage ? 'barcode-text' : 'barcode-text-only' }}">{{ $item->barcode }}</div>
            </div>

            {{-- 3. Details line — design, karat, weight --}}
            <div class="details-line">
                {{ $detailsText }}
                @if($item->huid)
                    &nbsp;<span class="hallmark-badge">BIS</span> {{ $item->huid }}
                @endif
            </div>
        </div>
        @endforeach
    </div>

    @if($includeBarcodeImage)
    {{--
        JsBarcode served locally from public/js/jsbarcode.min.js.
        No CDN dependency — works offline and is not affected by CDN outages.
    --}}
    <script src="{{ asset('js/jsbarcode.min.js') }}"></script>
    <script>
    (() => {
        const optionsBySize = {
            small:  { width: 1.2, height: 35, fontSize: 0, margin: 0 },
            medium: { width: 1.5, height: 55, fontSize: 0, margin: 0 },
            large:  { width: 1.8, height: 75, fontSize: 0, margin: 0 },
        };

        let anyError = false;

        document.querySelectorAll('.js-barcode').forEach(svg => {
            const code = (svg.dataset.barcode || '').trim();
            if (!code) return;

            const size    = (svg.dataset.size || 'medium').toLowerCase();
            const options = optionsBySize[size] || optionsBySize.medium;

            try {
                JsBarcode(svg, code, { format: 'CODE128', displayValue: false, ...options });
            } catch (_) {
                const errDiv = document.createElement('div');
                errDiv.className   = 'barcode-error';
                errDiv.textContent = '⚠ Barcode error: ' + code;
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
