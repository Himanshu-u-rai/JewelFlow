@php
    $dhiranRegisterUrl = \App\Support\Realm::dhiranRegisterUrl(request());
    // Infer a login sibling only for the standard path, never for a custom campaign URL.
    $dhiranLoginUrl = $dhiranRegisterUrl && parse_url($dhiranRegisterUrl, PHP_URL_PATH) === '/register'
        ? preg_replace('~/register(?:[?#].*)?$~', '/login', $dhiranRegisterUrl)
        : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>JewelFlows — Retail Jewellery &amp; Dhiran Software</title>
    <meta name="description" content="Run your jewellery shop with JewelFlows Retail. Manage gold loans with JewelFlows Dhiran. Billing, stock, customer records and loan tracking, with a clear place for every task.">
    @include('partials.favicon')
    <style>
        :root{--ink:#14202c;--muted:#56616b;--paper:#f7f7f2;--line:#d9ddd9;--gold:#f2c65c;--green:#153f38}
        *,*::before,*::after{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:110px}
        body{margin:0;background:var(--paper);color:var(--ink);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;-webkit-font-smoothing:antialiased}
        a{color:inherit;text-decoration:none}button,summary{font:inherit}a:focus-visible,summary:focus-visible{outline:3px solid #1860bb;outline-offset:5px}
        h1,h2,h3,p{margin:0}h1,h2,h3{text-wrap:balance}p{line-height:1.7}.wrap{width:min(1240px,calc(100% - 80px));margin-inline:auto}
        .skip{position:fixed;top:8px;left:16px;z-index:100;background:white;padding:14px;transform:translateY(-180%)}.skip:focus{transform:none}
        .site-header{position:sticky;top:0;z-index:40;background:rgba(247,247,242,.97);border-bottom:1px solid var(--line)}
        .header-inner{min-height:84px;display:flex;align-items:center;justify-content:space-between;gap:28px}
        .brand{display:flex;align-items:center;gap:9px;font-size:22px;font-weight:800;letter-spacing:-1px;min-height:44px;white-space:nowrap}.brand img{width:36px;height:36px}.brand span span{font-weight:500}
        .nav-links{display:flex;align-items:center;gap:28px;font-size:13px;font-weight:700}.nav-links a{padding:14px 0}.nav-links a:hover{text-decoration:underline;text-underline-offset:5px}
        .entry-actions{display:flex;align-items:center;gap:10px}.entry{position:relative}
        .entry summary{display:flex;align-items:center;justify-content:center;gap:12px;min-height:46px;padding:0 19px;border:1px solid var(--ink);border-radius:8px;font-size:13px;font-weight:800;cursor:pointer;list-style:none;white-space:nowrap}
        summary::-webkit-details-marker{display:none}.entry summary::after{content:'⌄';font-size:15px}.entry[open] summary::after{content:'⌃'}.entry-register summary{background:var(--ink);color:white}.entry summary:hover{box-shadow:0 0 0 2px var(--gold)}
        .entry-menu{position:absolute;right:0;top:calc(100% + 12px);width:290px;max-width:calc(100vw - 32px);padding:8px;border:1px solid var(--line);border-radius:14px;background:white;box-shadow:0 16px 38px #14202c20}
        .entry-menu a{display:block;padding:15px 13px;border-radius:8px}.entry-menu a:hover{background:var(--paper)}.entry-menu strong{display:block;font-size:14px}.entry-menu span{display:block;font-size:12px;color:var(--muted);margin-top:5px;line-height:1.5}
        .eyebrow{display:flex;align-items:center;gap:10px;text-transform:uppercase;letter-spacing:.15em;font-size:11px;font-weight:800;line-height:1.6}.eyebrow::before{content:'';width:7px;height:7px;border-radius:50%;background:currentColor;flex:none}
        .hero{display:grid;grid-template-columns:1.05fr 1fr;gap:60px;align-items:center;padding-block:86px 80px}
        h1{font-size:clamp(52px,5.6vw,80px);line-height:1.06;letter-spacing:-.065em;margin:25px 0}.highlight{position:relative;display:inline-block;isolation:isolate}.highlight::after{content:'';position:absolute;bottom:3px;left:0;right:-4px;height:15px;background:var(--gold);z-index:-1}
        .hero-copy>p{max-width:460px;font-size:17px;color:var(--muted)}.actions{display:flex;align-items:center;flex-wrap:wrap;gap:14px;margin-top:30px}
        .button{display:inline-flex;align-items:center;justify-content:center;gap:24px;min-height:52px;padding:14px 22px;border:1px solid transparent;border-radius:8px;font-size:13px;font-weight:800;line-height:1.5;transition:background .15s,box-shadow .15s}
        .button-dark{background:var(--ink);color:white}.button-dark:hover{background:#2a3c4c;box-shadow:0 3px 0 var(--gold)}.button-gold{background:var(--gold);color:var(--ink)}.button-gold:hover{background:#ffdc87}.button-light{border-color:var(--line);background:white}.button-light:hover{background:#eceee8}
        .text-link{display:inline-flex;align-items:center;gap:15px;min-height:44px;font-size:13px;font-weight:800;text-decoration:underline;text-underline-offset:5px}.hero-note{display:flex;gap:9px;align-items:center;margin-top:23px;font-size:11px;color:var(--muted)}.hero-note span{font-size:15px;color:var(--green)}
        .workspace-wrap{position:relative;padding:25px 0 22px 24px;isolation:isolate}.workspace-wrap::before{content:'';position:absolute;inset:0 26px 0 0;background:#e7eae2;border-radius:34px;transform:rotate(-3deg);z-index:-1}
        .workspace{background:white;border:1px solid #d6dcd8;border-radius:16px;box-shadow:0 22px 55px #14202c0b;overflow:hidden}.workspace-top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:20px 23px;border-bottom:1px solid #e9ece7;font-size:12px;font-weight:800}.workspace-label{display:flex;align-items:center;gap:8px}.mini-mark{display:grid;place-items:center;width:25px;height:25px;background:var(--ink);color:var(--gold);border-radius:6px;font-size:10px}.sample{font-size:10px;color:var(--muted);font-weight:500}
        .workspace-body{padding:27px 23px 23px}.workspace-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:24px}.workspace-title strong{font-size:20px;letter-spacing:-.6px}.status{font-size:10px;padding:6px 9px;background:#edf5ee;color:#31503a;border-radius:5px;white-space:nowrap}
        .workspace-metrics{display:grid;grid-template-columns:1fr 1fr;gap:10px}.metric{padding:17px;border:1px solid #e5e8e1;border-radius:9px}.metric:first-child{background:#fff5d9;border-color:#f7e5b6}.metric small{display:block;color:var(--muted);font-size:10px;margin-bottom:8px}.metric strong{font-size:25px;letter-spacing:-1px}.metric em{display:block;font-size:10px;font-style:normal;margin-top:8px;color:var(--muted)}
        .stock-heading{display:flex;justify-content:space-between;margin:25px 0 7px;font-size:11px;font-weight:700}.stock-heading span:last-child{font-weight:500;color:var(--muted)}.stock-line{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid #edf0eb}.stock-icon{display:grid;place-items:center;flex:none;width:36px;height:36px;border-radius:8px;background:#f2f3ed;color:#866329}.stock-line strong{display:block;font-size:11px}.stock-line small{display:block;font-size:10px;color:var(--muted);margin-top:4px}.stock-line>b{margin-left:auto;font-size:11px;font-weight:600;white-space:nowrap}.ring{width:16px;height:16px;border:2px solid currentColor;border-radius:50%;box-shadow:2px 1px 0 #ceb570}.chain{width:17px;height:22px;border:2px solid currentColor;border-radius:4px 4px 12px 12px}
        .workspace-foot{display:flex;align-items:center;gap:9px;margin-top:18px;font-size:10px;color:var(--muted)}.workspace-foot span{color:var(--green);font-size:14px}.caption{font-size:10px;letter-spacing:.02em;color:var(--muted);text-align:right;margin:14px 5px 0}
        .workflow{display:flex;align-items:center;justify-content:space-between;gap:25px;padding:26px 0;border-block:1px solid var(--line);font-size:13px;font-weight:700}.workflow-label{font-size:10px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);line-height:1.6}.workflow-item{display:flex;gap:12px;align-items:center}.workflow-item span{font-size:11px;color:#707b7c;font-weight:500}.workflow-arrow{color:#7d878b;font-weight:400}
        .section{padding-block:96px}.section-top{display:grid;grid-template-columns:1.1fr 1fr;gap:80px;align-items:end;margin-bottom:46px}h2{font-size:clamp(34px,3.6vw,50px);line-height:1.12;letter-spacing:-.045em;margin-top:16px}.section-intro{font-size:15px;color:var(--muted);max-width:480px}.section-intro .text-link{margin-top:13px}
        .retail-grid{display:grid;grid-template-columns:1.15fr 1fr;gap:28px}.retail-feature{background:var(--ink);color:white;padding:38px;border-radius:22px;display:flex;flex-direction:column;align-items:start;justify-content:space-between;gap:30px}.retail-feature h3{font-size:31px;letter-spacing:-1.2px;line-height:1.2;max-width:300px;margin-top:20px}.retail-feature p{font-size:14px;color:#c3cdd1;max-width:365px;margin-top:16px}.retail-feature .eyebrow{color:var(--gold)}
        .receipt{width:100%;background:#f9faf6;color:var(--ink);border-radius:10px;padding:22px}.receipt-heading{display:flex;justify-content:space-between;font-size:11px;font-weight:800;padding-bottom:15px;border-bottom:1px dashed #b6bdb9}.receipt-row{display:flex;justify-content:space-between;gap:16px;font-size:12px;margin-top:15px}.receipt-row span:last-child{font-weight:700}.receipt-note{font-size:10px;color:var(--muted);margin-top:20px}.receipt-total{border-top:1px dashed #b6bdb9;padding-top:15px;font-weight:800}
        .feature-list{display:flex;flex-direction:column;justify-content:space-between}.feature-row{display:grid;grid-template-columns:38px 1fr;gap:16px;padding:24px 0;border-bottom:1px solid var(--line)}.feature-row:first-child{padding-top:8px}.feature-number{font-size:11px;color:var(--muted);padding-top:5px}.feature-row h3{font-size:20px;font-weight:700;letter-spacing:-.5px}.feature-row p{font-size:13px;color:var(--muted);margin-top:9px;max-width:405px}
        .dhiran{background:var(--green);color:white;border-radius:28px;display:grid;grid-template-columns:1fr 1fr;gap:70px;padding:60px}.dhiran .eyebrow{color:#d5e6b7}.dhiran h2{max-width:450px}.dhiran p{color:#d0dfd9;font-size:14px;margin-top:22px;max-width:410px}.dhiran .text-link{color:white}.loan-flow{display:flex;flex-direction:column;justify-content:center}.loan-step{display:grid;grid-template-columns:38px 1fr;gap:20px;padding:24px 0;border-bottom:1px solid #4b6a61}.loan-step:first-child{padding-top:0}.loan-step:last-child{border:0;padding-bottom:0}.loan-step>span{display:grid;place-items:center;width:36px;height:36px;border-radius:50%;border:1px solid #718d80;font-size:11px;color:#e9efd1}.loan-step h3{font-size:18px;letter-spacing:-.4px}.loan-step p{font-size:12px;margin-top:8px;line-height:1.65}.product-note{font-size:11px!important;color:#ccd9d3!important;margin-top:18px!important}
        .everyday{display:grid;grid-template-columns:1fr 1.15fr;gap:80px;align-items:center}.everyday p{font-size:15px;color:var(--muted);margin-top:22px;max-width:430px}.everyday-points{display:grid;grid-template-columns:1fr 1fr;gap:32px}.everyday-point{padding-top:20px;border-top:1px solid #bec7c2}.everyday-point span{display:block;font-size:12px;color:#596b64;margin-bottom:18px}.everyday-point h3{font-size:17px;letter-spacing:-.3px}.everyday-point p{font-size:12px;margin-top:9px}
        .faq{border-top:1px solid var(--line);display:grid;grid-template-columns:.8fr 1.2fr;gap:90px;padding-top:70px;padding-bottom:80px}.faq h2{max-width:330px}.faq-list details{border-bottom:1px solid var(--line)}.faq-list summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;gap:25px;font-size:14px;font-weight:700;line-height:1.5;padding:23px 0}.faq-list summary::after{content:'+';font-size:20px;font-weight:400;flex:none}.faq-list details[open] summary::after{content:'−'}.faq-list p{font-size:13px;color:var(--muted);padding:0 30px 23px 0}.faq-list a{text-decoration:underline;text-underline-offset:3px}
        .closing{background:var(--gold);padding:47px 50px;border-radius:22px;display:flex;justify-content:space-between;align-items:center;gap:40px}.closing h2{font-size:36px;margin:0}.closing p{font-size:13px;margin-top:12px}.closing .actions{margin:0;flex-shrink:0}
        footer{padding:46px 0 30px}.footer-top{display:flex;justify-content:space-between;align-items:center;gap:25px}.footer-links{display:flex;gap:25px;flex-wrap:wrap;font-size:12px}.footer-links a{display:inline-flex;align-items:center;min-height:44px}.footer-links a:hover{text-decoration:underline}.footer-bottom{margin-top:23px;padding-top:22px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px;color:var(--muted);font-size:10px;line-height:1.7}
        @media(max-width:1100px){.wrap{width:calc(100% - 48px)}.nav-links{gap:18px}.hero{gap:30px}h1{font-size:60px}.workspace-wrap{padding-left:12px}.section-top{gap:40px}.dhiran{gap:40px;padding:42px}.everyday{gap:45px}.closing{padding:35px}.closing h2{font-size:30px}}
        @media(max-width:900px){.nav-links{display:none}.hero{padding-block:60px;gap:22px}h1{font-size:49px}.hero-copy>p{font-size:14px}.hero .actions{gap:6px}.workspace-body{padding:20px 15px}.workspace-top{padding:17px 15px}.metric{padding:12px}.metric strong{font-size:22px}.workspace-title strong{font-size:17px}.workspace-title{flex-wrap:wrap}.retail-grid{gap:23px}.retail-feature{padding:28px}.section{padding-block:70px}.section-top{gap:35px}.dhiran{gap:28px;padding:36px}.faq{gap:35px}.closing{align-items:start;flex-direction:column;gap:25px}}
        @media(max-width:680px){html{scroll-padding-top:94px}.wrap{width:calc(100% - 36px)}.header-inner{min-height:74px;gap:10px}.brand{font-size:18px;gap:6px;letter-spacing:-.7px}.brand img{width:28px;height:28px}.entry-actions{gap:6px}.entry summary{padding:0 12px;font-size:12px;gap:8px;min-height:44px}.entry-login .entry-menu{right:-102px}.hero{grid-template-columns:1fr;padding-block:45px 39px;gap:32px}.hero-copy{max-width:510px}h1{font-size:clamp(46px,9.7vw,65px);margin-block:20px;letter-spacing:-.06em}.hero-copy>p{font-size:15px;max-width:450px}.hero .actions{gap:16px;margin-top:24px}.hero-note{margin-top:16px}.workspace-wrap{max-width:500px;width:100%;justify-self:center;padding:12px 0 10px 12px}.workspace-body{padding:22px}.workspace-top{padding:18px 22px}.workspace-title{flex-wrap:nowrap}.workspace-title strong{font-size:21px}.metric{padding:16px}.metric strong{font-size:25px}.workflow{display:grid;grid-template-columns:1fr 1fr;gap:20px;padding-block:25px;font-size:12px}.workflow-label{grid-column:1/-1}.workflow-arrow{display:none}.section{padding-block:55px}.section-top{grid-template-columns:1fr;gap:20px;margin-bottom:28px}h2{font-size:36px}.section-intro{font-size:14px}.retail-grid{grid-template-columns:1fr;gap:28px}.retail-feature{padding:28px;gap:26px}.retail-feature h3{max-width:360px}.feature-row{padding:22px 0}.feature-row:last-child{border-bottom:0;padding-bottom:0}.dhiran{grid-template-columns:1fr;padding:32px 25px;gap:37px;border-radius:20px}.dhiran h2{font-size:35px}.dhiran p{font-size:14px}.loan-step p{font-size:12px}.everyday{grid-template-columns:1fr;gap:35px}.everyday-points{gap:25px}.faq{grid-template-columns:1fr;gap:25px;padding-block:45px}.faq h2{max-width:none}.faq-list summary{font-size:13px}.closing{padding:30px 24px;gap:23px}.closing h2{font-size:32px}.closing .actions{gap:10px}.footer-top{align-items:start;flex-direction:column;gap:18px}.footer-links{gap:22px}.footer-bottom{flex-direction:column;gap:8px}.caption{font-size:9px}}
        @media(max-width:370px){.wrap{width:calc(100% - 28px)}.brand{font-size:16px;gap:4px}.brand img{width:24px;height:24px}.entry summary{padding:0 10px;gap:6px;font-size:11px}.entry-actions{gap:5px}.header-inner{gap:6px}h1{font-size:43px}.button{padding-inline:17px;gap:15px}.hero .actions{gap:9px}.workspace-body{padding:18px 14px}.workspace-top{padding:16px 14px}.metric{padding:13px}.workspace-title strong{font-size:19px}.status{font-size:9px}.retail-feature{padding:23px}.dhiran{padding:28px 22px}.everyday-points{grid-template-columns:1fr}.closing .actions{align-items:stretch;flex-direction:column;width:100%}}
        .device-showcase{padding-bottom:88px}
        .device-showcase .section-top{margin-bottom:30px}
        .device-grid{display:grid;grid-template-columns:1.65fr 1fr;gap:24px}
        .device-card{margin:0;border:1px solid var(--line);border-radius:20px;overflow:hidden;background:#edf0e9}
        .device-art{height:320px;display:flex;align-items:center;justify-content:center;padding:18px}
        .device-art img{display:block;width:auto;max-width:100%;height:auto;max-height:100%;object-fit:contain}
        .device-laptop .device-art img{border-radius:10px}
        .device-phone{background:#e8ecef}
        .device-phone .device-art{height:320px;padding:18px}
        .device-card figcaption{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:23px 25px;background:#fff;border-top:1px solid var(--line)}
        .device-card h3{font-size:18px;letter-spacing:-.5px}.device-card p{font-size:12px;color:var(--muted);margin-top:7px;max-width:320px}
        .device-tag{font-size:10px;font-weight:700;padding:7px 9px;border:1px solid var(--line);border-radius:5px;white-space:nowrap}
        .device-note{font-size:10px;color:var(--muted);margin-top:13px;text-align:right}
        @media(max-width:900px){.device-showcase{padding-bottom:66px}.device-grid{gap:18px}.device-card figcaption{padding:20px;align-items:start;flex-direction:column;gap:12px}.device-art,.device-phone .device-art{height:240px;padding:16px}}
        @media(max-width:680px){.device-showcase{padding-bottom:55px}.device-grid{grid-template-columns:1fr;gap:18px}.device-card figcaption{flex-direction:row;align-items:center;padding:20px}.device-art{height:auto;min-height:190px;padding:18px}.device-laptop .device-art img{width:min(100%,400px)}.device-phone .device-art{height:265px;padding:16px}.device-card p{max-width:235px}.device-note{text-align:left}}
        @media(max-width:370px){.device-card figcaption{padding:18px;gap:12px}.device-card h3{font-size:16px}.device-tag{font-size:9px}}
        @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*::before,*::after{transition:none!important}}
        @media print{.site-header{position:static}.entry-menu{position:static;box-shadow:none}.hero{padding-block:30px}.section{padding-block:30px}.workspace-wrap::before{display:none}}
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site-header"><div class="wrap header-inner">
    <a class="brand" href="{{ route('home') }}" aria-label="JewelFlows home"><img src="{{ asset('favicon.svg') }}" alt="" width="36" height="36"><span>Jewel<span>Flows</span></span></a>
    <nav class="nav-links" aria-label="Products"><a href="#retail">Retail</a><a href="#dhiran">Dhiran</a><a href="#questions">Questions</a></nav>
    <nav class="entry-actions" aria-label="Account access">
        <details class="entry entry-login"><summary>Log in</summary><div class="entry-menu"><a href="{{ route('login') }}"><strong>Retail login ↗</strong><span>Billing, stock and your jewellery shop.</span></a>
            @if ($dhiranLoginUrl)
                <a href="{{ $dhiranLoginUrl }}"><strong>Dhiran login ↗</strong><span>Your gold loan workspace.</span></a>
            @endif
        </div></details>
        <details class="entry entry-register"><summary>Register</summary><div class="entry-menu"><a href="{{ route('register') }}"><strong>Start with Retail ↗</strong><span>For your jewellery shop.</span></a>
            @if ($dhiranRegisterUrl)
                <a href="{{ $dhiranRegisterUrl }}"><strong>Start with Dhiran ↗</strong><span>For your gold loan business.</span></a>
            @endif
        </div></details>
    </nav>
</div></header>
<main id="main">
    <section class="wrap hero" aria-labelledby="hero-title"><div class="hero-copy">
        <div class="eyebrow">For jewellery retail &amp; Dhiran</div>
        <h1 id="hero-title">Made for the way <span class="highlight">jewellers</span> work.</h1>
        <p>From the first sale to the day's accounts. Give your jewellery shop—or your gold loan business—a clearer way to work.</p>
        <div class="actions"><a class="button button-dark" href="{{ route('register') }}">Start with Retail <span aria-hidden="true">↗</span></a><a class="text-link" href="#dhiran">Explore Dhiran <span aria-hidden="true">↓</span></a></div>
        <div class="hero-note"><span aria-hidden="true">✓</span>Two focused products. Built around your business.</div>
    </div><div class="workspace-wrap"><div class="workspace" role="img" aria-label="Illustrative retail workspace with sample stock and billing figures, not live customer data">
        <div class="workspace-top"><div class="workspace-label"><span class="mini-mark" aria-hidden="true">JF</span> Retail workspace</div><span class="sample">Sample data</span></div>
        <div class="workspace-body"><div class="workspace-title"><strong>A clearer view of today.</strong><span class="status">Daily overview</span></div>
            <div class="workspace-metrics"><div class="metric"><small>Today's sales</small><strong>₹1,24,500</strong><em>8 invoices recorded</em></div><div class="metric"><small>Items in stock</small><strong>248</strong><em>Ready for the counter</em></div></div>
            <div class="stock-heading"><span>Your stock, in detail</span><span>Weight · Purity</span></div>
            <div class="stock-line"><div class="stock-icon"><span class="ring"></span></div><div><strong>Gold ring</strong><small>22K gold · GR-001</small></div><b>4.250 g</b></div>
            <div class="stock-line"><div class="stock-icon"><span class="chain"></span></div><div><strong>Gold chain</strong><small>22K gold · GC-008</small></div><b>12.600 g</b></div>
            <div class="workspace-foot"><span aria-hidden="true">↗</span> Billing, inventory and customer records, connected.</div>
        </div></div><p class="caption">Illustrative workspace · figures shown are examples</p></div>
    </section>
    <div class="wrap workflow" aria-label="Retail workflows"><span class="workflow-label">The everyday essentials.<br>In one place.</span><span class="workflow-item"><span>01</span> Billing &amp; GST</span><span class="workflow-arrow" aria-hidden="true">→</span><span class="workflow-item"><span>02</span> Jewellery stock</span><span class="workflow-arrow" aria-hidden="true">→</span><span class="workflow-item"><span>03</span> Customer records</span><span class="workflow-arrow" aria-hidden="true">→</span><span class="workflow-item"><span>04</span> Reports</span></div>
    <section class="wrap section" id="retail" aria-labelledby="retail-title">
        <div class="section-top"><div><div class="eyebrow">01 / JewelFlows Retail</div><h2 id="retail-title">Less switching.<br>More shopkeeping.</h2></div><div class="section-intro"><p>Your counter, stock room and accounts belong together. Keep the details of your jewellery business connected, from an item's arrival to its sale.</p><a class="text-link" href="{{ route('register') }}">Create your Retail account <span aria-hidden="true">↗</span></a></div></div>
        <div class="retail-grid"><article class="retail-feature"><div><div class="eyebrow">At the counter</div><h3>A sale is more than a total.</h3><p>Bring weight, purity, making charges and GST into your billing workflow. Keep the invoice and the customer's record close at hand.</p></div><div class="receipt" aria-label="Example invoice breakdown"><div class="receipt-heading"><span>A closer look at your bill</span><span aria-hidden="true">↗</span></div><div class="receipt-row"><span>Jewellery details</span><span>Weight &amp; purity</span></div><div class="receipt-row"><span>Charges &amp; tax</span><span>Making &amp; GST</span></div><div class="receipt-row receipt-total"><span>Customer record</span><span>Linked to the sale</span></div><div class="receipt-note">A workflow illustration, not a tax invoice.</div></div></article>
        <div class="feature-list"><article class="feature-row"><span class="feature-number">01</span><div><h3>Know every piece.</h3><p>Track jewellery by weight, purity, barcode and HUID details. Follow stock and purchases without losing the item-level view.</p></div></article><article class="feature-row"><span class="feature-number">02</span><div><h3>Keep customers close.</h3><p>Find customer records, invoices and purchase history. Manage schemes, installments and repair work from their dedicated workspaces.</p></div></article><article class="feature-row"><span class="feature-number">03</span><div><h3>Close the day with clarity.</h3><p>Bring cash-book entries, sales reports and exports into your daily review. Give staff access through roles and permissions.</p></div></article></div></div>
    </section>
    <section class="wrap device-showcase" aria-labelledby="devices-title">
        <div class="section-top"><div><div class="eyebrow">Retail, wherever you work</div><h2 id="devices-title">At the counter.<br>On the move.</h2></div><p class="section-intro">A wider view for your desk. Retail tools for your phone. Get a feel for JewelFlows on the devices you use every day.</p></div>
        <div class="device-grid">
            <figure class="device-card device-laptop"><div class="device-art"><img src="{{ asset('images/laptopview-960.webp') }}" width="960" height="640" loading="lazy" decoding="async" alt="Laptop mockup illustrating the JewelFlows Retail dashboard"></div><figcaption><div><h3>Your shop, in view.</h3><p>Billing, inventory and reports in your browser.</p></div><span class="device-tag">Web</span></figcaption></figure>
            <figure class="device-card device-phone"><div class="device-art"><img src="{{ asset('images/phoneview-480.webp') }}" width="480" height="720" loading="lazy" decoding="async" alt="Phone mockup illustrating the JewelFlows Retail mobile dashboard"></div><figcaption><div><h3>Your counter companion.</h3><p>Stock lookup and Retail billing on your phone.</p></div><span class="device-tag">Android app</span></figcaption></figure>
        </div>
        <p class="device-note">Product illustrations. Screen appearance may differ from the current app.</p>
    </section>
    <section class="wrap dhiran" id="dhiran" aria-labelledby="dhiran-title"><div><div class="eyebrow">02 / JewelFlows Dhiran</div><h2 id="dhiran-title">Gold loans.<br>Every detail<br>accounted for.</h2><p>A dedicated workspace for your gold loan business. Keep borrowers, pledged items, interest payments and loan closure records together.</p><div class="actions">
        @if ($dhiranRegisterUrl)
            <a class="button button-gold" href="{{ $dhiranRegisterUrl }}">Start with Dhiran <span aria-hidden="true">↗</span></a>
        @else
            <a class="button button-gold" href="mailto:{{ config('app.support_email') }}?subject=Dhiran%20enquiry">Ask about Dhiran <span aria-hidden="true">↗</span></a>
        @endif
        @if ($dhiranLoginUrl)
            <a class="text-link" href="{{ $dhiranLoginUrl }}">Dhiran login <span aria-hidden="true">↗</span></a>
        @endif
    </div><p class="product-note">Dhiran uses a separate account from Retail.</p></div><div class="loan-flow"><article class="loan-step"><span>01</span><div><h3>Record the loan.</h3><p>Keep borrower details, pledged items and loan terms in a single record.</p></div></article><article class="loan-step"><span>02</span><div><h3>Follow each payment.</h3><p>Track interest payments, repayments and outstanding loans. Find the receipts when you need them.</p></div></article><article class="loan-step"><span>03</span><div><h3>Complete the closure.</h3><p>Record settlement and pledged-item release, with the loan's history close at hand.</p></div></article></div></section>
    <section class="wrap section everyday" aria-labelledby="everyday-title"><div><div class="eyebrow">Built for the everyday</div><h2 id="everyday-title">Real work.<br>Less running around.</h2><p>Software should fit your day. Find the record, finish the task, and get back to the person across the counter.</p></div><div class="everyday-points"><article class="everyday-point"><span>01 / At your desk</span><h3>A connected workspace.</h3><p>Open your business tools in the browser, with a dedicated place for each workflow.</p></article><article class="everyday-point"><span>02 / On the move</span><h3>A Retail companion.</h3><p>The Android app brings Retail tools to your phone, including stock lookup and billing.</p></article><article class="everyday-point"><span>03 / With your team</span><h3>Access with a purpose.</h3><p>Use staff roles and permissions to decide who can work with each part of your shop.</p></article><article class="everyday-point"><span>04 / When you need a hand</span><h3>A way to reach us.</h3><p><a class="text-link" href="mailto:{{ config('app.support_email') }}?subject=JewelFlows%20enquiry">Talk to the team <span aria-hidden="true">↗</span></a></p></article></div></section>
    <section class="wrap faq" id="questions" aria-labelledby="questions-title"><div><div class="eyebrow">A few useful answers</div><h2 id="questions-title">Before you<br>get started.</h2></div><div class="faq-list">
        <details><summary>Which product is right for my business?</summary><p>Choose Retail for jewellery-shop billing, inventory, customers and accounts. Choose Dhiran for gold loan records, pledged items, payments and closures. You can use both with separate accounts.</p></details>
        <details><summary>I already use JewelFlows. Where do I log in?</summary><p>Use Log in at the top of this page and choose your product. It stays visible as you scroll, including on a phone. <a href="{{ route('login') }}">Open Retail login</a>.</p></details>
        <details><summary>Can I use JewelFlows on my phone?</summary><p>The website adapts to smaller screens. Retail also has an Android companion app. Contact the team for the current app and setup guidance.</p></details>
        <details><summary>How do I create an account?</summary><p>Use Register at the top of the page, choose Retail or Dhiran, and complete that product's registration. Each product has its own account and workspace.</p></details>
    </div></section>
    <section class="wrap closing" aria-labelledby="closing-title"><div><h2 id="closing-title">Your business.<br>A better way to run it.</h2><p>Choose the workspace that fits your day.</p></div><div class="actions"><a class="button button-dark" href="{{ route('register') }}">Start with Retail <span aria-hidden="true">↗</span></a><a class="button button-light" href="#dhiran">Explore Dhiran <span aria-hidden="true">↑</span></a></div></section>
</main>
<footer class="wrap"><div class="footer-top"><a class="brand" href="{{ route('home') }}"><img src="{{ asset('favicon.svg') }}" alt="" width="36" height="36"><span>Jewel<span>Flows</span></span></a><nav class="footer-links" aria-label="Footer"><a href="#retail">Retail</a><a href="#dhiran">Dhiran</a><a href="{{ route('login') }}">Retail login</a><a href="mailto:{{ config('app.support_email') }}">Contact</a></nav></div><div class="footer-bottom"><span>© {{ date('Y') }} JewelFlows. All rights reserved.</span><span>Made for jewellery retail &amp; gold loan businesses.</span></div></footer>
<script>
(() => {
    // Native details work without JS; enhance with outside-click and Escape dismissal.
    const accountMenus = [...document.querySelectorAll('.entry')];
    accountMenus.forEach(menu => menu.addEventListener('toggle', () => {
        if (menu.open) accountMenus.forEach(other => { if (other !== menu) other.open = false; });
    }));
    document.addEventListener('click', event => {
        accountMenus.forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') accountMenus.forEach(menu => {
            if (menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
        });
    });
})();
</script>
</body>
</html>
