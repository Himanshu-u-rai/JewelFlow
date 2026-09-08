@props(['shop'])

{{--
    Single source of truth for how a shop's access_mode is LABELLED in the admin
    UI. Display only — this component reads state and never changes it.

    Why it exists: NEITHER restricted mode records its own cause. `read_only` is
    meant to denote a JewelFlows administrator hold, but the pre-2026-08-27 expiry
    fork also minted it on an ordinary subscription lapse with no actor recorded —
    and `suspended` is written both by an administrator AND by
    CheckSubscriptionExpiry on a grace-period lapse. Each pair stores the identical
    string, so a raw `{{ $shop->access_mode }}` echo showed one label for two states
    that need opposite operator responses:

      • administrator hold  → deliberate; leave it alone.
      • subscription lapse  → not a hold at all; the owner can already recover on
                              their own by choosing a plan.

    Shop::accessClassification() is the single derivation. It delegates to
    Shop::suspensionIsSubscriptionManaged() — the same classifier
    AuthenticatedSessionController and EnsureSubscriptionIsActive route on — so
    the badge cannot disagree with what the owner experiences, nor with the
    Platform Control panel and subscription summary on the detail page, which
    read the same method. It is deliberately NOT re-implemented here: it already
    applies admin-attribution precedence first and then corroborates against the
    shop's current subscription rows, which is exactly why a reason string or an
    absent timestamp must not be read on its own.

    ponytail: the classifier runs its two bounded subscription queries only for a
    shop already restricted — a small set. If that population ever grows large
    enough for the index page to feel it, eager-load the shop's subscriptions
    rather than caching a denormalised label.
--}}

@php
    // The MODE still drives the colour: it is the honest severity signal. A lapse
    // that suspended the shop is a full block and stays rose; a lapse that only
    // made it read-only stays amber. Only the LABEL depends on the cause.
    $mode = $shop->access_mode ?? 'active';

    $lapsed = $shop->accessClassification() === 'subscription_lapse';

    $label = match (true) {
        $lapsed             => 'Subscription ended',
        $mode === 'read_only' => 'Read Only',
        default             => ucfirst($mode),
    };

    $class = match ($mode) {
        'suspended' => 'admin-badge-rose',
        'read_only' => 'admin-badge-amber',
        default     => 'admin-badge-emerald',
    };
@endphp

<span class="admin-badge {{ $class }}"
      @if($lapsed) title="Subscription term ended — not an administrator restriction. The owner can choose a plan to restore access." @endif>
    {{ $label }}
</span>
