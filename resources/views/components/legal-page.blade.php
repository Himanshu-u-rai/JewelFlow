@props(['title', 'intro' => null])
@php
    use App\Support\LegalInfo;
    $current = request()->route()?->getName();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — JewelFlows</title>
    <meta name="description" content="{{ $intro ?? $title . ' for JewelFlows, by ' . config('legal.company') . '.' }}">
    @include('partials.favicon')
    <style>
        :root{--ink:#14202c;--muted:#56616b;--paper:#f7f7f2;--line:#d9ddd9;--gold:#f2c65c;--green:#153f38}
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;background:var(--paper);color:var(--ink);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;-webkit-font-smoothing:antialiased;line-height:1.65}
        a{color:var(--green)}a:focus-visible{outline:3px solid #1860bb;outline-offset:3px}
        .wrap{width:min(1080px,calc(100% - 48px));margin-inline:auto}
        .site-header{border-bottom:1px solid var(--line);background:rgba(247,247,242,.94)}
        .header-inner{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:72px}
        .brand{display:inline-flex;align-items:center;gap:8px;font-size:20px;font-weight:800;letter-spacing:-.6px;color:var(--ink);text-decoration:none}
        .brand span span{color:#b8860b}.brand img{width:30px;height:30px}
        .header-link{font-size:14px;font-weight:600;text-decoration:none;padding:10px 14px;border:1px solid var(--line);border-radius:999px;color:var(--ink);background:#fff}
        .legal{display:grid;grid-template-columns:220px minmax(0,1fr);gap:48px;padding-block:48px 64px}
        .legal-nav{position:sticky;top:24px;align-self:start;display:flex;flex-direction:column;gap:2px}
        .legal-nav a{display:block;padding:9px 12px;border-radius:8px;font-size:14px;color:var(--muted);text-decoration:none}
        .legal-nav a:hover{background:#fff;color:var(--ink)}
        .legal-nav a[aria-current="page"]{background:var(--green);color:#fff;font-weight:600}
        .legal-body{max-width:760px}
        .legal-body h1{font-size:clamp(30px,4vw,42px);line-height:1.15;letter-spacing:-.04em;margin:0 0 10px}
        .legal-meta{color:var(--muted);font-size:13px;margin:0 0 28px}
        .legal-body h2{font-size:19px;letter-spacing:-.02em;margin:34px 0 8px}
        .legal-body p,.legal-body li{font-size:15px;color:#2a3540}
        .legal-body ul{padding-left:20px}
        .legal-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:20px 22px;margin:18px 0}
        .legal-card dl{display:grid;grid-template-columns:170px 1fr;gap:10px 16px;margin:0}
        .legal-card dt{font-weight:600;font-size:14px}.legal-card dd{margin:0;font-size:14px}
        .legal-missing{background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:4px;font-weight:600}
        footer{border-top:1px solid var(--line);padding-block:24px 32px;color:var(--muted);font-size:13px}
        .footer-inner{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px}
        .footer-links{display:flex;flex-wrap:wrap;gap:6px 18px}.footer-links a{color:var(--muted);text-decoration:none}.footer-links a:hover{color:var(--ink);text-decoration:underline}
        @media(max-width:760px){
            .wrap{width:calc(100% - 32px)}
            .legal{grid-template-columns:1fr;gap:20px;padding-block:24px 48px}
            .legal-nav{position:static;flex-direction:row;overflow-x:auto;gap:6px;padding-bottom:4px}
            .legal-nav a{white-space:nowrap;border:1px solid var(--line);background:#fff;min-height:40px;display:inline-flex;align-items:center}
            .legal-card dl{grid-template-columns:1fr;gap:2px}.legal-card dd{margin-bottom:10px}
        }
        @media print{.site-header,.legal-nav,footer{display:none}.legal{display:block;padding:0}}
    </style>
</head>
<body>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="{{ route('home') }}" aria-label="JewelFlows home"><img src="{{ asset('favicon.svg') }}" alt="" width="30" height="30"><span>Jewel<span>Flows</span></span></a>
        <a class="header-link" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Back to app' : 'Log in' }}</a>
    </div>
</header>

<main class="wrap legal">
    <nav class="legal-nav" aria-label="Policies">
        @foreach(LegalInfo::pages() as $route => $label)
            <a href="{{ route($route) }}" @if($current === $route) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <article class="legal-body">
        <h1>{{ $title }}</h1>
        <p class="legal-meta">{{ config('legal.company') }} · Last updated {{ config('legal.effective_date') }}</p>
        {{ $slot }}
    </article>
</main>

<footer>
    <div class="wrap footer-inner">
        <span>© {{ date('Y') }} JewelFlows · By {{ config('legal.company') }}</span>
        <nav class="footer-links" aria-label="Footer">
            @foreach(LegalInfo::pages() as $route => $label)
                <a href="{{ route($route) }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</footer>
</body>
</html>
