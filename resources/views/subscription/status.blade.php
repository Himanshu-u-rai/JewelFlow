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
        .sub-wrap {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .sub-alert {
            border: 1px solid transparent;
            padding: 12px 14px;
            font-size: 13px;
            border-radius: 16px;
        }

        .sub-alert.success {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }

        .sub-alert.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .sub-grid {
            display: grid;
            grid-template-columns: 1.25fr .95fr;
            gap: 16px;
        }

        .sub-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            border-radius: 16px;
        }

        .sub-card-head {
            padding: 16px 18px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .sub-card-body {
            padding: 18px;
        }

        .sub-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .sub-kpi {
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            padding: 12px;
            border-radius: 12px;
        }

        .sub-kpi-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
            margin: 0 0 4px;
            font-weight: 700;
        }

        .sub-kpi-value {
            margin: 0;
            color: #0f172a;
            font-weight: 700;
            font-size: 18px;
            line-height: 1.2;
        }

        .sub-progress-track {
            width: 100%;
            height: 10px;
            background: #e2e8f0;
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 8px;
        }

        .sub-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0d9488 0%, #14b8a6 100%);
            border-radius: 9999px;
        }

        .sub-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .sub-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border-radius: 10px;
            transition: all .15s ease;
        }

        .sub-btn.primary {
            background: #0d9488;
            color: #fff;
            box-shadow: 0 8px 18px rgba(13, 148, 136, .25);
        }

        .sub-btn.primary:hover {
            background: #0f766e;
        }

        .sub-btn.secondary {
            background: #fff;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        .sub-btn.secondary:hover {
            background: #f8fafc;
        }

        .sub-feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
            max-height: 420px;
            overflow: auto;
        }

        .sub-feature-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 13px;
            color: #334155;
        }

        .sub-dot {
            width: 16px;
            height: 16px;
            margin-top: 1px;
            flex-shrink: 0;
            color: #0d9488;
        }

        .sub-note {
            margin-top: 12px;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 12px;
            color: #475569;
            border-radius: 8px;
        }

        @media (max-width: 980px) {
            .sub-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .sub-kpi-grid {
                grid-template-columns: 1fr;
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

    <div class="content-inner ops-treatment-page">
        <div class="sub-wrap">
            @if(session('success'))
                <div class="sub-alert success">{{ session('success') }}</div>
            @endif

            @if(session('error'))
                <div class="sub-alert error">{{ session('error') }}</div>
            @endif

            <div class="sub-grid">
                <section class="sub-card">
                    <div class="sub-card-head">
                        <div>
                            <h2 style="margin:0; font-size:24px; line-height:1.2; font-weight:800; color:#0f172a;">{{ $plan->name }}</h2>
                            <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Billed {{ $billingCycle }}</p>
                        </div>
                        <span style="display:inline-flex; align-items:center; padding:6px 10px; border:1px solid {{ $statusTone['border'] }}; background: {{ $statusTone['bg'] }}; color: {{ $statusTone['text'] }}; font-size:12px; font-weight:700; border-radius:9999px;">
                            {{ $statusLabel }}
                        </span>
                    </div>

                    <div class="sub-card-body">
                        <div class="sub-kpi-grid">
                            <div class="sub-kpi ops-kpi-card">
                                <p class="sub-kpi-label">Plan Amount</p>
                                <p class="sub-kpi-value">
                                    @if($pricePaid !== null)
                                        ₹{{ number_format((float) $pricePaid, 2) }}
                                    @else
                                        -
                                    @endif
                                </p>
                            </div>
                            <div class="sub-kpi ops-kpi-card">
                                <p class="sub-kpi-label">Billing Cycle</p>
                                <p class="sub-kpi-value">{{ $billingCycle }}</p>
                            </div>
                            <div class="sub-kpi ops-kpi-card">
                                <p class="sub-kpi-label">Start Date</p>
                                <p class="sub-kpi-value">{{ $startsAt ? $startsAt->format('d M Y') : '-' }}</p>
                            </div>
                            <div class="sub-kpi ops-kpi-card">
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

                        <div style="border:1px solid #e2e8f0; background:#ffffff; padding:12px; border-radius:16px;">
                            <p style="margin:0; font-size:13px; color:#334155; font-weight:700;">Plan Health</p>

                            @if($daysRemaining !== null && $daysRemaining >= 0)
                                <p style="margin:6px 0 0; font-size:13px; color:#64748b;">About <strong style="color:#0f172a;">{{ $daysRemaining }}</strong> days remaining before renewal.</p>
                                <div class="sub-progress-track">
                                    <div class="sub-progress-fill" style="width: {{ $usedPercent }}%;"></div>
                                </div>
                            @elseif($isInGrace)
                                <p style="margin:6px 0 0; font-size:13px; color:#92400e;">Subscription expired. Grace period active until {{ $graceEnd ? $graceEnd->format('d M, Y') : '-' }}.</p>
                                <div class="sub-progress-track">
                                    <div class="sub-progress-fill" style="width: 100%; background: #f59e0b;"></div>
                                </div>
                            @else
                                <p style="margin:6px 0 0; font-size:13px; color:#991b1b;">Subscription is expired. Renew plan to restore uninterrupted access.</p>
                                <div class="sub-progress-track">
                                    <div class="sub-progress-fill" style="width: 100%; background: #dc2626;"></div>
                                </div>
                            @endif
                        </div>

                        <div class="sub-actions">
                            <a href="{{ route('subscription.plans') }}" class="sub-btn primary">Upgrade / Renew</a>
                            <a href="mailto:support@jewelflow.io" class="sub-btn secondary">Need Help?</a>
                        </div>
                    </div>
                </section>

                <aside class="sub-card">
                    <div class="sub-card-head">
                        <div>
                            <h3 style="margin:0; font-size:18px; font-weight:800; color:#0f172a;">Included Features</h3>
                            <p style="margin:4px 0 0; font-size:12px; color:#64748b;">Enabled tools in your current plan</p>
                        </div>
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

                        <div class="sub-note">
                            Need a custom plan? Contact support for enterprise features, custom limits, and billing terms.
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
