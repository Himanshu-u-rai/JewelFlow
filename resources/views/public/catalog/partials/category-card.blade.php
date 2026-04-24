<a href="{{ route('catalog.website.category', [$shop->catalog_slug, $cat->name]) }}" class="cat-card">
    <div class="cat-card-img-wrap">
        @if($cat->image_url)
            <img src="{{ $cat->image_url }}" alt="{{ $cat->name }}" class="cat-card-img" loading="lazy">
        @else
            <div class="cat-card-placeholder">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity="0.3">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>
                </svg>
            </div>
        @endif
    </div>
    <div class="cat-card-body">
        <h3 class="cat-card-name">{{ $cat->name }}</h3>
        <p class="cat-card-count">{{ $cat->count }} {{ Str::plural('piece', $cat->count) }}</p>
    </div>
</a>
