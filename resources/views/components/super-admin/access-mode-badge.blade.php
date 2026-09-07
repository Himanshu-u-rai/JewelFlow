@props(['shop'])

{{--
    Single source of truth for how a shop's access_mode is LABELLED in the admin
    UI. Display only — this component reads state and never changes it.

    Why it exists: `read_only` is meant to denote a JewelFlows administrator
    hold, but the pre-2026-08-27 expiry fork also minted `read_only` on an
    ordinary subscription lapse with no actor recorded. Both rows stored the
    identical string, so a raw `{{ $shop->access_mode }}` echo showed "Read Only"
    for two states that need opposite operator responses:

      • administrator hold  → deliberate; leave it alone.
      • subscription lapse  → not a hold at all; the owner can already recover on
                              their own by choosing a plan.

    Shop::suspensionIsSubscriptionManaged() is the existing authoritative
    classifier and the same one AuthenticatedSessionController and
    EnsureSubscriptionIsActive route on, so the badge cannot disagree with what
    the owner actually experiences. It is deliberately NOT re-implemented here:
    it already applies admin-attribution precedence first and then corroborates
    against the shop's current subscription rows, which is exactly why a reason
    string or an absent timestamp must not be read on its own.

    ponytail: the classifier runs two bounded subscription queries, and only for
    a shop already in `read_only` — a rare, closed set (nothing mints read_only
    any more except a deliberate admin action). If a read_only population ever
    grows large enough for the index page to feel it, eager-load the shop's
    subscriptions rather than caching a denormalised label.
--}}

@php
    $mode = $shop->access_mode ?? 'active';

    // Only a read_only row is ambiguous. `suspended` and `active` mean exactly
    // what they say, so their labels and colours are untouched.
    $lapsed = $mode === 'read_only' && $shop->suspensionIsSubscriptionManaged();

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
