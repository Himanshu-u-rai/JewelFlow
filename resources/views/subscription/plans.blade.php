<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Choose Plan — JewelFlow</title>
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
  @vite(['resources/css/app.css', 'resources/js/app.js'])
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
      <span>Step 2 of 3 — Choose Plan</span>
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
    $hasBoth = $hasYearly && $hasMonthly;
    $savingsPercent = 0;

    if ($hasBoth && $monthlyPlan->price_monthly > 0) {
      $savingsPercent = round(100 - ($yearlyPlan->price_yearly / ($monthlyPlan->price_monthly * 12)) * 100);
    }
  @endphp

  <div>
    <div class="plans-container">
      <div class="plans-back-link-wrap">
        <a href="{{ route('shops.choose-type') }}" class="back-btn">← Change business type</a>
      </div>
      {{-- Plans Grid --}}
      @if($hasBoth)
        {{-- BOTH PLANS: side by side, yearly highlighted --}}
        <div class="plans-grid dual">
          {{-- Monthly Card --}}
          @include('subscription._plan-card', [
            'plan' => $monthlyPlan,
            'isYearly' => false,
            'isHighlighted' => false,
            'highlightExpr' => null,
            'showBestBadge' => false,
            'btnStyle' => 'secondary',
            'btnStyleExpr' => null,
            'savingsPercent' => 0,
          ])

          {{-- Yearly Card --}}
          @include('subscription._plan-card', [
            'plan' => $yearlyPlan,
            'isYearly' => true,
            'isHighlighted' => true,
            'highlightExpr' => null,
            'showBestBadge' => true,
            'btnStyle' => 'primary',
            'btnStyleExpr' => null,
            'savingsPercent' => $savingsPercent,
          ])
        </div>

      @elseif($hasMonthly)
        {{-- MONTHLY ONLY: single centered card --}}
        <div class="plans-grid single">
          @include('subscription._plan-card', [
            'plan' => $monthlyPlan,
            'isYearly' => false,
            'isHighlighted' => true,
            'highlightExpr' => null,
            'showBestBadge' => false,
            'btnStyle' => 'primary',
            'btnStyleExpr' => null,
            'savingsPercent' => 0,
          ])
        </div>

      @elseif($hasYearly)
        {{-- YEARLY ONLY: single centered card --}}
        <div class="plans-grid single">
          @include('subscription._plan-card', [
            'plan' => $yearlyPlan,
            'isYearly' => true,
            'isHighlighted' => true,
            'highlightExpr' => null,
            'showBestBadge' => false,
            'btnStyle' => 'primary',
            'btnStyleExpr' => null,
            'savingsPercent' => 0,
          ])
        </div>
      @endif

      {{-- Free trial option — no payment, no card. Uses the yearly plan id when
           present (else monthly) only to identify the PRODUCT; a trial is never
           billed, so the cycle is irrelevant. --}}
      @php $trialPlan = $yearlyPlan ?? $monthlyPlan; @endphp
      @if($trialPlan)
        <div class="trial-band" style="margin:22px auto 0;max-width:640px;background:#fffbeb;border:1px solid #fcd34d;border-radius:16px;padding:20px 22px;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;">
          <div style="flex:1 1 280px;min-width:240px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <svg width="18" height="18" viewBox="0 0 20 20" fill="#b45309"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.5 2.5a1 1 0 001.414-1.414L11 9.586V6z" clip-rule="evenodd"/></svg>
              <span style="font-weight:700;color:#92400e;font-size:1.02rem;">Not ready to pay yet?</span>
            </div>
            <p style="margin:0;color:#a16207;font-size:0.92rem;line-height:1.45;">
              Start a <strong>1-month free trial</strong>. No card needed. Set up your shop and try everything.
              When the trial ends, your data stays safe — just pick a plan to keep going.
            </p>
          </div>
          <form action="{{ route('subscription.trial.start') }}" method="POST" style="margin:0;flex:0 0 auto;">
            @csrf
            <input type="hidden" name="plan_id" value="{{ $trialPlan->id }}">
            <button type="submit"
                    style="background:#b45309;color:#fff;border:0;border-radius:10px;padding:12px 22px;font-weight:700;font-size:0.95rem;cursor:pointer;white-space:nowrap;transition:transform .12s ease-out,background .12s ease-out;"
                    onmouseover="this.style.background='#92400e'"
                    onmouseout="this.style.background='#b45309'"
                    onmousedown="this.style.transform='scale(0.98)'"
                    onmouseup="this.style.transform='scale(1)'">
              Start 1-month free trial
            </button>
          </form>
        </div>
      @endif

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
</body>
</html>
