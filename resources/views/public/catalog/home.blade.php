@extends('layouts.catalog')

@section('title', $shop->name . ' — Jewelry Catalog')
@section('og_title', $shop->name)
@section('og_description', $catalogSettings->tagline ?? 'Browse our curated jewelry collection')

@section('head')
<style>
    /* ─── Hero ─── */
    .hero {
        background: var(--bg-secondary);
        padding: 80px 0;
        text-align: center;
    }

    .hero-tagline {
        font-size: 14px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 3px;
        color: var(--accent);
        margin-bottom: 16px;
    }

    .hero h1 {
        font-size: clamp(32px, 5vw, 56px);
        color: var(--text-primary);
        margin-bottom: 16px;
    }

    .hero-sub {
        font-size: 17px;
        color: var(--text-secondary);
        max-width: 560px;
        margin: 0 auto 32px;
    }

    .hero-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
    }

    .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 32px;
        background: var(--accent);
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        border-radius: 50px;
        transition: all 0.2s;
        letter-spacing: 0.3px;
    }

    .btn-primary:hover {
        filter: brightness(1.1);
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.12);
    }

    .btn-outline {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 14px 32px;
        background: transparent;
        color: var(--text-primary);
        font-size: 14px;
        font-weight: 600;
        border: 1.5px solid var(--border);
        border-radius: 50px;
        transition: all 0.2s;
    }

    .btn-outline:hover {
        border-color: var(--accent);
        color: var(--accent);
    }

    /* ─── Section ─── */
    .section {
        padding: 72px 0;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        margin-bottom: 36px;
    }

    .section-title {
        font-size: 28px;
    }

    .section-link {
        font-size: 13px;
        font-weight: 600;
        color: var(--accent);
        text-transform: uppercase;
        letter-spacing: 1px;
        transition: opacity 0.2s;
    }

    .section-link:hover { opacity: 0.7; }

    /* ─── Category cards ─── */
    .cat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 20px;
    }

    .cat-card {
        display: block;
        border-radius: 16px;
        overflow: hidden;
        background: var(--bg-primary);
        border: 1px solid var(--border-light);
        transition: all 0.25s;
    }

    .cat-card:hover {
        border-color: var(--accent);
        box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        transform: translateY(-3px);
    }

    .cat-card-img-wrap {
        aspect-ratio: 4/3;
        background: var(--bg-tertiary);
        overflow: hidden;
    }

    .cat-card-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s;
    }

    .cat-card:hover .cat-card-img { transform: scale(1.05); }

    .cat-card-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-tertiary);
    }

    .cat-card-body {
        padding: 16px 18px;
    }

    .cat-card-name {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 2px;
    }

    .cat-card-count {
        font-size: 12px;
        color: var(--text-muted);
    }

    /* ─── Item cards ─── */
    .item-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }

    .item-card {
        display: block;
        border-radius: 16px;
        overflow: hidden;
        background: var(--bg-primary);
        border: 1px solid var(--border-light);
        transition: all 0.25s;
    }

    .item-card:hover {
        border-color: var(--accent);
        box-shadow: 0 8px 30px rgba(0,0,0,0.06);
        transform: translateY(-3px);
    }

    .item-card-img-wrap {
        aspect-ratio: 4/5;
        background: var(--bg-tertiary);
        overflow: hidden;
        position: relative;
    }

    .item-card-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.4s;
    }

    .item-card:hover .item-card-img { transform: scale(1.05); }

    .item-card-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-tertiary);
    }

    .item-card-badge {
        position: absolute;
        top: 12px;
        left: 12px;
        padding: 4px 10px;
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        background: rgba(255,255,255,0.92);
        border-radius: 6px;
        color: var(--text-secondary);
        backdrop-filter: blur(4px);
    }

    .item-card-body {
        padding: 16px 18px;
    }

    .item-card-name {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .item-card-sub {
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 10px;
    }

    .item-card-meta {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .item-card-price {
        font-family: 'Playfair Display', serif;
        font-size: 18px;
        font-weight: 600;
        color: var(--accent);
    }

    .item-card-weight,
    .item-card-purity {
        font-size: 12px;
        color: var(--text-muted);
        padding: 3px 8px;
        background: var(--bg-tertiary);
        border-radius: 4px;
    }

    /* ─── Responsive ─── */
    @media (max-width: 768px) {
        .hero { padding: 48px 0; }
        .section { padding: 48px 0; }
        .item-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .cat-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .item-card-body { padding: 12px 14px; }
        .item-card-name { font-size: 14px; }
        .item-card-price { font-size: 15px; }
    }
</style>
@endsection

@section('content')
    {{-- Hero --}}
    <section class="hero">
        <div class="cat-container">
            <p class="hero-tagline">Welcome to</p>
            <h1>{{ $shop->name }}</h1>
            @if($catalogSettings->tagline)
                <p class="hero-sub">{{ $catalogSettings->tagline }}</p>
            @else
                <p class="hero-sub">Discover our exquisite collection of fine jewelry, curated with passion and craftsmanship.</p>
            @endif
            <div class="hero-actions">
                <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="btn-primary">Browse Collection</a>
                @php $waNum = preg_replace('/\D/', '', $shop->shop_whatsapp ?? $shop->phone ?? ''); @endphp
                @if($waNum)
                    <a href="https://wa.me/{{ $waNum }}" target="_blank" rel="noopener" class="btn-outline">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.553 4.113 1.519 5.845L.058 23.7a.5.5 0 00.612.612l5.855-1.46A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
                        Chat with Us
                    </a>
                @endif
            </div>
        </div>
    </section>

    {{-- Categories --}}
    @if($categoryData->isNotEmpty())
        <section class="section">
            <div class="cat-container">
                <div class="section-header">
                    <h2 class="section-title">Shop by Category</h2>
                    <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="section-link">View All &rarr;</a>
                </div>
                <div class="cat-grid">
                    @foreach($categoryData->take(8) as $cat)
                        @include('public.catalog.partials.category-card', ['cat' => $cat])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Recent items --}}
    @if($recentItems->isNotEmpty())
        <section class="section" style="background: var(--bg-secondary);">
            <div class="cat-container">
                <div class="section-header">
                    <h2 class="section-title">New Arrivals</h2>
                    <a href="{{ route('catalog.website.products', ['slug' => $shop->catalog_slug, 'sort' => 'newest']) }}" class="section-link">View All &rarr;</a>
                </div>
                <div class="item-grid">
                    @foreach($recentItems as $item)
                        @include('public.catalog.partials.item-card', ['item' => $item, 'imageUrls' => $recentImageUrls])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- CTA --}}
    <section class="section" style="text-align:center;">
        <div class="cat-container">
            <h2 class="section-title" style="margin-bottom:12px;">Looking for Something Special?</h2>
            <p style="color:var(--text-secondary);font-size:16px;margin-bottom:28px;max-width:480px;margin-left:auto;margin-right:auto;">
                We'd love to help you find the perfect piece. Reach out to us and let's create something memorable.
            </p>
            @if($waNum)
                <a href="https://wa.me/{{ $waNum }}" target="_blank" rel="noopener" class="btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.553 4.113 1.519 5.845L.058 23.7a.5.5 0 00.612.612l5.855-1.46A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
                    Get in Touch
                </a>
            @endif
        </div>
    </section>
@endsection
