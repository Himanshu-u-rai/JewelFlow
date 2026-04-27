<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $shop->name . ' — Jewelry Catalog')</title>
    @include('partials.favicon')
    <meta name="description" content="@yield('meta_description', $catalogSettings->meta_description ?? $shop->name . ' — Browse our curated jewelry collection')">

    {{-- OG tags for WhatsApp link previews --}}
    <meta property="og:title" content="@yield('og_title', $shop->name)">
    <meta property="og:description" content="@yield('og_description', $catalogSettings->tagline ?? 'Browse our curated jewelry collection')">
    <meta property="og:type" content="website">
    @hasSection('og_image')
        <meta property="og:image" content="@yield('og_image')">
    @endif

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --accent: {{ $catalogSettings->accent_color ?? '#8f6a2d' }};
            --accent-light: {{ $catalogSettings->accent_color ?? '#8f6a2d' }}1a;
            --text-primary: #1a1a1a;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --bg-primary: #ffffff;
            --bg-secondary: #fafaf8;
            --bg-tertiary: #f5f3ef;
            --border: #e8e5e0;
            --border-light: #f0ede8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-primary);
            background: var(--bg-primary);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Playfair Display', Georgia, serif;
            font-weight: 500;
            line-height: 1.2;
        }

        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; height: auto; display: block; }

        /* ─── Navbar ─── */
        .cat-nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-light);
        }

        .cat-nav-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 72px;
        }

        .cat-nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .cat-nav-logo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid var(--border);
        }

        .cat-nav-logo-placeholder {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Playfair Display', serif;
            font-size: 18px;
            font-weight: 600;
        }

        .cat-nav-shop-name {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: -0.3px;
        }

        .cat-nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
            list-style: none;
        }

        .cat-nav-link {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            border-radius: 8px;
            transition: all 0.2s;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .cat-nav-link:hover,
        .cat-nav-link.active {
            color: var(--text-primary);
            background: var(--bg-tertiary);
        }

        .cat-nav-dropdown {
            position: relative;
        }

        .cat-nav-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 4px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 8px;
            min-width: 200px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.08);
            z-index: 200;
        }

        .cat-nav-dropdown:hover .cat-nav-dropdown-menu {
            display: block;
        }

        .cat-nav-dropdown-item {
            display: block;
            padding: 10px 14px;
            font-size: 13px;
            color: var(--text-secondary);
            border-radius: 8px;
            transition: all 0.15s;
        }

        .cat-nav-dropdown-item:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .cat-nav-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #25D366;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            border-radius: 50px;
            transition: all 0.2s;
            letter-spacing: 0.2px;
        }

        .cat-nav-cta:hover {
            background: #1fb855;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 211, 102, 0.3);
        }

        .cat-nav-cta svg { width: 16px; height: 16px; }

        /* Mobile nav */
        .cat-nav-toggle {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            color: var(--text-primary);
        }

        .cat-mobile-menu {
            display: none;
            background: #fff;
            border-top: 1px solid var(--border-light);
            padding: 16px 24px;
        }

        .cat-mobile-menu.open { display: block; }

        .cat-mobile-link {
            display: block;
            padding: 12px 0;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-light);
        }

        .cat-mobile-link:last-child { border-bottom: none; }

        /* ─── Main content ─── */
        .cat-main {
            min-height: calc(100vh - 72px - 280px);
        }

        .cat-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
        }

        /* ─── Footer ─── */
        .cat-footer {
            background: var(--bg-secondary);
            border-top: 1px solid var(--border);
            padding: 60px 0 40px;
            margin-top: 80px;
        }

        .cat-footer-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
        }

        .cat-footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 48px;
            margin-bottom: 40px;
        }

        .cat-footer-brand h3 {
            font-size: 22px;
            margin-bottom: 8px;
        }

        .cat-footer-brand p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 16px;
        }

        .cat-footer-address {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.8;
        }

        .cat-footer-heading {
            font-family: 'Inter', sans-serif;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .cat-footer-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .cat-footer-links a {
            font-size: 14px;
            color: var(--text-secondary);
            transition: color 0.2s;
        }

        .cat-footer-links a:hover { color: var(--accent); }

        .cat-footer-social {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }

        .cat-footer-social a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            transition: all 0.2s;
        }

        .cat-footer-social a:hover {
            background: var(--accent);
            color: #fff;
        }

        .cat-footer-bottom {
            border-top: 1px solid var(--border);
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .cat-footer-powered a {
            color: var(--accent);
            font-weight: 500;
        }

        /* ─── Responsive ─── */
        @media (max-width: 768px) {
            .cat-nav-inner { height: 60px; }
            .cat-nav-links { display: none; }
            .cat-nav-toggle { display: block; }
            .cat-nav-cta { padding: 8px 14px; font-size: 12px; }
            .cat-nav-cta span { display: none; }

            .cat-footer-grid {
                grid-template-columns: 1fr;
                gap: 32px;
            }

            .cat-footer-bottom {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }
        }
        /* ─── Collection Banner ─── */
        .cat-collection-banner {
            display: none;
            background: linear-gradient(135deg, var(--bg-tertiary), #faf6f0);
            border-bottom: 1px solid var(--border);
            padding: 10px 24px;
        }
        .cat-collection-banner.visible { display: block; }
        .cat-collection-banner-inner {
            max-width: 1280px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .cat-collection-banner-left {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .cat-collection-banner-icon {
            width: 20px; height: 20px;
            color: var(--accent);
            flex-shrink: 0;
        }
        .cat-collection-banner-link {
            font-weight: 600;
            color: var(--accent);
            text-decoration: none;
            white-space: nowrap;
        }
        .cat-collection-banner-link:hover { text-decoration: underline; }
        .cat-collection-banner-close {
            background: none; border: none; cursor: pointer;
            color: var(--text-muted); padding: 4px; line-height: 0;
        }
        .cat-collection-banner-close:hover { color: var(--text-primary); }
        @media (max-width: 768px) {
            .cat-collection-banner { padding: 8px 16px; }
            .cat-collection-banner-left { font-size: 12px; }
        }
    </style>

    @yield('head')
</head>
<body>
    {{-- Navbar --}}
    <nav class="cat-nav">
        <div class="cat-nav-inner">
            <a href="{{ route('catalog.website.home', $shop->catalog_slug) }}" class="cat-nav-brand">
                @if($shop->logo_path)
                    <img src="{{ asset('storage/' . $shop->logo_path) }}" alt="{{ $shop->name }}" class="cat-nav-logo">
                @else
                    <span class="cat-nav-logo-placeholder">{{ strtoupper(substr($shop->name, 0, 1)) }}</span>
                @endif
                <span class="cat-nav-shop-name">{{ $shop->name }}</span>
            </a>

            <ul class="cat-nav-links">
                <li><a href="{{ route('catalog.website.home', $shop->catalog_slug) }}" class="cat-nav-link @if(request()->routeIs('catalog.website.home')) active @endif">Home</a></li>

                @if($navCategories->isNotEmpty())
                    <li class="cat-nav-dropdown">
                        <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="cat-nav-link @if(request()->routeIs('catalog.website.products', 'catalog.website.category')) active @endif">
                            Collections <svg style="display:inline;width:10px;height:10px;margin-left:4px" viewBox="0 0 10 6"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>
                        </a>
                        <div class="cat-nav-dropdown-menu">
                            <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="cat-nav-dropdown-item">All Products</a>
                            @foreach($navCategories as $cat)
                                <a href="{{ route('catalog.website.category', [$shop->catalog_slug, $cat]) }}" class="cat-nav-dropdown-item">{{ $cat }}</a>
                            @endforeach
                        </div>
                    </li>
                @else
                    <li><a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="cat-nav-link @if(request()->routeIs('catalog.website.products')) active @endif">Products</a></li>
                @endif

                @foreach($catalogPages as $cmsPage)
                    <li><a href="{{ route('catalog.website.page', [$shop->catalog_slug, $cmsPage->slug]) }}" class="cat-nav-link @if(request()->is('s/*/page/' . $cmsPage->slug)) active @endif">{{ $cmsPage->title }}</a></li>
                @endforeach
            </ul>

            <div style="display:flex;align-items:center;gap:12px">
                @php
                    $waNumber = preg_replace('/\D/', '', $shop->shop_whatsapp ?? $shop->phone ?? '');
                @endphp
                @if($waNumber)
                    <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener" class="cat-nav-cta">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.553 4.113 1.519 5.845L.058 23.7a.5.5 0 00.612.612l5.855-1.46A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22a9.94 9.94 0 01-5.332-1.544l-.382-.228-3.478.868.884-3.49-.25-.398A9.94 9.94 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>
                        <span>WhatsApp</span>
                    </a>
                @endif

                <button class="cat-nav-toggle" onclick="document.getElementById('catMobileMenu').classList.toggle('open')" aria-label="Menu">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
            </div>
        </div>

        {{-- Mobile menu --}}
        <div class="cat-mobile-menu" id="catMobileMenu">
            <a href="{{ route('catalog.website.home', $shop->catalog_slug) }}" class="cat-mobile-link">Home</a>
            <a href="{{ route('catalog.website.products', $shop->catalog_slug) }}" class="cat-mobile-link">All Products</a>
            @foreach($navCategories as $cat)
                <a href="{{ route('catalog.website.category', [$shop->catalog_slug, $cat]) }}" class="cat-mobile-link">{{ $cat }}</a>
            @endforeach
            @foreach($catalogPages as $cmsPage)
                <a href="{{ route('catalog.website.page', [$shop->catalog_slug, $cmsPage->slug]) }}" class="cat-mobile-link">{{ $cmsPage->title }}</a>
            @endforeach
            @if($shop->phone)
                <a href="tel:{{ $shop->phone }}" class="cat-mobile-link">Call: {{ $shop->phone }}</a>
            @endif
        </div>
    </nav>

    {{-- Collection return banner --}}
    <div class="cat-collection-banner" id="collectionBanner">
        <div class="cat-collection-banner-inner">
            <div class="cat-collection-banner-left">
                <svg class="cat-collection-banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                <span>A collection was curated just for you.</span>
                <a href="#" class="cat-collection-banner-link" id="collectionBannerLink">View Your Collection &rarr;</a>
            </div>
            <button class="cat-collection-banner-close" id="collectionBannerClose" aria-label="Dismiss">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
    </div>

    {{-- Main content --}}
    <main class="cat-main">
        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="cat-footer">
        <div class="cat-footer-inner">
            <div class="cat-footer-grid">
                <div class="cat-footer-brand">
                    <h3>{{ $shop->name }}</h3>
                    @if($catalogSettings->tagline)
                        <p>{{ $catalogSettings->tagline }}</p>
                    @endif
                    @if($shop->address || $shop->city)
                        <div class="cat-footer-address">
                            @if($shop->address_line1){{ $shop->address_line1 }}<br>@endif
                            @if($shop->address_line2){{ $shop->address_line2 }}<br>@endif
                            @if($shop->city){{ $shop->city }}@endif
                            @if($shop->state), {{ $shop->state }}@endif
                            @if($shop->pincode) — {{ $shop->pincode }}@endif
                        </div>
                    @endif
                    <div class="cat-footer-social">
                        @if($catalogSettings->social_whatsapp)
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $catalogSettings->social_whatsapp) }}" target="_blank" rel="noopener" title="WhatsApp">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.12.553 4.113 1.519 5.845L.058 23.7a.5.5 0 00.612.612l5.855-1.46A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0z"/></svg>
                            </a>
                        @endif
                        @if($catalogSettings->social_instagram)
                            <a href="{{ $catalogSettings->social_instagram }}" target="_blank" rel="noopener" title="Instagram">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="5"/><circle cx="17.5" cy="6.5" r="1.5" fill="currentColor" stroke="none"/></svg>
                            </a>
                        @endif
                        @if($catalogSettings->social_facebook)
                            <a href="{{ $catalogSettings->social_facebook }}" target="_blank" rel="noopener" title="Facebook">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                        @endif
                    </div>
                </div>

                <div>
                    <h4 class="cat-footer-heading">Quick Links</h4>
                    <ul class="cat-footer-links">
                        <li><a href="{{ route('catalog.website.home', $shop->catalog_slug) }}">Home</a></li>
                        <li><a href="{{ route('catalog.website.products', $shop->catalog_slug) }}">All Products</a></li>
                        @foreach($navCategories->take(5) as $cat)
                            <li><a href="{{ route('catalog.website.category', [$shop->catalog_slug, $cat]) }}">{{ $cat }}</a></li>
                        @endforeach
                    </ul>
                </div>

                <div>
                    <h4 class="cat-footer-heading">Contact</h4>
                    <ul class="cat-footer-links">
                        @if($shop->phone)
                            <li><a href="tel:{{ $shop->phone }}">{{ $shop->phone }}</a></li>
                        @endif
                        @if($shop->shop_email)
                            <li><a href="mailto:{{ $shop->shop_email }}">{{ $shop->shop_email }}</a></li>
                        @endif
                        @foreach($catalogPages as $cmsPage)
                            <li><a href="{{ route('catalog.website.page', [$shop->catalog_slug, $cmsPage->slug]) }}">{{ $cmsPage->title }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="cat-footer-bottom">
                <span>&copy; {{ date('Y') }} {{ $shop->name }}. All rights reserved.</span>
                <span class="cat-footer-powered">Powered by <a href="https://jewelflow.in" target="_blank" rel="noopener">JewelFlow</a></span>
            </div>
        </div>
    </footer>

    @yield('scripts')

    <script>
    (() => {
        const KEY = 'catalog_collection_url';
        const DISMISSED_KEY = 'catalog_collection_dismissed';
        const banner = document.getElementById('collectionBanner');
        const link = document.getElementById('collectionBannerLink');
        const closeBtn = document.getElementById('collectionBannerClose');
        if (!banner || !link) return;

        // If we're on a collection page, save the URL
        if (window.location.pathname.includes('/collection/')) {
            sessionStorage.setItem(KEY, window.location.href);
            sessionStorage.removeItem(DISMISSED_KEY);
            return; // Don't show banner on the collection page itself
        }

        // On other pages, show the banner if a collection URL is saved
        const savedUrl = sessionStorage.getItem(KEY);
        const dismissed = sessionStorage.getItem(DISMISSED_KEY);
        if (savedUrl && !dismissed) {
            link.href = savedUrl;
            banner.classList.add('visible');
        }

        closeBtn?.addEventListener('click', () => {
            banner.classList.remove('visible');
            sessionStorage.setItem(DISMISSED_KEY, '1');
        });
    })();
    </script>
</body>
</html>
