<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $collection->title ?: 'Jewellery Collection' }} · {{ $shop?->name ?? 'JewelFlow' }}</title>
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
            --amber: #176f59;
            --amber-dark: #115543;
            --shadow: 0 20px 45px rgba(47, 36, 20, 0.09);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Manrope", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 10% -10%, #efe4d0 0%, rgba(239, 228, 208, 0) 45%),
                radial-gradient(circle at 110% 100%, #ece5d8 0%, rgba(236, 229, 216, 0) 42%),
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
            border: 1px solid var(--line);
            border-radius: 22px;
            background: var(--surface);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .hero-head {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
            padding: 20px;
            border-bottom: 1px solid var(--line);
            background: linear-gradient(145deg, #f9f4e8 0%, #fefcf7 60%);
        }

        @media (min-width: 960px) {
            .hero-head {
                grid-template-columns: 1.2fr auto;
                align-items: center;
                padding: 24px;
            }
        }

        h1 {
            margin: 0;
            font-family: "Noto Serif", serif;
            font-size: clamp(32px, 3.8vw, 52px);
            line-height: 1.04;
            letter-spacing: -0.03em;
        }

        .hero-sub {
            margin-top: 8px;
            color: #6a6255;
            font-size: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            border-radius: 12px;
            padding: 11px 14px;
            border: 1px solid transparent;
            transition: transform .12s ease, box-shadow .15s ease;
            white-space: nowrap;
        }

        .btn:active { transform: translateY(1px); }

        .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, var(--amber), var(--amber-dark));
            box-shadow: 0 12px 24px rgba(23, 111, 89, 0.23);
        }

        .btn-outline {
            color: #2f2618;
            background: #fff;
            border-color: #d5c7af;
        }

        .stats {
            padding: 14px 18px 18px;
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 10px;
        }

        @media (min-width: 760px) {
            .stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                padding: 14px 24px 20px;
            }
        }

        .stat {
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 12px;
            padding: 11px 12px;
        }

        .stat .k {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 800;
            color: #766d5d;
            margin-bottom: 3px;
        }

        .stat .v {
            font-size: 20px;
            font-weight: 800;
            color: #2b2418;
            letter-spacing: -0.01em;
        }

        .section-head {
            margin: 24px 0 12px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px;
            align-items: center;
        }

        .section-head h2 {
            margin: 0;
            font-family: "Noto Serif", serif;
            font-size: clamp(24px, 2.4vw, 30px);
            letter-spacing: -0.02em;
        }

        .section-head p {
            margin: 0;
            font-size: 13px;
            color: #70695a;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 14px;
        }

        @media (min-width: 760px) {
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (min-width: 1160px) {
            .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        .item {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--surface);
            box-shadow: 0 10px 26px rgba(41, 32, 19, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .media {
            background: linear-gradient(165deg, #f2eadc 0%, #f8f5ef 100%);
            aspect-ratio: 4 / 3;
            border-bottom: 1px solid var(--line);
            overflow: hidden;
        }

        .media img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .media-empty {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            color: #726853;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .body {
            padding: 12px 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .name {
            margin: 0;
            font-family: "Noto Serif", serif;
            font-size: 23px;
            line-height: 1.15;
            letter-spacing: -0.02em;
            color: #231c11;
        }

        .code {
            color: #61594c;
            font-size: 12px;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .meta-pill {
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--surface-2);
            color: #5d5344;
            padding: 5px 8px;
            font-size: 11px;
            font-weight: 700;
        }

        .price {
            border: 1px solid #d8c49b;
            background: linear-gradient(145deg, #f8ecd3, #f1ddb2);
            border-radius: 12px;
            padding: 9px 10px;
        }

        .price .k {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 800;
            color: #7b5d2a;
            margin-bottom: 2px;
        }

        .price .v {
            font-family: "Noto Serif", serif;
            font-size: 30px;
            line-height: 1;
            color: var(--accent-strong);
            font-weight: 700;
        }

        .mini {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .mini-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            padding: 8px;
        }

        .mini-box .k {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 800;
            color: #766d5d;
            margin-bottom: 3px;
        }

        .mini-box .v {
            font-size: 14px;
            font-weight: 800;
            color: #2f2719;
        }

        .actions {
            margin-top: 2px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        @media (max-width: 520px) {
            .actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
@php
    $phoneDigits = preg_replace('/\D+/', '', (string) ($shop?->phone ?? ''));
    $collectionTitle = trim((string) ($collection->title ?? ''));
    $title = $collectionTitle !== '' ? $collectionTitle : 'Jewellery Collection';
    $waText = rawurlencode('Hi, I am interested in your collection: ' . $title);
    $waLink = $phoneDigits ? 'https://wa.me/' . $phoneDigits . '?text=' . $waText : null;
@endphp

<div class="shell">
    <header class="topbar">
        <div class="brand">
            <div class="brand-mark"></div>
            <div>
                <div class="brand-name">{{ $shop?->name ?? 'JewelFlow' }}</div>
                <div class="brand-sub">Curated collection showcase</div>
            </div>
        </div>
        <div class="chip">Shared via JewelFlow Catalog</div>
    </header>

    <section class="hero">
        <div class="hero-head">
            <div>
                <h1>{{ $title }}</h1>
                <div class="hero-sub">
                    <span>{{ $items->count() }} item{{ $items->count() === 1 ? '' : 's' }}</span>
                    @if($collection->created_at)
                        <span>Created {{ $collection->created_at->format('d M Y') }}</span>
                    @endif
                    @if($shop?->phone)
                        <span>Contact {{ $shop->phone }}</span>
                    @endif
                </div>
            </div>

            <div class="hero-actions">
                @if($waLink)
                    <a class="btn btn-primary" href="{{ $waLink }}" target="_blank" rel="noopener noreferrer">Chat on WhatsApp</a>
                @endif
                @if($shop?->phone)
                    <a class="btn btn-outline" href="tel:{{ $shop->phone }}">Call Store</a>
                @endif
            </div>
        </div>

        <div class="stats">
            <div class="stat">
                <div class="k">Total items</div>
                <div class="v">{{ $items->count() }}</div>
            </div>
            <div class="stat">
                <div class="k">Estimated value</div>
                <div class="v">₹{{ number_format((float) $items->sum('selling_price'), 2) }}</div>
            </div>
            <div class="stat">
                <div class="k">Shop</div>
                <div class="v">{{ $shop?->name ?? 'JewelFlow' }}</div>
            </div>
        </div>
    </section>

    <div class="section-head">
        <h2>Collection Pieces</h2>
        <p>Tap any piece for direct inquiry or single-product page.</p>
    </div>

    <section class="grid">
        @foreach($items as $item)
            @php
                $name = $item->design ?: (($item->category ?? 'Jewellery') . ($item->sub_category ? ' · ' . $item->sub_category : ''));
                $purity = $item->purity ? rtrim(rtrim(number_format((float) $item->purity, 2, '.', ''), '0'), '.') . 'K' : '—';
                $shareUrl = $itemShareUrls[$item->id] ?? null;
                $itemWaText = rawurlencode('Hi, I am interested in ' . ($item->design ?: $item->barcode) . ' (' . $item->barcode . ').');
                $itemWaLink = $phoneDigits ? 'https://wa.me/' . $phoneDigits . '?text=' . $itemWaText : null;
                $img = $imageUrls[$item->id] ?? null;
            @endphp

            <article class="item">
                <div class="media">
                    @if($img)
                        <img src="{{ $img }}" alt="{{ $name }}">
                    @else
                        <div class="media-empty">Image not available</div>
                    @endif
                </div>

                <div class="body">
                    <h3 class="name">{{ $name }}</h3>
                    <div class="code">{{ $item->barcode }}</div>

                    <div class="meta">
                        <span class="meta-pill">{{ $item->category ?? 'Jewellery' }}{{ $item->sub_category ? ' · ' . $item->sub_category : '' }}</span>
                        <span class="meta-pill">{{ $purity }}</span>
                    </div>

                    <div class="price">
                        <div class="k">Selling price</div>
                        <div class="v">₹{{ number_format((float) ($item->selling_price ?? 0), 2) }}</div>
                    </div>

                    <div class="mini">
                        <div class="mini-box">
                            <div class="k">Gross wt</div>
                            <div class="v">{{ number_format((float) ($item->gross_weight ?? 0), 3) }} g</div>
                        </div>
                        <div class="mini-box">
                            <div class="k">Purity</div>
                            <div class="v">{{ $purity }}</div>
                        </div>
                    </div>

                    <div class="actions">
                        @if($itemWaLink)
                            <a class="btn btn-primary" href="{{ $itemWaLink }}" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                        @else
                            <span class="btn btn-primary" style="opacity:.55; pointer-events:none;">WhatsApp</span>
                        @endif

                        @if($shareUrl)
                            <a class="btn btn-outline" href="{{ $shareUrl }}" target="_blank" rel="noopener noreferrer">Public Page</a>
                        @else
                            <span class="btn btn-outline" style="opacity:.55; pointer-events:none;">Public Page</span>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </section>
</div>
</body>
</html>
