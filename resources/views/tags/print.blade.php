<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Tags — {{ $shop->name ?? 'JewelFlows' }}</title>
    @php
        /*
         * Every size here is in millimetres, worked out from the tag the shop
         * chose, so small tags stay readable and nothing spills onto the
         * blank tail of a folded tag. The QR and the barcode carry the same
         * code: whichever survives a smudge still scans.
         */
        $mm = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . 'mm';
        $pt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . 'pt';
        $L = (float) $tag['length'];
        $H = (float) $tag['height'];
        $pad = $H < 14 ? 0.6 : 1.0;
        // Text that fills the given height with n lines, never below 4.5 pt (≈1.6 mm caps).
        $fontFor = fn (float $height, int $lines) => max(4.5, min(9.0, ($height / $lines) / 1.18 / 0.3528));
        $inner = $H - 2 * $pad;

        // Shrinks one line's text until it fits the width (≈0.56 em per character).
        $fit = fn (string $text, float $widthMm, float $font) => max(3.5, min($font, $widthMm / max(1, mb_strlen($text) * 0.56) / 0.3528));

        if ($tag['folded']) {
            $E = (float) $tag['end'];
            $qr = $tag['qr'] ? $inner : 0;
            $textW = $E - 2 * $pad - ($qr > 0 ? $qr + $pad : 0);
            $frontFont = $fontFor($inner, 4);
            $backFont = $fontFor($inner, 4);
            $barcodeH = $inner - ($backFont * 0.3528 * 1.25);
        } else {
            $shopFont = max(4.5, min(8.0, $H * 0.22));
            $body = $inner - $shopFont * 0.3528 * 1.25;
            $showBarcode = $tag['barcode'] && (! $tag['qr'] || $H >= 20);
            $barcodeH = $showBarcode ? max(4.0, $body * 0.38) : 0;
            $topH = $body - ($showBarcode ? $barcodeH + 0.6 : 0);
            $qr = $tag['qr'] ? min($topH, $L * 0.42) : 0;
            $flatFont = $fontFor($topH, 4);
        }
    @endphp
    <style>
        @page { size: {{ $mm($L) }} {{ $mm($H) }}; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; background: #f1f5f9; color: #000; }

        .sheet { display: flex; flex-wrap: wrap; gap: 4mm; padding: 4mm; }
        .tag { background: #fff; overflow: hidden; outline: 0.2mm dashed #94a3b8; }
        .end, .flat { padding: {{ $mm($pad) }}; overflow: hidden; }
        .folded { display: flex; justify-content: space-between; }
        .tail { flex: 1; }
        .front { display: flex; gap: {{ $mm($pad) }}; align-items: center; }
        .back { display: flex; flex-direction: column; justify-content: space-between; }
        .lines { min-width: 0; flex: 1; line-height: 1.18; }
        .lines div, .row { white-space: nowrap; overflow: hidden; text-overflow: clip; }
        .code { font-weight: 700; }
        .row { display: flex; justify-content: space-between; gap: 1mm; line-height: 1.18; }
        .shop { font-weight: 700; white-space: nowrap; overflow: hidden; line-height: 1.25; }
        .flat-top { display: flex; gap: {{ $mm($pad) }}; align-items: center; }

        /* QR: a white margin is the tag itself; pixels stay square and sharp. */
        .js-qr { flex: none; }
        .js-qr img, .js-qr canvas { width: 100% !important; height: 100% !important; image-rendering: pixelated; }
        .js-barcode { display: block; width: 100%; }
        .code-error { color: #b91c1c; font-weight: 700; }

        .toolbar { padding: 10px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; gap: 12px; align-items: center; justify-content: center; flex-wrap: wrap; font-size: 13px; color: #475569; }
        .toolbar button { padding: 8px 20px; border: 0; cursor: pointer; font-size: 14px; border-radius: 6px; }

        @media print {
            body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none !important; }
            .sheet { display: block; padding: 0; }
            /* Tag printers feed one tag per page, page = tag. */
            .tag { outline: none; page-break-after: always; break-after: page; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()" style="background:#0d9488;color:#fff;">Print tags</button>
        <button onclick="window.close()" style="background:#e5e7eb;color:#333;">Close</button>
        <span>
            {{ $items->count() }} tag(s) ·
            {{ $tag['folded'] ? 'Jewellery tag' : 'Label' }} {{ rtrim(rtrim(number_format($L, 1), '0'), '.') }}×{{ rtrim(rtrim(number_format($H, 1), '0'), '.') }} mm
            @if($tag['folded']) (ends {{ rtrim(rtrim(number_format($E, 1), '0'), '.') }} mm) @endif
            · {{ $tag['qr'] && $tag['barcode'] ? 'QR + barcode' : ($tag['qr'] ? 'QR' : ($tag['barcode'] ? 'Barcode' : 'Text only')) }}
        </span>
        <span>Set the printer's paper size to the tag size and margins to none.</span>
        @php
            // Code 128 needs about 11 bar widths per character (+ start/check/stop).
            // Below ~0.25 mm per bar a 203 dpi tag printer (0.125 mm dots) blurs it.
            $longest = $items->max(fn ($i) => mb_strlen((string) $i->barcode)) ?: 1;
            $barSpace = ($tag['folded'] ? (float) $tag['end'] : $L) - 2 * $pad;
            $barMm = $barSpace / (11 * ($longest + 3) + 2);
        @endphp
        @if($tag['barcode'] && $barMm < 0.25)
            <span style="color:#b45309;font-weight:600;">
                Barcode bars are very fine at this size ({{ number_format($barMm, 2) }} mm) — it may not scan from a 203 dpi printer.
                @if($tag['qr']) The QR on the same tag will scan. @else Choose QR + barcode, or a bigger size. @endif
            </span>
        @endif
        <span id="code-error-summary" style="color:#b91c1c;display:none;">Some codes could not be drawn — see tags marked in red.</span>
    </div>

    <div class="sheet">
        @foreach($items as $item)
            @php
                $code = (string) $item->barcode;
                $purity = (string) ($item->purity_label ?? '');
                $gross = 'G ' . number_format((float) $item->gross_weight, 3);
                $stone = (float) $item->stone_weight > 0 ? 'L ' . number_format((float) $item->stone_weight, 3) : '';
                $netW = 'N ' . number_format((float) $item->net_metal_weight, 3);
                // Flat labels have room for purity beside the gross weight.
                $weights = trim(($purity ? $purity . '  ' : '') . $gross);
                $net = trim(($stone ? $stone . '  ' : '') . $netW);
                $name = $item->design ?: trim(($item->category ?? '') . ($item->sub_category ? ' ' . $item->sub_category : ''));
                $price = (float) $item->selling_price > 0 ? '₹' . number_format((float) $item->selling_price, 0) : '';
            @endphp

            @if($tag['folded'])
                <div class="tag folded" style="width: {{ $mm($L) }}; height: {{ $mm($H) }};">
                    {{-- Front end: QR + one fact per line (code, G, L, N), like a shop's own tags --}}
                    @php($frontLinesText = array_values(array_filter([$code, $gross, $stone, $netW])))
                    <div class="end front" style="width: {{ $mm($E) }};">
                        @if($tag['qr'])
                            <div class="js-qr" data-code="{{ $code }}" style="width: {{ $mm($qr) }}; height: {{ $mm($qr) }};" aria-label="QR {{ $code }}"></div>
                        @endif
                        <div class="lines">
                            @foreach($frontLinesText as $i => $line)
                                <div @class(['code' => $i === 0]) style="font-size: {{ $pt($fit($line, $textW, $fontFor($inner, count($frontLinesText)))) }};">{{ $line }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="tail"></div>
                    {{-- Back end: barcode + purity and price --}}
                    <div class="end back" style="width: {{ $mm($E) }}; font-size: {{ $pt($backFont) }};">
                        @if($tag['barcode'])
                            <svg class="js-barcode" data-barcode="{{ $code }}" style="height: {{ $mm($barcodeH) }};" aria-label="Barcode {{ $code }}"></svg>
                            <div class="row"><span class="code">{{ $purity }}</span><span class="code">{{ $price }}</span></div>
                        @else
                            <div class="lines">
                                <div class="code" style="font-size: 1.3em;">{{ $price }}</div>
                                <div>{{ $name }}</div>
                                <div>{{ $shop->name ?? '' }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            @else
                <div class="tag flat" style="width: {{ $mm($L) }}; height: {{ $mm($H) }};">
                    <div class="shop" style="font-size: {{ $pt($shopFont) }};">{{ $shop->name ?? 'JewelFlows' }}</div>
                    <div class="flat-top" style="height: {{ $mm($topH) }}; font-size: {{ $pt($flatFont) }};">
                        @if($tag['qr'])
                            <div class="js-qr" data-code="{{ $code }}" style="width: {{ $mm($qr) }}; height: {{ $mm($qr) }};" aria-label="QR {{ $code }}"></div>
                        @endif
                        <div class="lines">
                            <div class="code">{{ $code }}</div>
                            <div>{{ $weights }}</div>
                            <div>{{ $net }}</div>
                            <div class="code">{{ $price }}</div>
                        </div>
                    </div>
                    @if($showBarcode)
                        <svg class="js-barcode" data-barcode="{{ $code }}" style="height: {{ $mm($barcodeH) }}; margin-top: 0.6mm;" aria-label="Barcode {{ $code }}"></svg>
                    @endif
                </div>
            @endif
        @endforeach
    </div>

    {{-- Both libraries are served locally: printing works offline. --}}
    @if($tag['qr'])
        <script src="{{ asset('js/qrcode.min.js') }}"></script>
    @endif
    @if($tag['barcode'])
        <script src="{{ asset('js/jsbarcode.min.js') }}"></script>
    @endif
    <script>
    (() => {
        let failed = false;
        const fail = (el, code) => {
            const div = document.createElement('div');
            div.className = 'code-error';
            div.textContent = 'Code error: ' + code;
            el.replaceWith(div);
            failed = true;
        };

        // QR: high error correction (Q) so a smudged or scratched corner still reads.
        document.querySelectorAll('.js-qr').forEach(el => {
            const code = (el.dataset.code || '').trim();
            if (!code || typeof QRCode === 'undefined') return;
            try {
                new QRCode(el, { text: code, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.Q });
            } catch (_) { fail(el, code); }
        });

        // Barcode: Code 128, drawn once and scaled to the end's width (viewBox).
        document.querySelectorAll('.js-barcode').forEach(svg => {
            const code = (svg.dataset.barcode || '').trim();
            if (!code || typeof JsBarcode === 'undefined') return;
            try {
                JsBarcode(svg, code, { format: 'CODE128', displayValue: false, width: 2, height: 60, margin: 10 });
                const w = svg.getAttribute('width'), h = svg.getAttribute('height');
                svg.setAttribute('viewBox', '0 0 ' + parseFloat(w) + ' ' + parseFloat(h));
                svg.setAttribute('preserveAspectRatio', 'none');
                svg.removeAttribute('width');
                svg.removeAttribute('height');
            } catch (_) { fail(svg, code); }
        });

        if (failed) document.getElementById('code-error-summary').style.display = 'inline';
    })();
    </script>
</body>
</html>
