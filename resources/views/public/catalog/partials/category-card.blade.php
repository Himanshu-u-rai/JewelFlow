<a href="{{ route('catalog.website.category', [$shop->catalog_slug, $cat->name]) }}" class="cat-card">
    <div class="cat-card-img-wrap">
        @if($cat->image_url)
            <img src="{{ $cat->image_url }}" alt="{{ $cat->name }}" class="cat-card-img" loading="lazy">
        @else
            <div class="cat-card-placeholder">
                <x-icon name="layers" size="32" opacity="0.3" stroke-width="1" />
            </div>
        @endif
    </div>
    <div class="cat-card-body">
        <h3 class="cat-card-name">{{ $cat->name }}</h3>
        <p class="cat-card-count">{{ $cat->count }} {{ Str::plural('piece', $cat->count) }}</p>
    </div>
</a>
