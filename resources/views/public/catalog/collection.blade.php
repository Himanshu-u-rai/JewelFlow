@extends('layouts.catalog')

@section('title', ($collection->title ?? $shop->name . ' Collection') . ' — ' . $shop->name)
@section('og_title', ($collection->title ?? 'Curated Collection') . ' — ' . $shop->name)
@section('og_description', $items->count() . ' pieces curated specially for you')

@section('head')
<style>
    .collection-hero {
        background: var(--bg-secondary);
        padding: 56px 0;
        text-align: center;
        border-bottom: 1px solid var(--border-light);
    }

    .collection-badge {
        display: inline-block;
        padding: 6px 16px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: var(--accent);
        background: var(--accent-light);
        border-radius: 50px;
        margin-bottom: 20px;
    }

    .collection-hero h1 {
        font-size: clamp(28px, 4vw, 44px);
        margin-bottom: 8px;
    }

    .collection-hero-sub {
        color: var(--text-secondary);
        font-size: 15px;
        margin-bottom: 24px;
    }

    .collection-stats {
        display: flex;
        justify-content: center;
        gap: 40px;
        margin-top: 24px;
    }

    .collection-stat {
        text-align: center;
    }

    .collection-stat-value {
        font-family: 'Playfair Display', serif;
        font-size: 24px;
        font-weight: 600;
        color: var(--text-primary);
    }

    .collection-stat-label {
        font-size: 12px;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .collection-grid {
        padding: 48px 0;
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

    .browse-more {
        text-align: center;
        padding: 24px 0 48px;
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

    .btn-outline:hover { border-color: var(--accent); color: var(--accent); }

    @media (max-width: 768px) {
        .collection-hero { padding: 36px 0; }
        .collection-stats { gap: 24px; }
        .item-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .item-card-body { padding: 12px 14px; }
        .item-card-name { font-size: 14px; }
    }
</style>
@endsection

@section('content')
    <section class="collection-hero">
        <div class="cat-container">
            <span class="collection-badge">Curated For You</span>
            <h1>{{ $collection->title ?? $shop->name . ' Collection' }}</h1>
            <p class="collection-hero-sub">
                {{ $items->count() }} {{ Str::plural('piece', $items->count()) }} hand-picked from our collection
            </p>

            @php
                $totalValue = $items->sum('selling_price');
            @endphp
            <div class="collection-stats">
                <div class="collection-stat">
                    <div class="collection-stat-value">{{ $items->count() }}</div>
                    <div class="collection-stat-label">Pieces</div>
                </div>
                @if(($catalogSettings->show_prices ?? true) && $totalValue > 0)
                    <div class="collection-stat">
                        <div class="collection-stat-value">&#8377;{{ number_format((float) $totalValue, 0) }}</div>
                        <div class="collection-stat-label">Estimated Value</div>
                    </div>
                @endif
                <div class="collection-stat">
                    <div class="collection-stat-value">{{ $shop->name }}</div>
                    <div class="collection-stat-label">By</div>
                </div>
            </div>
        </div>
    </section>

    <section class="collection-grid">
        <div class="cat-container">
            <div class="item-grid">
                @foreach($items as $item)
                    @include('public.catalog.partials.item-card', ['item' => $item])
                @endforeach
            </div>

            <div class="browse-more">
                <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="btn-outline">
                    Browse Full Catalog &rarr;
                </a>
            </div>
        </div>
    </section>
@endsection
