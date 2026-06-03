<x-app-layout>
    @php
        $rawStatus = (string) ($subscription->status ?? 'inactive');
        $statusLabel = ucfirst($rawStatus);

        if ($isInGrace) {
            $statusLabel = 'Grace Period';
        } elseif ($isExpired) {
            $statusLabel = 'Expired';
        }

        $statusTone = [
            'trial' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
            'active' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac'],
            'grace period' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
            'expired' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fca5a5'],
            'cancelled' => ['bg' => '#f1f5f9', 'text' => '#334155', 'border' => '#cbd5e1'],
            'suspended' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fca5a5'],
            'read_only' => ['bg' => '#ffedd5', 'text' => '#9a3412', 'border' => '#fdba74'],
        ][strtolower($statusLabel)] ?? ['bg' => '#f1f5f9', 'text' => '#334155', 'border' => '#cbd5e1'];

        $startsAt = $subscription->starts_at;
        $endsAt = $subscription->ends_at;
        $graceEnd = $endsAt ? $endsAt->copy()->addDays((int) ($plan->grace_days ?? 7)) : null;

        $totalDays = ($startsAt && $endsAt) ? max(1, $startsAt->diffInDays($endsAt)) : 0;
        $usedPercent = 0;

        if ($daysRemaining !== null && $daysRemaining >= 0 && $totalDays > 0) {
            $usedPercent = max(0, min(100, (int) round(100 - (($daysRemaining / $totalDays) * 100))));
        } elseif ($isExpired) {
            $usedPercent = 100;
        }

        $planFeatures = is_array($plan->features)
            ? $plan->features
            : (json_decode((string) ($plan->features ?? '[]'), true) ?: []);

        $featureLabels = [
            'pos' => 'Point of Sale',
            'inventory' => 'Inventory Management',
            'customers' => 'Customer Management',
            'repairs' => 'Repair Tracking',
            'invoices' => 'Invoicing & Billing',
            'reports' => 'Business Reports',
            'vendors' => 'Vendor Management',
            'schemes' => 'Gold Schemes',
            'loyalty' => 'Loyalty Points',
            'installments' => 'EMI / Installments',
            'reorder_alerts' => 'Reorder Alerts',
            'tag_printing' => 'Tag Printing',
            'whatsapp_catalog' => 'Catalog',
            'bulk_imports' => 'Bulk Imports',
            'staff_limit' => 'Staff Accounts',
            'max_items' => 'Item Limit',
            'gold_inventory' => 'Gold Inventory',
            'manufacturing' => 'Manufacturing Workflow',
            'customer_gold' => 'Customer Gold Ledger',
            'exchange' => 'Gold Exchange',
            'public_catalog' => 'Public Item Catalog',
        ];

        $billingCycle = ucfirst((string) ($subscription->billing_cycle ?? 'monthly'));
        $pricePaid = $subscription->price_paid
            ?? ($subscription->billing_cycle === 'yearly' ? ($plan->price_yearly ?? null) : ($plan->price_monthly ?? null));
    @endphp

    <style>
        /* ──────────────────────────────────────────────────────────────
           Subscription status — restrained, hairline-led surface.
           No edge accent bars, no decorative glow, no box-in-box tinting.
           Teal is a precise accent (status, progress, primary action),
           not a wash. Type leans on weight + scale contrast, not all-800.
           ────────────────────────────────────────────────────────────── */
        .sub-status-page {
            --sub-border: #e7ebf1;
            --sub-border-soft: #eef1f6;
            --sub-border-strong: #d9dfe8;
            --sub-surface: #ffffff;
            --sub-ink: #0f172a;
            --sub-ink-2: #3d4861;
            --sub-muted: #6a7588;
            --sub-accent: #0d9488;
            --sub-accent-deep: #0f766e;
            --sub-accent-soft: rgba(13, 148, 136, 0.08);
            /* Whisper shadow: a hairline border carries the structure, the
               shadow only lifts the surface a touch off the page. */
            --sub-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 10px 28px -20px rgba(16, 24, 40, 0.20);
            --sub-ease: cubic-bezier(0.23, 1, 0.32, 1);
        }

        .sub-status-page .sub-status-wrap {
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        /* Motion-safe entrance: base state is fully visible; the keyframe only
           plays when motion is allowed, so headless/print/reduced-motion render
           the content immediately (never gated on a class). */
        @media (prefers-reduced-motion: no-preference) {
            .sub-status-page .sub-hero,
            .sub-status-page .sub-grid > *,
            .sub-status-page .sub-billing-section {
                animation: subRise 0.5s var(--sub-ease) both;
            }
            .sub-status-page .sub-grid > *:nth-child(2) { animation-delay: 0.05s; }
            .sub-status-page .sub-billing-section { animation-delay: 0.1s; }
            @keyframes subRise {
                from { opacity: 0; transform: translateY(8px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        }

        .sub-status-page .sub-alert {
            border: 1px solid transparent;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }

        .sub-status-page .sub-alert.success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }

        .sub-status-page .sub-alert.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .sub-status-page .sub-hero,
        .sub-status-page .sub-card {
            border: 1px solid var(--sub-border);
            border-radius: 16px;
            background: var(--sub-surface);
            box-shadow: var(--sub-shadow);
        }

        /* Hero: a calm white surface. No top accent bar, no corner glow. */
        .sub-status-page .sub-hero {
            padding: 28px 28px 26px;
        }

        .sub-status-page .sub-hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 26px;
        }

        .sub-status-page .sub-plan-name {
            margin: 0;
            color: var(--sub-ink);
            font-size: 28px;
            font-weight: 700;
            line-height: 1.12;
            letter-spacing: -0.02em;
            text-wrap: balance;
        }

        .sub-status-page .sub-plan-copy {
            margin: 8px 0 0;
            max-width: 58ch;
            color: var(--sub-muted);
            font-size: 14px;
            line-height: 1.6;
            text-wrap: pretty;
        }

        .sub-status-page .sub-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 30px;
            padding: 0 13px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.01em;
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Status dot inside the pill (pure CSS, uses currentColor). */
        .sub-status-page .sub-status-pill::before {
            content: "";
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: currentColor;
            box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 18%, transparent);
        }

        /* KPI strip: one bordered panel divided by hairline rules — a ledger,
           not four separate tinted boxes. */
        .sub-status-page .sub-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            margin-bottom: 26px;
            border: 1px solid var(--sub-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .sub-status-page .sub-kpi {
            padding: 16px 18px;
            border-right: 1px solid var(--sub-border);
        }

        .sub-status-page .sub-kpi:last-child {
            border-right: 0;
        }

        .sub-status-page .sub-kpi-label {
            margin: 0 0 7px;
            color: var(--sub-muted);
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
        }

        .sub-status-page .sub-kpi-value {
            margin: 0;
            color: var(--sub-ink);
            font-size: 19px;
            font-weight: 650;
            line-height: 1.2;
            letter-spacing: -0.01em;
            font-variant-numeric: tabular-nums;
        }

        /* Plan health: separated from the KPI strip by a hairline, not boxed. */
        .sub-status-page .sub-health {
            border-top: 1px solid var(--sub-border-soft);
            padding-top: 22px;
        }

        .sub-status-page .sub-health-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .sub-status-page .sub-health-title {
            margin: 0;
            color: var(--sub-ink);
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
        }

        .sub-status-page .sub-health-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 0 11px;
            border-radius: 999px;
            border: 1px solid #cfe6e2;
            background: var(--sub-accent-soft);
            color: #0f766e;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .sub-status-page .sub-health-copy {
            margin: 0;
            color: var(--sub-muted);
            font-size: 13.5px;
            line-height: 1.6;
        }

        .sub-status-page .sub-progress-track {
            width: 100%;
            height: 6px;
            margin-top: 14px;
            border-radius: 999px;
            background: #eceff4;
            overflow: hidden;
        }

        .sub-status-page .sub-progress-fill {
            height: 100%;
            border-radius: 999px;
            background: var(--sub-accent);
            transform-origin: left center;
        }

        /* Grow the fill on load (GPU transform, not layout). Width stays inline;
           reduced motion skips straight to the final state. */
        @media (prefers-reduced-motion: no-preference) {
            .sub-status-page .sub-progress-fill {
                animation: subFill 0.7s var(--sub-ease) 0.08s both;
            }
            @keyframes subFill {
                from { transform: scaleX(0); }
                to   { transform: scaleX(1); }
            }
        }

        .sub-status-page .sub-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
            gap: 20px;
        }

        .sub-status-page .sub-card-head {
            padding: 22px 24px 0;
        }

        .sub-status-page .sub-card-title {
            margin: 0;
            color: var(--sub-ink);
            font-size: 16px;
            font-weight: 600;
            line-height: 1.25;
            letter-spacing: -0.01em;
        }

        .sub-status-page .sub-card-copy {
            margin: 5px 0 0;
            color: var(--sub-muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .sub-status-page .sub-card-body {
            padding: 16px 24px 22px;
        }

        /* Billing overview: a clean key/value ledger with hairline rows —
           no inner boxes. */
        .sub-status-page .sub-detail-list {
            display: grid;
        }

        .sub-status-page .sub-detail-item {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
            padding: 13px 0;
            border-bottom: 1px solid var(--sub-border-soft);
        }

        .sub-status-page .sub-detail-item:first-child { padding-top: 4px; }

        .sub-status-page .sub-detail-item:last-child {
            border-bottom: 0;
            padding-bottom: 4px;
        }

        .sub-status-page .sub-detail-label {
            margin: 0;
            color: var(--sub-muted);
            font-size: 13px;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
            flex-shrink: 0;
        }

        .sub-status-page .sub-detail-value {
            margin: 0;
            color: var(--sub-ink);
            font-size: 13.5px;
            font-weight: 600;
            line-height: 1.45;
            text-align: right;
        }

        .sub-status-page .sub-note {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--sub-border-soft);
            color: var(--sub-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .sub-status-page .sub-feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            column-gap: 28px;
        }

        .sub-status-page .sub-feature-item {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            padding: 11px 0;
            border-bottom: 1px solid var(--sub-border-soft);
            color: var(--sub-ink-2);
            font-size: 13.5px;
            font-weight: 500;
            line-height: 1.45;
        }

        /* Last row in each column shouldn't carry a divider. Odd total leaves
           one cell in the final row; covering the last two is the simplest
           robust rule for a 2-column grid. */
        .sub-status-page .sub-feature-item:last-child,
        .sub-status-page .sub-feature-item:nth-last-child(2):nth-child(odd) {
            border-bottom: 0;
        }

        /* Bare accent check — no chip. */
        .sub-status-page .sub-dot {
            width: 16px;
            height: 16px;
            color: var(--sub-accent);
            flex-shrink: 0;
        }

        .sub-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 17px;
            border: 1px solid transparent;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.01em;
            text-decoration: none;
            transition: transform 0.16s var(--sub-ease), background-color 0.16s var(--sub-ease), border-color 0.16s var(--sub-ease), box-shadow 0.16s var(--sub-ease);
        }

        .sub-btn:focus-visible {
            outline: 2px solid var(--sub-accent);
            outline-offset: 2px;
        }

        .sub-btn:active {
            transform: scale(0.97);
        }

        .sub-btn.primary {
            background: var(--sub-accent, #0d9488);
            color: #fff;
            box-shadow: 0 1px 2px rgba(13, 148, 136, 0.22);
        }

        .sub-btn.primary:hover {
            background: var(--sub-accent-deep, #0f766e);
        }

        .sub-btn.secondary {
            background: #fff;
            color: var(--sub-ink, #0f172a);
            border-color: var(--sub-border-strong, #d9dfe8);
        }

        .sub-btn.secondary:hover {
            background: #f7f9fc;
            border-color: #c5cedb;
        }

        @media (max-width: 1100px) {
            .sub-status-page .sub-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            /* Hairline grid for a 2-up layout: drop the right edge on the
               right column, drop the bottom edge on the last row. */
            .sub-status-page .sub-kpi {
                border-bottom: 1px solid var(--sub-border);
            }
            .sub-status-page .sub-kpi:nth-child(2n) { border-right: 0; }
            .sub-status-page .sub-kpi:nth-child(n+3) { border-bottom: 0; }

            .sub-status-page .sub-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .sub-status-page .sub-hero {
                padding: 20px;
            }

            .sub-status-page .sub-hero-top {
                flex-direction: column;
                align-items: flex-start;
                margin-bottom: 20px;
            }

            .sub-status-page .sub-plan-name {
                font-size: 23px;
            }

            .sub-status-page .sub-plan-copy {
                font-size: 13px;
            }

            .sub-status-page .sub-kpi-grid {
                grid-template-columns: 1fr;
                margin-bottom: 22px;
            }

            .sub-status-page .sub-kpi {
                border-right: 0;
                border-bottom: 1px solid var(--sub-border);
            }
            .sub-status-page .sub-kpi:last-child { border-bottom: 0; }

            .sub-status-page .sub-health-head {
                align-items: flex-start;
            }

            .sub-status-page .sub-card-head {
                padding: 20px 18px 0;
            }

            .sub-status-page .sub-card-body {
                padding: 14px 18px 20px;
            }

            .sub-status-page .sub-feature-list {
                grid-template-columns: 1fr;
            }

            /* Single column on phones: only the very last item loses its rule. */
            .sub-status-page .sub-feature-item:nth-last-child(2):nth-child(odd) {
                border-bottom: 1px solid var(--sub-border-soft);
            }
            .sub-status-page .sub-feature-item:last-child {
                border-bottom: 0;
            }
        }

        /* ─── Billing History section ─── */
        .sub-status-page .sub-billing-head {
            margin-bottom: 16px;
        }
        .sub-status-page .sub-billing-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--sub-ink);
            margin: 0 0 5px;
            letter-spacing: -0.01em;
        }
        .sub-status-page .sub-billing-copy {
            font-size: 13px;
            color: var(--sub-muted);
            margin: 0;
        }
        .sub-status-page .sub-billing-card {
            background: #ffffff;
            border: 1px solid var(--sub-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--sub-shadow);
        }
        .sub-status-page .sub-billing-table-wrap {
            overflow-x: auto;
        }
        .sub-status-page .sub-billing-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .sub-status-page .sub-billing-table thead th {
            padding: 13px 18px;
            text-align: left;
            font-weight: 500;
            font-size: 12px;
            color: var(--sub-muted);
            background: #fafbfd;
            border-bottom: 1px solid var(--sub-border);
            text-transform: none;
            letter-spacing: 0;
            white-space: nowrap;
        }
        .sub-status-page .sub-billing-table thead th.text-right { text-align: right; }
        .sub-status-page .sub-billing-table tbody td {
            padding: 13px 18px;
            border-bottom: 1px solid var(--sub-border-soft);
            color: var(--sub-ink-2);
            vertical-align: middle;
        }
        .sub-status-page .sub-billing-table tbody tr:last-child td { border-bottom: 0; }
        .sub-status-page .sub-billing-table tbody tr:hover { background: #fafbfd; }
        .sub-status-page .sub-billing-table td.text-right { text-align: right; }
        .sub-status-page .sub-billing-num {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px;
            font-weight: 600;
            color: var(--sub-ink);
        }
        .sub-status-page .sub-billing-capitalize { text-transform: capitalize; color: var(--sub-ink-2); }
        .sub-status-page .sub-billing-muted { color: var(--sub-muted); font-size: 12.5px; }
        .sub-status-page .sub-billing-amount { font-weight: 600; color: var(--sub-ink); font-variant-numeric: tabular-nums; }
        .sub-status-page .sub-billing-pill {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.6;
        }
        .sub-status-page .sub-billing-pill-paid {
            background: #ecfdf5; color: #065f46; box-shadow: inset 0 0 0 1px #a7f3d0;
        }
        .sub-status-page .sub-billing-pill-cancelled {
            background: #fef2f2; color: #991b1b; box-shadow: inset 0 0 0 1px #fecaca;
        }
        .sub-status-page .sub-billing-view {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--sub-accent-deep);
            text-decoration: none;
        }
        .sub-status-page .sub-billing-view:hover { color: var(--sub-accent); text-decoration: underline; }
        .sub-status-page .sub-billing-empty {
            text-align: center;
            color: var(--sub-muted);
            padding: 32px 16px !important;
            font-size: 13px;
        }
        .sub-status-page .sub-billing-pagination {
            padding: 14px 18px;
            border-top: 1px solid var(--sub-border-soft);
            background: #fafbfd;
        }

        /* ─── Billing History on phones: table → stacked cards (no sideways
           scroll). Each invoice becomes a card; column headers become inline
           labels via data-label. ─── */
        @media (max-width: 767px) {
            .sub-status-page .sub-billing-table-wrap { overflow-x: visible; }
            .sub-status-page .sub-billing-table thead { display: none; }
            .sub-status-page .sub-billing-table,
            .sub-status-page .sub-billing-table tbody,
            .sub-status-page .sub-billing-table tr,
            .sub-status-page .sub-billing-table td {
                display: block;
                width: 100%;
            }
            .sub-status-page .sub-billing-table tr {
                padding: 4px 16px 12px;
                border-bottom: 8px solid #f1f5f9;
            }
            .sub-status-page .sub-billing-table tbody tr:last-child { border-bottom: 0; }
            .sub-status-page .sub-billing-table td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                padding: 10px 0;
                border-bottom: 1px solid #f4f7fb;
                text-align: right;
                white-space: normal;
            }
            .sub-status-page .sub-billing-table td:last-child { border-bottom: 0; }
            .sub-status-page .sub-billing-table td[data-label]::before {
                content: attr(data-label);
                flex-shrink: 0;
                text-align: left;
                color: #7d8aa3;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }
            /* View action: full-width button at the foot of each card. */
            .sub-status-page .sub-billing-table td.sub-billing-action { padding-top: 12px; }
            .sub-status-page .sub-billing-table td.sub-billing-action::before { display: none; }
            .sub-status-page .sub-billing-view {
                display: block;
                width: 100%;
                text-align: center;
                padding: 10px 12px;
                border-radius: 12px;
                border: 1px solid var(--sub-border-strong);
                background: #f6f9fc;
                color: var(--sub-accent-deep);
            }
            .sub-status-page .sub-billing-view:hover { background: #eef4fb; text-decoration: none; }
            /* Empty state stays a centered full-width message. */
            .sub-status-page .sub-billing-table td.sub-billing-empty {
                display: block;
                text-align: center;
                padding: 28px 16px !important;
            }
        }
    </style>

    <x-page-header class="ops-treatment-header">
        <div>
            <h1 class="page-title">Subscription</h1>
            <p class="text-sm text-gray-600 mt-1">View your current plan, billing cycle, renewal date, and included features.</p>
        </div>
        <div class="page-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="{{ route('subscription.plans') }}" class="sub-btn primary">Change Plan</a>
            <a href="mailto:support@jewelflow.io" class="sub-btn secondary">Contact Support</a>
        </div>
    </x-page-header>

    <div class="content-inner sub-status-page">
        <div class="sub-status-wrap">
<section class="sub-hero">
                <div class="sub-hero-top">
                    <div>
                        <h2 class="sub-plan-name">{{ $plan->name }}</h2>
                        <p class="sub-plan-copy">Billed {{ strtolower($billingCycle) }} with your current access, renewal timeline, and plan limits shown below.</p>
                    </div>
                    <span class="sub-status-pill" style="border:1px solid {{ $statusTone['border'] }}; background: {{ $statusTone['bg'] }}; color: {{ $statusTone['text'] }};">
                        {{ $statusLabel }}
                    </span>
                </div>

                <div class="sub-kpi-grid">
                    <div class="sub-kpi">
                        <p class="sub-kpi-label">Plan Amount</p>
                        <p class="sub-kpi-value">
                            @if($pricePaid !== null)
                                ₹{{ number_format((float) $pricePaid, 2) }}
                            @else
                                -
                            @endif
                        </p>
                    </div>
                    <div class="sub-kpi">
                        <p class="sub-kpi-label">Billing Cycle</p>
                        <p class="sub-kpi-value">{{ $billingCycle }}</p>
                    </div>
                    <div class="sub-kpi">
                        <p class="sub-kpi-label">Start Date</p>
                        <p class="sub-kpi-value">{{ $startsAt ? $startsAt->format('d M Y') : '-' }}</p>
                    </div>
                    <div class="sub-kpi">
                        <p class="sub-kpi-label">
                            @if($isInGrace)
                                Grace Ends
                            @elseif($isExpired)
                                Expired On
                            @else
                                Renews On
                            @endif
                        </p>
                        <p class="sub-kpi-value">
                            @if($isInGrace)
                                {{ $graceEnd ? $graceEnd->format('d M Y') : '-' }}
                            @else
                                {{ $endsAt ? $endsAt->format('d M Y') : '-' }}
                            @endif
                        </p>
                    </div>
                </div>

                <div class="sub-health">
                    <div class="sub-health-head">
                        <h3 class="sub-health-title">Plan health</h3>
                        @if($daysRemaining !== null && $daysRemaining >= 0)
                            <span class="sub-health-pill">{{ $daysRemaining }} day{{ $daysRemaining === 1 ? '' : 's' }} left</span>
                        @elseif($isInGrace)
                            <span class="sub-health-pill" style="border-color:#f3d7a3; background:#fff7ed; color:#9a3412;">Grace active</span>
                        @else
                            <span class="sub-health-pill" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">Renew required</span>
                        @endif
                    </div>

                    @if($daysRemaining !== null && $daysRemaining >= 0)
                        <p class="sub-health-copy">About <strong style="color:var(--sub-text);">{{ $daysRemaining }}</strong> days remain before your next renewal date.</p>
                        <div class="sub-progress-track">
                            <div class="sub-progress-fill" style="width: {{ $usedPercent }}%;"></div>
                        </div>
                    @elseif($isInGrace)
                        <p class="sub-health-copy" style="color:#92400e;">Subscription expired, but grace access stays available until {{ $graceEnd ? $graceEnd->format('d M, Y') : '-' }}.</p>
                        <div class="sub-progress-track">
                            <div class="sub-progress-fill" style="width:100%; background:#f59e0b;"></div>
                        </div>
                    @else
                        <p class="sub-health-copy" style="color:#991b1b;">Subscription access has expired. Renew the plan to restore uninterrupted usage.</p>
                        <div class="sub-progress-track">
                            <div class="sub-progress-fill" style="width:100%; background:#dc2626;"></div>
                        </div>
                    @endif
                </div>
            </section>

            <div class="sub-grid">
                <section class="sub-card">
                    <div class="sub-card-head">
                        <h3 class="sub-card-title">Billing overview</h3>
                        <p class="sub-card-copy">A compact summary of your current access state, renewal timing, and support path.</p>
                    </div>
                    <div class="sub-card-body">
                        <div class="sub-detail-list">
                            <div class="sub-detail-item">
                                <div>
                                    <p class="sub-detail-label">Current Status</p>
                                    <p class="sub-detail-value">{{ $statusLabel }}</p>
                                </div>
                            </div>
                            <div class="sub-detail-item">
                                <div>
                                    <p class="sub-detail-label">Active Window</p>
                                    <p class="sub-detail-value">
                                        {{ $startsAt ? $startsAt->format('d M Y') : '-' }}
                                        <span style="color:var(--sub-text-soft); font-weight:600;">to</span>
                                        {{ $endsAt ? $endsAt->format('d M Y') : '-' }}
                                    </p>
                                </div>
                            </div>
                            <div class="sub-detail-item">
                                <div>
                                    <p class="sub-detail-label">Renewal Mode</p>
                                    <p class="sub-detail-value">{{ $billingCycle }} billing</p>
                                </div>
                            </div>
                            <div class="sub-detail-item">
                                <div>
                                    <p class="sub-detail-label">Support</p>
                                    <p class="sub-detail-value">support@jewelflow.io</p>
                                </div>
                            </div>
                        </div>

                        <div class="sub-note">
                            Need a different plan structure, custom limits, or renewal help? Contact support and reference your current plan name for faster help.
                        </div>
                    </div>
                </section>

                <aside class="sub-card">
                    <div class="sub-card-head">
                        <h3 class="sub-card-title">Included features</h3>
                        <p class="sub-card-copy">The tools and limits enabled under your current subscription.</p>
                    </div>
                    <div class="sub-card-body">
                        <ul class="sub-feature-list">
                            @forelse($planFeatures as $feature => $value)
                                @if($value === false)
                                    @continue
                                @endif

                                <li class="sub-feature-item">
                                    <svg class="sub-dot" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                    <span>
                                        @if(is_bool($value) && $value)
                                            {{ $featureLabels[$feature] ?? ucfirst(str_replace('_', ' ', (string) $feature)) }}
                                        @elseif(!is_bool($value))
                                            @if($feature === 'max_items' && (int) $value === -1)
                                                Unlimited items
                                            @elseif($feature === 'staff_limit')
                                                Up to {{ $value }} staff accounts
                                            @else
                                                Up to {{ $value }} {{ $featureLabels[$feature] ?? \Illuminate\Support\Str::plural(str_replace('_', ' ', (string) $feature)) }}
                                            @endif
                                        @endif
                                    </span>
                                </li>
                            @empty
                                <li class="sub-feature-item" style="color:#64748b;">No features configured for this plan.</li>
                            @endforelse
                        </ul>
                    </div>
                </aside>
            </div>

            {{-- ─── Billing History ───────────────────────────────────────────── --}}
            <section class="sub-billing-section">
                <div class="sub-billing-head">
                    <div>
                        <h2 class="sub-billing-title">Billing History</h2>
                        <p class="sub-billing-copy">All invoices issued for your subscription. Click any row to view or print.</p>
                    </div>
                </div>

                <div class="sub-billing-card">
                    <div class="sub-billing-table-wrap">
                        <table class="sub-billing-table">
                            <thead>
                                <tr>
                                    <th class="text-left">Invoice #</th>
                                    <th class="text-left">Plan</th>
                                    <th class="text-left">Cycle</th>
                                    <th class="text-left">Period</th>
                                    <th class="text-right">Amount</th>
                                    <th class="text-left">Date</th>
                                    <th class="text-left">Status</th>
                                    <th class="text-right"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($invoices as $inv)
                                    <tr>
                                        <td class="sub-billing-num" data-label="Invoice #">{{ $inv->invoice_number }}</td>
                                        <td data-label="Plan">{{ $inv->plan?->name ?? '—' }}</td>
                                        <td class="sub-billing-capitalize" data-label="Cycle">{{ $inv->billing_cycle }}</td>
                                        <td class="sub-billing-muted" data-label="Period">
                                            {{ $inv->billing_period_start->format('d M Y') }}
                                            –
                                            {{ $inv->billing_period_end->format('d M Y') }}
                                        </td>
                                        <td class="text-right sub-billing-amount" data-label="Amount">₹{{ number_format($inv->total_amount, 2) }}</td>
                                        <td class="sub-billing-muted" data-label="Date">{{ $inv->issued_at->format('d M Y') }}</td>
                                        <td data-label="Status">
                                            @if($inv->status === 'issued')
                                                <span class="sub-billing-pill sub-billing-pill-paid">Paid</span>
                                            @else
                                                <span class="sub-billing-pill sub-billing-pill-cancelled">Cancelled</span>
                                            @endif
                                        </td>
                                        <td class="text-right sub-billing-action">
                                            <a href="{{ route('billing.invoices.show', $inv) }}" class="sub-billing-view">View invoice</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="sub-billing-empty">
                                            No invoices yet. Invoices appear here after each subscription payment.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($invoices->hasPages())
                        <div class="sub-billing-pagination">
                            {{ $invoices->links() }}
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
