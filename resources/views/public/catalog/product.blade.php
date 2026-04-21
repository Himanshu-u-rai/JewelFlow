@extends('layouts.catalog')

@section('title', ($item->design ?? $item->barcode) . ' — ' . $shop->name)
@section('og_title', ($item->design ?? $item->barcode) . ' — ' . $shop->name)
@section('og_description', ($item->category ? $item->category . ' · ' : '') . ($item->purity ? $item->purity . 'K · ' : '') . ($item->gross_weight ? number_format((float)$item->gross_weight, 2) . 'g' : ''))
@if($imageUrl)
    @section('og_image', $imageUrl)
@endif

@section('head')
<style>
    .breadcrumb {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 13px;
        color: var(--text-muted);
        padding: 20px 0;
    }

    .breadcrumb a { color: var(--accent); }
    .breadcrumb span { opacity: 0.5; }

    /* ─── Product layout ─── */
    .product-layout {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 60px;
        padding-bottom: 60px;
    }

    .product-image-wrap {
        position: sticky;
        top: 92px;
        align-self: start;
    }

    .product-image-frame {
        aspect-ratio: 4/5;
        border-radius: 20px;
        overflow: hidden;
        background: var(--bg-tertiary);
        border: 1px solid var(--border-light);
    }

    .product-image-frame img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .product-image-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 12px;
        color: var(--text-muted);
        font-size: 14px;
    }

    /* ─── Product info ─── */
    .product-info { padding-top: 8px; }

    .product-badges {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }

    .product-badge {
        padding: 5px 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        border-radius: 6px;
        background: var(--bg-tertiary);
        color: var(--text-secondary);
    }

    .product-badge.accent {
        background: var(--accent-light);
        color: var(--accent);
    }

    .product-name {
        font-size: clamp(28px, 3.5vw, 40px);
        margin-bottom: 4px;
    }

    .product-code {
        font-size: 14px;
        color: var(--text-muted);
        margin-bottom: 24px;
    }

    .product-price-box {
        background: linear-gradient(135deg, var(--accent), #b8923a);
        border-radius: 16px;
        padding: 24px 28px;
        margin-bottom: 32px;
    }

    .product-price-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: rgba(255,255,255,0.7);
        margin-bottom: 4px;
    }

    .product-price-value {
        font-family: 'Playfair Display', serif;
        font-size: 36px;
        font-weight: 700;
        color: #fff;
    }

    .product-divider {
        height: 1px;
        background: var(--border);
        margin: 28px 0;
    }

    /* ─── Specs grid ─── */
    .specs-title {
        font-family: 'Inter', sans-serif;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: var(--text-muted);
        margin-bottom: 16px;
    }

    .specs-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1px;
        background: var(--border-light);
        border: 1px solid var(--border-light);
        border-radius: 14px;
        overflow: hidden;
        margin-bottom: 32px;
    }

    .spec-cell {
        background: var(--bg-primary);
        padding: 18px 20px;
    }

    .spec-cell-label {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: var(--text-muted);
        margin-bottom: 4px;
    }

    .spec-cell-value {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
    }

    /* ─── Actions ─── */
    .product-actions {
        display: flex;
        gap: 12px;
        margin-bottom: 28px;
    }

    .btn-wa {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 16px 28px;
        background: #25D366;
        color: #fff;
        font-size: 15px;
        font-weight: 600;
        border-radius: 14px;
        transition: all 0.2s;
    }

    .btn-wa:hover {
        background: #1fb855;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(37, 211, 102, 0.25);
    }

    .btn-call {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        padding: 16px 28px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-size: 15px;
        font-weight: 600;
        border: 1.5px solid var(--border);
        border-radius: 14px;
        transition: all 0.2s;
    }

    .btn-call:hover {
        border-color: var(--accent);
        color: var(--accent);
    }

    .product-note {
        font-size: 13px;
        color: var(--text-muted);
        text-align: center;
        padding: 16px;
        background: var(--bg-secondary);
        border-radius: 12px;
    }

    /* ─── Related ─── */
    .related-section {
        background: var(--bg-secondary);
        padding: 72px 0;
    }

    .related-section .section-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 32px;
    }

    .section-title { font-size: 28px; }

    .section-link {
        font-size: 13px;
        font-weight: 600;
        color: var(--accent);
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .item-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }

    .item-card { display: block; border-radius: 16px; overflow: hidden; background: var(--bg-primary); border: 1px solid var(--border-light); transition: all 0.25s; }
    .item-card:hover { border-color: var(--accent); box-shadow: 0 8px 30px rgba(0,0,0,0.06); transform: translateY(-3px); }
    .item-card-img-wrap { aspect-ratio: 4/5; background: var(--bg-tertiary); overflow: hidden; position: relative; }
    .item-card-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s; }
    .item-card:hover .item-card-img { transform: scale(1.05); }
    .item-card-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: var(--bg-tertiary); }
    .item-card-badge { position: absolute; top: 12px; left: 12px; padding: 4px 10px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; background: rgba(255,255,255,0.92); border-radius: 6px; color: var(--text-secondary); backdrop-filter: blur(4px); }
    .item-card-body { padding: 16px 18px; }
    .item-card-name { font-family: 'Playfair Display', serif; font-size: 16px; font-weight: 600; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-card-sub { font-size: 12px; color: var(--text-muted); margin-bottom: 10px; }
    .item-card-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .item-card-price { font-family: 'Playfair Display', serif; font-size: 18px; font-weight: 600; color: var(--accent); }
    .item-card-weight, .item-card-purity { font-size: 12px; color: var(--text-muted); padding: 3px 8px; background: var(--bg-tertiary); border-radius: 4px; }

    @media (max-width: 768px) {
        .product-layout {
            grid-template-columns: 1fr;
            gap: 28px;
        }

        .product-image-wrap { position: static; }
        .product-price-value { font-size: 28px; }
        .product-actions { flex-direction: column; }
        .item-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    }
</style>
@endsection

@section('content')
    <div class="cat-container">
        <div class="breadcrumb">
            <a href="{{ route('catalog.website.home', $shop->catalog_slug) }}">Home</a>
            <span>/</span>
            @if($item->category)
                <a href="{{ route('catalog.website.category', [$shop->catalog_slug, $item->category]) }}">{{ $item->category }}</a>
                <span>/</span>
            @endif
            {{ $item->design ?? $item->barcode }}
        </div>

        <div class="product-layout">
            {{-- Image --}}
            <div class="product-image-wrap">
                <div class="product-image-frame">
                    @if($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $item->design ?? $item->barcode }}">
                    @else
                        <div class="product-image-placeholder">
                            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity="0.25">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                            </svg>
                            <span>Image not available</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Info --}}
            <div class="product-info">
                <div class="product-badges">
                    @if($item->category)
                        <span class="product-badge">{{ $item->category }}@if($item->sub_category) &middot; {{ $item->sub_category }}@endif</span>
                    @endif
                    @if($item->purity)
                        <span class="product-badge accent">{{ $item->purity }}K</span>
                    @endif
                    <span class="product-badge" style="background:#d1fae5;color:#065f46;">Available</span>
                </div>

                <h1 class="product-name">{{ $item->design ?? $item->barcode }}</h1>
                <p class="product-code">{{ $item->barcode }}</p>

                @if(($catalogSettings->show_prices ?? true) && $item->selling_price)
                    <div class="product-price-box">
                        <div class="product-price-label">Selling Price</div>
                        <div class="product-price-value">&#8377;{{ number_format((float) $item->selling_price, 2) }}</div>
                    </div>
                @endif

                {{-- Specs --}}
                <p class="specs-title">Specifications</p>
                <div class="specs-grid">
                    @if(($catalogSettings->show_weights ?? true) && $item->gross_weight)
                        <div class="spec-cell">
                            <div class="spec-cell-label">Gross Weight</div>
                            <div class="spec-cell-value">{{ number_format((float) $item->gross_weight, 3) }} g</div>
                        </div>
                    @endif
                    @if(($catalogSettings->show_weights ?? true) && $item->net_metal_weight)
                        <div class="spec-cell">
                            <div class="spec-cell-label">Net Metal</div>
                            <div class="spec-cell-value">{{ number_format((float) $item->net_metal_weight, 3) }} g</div>
                        </div>
                    @endif
                    @if($item->purity)
                        <div class="spec-cell">
                            <div class="spec-cell-label">Purity</div>
                            <div class="spec-cell-value">{{ $item->purity }}K</div>
                        </div>
                    @endif
                    @if(($catalogSettings->show_huid ?? false) && $item->huid)
                        <div class="spec-cell">
                            <div class="spec-cell-label">HUID</div>
                            <div class="spec-cell-value">{{ $item->huid }}</div>
                        </div>
                    @endif
                    @if($item->stone_weight && (float) $item->stone_weight > 0)
                        <div class="spec-cell">
                            <div class="spec-cell-label">Stone Weight</div>
                            <div class="spec-cell-value">{{ number_format((float) $item->stone_weight, 3) }} g</div>
                        </div>
                    @endif
                    @if($item->category)
                        <div class="spec-cell">
                            <div class="spec-cell-label">Category</div>
                            <div class="spec-cell-value">{{ $item->category }}</div>
                        </div>
                    @endif
                </div>

                <div class="product-divider"></div>

                {{-- CTA --}}
                @php
                    $waNum = preg_replace('/\D/', '', $shop->shop_whatsapp ?? $shop->phone ?? '');
                    $waText = urlencode("Hi! I'm interested in " . ($item->design ?? $item->barcode) . " (" . $item->barcode . "). Could you share more details?");
                @endphp

                <div class="product-actions">
                    @if($waNum)
                        <a href="https://wa.me/{{ $waNum }}?text={{ $waText }}" target="_blank" rel="noopener" class="btn-wa">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.553 4.113 1.519 5.845L.058 23.7a.5.5 0 00.612.612l5.855-1.46A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
                            Chat on WhatsApp
                        </a>
                    @endif
                    @if($shop->phone)
                        <a href="tel:{{ $shop->phone }}" class="btn-call">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                            Call Store
                        </a>
                    @endif
                </div>

                <div class="product-note">
                    Contact us for personalized pricing, availability, and customization options.
                    @if($shop->phone)
                        <br>{{ $shop->phone }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Related --}}
    @if($relatedItems->isNotEmpty())
        <section class="related-section">
            <div class="cat-container">
                <div class="section-header">
                    <h2 class="section-title">You May Also Like</h2>
                    @if($item->category)
                        <a href="{{ route('catalog.website.category', [$shop->catalog_slug, $item->category]) }}" class="section-link">View All &rarr;</a>
                    @endif
                </div>
                <div class="item-grid">
                    @foreach($relatedItems as $related)
                        @include('public.catalog.partials.item-card', ['item' => $related, 'imageUrls' => $relatedImageUrls])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
