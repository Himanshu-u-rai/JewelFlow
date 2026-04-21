@extends('layouts.catalog')

@section('title', $category . ' — ' . $shop->name)
@section('og_title', $category . ' — ' . $shop->name)
@section('og_description', 'Browse our ' . $category . ' collection — ' . $itemCount . ' pieces available')

@section('head')
<style>
    .page-header {
        background: var(--bg-secondary);
        padding: 48px 0 40px;
        border-bottom: 1px solid var(--border-light);
    }

    .page-header h1 { font-size: clamp(28px, 4vw, 40px); margin-bottom: 6px; }
    .page-header p { color: var(--text-secondary); font-size: 15px; }

    .breadcrumb {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 13px;
        color: var(--text-muted);
        margin-bottom: 12px;
    }

    .breadcrumb a { color: var(--accent); }
    .breadcrumb span { opacity: 0.5; }

    .sub-chips {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    .sub-chip {
        padding: 8px 16px;
        font-size: 13px;
        font-weight: 500;
        color: var(--text-secondary);
        border: 1px solid var(--border);
        border-radius: 50px;
        background: var(--bg-primary);
        text-decoration: none;
        transition: all 0.2s;
    }

    .sub-chip:hover,
    .sub-chip.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }

    .sort-bar {
        display: flex;
        justify-content: flex-end;
        padding: 20px 0;
    }

    .filter-select {
        padding: 9px 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 13px;
        font-family: inherit;
        color: var(--text-secondary);
        outline: none;
        background: var(--bg-primary);
        cursor: pointer;
    }

    .item-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
        padding-bottom: 36px;
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

    .pagination-wrap { display: flex; justify-content: center; padding: 20px 0 48px; gap: 6px; }
    .pagination-wrap nav > div:first-child { display: none; }
    .pagination-wrap a, .pagination-wrap span { display: inline-flex; align-items: center; justify-content: center; min-width: 40px; height: 40px; padding: 0 12px; font-size: 14px; border-radius: 10px; border: 1px solid var(--border); color: var(--text-secondary); text-decoration: none; transition: all 0.2s; }
    .pagination-wrap a:hover { border-color: var(--accent); color: var(--accent); }
    .pagination-wrap span[aria-current] { background: var(--accent); color: #fff; border-color: var(--accent); }
    .pagination-wrap .pg-disabled { opacity: 0.4; cursor: not-allowed; }

    .empty-state { text-align: center; padding: 80px 20px; }
    .empty-state h3 { font-size: 22px; margin-bottom: 8px; }
    .empty-state p { color: var(--text-secondary); font-size: 15px; }

    @media (max-width: 768px) {
        .item-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .item-card-body { padding: 12px 14px; }
        .item-card-name { font-size: 14px; }
        .item-card-price { font-size: 15px; }
    }
</style>
@endsection

@section('content')
    <section class="page-header">
        <div class="cat-container">
            <div class="breadcrumb">
                <a href="{{ route('catalog.website.home', $shop->catalog_slug) }}">Home</a>
                <span>/</span>
                <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}">Products</a>
                <span>/</span>
                {{ $category }}
            </div>
            <h1>{{ $category }}</h1>
            <p>{{ $itemCount }} {{ Str::plural('piece', $itemCount) }} available</p>

            @if($subCategories->isNotEmpty())
                <div class="sub-chips">
                    <a href="{{ route('catalog.website.category', [$shop->catalog_slug, $category]) }}"
                       class="sub-chip {{ !request('sub_category') ? 'active' : '' }}">All</a>
                    @foreach($subCategories as $sub)
                        <a href="{{ route('catalog.website.category', [$shop->catalog_slug, $category, 'sub_category' => $sub]) }}"
                           class="sub-chip {{ request('sub_category') === $sub ? 'active' : '' }}">{{ $sub }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <div class="cat-container">
        <div class="sort-bar">
            <select class="filter-select" onchange="window.location.href=this.value">
                @foreach(['newest' => 'Newest First', 'price_asc' => 'Price: Low to High', 'price_desc' => 'Price: High to Low'] as $key => $label)
                    <option value="{{ route('catalog.website.category', array_merge(request()->except('page'), [$shop->catalog_slug, $category, 'sort' => $key])) }}" {{ ($sort ?? 'newest') === $key ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        @if($items->isNotEmpty())
            <div class="item-grid">
                @foreach($items as $item)
                    @include('public.catalog.partials.item-card', ['item' => $item])
                @endforeach
            </div>

            @if($items->hasPages())
            <div class="pagination-wrap">
                @if($items->onFirstPage())
                    <span class="pg-disabled">&laquo; Prev</span>
                @else
                    <a href="{{ $items->previousPageUrl() }}">&laquo; Prev</a>
                @endif

                @foreach($items->getUrlRange(max(1, $items->currentPage() - 2), min($items->lastPage(), $items->currentPage() + 2)) as $page => $url)
                    @if($page == $items->currentPage())
                        <span aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach

                @if($items->hasMorePages())
                    <a href="{{ $items->nextPageUrl() }}">Next &raquo;</a>
                @else
                    <span class="pg-disabled">Next &raquo;</span>
                @endif
            </div>
            @endif
        @else
            <div class="empty-state">
                <h3>No Products Found</h3>
                <p>This category is currently empty. Check back soon.</p>
            </div>
        @endif
    </div>
@endsection
