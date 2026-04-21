<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $item->design ?: 'Product' }} · {{ $shop?->name ?? 'JewelFlow' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Noto+Serif:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8f5ef;
            --surface: #fffdf9;
            --surface-2: #f3efe7;
            --ink: #1d1a14;
            --muted: #6b6457;
            --line: #e2dbcf;
            --accent: #8f6a2d;
            --accent-strong: #6f4f1e;
            --accent-soft: #efe3c9;
            --ok: #1f6d46;
            --warn: #9f2d2d;
            --shadow: 0 20px 45px rgba(47, 36, 20, 0.09);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Manrope", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 0% 0%, #f0e6d4 0%, rgba(240, 230, 212, 0) 45%),
                radial-gradient(circle at 100% 100%, #ece5d8 0%, rgba(236, 229, 216, 0) 42%),
                var(--bg);
        }

        .shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 22px 18px 56px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            background: rgba(255, 253, 249, 0.94);
            border: 1px solid var(--line);
            padding: 12px 16px;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(39, 30, 18, 0.06);
            backdrop-filter: blur(8px);
        }

        .brand {
            display: flex;
            gap: 10px;
            align-items: center;
            min-width: 0;
        }

        .brand-mark {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: linear-gradient(145deg, #d9b672, #9d742f);
            box-shadow: 0 0 0 4px rgba(191, 151, 82, 0.25);
            flex-shrink: 0;
        }

        .brand-name {
            font-family: "Noto Serif", serif;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.02em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .brand-sub {
            margin-top: 1px;
            font-size: 12px;
            color: var(--muted);
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--surface);
            font-size: 12px;
            font-weight: 700;
            color: #5e5547;
            white-space: nowrap;
        }

        .hero {
            margin-top: 16px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 22px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr;
        }

        @media (min-width: 980px) {
            .hero-grid { grid-template-columns: minmax(420px, 52%) 1fr; }
        }

        .image-wrap {
            background: linear-gradient(165deg, #f2eadc 0%, #f8f5ef 100%);
            border-bottom: 1px solid var(--line);
            padding: 20px;
        }

        @media (min-width: 980px) {
            .image-wrap {
                border-bottom: 0;
                border-right: 1px solid var(--line);
                padding: 24px;
            }
        }

        .image-box {
            border-radius: 16px;
            overflow: hidden;
            background: #ede7dc;
            aspect-ratio: 4 / 5;
            box-shadow: 0 16px 34px rgba(42, 33, 21, 0.16);
        }

        .image-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .image-empty {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            text-align: center;
            color: #726853;
            font-size: 13px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
            border: 2px dashed #ccbfa9;
            border-radius: 16px;
        }

        .content {
            padding: 22px;
        }

        @media (min-width: 980px) {
            .content { padding: 28px 28px 24px; }
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .meta-pill {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--surface-2);
            color: #594f40;
        }

        .status.stock {
            color: var(--ok);
            background: #eaf7ef;
            border-color: #b8dfc7;
        }

        .status.other {
            color: var(--warn);
            background: #fef0f0;
            border-color: #f2c5c5;
        }

        h1 {
            margin: 0;
            font-family: "Noto Serif", serif;
            font-size: clamp(34px, 4vw, 52px);
            line-height: 1.05;
            letter-spacing: -0.03em;
        }

        .code {
            margin: 8px 0 16px;
            color: #5f5748;
            font-size: 13px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-weight: 700;
        }

        .price {
            border: 1px solid #d8c49b;
            background: linear-gradient(145deg, #f8ecd3, #f1ddb2);
            border-radius: 14px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .price .label {
            font-size: 11px;
            letter-spacing: 0.08em;
            font-weight: 800;
            text-transform: uppercase;
            color: #7b5d2a;
            margin-bottom: 4px;
        }

        .price .value {
            font-family: "Noto Serif", serif;
            font-size: clamp(30px, 3vw, 38px);
            color: var(--accent-strong);
            line-height: 1;
            font-weight: 700;
        }

        .spec-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        @media (max-width: 640px) {
            .spec-grid { grid-template-columns: 1fr; }
        }

        .spec {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 10px 11px;
            background: #fff;
        }

        .spec .k {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 800;
            color: #746b5a;
            margin-bottom: 3px;
        }

        .spec .v {
            font-size: 17px;
            font-weight: 800;
            color: #2d2518;
            letter-spacing: -0.01em;
        }

        .actions {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        @media (max-width: 560px) {
            .actions { grid-template-columns: 1fr; }
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
            border-radius: 12px;
            padding: 12px 14px;
            border: 1px solid transparent;
            transition: transform .12s ease, box-shadow .15s ease;
        }

        .btn:active { transform: translateY(1px); }

        .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, #17805f, #116a4d);
            box-shadow: 0 12px 24px rgba(23, 128, 95, 0.26);
        }

        .btn-outline {
            color: #2f2618;
            background: #fff;
            border-color: #d5c7af;
        }

        .foot-note {
            margin-top: 14px;
            font-size: 12px;
            color: #746b5a;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .support {
            margin-top: 18px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--surface);
            box-shadow: 0 12px 28px rgba(44, 34, 20, 0.06);
            padding: 18px;
            display: grid;
            gap: 10px;
        }

        @media (min-width: 880px) {
            .support {
                grid-template-columns: 1.2fr 1fr;
                align-items: center;
            }
        }

        .support h2 {
            margin: 0;
            font-family: "Noto Serif", serif;
            font-size: clamp(24px, 2.3vw, 30px);
            line-height: 1.15;
            letter-spacing: -0.02em;
        }

        .support p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
            max-width: 62ch;
            line-height: 1.6;
        }

        .support-right {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-start;
        }

        @media (min-width: 880px) {
            .support-right {
                justify-content: flex-end;
            }
        }

        .support-tag {
            font-size: 12px;
            font-weight: 700;
            color: #5f5647;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
        }
    </style>
</head>
<body>
@php
    $phoneDigits = preg_replace('/\D+/', '', (string) ($shop?->phone ?? ''));
    $name = $item->design ?: (($item->category ?? 'Jewellery') . ($item->sub_category ? ' ' . $item->sub_category : ' Item'));
    $waText = rawurlencode('Hi, I am interested in ' . $name . ' (' . $item->barcode . ').');
    $waLink = $phoneDigits ? 'https://wa.me/' . $phoneDigits . '?text=' . $waText : null;
    $purity = $item->purity ? rtrim(rtrim(number_format((float) $item->purity, 2, '.', ''), '0'), '.') . 'K' : '—';
@endphp

<div class="shell">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark"></div>
            <div>
                <div class="brand-name">{{ $shop?->name ?? 'JewelFlow' }}</div>
                <div class="brand-sub">Curated jewellery listing</div>
            </div>
        </div>
        <div class="chip">Shared via JewelFlow Catalog</div>
    </header>

    <section class="hero">
        <div class="hero-grid">
            <div class="image-wrap">
                <div class="image-box">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $name }}">
                    @else
                        <div class="image-empty">Image not available</div>
                    @endif
                </div>
            </div>

            <div class="content">
                <div class="meta">
                    <span class="meta-pill">{{ $item->category ?? 'Jewellery' }}{{ $item->sub_category ? ' · ' . $item->sub_category : '' }}</span>
                    <span class="meta-pill">{{ $purity }}</span>
                    <span class="meta-pill status {{ $item->status === 'in_stock' ? 'stock' : 'other' }}">
                        {{ $item->status === 'in_stock' ? 'Available' : ucfirst($item->status) }}
                    </span>
                </div>

                <h1>{{ $name }}</h1>
                <div class="code">{{ $item->barcode }}</div>

                <div class="price">
                    <div class="label">Selling price</div>
                    <div class="value">₹{{ number_format((float) ($item->selling_price ?? 0), 2) }}</div>
                </div>

                <div class="spec-grid">
                    <div class="spec">
                        <div class="k">Gross weight</div>
                        <div class="v">{{ number_format((float) ($item->gross_weight ?? 0), 3) }} g</div>
                    </div>
                    <div class="spec">
                        <div class="k">Net metal</div>
                        <div class="v">{{ number_format((float) ($item->net_metal_weight ?? 0), 3) }} g</div>
                    </div>
                    <div class="spec">
                        <div class="k">Purity</div>
                        <div class="v">{{ $purity }}</div>
                    </div>
                    <div class="spec">
                        <div class="k">HUID</div>
                        <div class="v">{{ $item->huid ?: '—' }}</div>
                    </div>
                </div>

                <div class="actions">
                    @if($waLink)
                        <a class="btn btn-primary" href="{{ $waLink }}" target="_blank" rel="noopener noreferrer">Chat on WhatsApp</a>
                    @endif
                    @if($shop?->phone)
                        <a class="btn btn-outline" href="tel:{{ $shop->phone }}">Call Store</a>
                    @endif
                </div>

                <div class="foot-note">
                    @if($shop?->phone)
                        <span>Contact: {{ $shop->phone }}</span>
                    @endif
                    @if($item->updated_at)
                        <span>Updated {{ $item->updated_at->format('d M Y, h:i A') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="support">
        <div>
            <h2>Need a personalized recommendation?</h2>
            <p>
                Share your budget, preferred style, and occasion. The store team can suggest matching pieces, alternatives, and coordinated sets from this collection.
            </p>
        </div>
        <div class="support-right">
            <span class="support-tag">Store verified pricing</span>
            <span class="support-tag">Direct WhatsApp inquiry</span>
            <span class="support-tag">Quick response support</span>
        </div>
    </section>
</div>
</body>
</html>
