<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Choose Plan | JewelFlow</title>
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
  <style>
    /* Master / detail plan picker: a slim stacked option rail on the left, the
       selected plan's full detail on the right. Compact, no vertical stretch. */
    :root { --md-gold:#d97706; --md-gold-deep:#b45309; --md-ink:#1f2430; --md-muted:#6b7280; --md-line:#ece7dc; }

    .subscription-plans-page .hero { padding: 26px 24px 6px; }
    .plans-md { max-width: 940px; margin: 0 auto; padding: 16px 24px 40px; }
    .plans-md .plans-back-link-wrap { margin-bottom: 16px; }

    .md-shell {
      display: grid;
      grid-template-columns: 0.82fr 1fr;
      gap: 22px;
      align-items: stretch;   /* both columns share the same height */
    }

    /* Left rail: options stretch to fill the column so its height matches the
       detail pane on the right (equal-height columns, like the reference). */
    .md-rail { display: flex; flex-direction: column; gap: 12px; height: 100%; }
    .md-opt {
      flex: 1 1 0;            /* distribute evenly to fill the column height */
      display: flex; align-items: center; justify-content: space-between; gap: 14px;
      width: 100%; text-align: left; cursor: pointer;
      background: #fff; border: 1.5px solid var(--md-line); border-radius: 16px;
      padding: 16px 18px; font: inherit; color: var(--md-ink);
      box-shadow: 0 1px 2px rgba(31,36,48,0.04);
      transition: border-color .16s ease, box-shadow .16s ease, transform .12s ease;
    }
    .md-opt:hover { border-color: var(--md-gold); }
    .md-opt:active { transform: scale(0.99); }
    .md-opt.active {
      border-color: var(--md-gold);
      background: #fffdf7;
      box-shadow: 0 0 0 3px rgba(245,158,11,0.16), 0 10px 26px -14px rgba(180,83,9,0.30);
    }
    .md-opt-title { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .md-opt-badge {
      font-size: 10px; font-weight: 700; letter-spacing: .02em;
      color: var(--md-gold-deep); background: rgba(245,158,11,0.14);
      padding: 2px 8px; border-radius: 999px; text-transform: uppercase;
    }
    .md-opt-sub { font-size: 12px; color: var(--md-muted); margin-top: 3px; }
    .md-opt-price { font-size: 18px; font-weight: 800; color: var(--md-ink); white-space: nowrap; }
    .md-opt-price span { font-size: 12px; font-weight: 600; color: var(--md-muted); }
    .md-opt-trial .md-opt-price { color: var(--md-gold-deep); }

    /* Right detail */
    .md-detail { position: relative; }
    .md-pane {
      background: #fff; border: 1px solid var(--md-line); border-radius: 20px;
      padding: 28px 28px 24px;
      box-shadow: 0 1px 2px rgba(31,36,48,0.04), 0 16px 40px -20px rgba(31,36,48,0.16);
      display: none;
      animation: md-fade .18s ease-out;
    }
    .md-pane.active { display: block; }
    @keyframes md-fade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

    .md-pane-name { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--md-muted); }
    .md-pane-price { font-size: 40px; font-weight: 800; color: var(--md-ink); letter-spacing: -1px; margin-top: 4px; }
    .md-pane-per { font-size: 15px; font-weight: 600; color: var(--md-muted); letter-spacing: 0; margin-left: 4px; }
    .md-pane-sub { font-size: 13px; color: var(--md-muted); margin-top: 2px; }

    .md-cta {
      display: block; width: 100%; margin: 18px 0 4px;
      background: var(--md-gold-deep); color: #fff; border: 0; border-radius: 12px;
      padding: 13px 22px; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer;
      box-shadow: 0 6px 16px -4px rgba(217,119,6,0.45);
      transition: background .16s ease, transform .12s ease, box-shadow .16s ease;
    }
    .md-cta:hover { background: #92400e; box-shadow: 0 10px 26px -6px rgba(217,119,6,0.5); }
    .md-cta:active { transform: scale(0.98); }

    .md-pane-features-head {
      font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
      color: var(--md-muted); margin: 20px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--md-line);
    }
    .md-pane-features { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; column-gap: 18px; row-gap: 0; }
    .md-pane-features li { display: flex; align-items: center; gap: 9px; padding: 6px 0; font-size: 12.5px; color: #4b5260; }
    .md-pane-features svg { flex-shrink: 0; width: 15px; height: 15px; color: var(--md-gold); }

    @media (max-width: 760px) {
      .md-shell { grid-template-columns: 1fr; gap: 16px; }
      .md-pane-features { grid-template-columns: 1fr; }
    }

    @media (prefers-reduced-motion: reduce) {
      .md-pane { animation: none; }
      .md-opt, .md-cta { transition: none; }
    }
  </style>
</head>
<body>
<div class="page-wrapper subscription-plans-page ops-treatment-page">

  {{-- Header --}}
  <div class="header">
    <div class="header-brand">
      <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="header-brand-mark">
        <path d="M8 13L16 4L24 13L16 28Z" fill="url(#dg)"/>
        <path d="M8 13L16 4L24 13" stroke="#d97706" stroke-width="1.2" stroke-linejoin="round"/>
        <line x1="8" y1="13" x2="24" y2="13" stroke="#f59e0b" stroke-width="0.8" opacity="0.6"/>
        <defs>
          <linearGradient id="dg" x1="16" y1="4" x2="16" y2="28" gradientUnits="userSpaceOnUse">
            <stop stop-color="#fcd34d"/>
            <stop offset="1" stop-color="#f59e0b"/>
          </linearGradient>
        </defs>
      </svg>
      <div class="header-brand-text">Jewel<span>Flow</span></div>
    </div>
    <div class="header-step" style="display:flex;align-items:center;gap:18px;">
      <span>Step 2 of 3 · Choose Plan</span>
      {{-- Always allow an escape: a user without a plan must still be able to
           log out and sign into an account that does have one. --}}
      <form method="POST" action="{{ route('logout') }}" style="margin:0;">
        @csrf
        <button type="submit" style="background:none;border:0;padding:0;font:inherit;font-weight:600;color:#b45309;cursor:pointer;text-decoration:underline;">{{ __('Log out') }}</button>
      </form>
    </div>
  </div>

  {{-- Hero --}}
  <div class="hero">
    <h1>Choose Your Plan</h1>
    <p>Simple pricing, powerful features. No hidden fees.</p>
    <div class="shop-badge">
      <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v6a4 4 0 01-4 4H6a4 4 0 01-4-4V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5z" clip-rule="evenodd"/>
      </svg>
      {{ ucfirst($shopType) }} Plan
    </div>
  </div>

  @php
    $hasYearly = !is_null($yearlyPlan);
    $hasMonthly = !is_null($monthlyPlan);
    $savingsPercent = ($hasYearly && $hasMonthly && $monthlyPlan->price_monthly > 0)
      ? (int) round(100 - ($yearlyPlan->price_yearly / ($monthlyPlan->price_monthly * 12)) * 100)
      : 0;

    // Build the option list (left rail). Each option: a plan to bill, the cycle,
    // the displayed monthly-equivalent price, a sublabel, and an optional badge.
    $options = [];
    if ($hasYearly) {
      $options[] = [
        'key' => 'yearly', 'plan' => $yearlyPlan, 'cycle' => 'yearly',
        'title' => 'Yearly', 'price' => (int) round($yearlyPlan->price_yearly / 12),
        'sub' => '₹' . number_format($yearlyPlan->price_yearly, 0) . ' billed yearly',
        'badge' => $savingsPercent > 0 ? 'Save ' . $savingsPercent . '%' : 'Best value',
      ];
    }
    if ($hasMonthly) {
      $options[] = [
        'key' => 'monthly', 'plan' => $monthlyPlan, 'cycle' => 'monthly',
        'title' => 'Monthly', 'price' => (int) round($monthlyPlan->price_monthly),
        'sub' => 'Billed every month', 'badge' => null,
      ];
    }
    $trialPlan = $yearlyPlan ?? $monthlyPlan;

    // Feature list for a plan, decoded + label-mapped (used in the detail pane).
    $featuresOf = function ($plan) use ($featureLabels) {
      $f = $plan->features ?? [];
      if (is_string($f)) { $f = json_decode($f, true) ?? []; }
      if (! is_array($f)) { $f = []; }
      $out = [];
      foreach ($f as $key => $val) {
        if (is_bool($val) && ! $val) { continue; }
        if (! is_bool($val)) {
          if ($key === 'max_items' && (int) $val === -1) { $out[] = 'Unlimited items'; }
          else { $out[] = 'Up to ' . $val . ' ' . ($featureLabels[$key] ?? $key); }
        } else {
          $out[] = $featureLabels[$key] ?? $key;
        }
      }
      return $out;
    };
    $defaultKey = $options[0]['key'] ?? '';
  @endphp

  <div>
    <div class="plans-container plans-md">
      <div class="plans-back-link-wrap">
        <a href="{{ route('shops.choose-type') }}" class="back-btn">← Change business type</a>
      </div>

      {{-- Master / detail: stacked options on the left, full detail on the right. --}}
      <div class="md-shell" id="planMaster">
        {{-- LEFT: option rail --}}
        <div class="md-rail" role="tablist" aria-label="Plans">
          @foreach($options as $i => $opt)
            <button type="button" class="md-opt {{ $i === 0 ? 'active' : '' }}"
                    role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                    data-plan="{{ $opt['key'] }}">
              <div class="md-opt-main">
                <div class="md-opt-title">
                  {{ $opt['title'] }}
                  @if($opt['badge'])<span class="md-opt-badge">{{ $opt['badge'] }}</span>@endif
                </div>
                <div class="md-opt-sub">{{ $opt['sub'] }}</div>
              </div>
              <div class="md-opt-price">₹{{ number_format($opt['price'], 0) }}<span>/mo</span></div>
            </button>
          @endforeach

          @if($trialPlan)
            <button type="button" class="md-opt md-opt-trial" role="tab" aria-selected="false" data-plan="trial">
              <div class="md-opt-main">
                <div class="md-opt-title">Free trial</div>
                <div class="md-opt-sub">1 month, no card</div>
              </div>
              <div class="md-opt-price">₹0</div>
            </button>
          @endif
        </div>

        {{-- RIGHT: detail panes (one per option; one visible at a time) --}}
        <div class="md-detail">
          @foreach($options as $i => $opt)
            <div class="md-pane {{ $i === 0 ? 'active' : '' }}" data-pane="{{ $opt['key'] }}">
              <div class="md-pane-name">{{ $shopType ? ucfirst($shopType) : '' }} {{ $opt['title'] }}</div>
              <div class="md-pane-price">
                ₹{{ number_format($opt['price'], 0) }}<span class="md-pane-per">/month</span>
              </div>
              <div class="md-pane-sub">{{ $opt['sub'] }}</div>

              <form action="{{ route('subscription.choose') }}" method="POST" class="md-pane-form">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $opt['plan']->id }}">
                <input type="hidden" name="billing_cycle" value="{{ $opt['cycle'] }}">
                <button type="submit" class="md-cta">Get Started →</button>
              </form>

              <div class="md-pane-features-head">What's included</div>
              <ul class="md-pane-features">
                @foreach($featuresOf($opt['plan']) as $feat)
                  <li>
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    <span>{{ $feat }}</span>
                  </li>
                @endforeach
              </ul>
            </div>
          @endforeach

          @if($trialPlan)
            <div class="md-pane md-pane-trial" data-pane="trial">
              <div class="md-pane-name">1-month free trial</div>
              <div class="md-pane-price">₹0<span class="md-pane-per">/first month</span></div>
              <div class="md-pane-sub">No card needed. Set up your shop and try everything.</div>

              <form action="{{ route('subscription.trial.start') }}" method="POST" class="md-pane-form">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $trialPlan->id }}">
                <button type="submit" class="md-cta">Start free trial →</button>
              </form>

              <div class="md-pane-features-head">How it works</div>
              <ul class="md-pane-features">
                <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span>Full access for one month</span></li>
                <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span>No card, no charge upfront</span></li>
                <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span>Your data stays safe when it ends</span></li>
                <li><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg><span>Pick a plan anytime to keep going</span></li>
              </ul>
            </div>
          @endif
        </div>
      </div>

      {{-- Trust Badges --}}
      <div class="footer-info">
        <div class="trust-badges">
          <span class="trust-badge">
            <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 1l3.09 1.545a12.028 12.028 0 013.91 3.91V10a8 8 0 01-14 5.292V6.455A12.028 12.028 0 016.91 2.545L10 1z" clip-rule="evenodd"/></svg>
            Secure via Razorpay
          </span>
          <span class="trust-badge">
            <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            GST included
          </span>
          <span class="trust-badge">
            <svg viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 11.707a1 1 0 001.414 1.414l2-2A1 1 0 0011 10.586V7z" clip-rule="evenodd"/></svg>
            Cancel anytime
          </span>
        </div>
      </div>
    </div>
  </div>

</div>
<script>
(function () {
  var master = document.getElementById('planMaster');
  if (!master) return;
  var opts  = master.querySelectorAll('.md-opt');
  var panes = master.querySelectorAll('.md-pane');

  function select(key) {
    opts.forEach(function (o) {
      var on = o.getAttribute('data-plan') === key;
      o.classList.toggle('active', on);
      o.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panes.forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-pane') === key);
    });
  }

  opts.forEach(function (o) {
    o.addEventListener('click', function () { select(o.getAttribute('data-plan')); });
  });
})();
</script>
</body>
</html>
