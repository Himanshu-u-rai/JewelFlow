<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Payment - JewelFlows</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        :root {
            --pay-bg: #f8f3e8;
            --pay-surface: #ffffff;
            --pay-surface-soft: #fff8ec;
            --pay-border: #eadcc8;
            --pay-ink: #221f1a;
            --pay-muted: #756b5d;
            --pay-accent: #b45309;
            --pay-accent-strong: #92400e;
            --pay-accent-soft: #fff0cf;
            --pay-warning-bg: #fff7e6;
            --pay-warning-border: #f4c770;
            --pay-warning-ink: #a16207;
            --pay-danger-bg: #fff1f2;
            --pay-danger-border: #fca5a5;
            --pay-danger-ink: #b91c1c;
            --pay-radius-lg: 10px;
            --pay-radius-md: 8px;
            --pay-shadow: 0 18px 48px rgba(72, 51, 22, 0.09);
        }

        * {
            box-sizing: border-box;
        }

        .sub-pay-body {
            margin: 0;
            background:
                radial-gradient(90% 48% at 50% -12%, rgba(245, 158, 11, 0.18), transparent 62%),
                linear-gradient(180deg, #fbf8f1 0%, var(--pay-bg) 100%);
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
            font-weight: 700;
            letter-spacing: 0.18em;
        }

        .sub-pay-brand-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--pay-ink);
            letter-spacing: 0;
        }

        .sub-pay-brand-title span {
            background: linear-gradient(135deg, #f59e0b 0%, #b45309 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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

        .sub-pay-logout {
            min-height: 38px;
            background: transparent;
            border: 0;
            border-bottom: 1px solid transparent;
            border-radius: 0;
            padding: 8px 2px;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            color: #6f6253;
            cursor: pointer;
            transition: border-color .16s ease, color .16s ease;
        }

        .sub-pay-logout:hover {
            border-color: var(--pay-accent-strong);
            color: var(--pay-accent-strong);
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
            padding: 28px;
        }

        .sub-pay-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--pay-ink);
            margin-bottom: 16px;
        }

        .sub-pay-plan-name {
            font-size: 26px;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 10px;
            color: #0f172a;
        }

        .sub-pay-plan-meta {
            color: var(--pay-muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .sub-pay-cycle-badge {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--pay-accent-soft);
            color: var(--pay-accent-strong);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 16px;
        }

        .sub-pay-amount-panel {
            border: 1px solid #f0d7ab;
            border-radius: var(--pay-radius-lg);
            background: linear-gradient(180deg, #fffcf6 0%, #fff7e7 100%);
            padding: 18px;
            margin-top: 18px;
        }

        .sub-pay-amount-label {
            color: var(--pay-muted);
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .sub-pay-price {
            font-size: clamp(34px, 4vw, 44px);
            font-weight: 700;
            line-height: 1;
            color: var(--pay-ink);
            margin-bottom: 8px;
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

        .sub-pay-details {
            display: grid;
            gap: 0;
            margin: 18px 0 0;
        }

        .sub-pay-detail-row {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 13px 0;
            border-bottom: 1px solid #f0e5d5;
            font-size: 13.5px;
            line-height: 1.4;
        }

        .sub-pay-detail-row:first-child {
            border-top: 1px solid #f0e5d5;
        }

        .sub-pay-detail-row dt {
            margin: 0;
            color: var(--pay-muted);
        }

        .sub-pay-detail-row dd {
            margin: 0;
            color: var(--pay-ink);
            font-weight: 600;
            text-align: right;
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

        .sub-pay-legal {
            border: 1px solid #e8dccb;
            border-radius: var(--pay-radius-md);
            background: #fffdf8;
            padding: 14px;
            margin-top: 18px;
            color: #5f5548;
            font-size: 12.5px;
            line-height: 1.65;
        }

        .sub-pay-legal strong {
            display: block;
            color: var(--pay-ink);
            font-size: 13px;
            margin-bottom: 4px;
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

        .sub-pay-cta {
            width: 100%;
            min-height: 52px;
            border-radius: 10px;
            border: 1px solid #16130f;
            background: #16130f;
            color: #ffffff;
            font-size: 15.5px;
            font-weight: 700;
            letter-spacing: 0;
            cursor: pointer;
            box-shadow: 0 14px 26px -20px rgba(22, 19, 15, 0.9);
            transition: background-color 0.18s ease, border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }

        .sub-pay-cta:hover {
            background: #2b2118;
            border-color: #2b2118;
            box-shadow: 0 16px 28px -18px rgba(22, 19, 15, 0.85);
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
            margin-top: 14px;
        }

        .sub-pay-change-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 7px 2px;
            border: 0;
            border-bottom: 1px solid transparent;
            background: transparent;
            color: #6f6253;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: border-color 0.16s ease, color 0.16s ease;
        }

        .sub-pay-change-btn:hover {
            border-color: var(--pay-accent-strong);
            color: var(--pay-accent-strong);
        }

        .sub-pay-logout:focus-visible,
        .sub-pay-cta:focus-visible,
        .sub-pay-change-btn:focus-visible {
            outline: 3px solid rgba(245, 158, 11, 0.28);
            outline-offset: 3px;
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
                flex-direction: row;
                align-items: center;
                gap: 14px;
            }

            .sub-pay-step {
                font-size: 11px;
                letter-spacing: 0.05em;
                margin-left: auto;
            }

            .sub-pay-brand-title {
                font-size: 16px;
            }

            .sub-pay-card {
                border-radius: 16px;
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

        @media (max-width: 360px) {
            .sub-pay-card {
                padding: 22px 18px;
            }

            .sub-pay-detail-row {
                display: grid;
                gap: 4px;
            }

            .sub-pay-detail-row dd {
                text-align: left;
            }
        }
    </style>
</head>
<body class="sub-pay-body ops-treatment-page">
    <header class="sub-pay-topbar">
        <div class="sub-pay-topbar-inner">
            <div class="sub-pay-brand">
                <div>
                    <div class="sub-pay-brand-title">Jewel<span>Flows</span></div>
                    <div class="sub-pay-brand-subtitle">Secure subscription checkout</div>
                </div>
            </div>
            <div class="sub-pay-step" style="display:flex;align-items:center;gap:18px;">
                {{-- Always allow an escape back to login. --}}
                <form method="POST" action="{{ route('logout') }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="sub-pay-logout">{{ __('Log out') }}</button>
                </form>
            </div>
        </div>
    </header>

    <main class="sub-pay-shell">
        @php
            $cycleLabel = $billingCycle === 'yearly' ? 'Annual' : 'Monthly';
            $renewalDate = $billingCycle === 'yearly' ? now()->addYear()->format('d M Y') : now()->addMonth()->format('d M Y');
            $shopTypeLabel = ucfirst((string) ($shopType ?? 'business'));
        @endphp
        <div class="sub-pay-grid">
            <section class="sub-pay-card sub-pay-card-summary" aria-label="Order summary">
                <div class="sub-pay-title">Subscription invoice</div>
                <div class="sub-pay-plan-name">{{ $plan->name }}</div>
                <div class="sub-pay-plan-meta">{{ $shopTypeLabel }} account access</div>
                <div class="sub-pay-cycle-badge">{{ $cycleLabel }} billing</div>

                <div class="sub-pay-amount-panel">
                    <div class="sub-pay-amount-label">Amount payable</div>
                    <div class="sub-pay-price">₹{{ number_format($price, 0) }}</div>
                    @if($billingCycle === 'yearly')
                        <p class="sub-pay-caption">₹{{ number_format($price, 0) }} billed once per year</p>
                        <p class="sub-pay-caption">Equivalent to ₹{{ number_format($price / 12, 0) }}/month</p>
                    @else
                        <p class="sub-pay-caption">Billed monthly until cancelled</p>
                    @endif
                </div>

                <dl class="sub-pay-details">
                    <div class="sub-pay-detail-row">
                        <dt>Billing cycle</dt>
                        <dd>{{ $cycleLabel }}</dd>
                    </div>
                    <div class="sub-pay-detail-row">
                        <dt>Renews on</dt>
                        <dd>{{ $renewalDate }}</dd>
                    </div>
                    <div class="sub-pay-detail-row">
                        <dt>Plan activation</dt>
                        <dd>After successful payment</dd>
                    </div>
                    <div class="sub-pay-detail-row">
                        <dt>Payment processor</dt>
                        <dd>Razorpay</dd>
                    </div>
                </dl>

                @if(($plan->trial_days ?? 0) > 0)
                    <div class="sub-pay-trial-note">
                        {{ $plan->trial_days }}-day trial included
                    </div>
                @endif

                <div class="sub-pay-legal">
                    <strong>Payment note</strong>
                    Your subscription starts only after Razorpay confirms the payment. JewelFlows does not store card, UPI, or banking credentials.
                </div>
            </section>

            <section class="sub-pay-card sub-pay-card-payment" aria-label="Payment section">
                <div class="sub-pay-title">Pay securely</div>
                <div class="sub-pay-powered">Complete checkout with Razorpay.</div>

                <div class="sub-pay-methods" aria-label="Supported payment methods">
                    @foreach(['UPI', 'Cards', 'Net Banking', 'Wallets'] as $method)
                        <span class="sub-pay-method-chip">{{ $method }}</span>
                    @endforeach
                </div>

                <button id="pay-btn" type="button" class="sub-pay-cta" data-label="Pay ₹{{ number_format($price, 0) }}">
                    Pay ₹{{ number_format($price, 0) }}
                </button>

                <div id="pay-error" class="sub-pay-error"></div>

                <form id="payment-form" action="{{ route('subscription.payment.callback') }}" method="POST" style="display:none;">
                    @csrf
                    <input type="hidden" id="rzp_payment_id" name="razorpay_payment_id">
                    <input type="hidden" id="rzp_order_id" name="razorpay_order_id">
                    <input type="hidden" id="rzp_signature" name="razorpay_signature">
                </form>

                <div class="sub-pay-change">
                    <a href="{{ route('subscription.plans') }}" class="sub-pay-change-btn">Change plan</a>
                </div>
            </section>
        </div>
    </main>

    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <script>
        document.getElementById('pay-btn').addEventListener('click', async function () {
            const btn = this;
            const errorBox = document.getElementById('pay-error');
            const payLabel = btn.dataset.label || 'Pay ₹{{ number_format($price, 0) }}';
            btn.disabled = true;
            btn.textContent = 'Opening Razorpay...';
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
                    name: 'JewelFlows',
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
                            btn.textContent = payLabel;
                        }
                    }
                };

                const rzp = new Razorpay(options);
                rzp.open();
            } catch (err) {
                errorBox.textContent = err.message;
                errorBox.style.display = 'block';
                btn.disabled = false;
                btn.textContent = payLabel;
            }
        });
    </script>
</body>
</html>
