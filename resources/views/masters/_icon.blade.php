{{-- Decorative card icons; the wrapping element carries aria-hidden. --}}
@switch($icon)
    @case('customers')
        <x-icon name="users" size="20" />
        @break
    @case('vendors')
        <x-icon name="truck" size="20" />
        @break
    @case('karigars')
        <x-icon name="hammer" size="20" />
        @break
    @case('categories')
        <x-icon name="tag" size="20" />
        @break
    @case('products')
        <x-icon name="layout-grid" size="20" />
        @break
    @case('gst')
        <x-icon name="file-text" size="20" />
        @break
    @case('purity')
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12l4 6-10 13L2 9z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/></svg>
        @break
@endswitch
