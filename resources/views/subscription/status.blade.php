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
            'whatsapp_catalog' => 'WhatsApp Catalog',
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
        .sub-status-page {
            --sub-border: #d9e2ef;
            --sub-border-strong: #c8d5e7;
            --sub-surface: #ffffff;
            --sub-surface-soft: #f7f9fc;
            --sub-text: #16213d;
            --sub-text-soft: #64748b;
            --sub-accent: #0d9488;
            --sub-accent-soft: rgba(13, 148, 136, 0.1);
            --sub-shadow: 0 18px 42px rgba(15, 23, 42, 0.06);
        }

        .sub-status-page .sub-status-wrap {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .sub-status-page .sub-alert {
            border: 1px solid transparent;
            padding: 12px 14px;
            border-radius: 18px;
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
            border-radius: 24px;
            background: var(--sub-surface);
            box-shadow: var(--sub-shadow);
        }

        .sub-status-page .sub-hero {
            padding: 20px;
        }

        .sub-status-page .sub-hero-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .sub-status-page .sub-kicker {
            margin: 0 0 6px;
            color: var(--sub-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .sub-status-page .sub-plan-name {
            margin: 0;
            color: var(--sub-text);
            font-size: 30px;
            font-weight: 800;
            line-height: 1.1;
        }

        .sub-status-page .sub-plan-copy {
            margin: 8px 0 0;
            color: var(--sub-text-soft);
            font-size: 14px;
            line-height: 1.55;
        }

        .sub-status-page .sub-status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .sub-status-page .sub-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .sub-status-page .sub-kpi {
            border: 1px solid #e5edf6;
            background: #fbfcfe;
            padding: 14px;
            border-radius: 18px;
        }

        .sub-status-page .sub-kpi-label {
            margin: 0 0 6px;
            color: var(--sub-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .sub-status-page .sub-kpi-value {
            margin: 0;
            color: var(--sub-text);
            font-size: 20px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sub-status-page .sub-health {
            border: 1px solid #e5edf6;
            border-radius: 20px;
            background: linear-gradient(180deg, #fbfcfe 0%, #ffffff 100%);
            padding: 16px;
        }

        .sub-status-page .sub-health-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .sub-status-page .sub-health-title {
            margin: 0;
            color: var(--sub-text);
            font-size: 16px;
            font-weight: 700;
            line-height: 1.3;
        }

        .sub-status-page .sub-health-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 999px;
            border: 1px solid #d6e5e2;
            background: var(--sub-accent-soft);
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sub-status-page .sub-health-copy {
            margin: 0;
            color: var(--sub-text-soft);
            font-size: 14px;
            line-height: 1.6;
        }

        .sub-status-page .sub-progress-track {
            width: 100%;
            height: 10px;
            margin-top: 10px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .sub-status-page .sub-progress-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, #0d9488 0%, #14b8a6 100%);
        }

        .sub-status-page .sub-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
            gap: 16px;
        }

        .sub-status-page .sub-card-head {
            padding: 18px 20px 0;
        }

        .sub-status-page .sub-card-title {
            margin: 0;
            color: var(--sub-text);
            font-size: 20px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sub-status-page .sub-card-copy {
            margin: 6px 0 0;
            color: var(--sub-text-soft);
            font-size: 13px;
            line-height: 1.55;
        }

        .sub-status-page .sub-card-body {
            padding: 18px 20px 20px;
        }

        .sub-status-page .sub-detail-list {
            display: grid;
            gap: 12px;
        }

        .sub-status-page .sub-detail-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 14px;
            border: 1px solid #e5edf6;
            border-radius: 16px;
            background: #fbfcfe;
        }

        .sub-status-page .sub-detail-label {
            margin: 0;
            color: var(--sub-text-soft);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .sub-status-page .sub-detail-value {
            margin: 4px 0 0;
            color: var(--sub-text);
            font-size: 14px;
            font-weight: 700;
            line-height: 1.45;
            text-align: right;
        }

        .sub-status-page .sub-note {
            margin-top: 14px;
            padding: 13px 14px;
            border: 1px solid #e5edf6;
            border-radius: 16px;
            background: #fbfcfe;
            color: var(--sub-text-soft);
            font-size: 13px;
            line-height: 1.6;
        }

        .sub-status-page .sub-feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .sub-status-page .sub-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            min-width: 0;
            padding: 12px 13px;
            border: 1px solid #e5edf6;
            border-radius: 16px;
            background: #fbfcfe;
            color: #334155;
            font-size: 13px;
            line-height: 1.55;
        }

        .sub-status-page .sub-dot {
            width: 16px;
            height: 16px;
            margin-top: 1px;
            color: var(--sub-accent);
            flex-shrink: 0;
        }

        .sub-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            padding: 0 14px;
            border: 1px solid transparent;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.16s ease, background-color 0.16s ease, border-color 0.16s ease;
        }

        .sub-btn:hover {
            transform: translateY(-1px);
        }

        .sub-btn.primary {
            background: var(--sub-accent, #0d9488);
            color: #fff;
            box-shadow: 0 10px 24px rgba(13, 148, 136, 0.2);
        }

        .sub-btn.primary:hover {
            background: #0f766e;
        }

        .sub-btn.secondary {
            background: #fff;
            color: var(--sub-text, #16213d);
            border-color: var(--sub-border-strong, #c8d5e7);
        }

        .sub-btn.secondary:hover {
            background: var(--sub-surface-soft, #f7f9fc);
        }

        @media (max-width: 1100px) {
            .sub-status-page .sub-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sub-status-page .sub-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .sub-status-page .sub-hero,
            .sub-status-page .sub-card,
            .sub-status-page .sub-alert {
                border-radius: 20px;
            }

            .sub-status-page .sub-hero {
                padding: 16px;
            }

            .sub-status-page .sub-hero-top {
                flex-direction: column;
                align-items: flex-start;
                margin-bottom: 14px;
            }

            .sub-status-page .sub-plan-name {
                font-size: 24px;
            }

            .sub-status-page .sub-plan-copy {
                font-size: 13px;
            }

            .sub-status-page .sub-kpi-grid {
                gap: 10px;
                margin-bottom: 14px;
            }

            .sub-status-page .sub-kpi {
                padding: 12px;
                border-radius: 16px;
            }

            .sub-status-page .sub-kpi-value {
                font-size: 17px;
            }

            .sub-status-page .sub-health {
                padding: 14px;
                border-radius: 18px;
            }

            .sub-status-page .sub-health-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .sub-status-page .sub-card-head {
                padding: 16px 16px 0;
            }

            .sub-status-page .sub-card-title {
                font-size: 18px;
            }

            .sub-status-page .sub-card-body {
                padding: 16px;
            }

            .sub-status-page .sub-detail-item {
                flex-direction: column;
            }

            .sub-status-page .sub-detail-value {
                text-align: left;
            }

            .sub-status-page .sub-feature-list {
                grid-template-columns: 1fr;
                gap: 8px;
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
            @if(session('success'))
                <div class="sub-alert success">{{ session('success') }}</div>
            @endif

            @if(session('error'))
                <div class="sub-alert error">{{ session('error') }}</div>
            @endif

            <section class="sub-hero">
                <div class="sub-hero-top">
                    <div>
                        <p class="sub-kicker">Current Plan</p>
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
        </div>
    </div>
</x-app-layout>
