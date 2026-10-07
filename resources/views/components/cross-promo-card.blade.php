@props(['heading', 'body', 'cta', 'url', 'key' => 'default', 'realm' => null])
@php
    $override = $realm ? \App\Models\Platform\PlatformAnnouncement::crossPromoFor($realm) : null;
    if ($override) {
        $heading = $override->title ?: $heading;
        $body = $override->body ?: $body;
        $cta = $override->cta_label ?: $cta;
        $url = $override->cta_url ?: $url;
    }
    $preferencesPrefix = ($realm === 'dhiran' ? 'dhiran.' : '').'product-preferences';
@endphp
{{-- In-flow card cannot cover dashboard controls. Turbo must not restore a claimed introduction. --}}
<section class="product-introduction" data-cross-promo data-turbo-temporary aria-label="Other JewelFlows product">
    <style>
        .product-introduction { border:1px solid #d6e5df; border-radius:14px; padding:18px; margin-bottom:16px; background:#f3faf7; color:#142f28; }
        .product-introduction h2 { margin:0 0 6px; font-size:17px; font-weight:700; }
        .product-introduction p { margin:0 0 12px; font-size:14px; line-height:1.6; }
        .product-introduction__actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .product-introduction__actions form { margin:0; }
        .product-introduction .product-introduction__action { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:10px 14px; border:1px solid #57776c; border-radius:8px; background:white; color:#183e30; font:inherit; font-size:14px; text-decoration:none; cursor:pointer; }
        .product-introduction .product-introduction__action--primary { background:#194d3c; color:white; }
        .product-introduction :is(a,button):focus-visible { outline:3px solid #b77900; outline-offset:3px; }
    </style>
    <h2>{{ $heading }}</h2>
    <p>{{ $body }}</p>
    <div class="product-introduction__actions">
        <a href="{{ $url }}" class="product-introduction__action product-introduction__action--primary" data-turbo="false" rel="noopener">{{ $cta }}</a>
        <form method="POST" action="{{ route($preferencesPrefix.'.preference') }}" data-turbo="false">
            @csrf
            <button type="submit" class="product-introduction__action" name="choice" value="already_use">I already use this</button>
        </form>
        <form method="POST" action="{{ route($preferencesPrefix.'.preference') }}" data-turbo="false">
            @csrf
            <button type="submit" class="product-introduction__action" name="choice" value="opt_out">Don't show again</button>
        </form>
        <a href="{{ route($preferencesPrefix.'.index') }}" class="product-introduction__action" data-turbo="false">Product preferences</a>
    </div>
</section>
