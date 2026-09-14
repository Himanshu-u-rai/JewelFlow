@props(['shop'])

{{--
    WHO caused this shop's restriction — the companion to access-mode-badge,
    which says how hard the block is. Display only; decides and writes nothing.

    The two axes are independent and neither mode records its own cause.
    `suspended` is written by an administrator AND by CheckSubscriptionExpiry on
    a grace lapse, with no actor stamped; `read_only` likewise. So the stored
    column cannot answer the only question the operator has at a dashboard —
    "did we do this, and must I act?" — and the alert tables that echoed
    suspension_reason answered it with whatever free text happened to be there,
    or with an em-dash.

    Shop::accessClassification() is the single derivation, the same one the
    badge, the shop list and the detail page read, so this cannot contradict
    them nor what the owner experiences. Deliberately not re-implemented from
    suspended_by or a reason string: the precedence between the two lives in the
    model and has exactly one home.

    FAIL CLOSED. Only the three positively-classified states get a confident
    label. Anything else — the unclassified pair, and any classification added
    later — reads "Unattributed", never "None".
--}}

@php
    [$label, $tone, $explanation] = match ($shop->accessClassification()) {
        'admin_suspended', 'admin_read_only' => [
            'Admin hold',
            'rose',
            'Recorded as a deliberate administrator restriction. The owner cannot lift this themselves, and buying a plan will not clear it.',
        ],
        'subscription_lapse' => [
            'Subscription lapse',
            'amber',
            'The subscription term ended — this is not an administrator restriction. The owner is sent to the plan picker on login and can choose a plan to restore access. No action is needed here.',
        ],
        'active' => [
            'None',
            'emerald',
            'No restriction.',
        ],
        default => [
            'Unattributed',
            'slate',
            'Restricted, but no administrator is recorded and the subscription rows do not confirm an expiry either. Unresolved: the shop is blocked and is not known to be owner-recoverable. Investigate before applying anything.',
        ],
    };
@endphp

<span class="admin-badge admin-badge-{{ $tone }}" title="{{ $explanation }}">{{ $label }}</span>
