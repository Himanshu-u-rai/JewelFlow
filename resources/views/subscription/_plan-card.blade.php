{{--
  Plan Card Partial
  Variables: $plan, $isYearly, $isHighlighted, $highlightExpr, $showBestBadge,
             $btnStyle, $btnStyleExpr, $savingsPercent, $featureLabels
--}}

@php
  $features = $plan->features ?? [];
  if (is_string($features)) {
      $features = json_decode($features, true) ?? [];
  }
  if (!is_array($features)) {
      $features = [];
  }
  $displayPrice = $isYearly
    ? number_format(round($plan->price_yearly / 12), 0)
    : number_format($plan->price_monthly, 0);
@endphp

<div
  class="plan-card {{ $isHighlighted && !$highlightExpr ? 'highlighted' : '' }}"
  @if($highlightExpr)
    :class="{ 'highlighted': {{ $highlightExpr }} }"
  @endif
>
  @if($showBestBadge)
    <div class="best-badge">BEST VALUE</div>
  @endif

  <div class="plan-name">{{ $plan->name }}</div>

  <div class="plan-price">
    <span class="amount">₹{{ $displayPrice }}</span>
    <span class="period">/month</span>
  </div>

  @if($isYearly)
    <div class="plan-billing-note">
      ₹{{ number_format($plan->price_yearly, 0) }} billed annually
    </div>
    @if($savingsPercent > 0)
    <div class="savings-pill">
      💰 Save {{ $savingsPercent }}% vs monthly
    </div>
    @endif
  @else
    <div class="plan-billing-note">Billed monthly</div>
  @endif

  <form action="{{ route('subscription.choose') }}" method="POST">
    @csrf
    <input type="hidden" name="plan_id" value="{{ $plan->id }}">
    <input type="hidden" name="billing_cycle" value="{{ $isYearly ? 'yearly' : 'monthly' }}">
    <button
      type="submit"
      class="subscribe-btn {{ $btnStyle }}"
      @if($btnStyleExpr)
        :class="({{ $btnStyleExpr }}) === 'primary' ? 'primary' : 'secondary'"
      @endif
    >
      Get Started →
    </button>
  </form>

  <div class="feature-header">What's included</div>

  <ul class="feature-list">
    @foreach($features as $key => $value)
      @if((is_bool($value) && $value) || !is_bool($value))
        <li class="feature-item">
          <span class="check-icon">
            <svg viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
            </svg>
          </span>
            <span>
            @if(!is_bool($value))
              @if($key === 'max_items' && (int) $value === -1)
                <strong class="plan-feature-value">Unlimited</strong>&nbsp;Items
              @else
                Up to <strong class="plan-feature-value">{{ $value }}</strong>&nbsp;{{ $featureLabels[$key] ?? $key }}
              @endif
            @else
              {{ $featureLabels[$key] ?? $key }}
            @endif
          </span>
        </li>
      @endif
    @endforeach
  </ul>

  @if(($plan->trial_days ?? 0) > 0)
  <div class="trial-note">
    <svg width="16" height="16" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 11.707a1 1 0 001.414 1.414l2-2A1 1 0 0011 10.586V7z" clip-rule="evenodd"/></svg>
    <span>Includes <strong>{{ $plan->trial_days }}-day</strong> free trial</span>
  </div>
  @endif
</div>
