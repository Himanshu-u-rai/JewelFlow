<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Payment - JewelFlow</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --pay-bg: #f4f7fb;
            --pay-surface: #ffffff;
            --pay-surface-soft: #eef3f9;
            --pay-border: #d8e2ee;
            --pay-ink: #1f2a37;
            --pay-muted: #64748b;
            --pay-accent: #0f766e;
            --pay-accent-soft: #d9f3ee;
            --pay-warning-bg: #fff7e6;
            --pay-warning-border: #f4c770;
            --pay-warning-ink: #a16207;
            --pay-danger-bg: #fff1f2;
            --pay-danger-border: #fca5a5;
            --pay-danger-ink: #b91c1c;
            --pay-radius-lg: 20px;
            --pay-radius-md: 14px;
            --pay-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        .sub-pay-body {
            margin: 0;
            background: var(--pay-bg);
            color: var(--pay-ink);
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
        }

        .sub-pay-topbar {
            border-bottom: 1px solid var(--pay-border);
            background: var(--pay-surface);
        }

        .sub-pay-topbar-inner {
            max-width: 1120px;
            margin: 0 auto;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .sub-pay-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sub-pay-logo {
            width: 38px;
            height: 38px;
            border: 1px solid var(--pay-border);
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: #fff9ee;
            color: #b45309;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.18em;
        }

        .sub-pay-brand-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--pay-ink);
            letter-spacing: 0.01em;
        }

        .sub-pay-brand-subtitle {
            font-size: 12px;
            color: var(--pay-muted);
            margin-top: 2px;
        }

        .sub-pay-step {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .sub-pay-shell {
            max-width: 1020px;
            margin: 0 auto;
            padding: 24px;
            min-height: calc(100vh - 76px);
            display: flex;
            align-items: flex-start;
        }

        .sub-pay-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
            gap: 22px;
            align-items: start;
            width: 100%;
        }

        .sub-pay-card {
            background: var(--pay-surface);
            border: 1px solid var(--pay-border);
            border-radius: var(--pay-radius-lg);
            box-shadow: var(--pay-shadow);
            padding: 26px;
        }

        .sub-pay-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--pay-ink);
            margin-bottom: 16px;
        }

        .sub-pay-plan-name {
            font-size: 26px;
            line-height: 1.2;
            font-weight: 800;
            margin-bottom: 10px;
            color: #0f172a;
        }

        .sub-pay-cycle-badge {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--pay-accent-soft);
            color: #0f5e58;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .sub-pay-price {
            font-size: clamp(34px, 4vw, 44px);
            font-weight: 800;
            line-height: 1;
            color: var(--pay-accent);
            margin-bottom: 10px;
        }

        .sub-pay-caption {
            margin: 0 0 4px;
            font-size: 13px;
            color: var(--pay-muted);
            line-height: 1.45;
        }

        .sub-pay-divider {
            height: 1px;
            background: var(--pay-border);
            margin: 16px 0;
        }

        .sub-pay-renew {
            margin: 0 0 14px;
            font-size: 13px;
            color: #475569;
        }

        .sub-pay-trial-note {
            background: var(--pay-warning-bg);
            border: 1px solid var(--pay-warning-border);
            color: var(--pay-warning-ink);
            border-radius: var(--pay-radius-md);
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .sub-pay-feature-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .sub-pay-feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 32px;
            border-radius: 10px;
            padding: 6px 8px;
            font-size: 13px;
            color: #334155;
            background: #f8fbff;
            border: 1px solid #ebf1f8;
        }

        .sub-pay-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--pay-accent);
            flex-shrink: 0;
        }

        .sub-pay-feature-list strong {
            color: #0f172a;
        }

        .sub-pay-trust {
            margin-top: 16px;
            font-size: 12px;
            color: #64748b;
            line-height: 1.7;
        }

        .sub-pay-powered {
            margin-top: -8px;
            margin-bottom: 16px;
            font-size: 13px;
            color: var(--pay-muted);
        }

        .sub-pay-methods {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .sub-pay-method-chip {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 6px 11px;
            border-radius: 999px;
            background: var(--pay-surface-soft);
            border: 1px solid var(--pay-border);
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }

        .sub-pay-test-box {
            background: var(--pay-warning-bg);
            border: 1px solid var(--pay-warning-border);
            color: var(--pay-warning-ink);
            border-radius: var(--pay-radius-md);
            padding: 12px 13px;
            font-size: 13px;
            line-height: 1.62;
            margin-bottom: 16px;
        }

        .sub-pay-cta {
            width: 100%;
            min-height: 54px;
            border-radius: 14px;
            border: 1px solid #0d675f;
            background: #0f766e;
            color: #ffffff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.01em;
            cursor: pointer;
            transition: background-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }

        .sub-pay-cta:hover {
            background: #0d665f;
            box-shadow: 0 8px 18px rgba(15, 118, 110, 0.24);
            transform: translateY(-1px);
        }

        .sub-pay-cta:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .sub-pay-error {
            display: none;
            background: var(--pay-danger-bg);
            border: 1px solid var(--pay-danger-border);
            border-radius: 12px;
            color: var(--pay-danger-ink);
            padding: 12px;
            font-size: 13px;
            margin-top: 14px;
        }

        .sub-pay-change {
            margin-top: 18px;
        }

        .sub-pay-change-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid #c9d6e6;
            background: #f8fbff;
            color: #1e3a5f;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: background-color 0.16s ease, border-color 0.16s ease, color 0.16s ease;
        }

        .sub-pay-change-btn:hover {
            background: #ecf4ff;
            border-color: #b9cce2;
            color: #0f2f54;
        }

        @media (max-width: 1023px) {
            .sub-pay-shell {
                min-height: auto;
                padding: 18px 14px 24px;
            }

            .sub-pay-grid {
                grid-template-columns: 1fr;
            }

            .sub-pay-card-payment {
                order: -1;
            }

            .sub-pay-card {
                padding: 20px 18px;
            }

            .sub-pay-plan-name {
                font-size: 23px;
            }

            .sub-pay-price {
                font-size: 35px;
            }
        }

        @media (max-width: 640px) {
            .sub-pay-topbar-inner {
                padding: 14px 12px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .sub-pay-step {
                font-size: 11px;
                letter-spacing: 0.05em;
            }

            .sub-pay-brand-title {
                font-size: 16px;
            }

            .sub-pay-card {
                border-radius: 16px;
            }

            .sub-pay-change-btn {
                width: 100%;
            }

            .sub-pay-feature-list li {
                font-size: 12px;
            }

            .sub-pay-method-chip {
                font-size: 11px;
                min-height: 30px;
            }

            .sub-pay-cta {
                min-height: 50px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body class="sub-pay-body ops-treatment-page">
    <header class="sub-pay-topbar">
        <div class="sub-pay-topbar-inner">
            <div class="sub-pay-brand">
                <div class="sub-pay-logo">JF</div>
                <div>
                    <div class="sub-pay-brand-title">JewelFlow</div>
                    <div class="sub-pay-brand-subtitle">Subscription onboarding</div>
                </div>
            </div>
            <div class="sub-pay-step">Step 3 of 3 - Payment</div>
        </div>
    </header>

    <main class="sub-pay-shell">
        <div class="sub-pay-grid">
            <section class="sub-pay-card sub-pay-card-summary" aria-label="Order summary">
                <div class="sub-pay-title">Order Summary</div>
                <div class="sub-pay-plan-name">{{ $plan->name }}</div>
                <div class="sub-pay-cycle-badge">{{ $billingCycle === 'yearly' ? 'Annual' : 'Monthly' }}</div>

                <div class="sub-pay-price">₹{{ number_format($price, 0) }}</div>
                @if($billingCycle === 'yearly')
                    <p class="sub-pay-caption">Billed as ₹{{ number_format($price, 0) }}/year</p>
                    <p class="sub-pay-caption">Equivalent to ₹{{ number_format($price / 12, 0) }}/month</p>
                @else
                    <p class="sub-pay-caption">Billed monthly</p>
                @endif

                <div class="sub-pay-divider"></div>

                <p class="sub-pay-renew">
                    Renews: {{ $billingCycle === 'yearly' ? now()->addYear()->format('d M Y') : now()->addMonth()->format('d M Y') }}
                </p>

                @if(($plan->trial_days ?? 0) > 0)
                    <div class="sub-pay-trial-note">
                        {{ $plan->trial_days }}-day trial included
                    </div>
                @endif

                @php
                    $planFeatures = $plan->features ?? [];
                    if (is_string($planFeatures)) {
                        $planFeatures = json_decode($planFeatures, true) ?? [];
                    }
                @endphp
                <ul class="sub-pay-feature-list">
                    @foreach($planFeatures as $key => $value)
                        @if((is_bool($value) && $value) || !is_bool($value))
                            <li>
                                <span class="sub-pay-dot" aria-hidden="true"></span>
                                @if(!is_bool($value))
                                    @if($key === 'max_items' && (int) $value === -1)
                                        <strong>Unlimited</strong>&nbsp;Items
                                    @else
                                        Up to <strong>{{ $value }}</strong>&nbsp;{{ $featureLabels[$key] ?? $key }}
                                    @endif
                                @else
                                    {{ $featureLabels[$key] ?? $key }}
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ul>

                <div class="sub-pay-trust">
                    256-bit SSL | Razorpay secured | PCI DSS Level 1
                </div>
            </section>

            <section class="sub-pay-card sub-pay-card-payment" aria-label="Payment section">
                <div class="sub-pay-title">Complete Payment</div>
                <div class="sub-pay-powered">Powered by Razorpay</div>

                <div class="sub-pay-methods" aria-label="Supported payment methods">
                    @foreach(['UPI', 'Cards', 'Net Banking', 'Wallets'] as $method)
                        <span class="sub-pay-method-chip">{{ $method }}</span>
                    @endforeach
                </div>

                @if($isTestMode)
                    <div class="sub-pay-test-box">
                        <strong>Test Mode Active</strong><br>
                        Use card: 4111 1111 1111 1111<br>
                        CVV: any 3 digits | Expiry: any future date<br>
                        UPI: success@razorpay
                    </div>
                @endif

                <button id="pay-btn" type="button" class="sub-pay-cta">
                    Pay ₹{{ number_format($price, 0) }} →
                </button>

                <div id="pay-error" class="sub-pay-error"></div>

                <form id="payment-form" action="{{ route('subscription.payment.callback') }}" method="POST" style="display:none;">
                    @csrf
                    <input type="hidden" id="rzp_payment_id" name="razorpay_payment_id">
                    <input type="hidden" id="rzp_order_id" name="razorpay_order_id">
                    <input type="hidden" id="rzp_signature" name="razorpay_signature">
                </form>

                <div class="sub-pay-change">
                    <a href="{{ route('subscription.plans') }}" class="sub-pay-change-btn">← Change plan</a>
                </div>
            </section>
        </div>
    </main>

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
        document.getElementById('pay-btn').addEventListener('click', async function () {
            const btn = this;
            const errorBox = document.getElementById('pay-error');
            btn.disabled = true;
            btn.textContent = 'Initiating secure payment...';
            errorBox.style.display = 'none';

            try {
                const res = await fetch('{{ route('subscription.payment.initiate') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({}),
                });

                const data = await res.json();

                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Payment initiation failed.');
                }

                const options = {
                    key: data.key_id,
                    amount: data.amount,
                    currency: data.currency,
                    order_id: data.order_id,
                    name: 'JewelFlow',
                    description: data.plan_name,
                    prefill: {
                        name: data.user_name,
                        email: data.user_email,
                        contact: data.user_contact,
                    },
                    theme: { color: '#0f766e' },
                    handler: function (response) {
                        document.getElementById('rzp_payment_id').value = response.razorpay_payment_id;
                        document.getElementById('rzp_order_id').value = response.razorpay_order_id;
                        document.getElementById('rzp_signature').value = response.razorpay_signature;
                        document.getElementById('payment-form').submit();
                    },
                    modal: {
                        ondismiss: function () {
                            btn.disabled = false;
                            btn.textContent = 'Pay ₹{{ number_format($price, 0) }} →';
                        }
                    }
                };

                const rzp = new Razorpay(options);
                rzp.open();
            } catch (err) {
                errorBox.textContent = err.message;
                errorBox.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Pay ₹{{ number_format($price, 0) }} →';
            }
        });
    </script>
</body>
</html>
