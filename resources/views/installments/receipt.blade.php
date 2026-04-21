<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMI Payment Receipt</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #dbe2ec;
            --line-soft: #e7edf5;
            --surface: #ffffff;
            --bg: #f1f5fb;
            --brand: #0f3a78;
            --brand-soft: #e7f0ff;
            --accent: #0f766e;
            --accent-soft: #ecfdf5;
        }

        body {
            font-family: "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 26px 16px;
            color: var(--ink);
        }

        .receipt-page {
            max-width: 760px;
            margin: 0 auto;
        }

        .receipt {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.07);
        }

        .receipt-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            padding: 18px 20px 16px;
            border-bottom: 1px solid var(--line-soft);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 10px;
            border: 1px solid #cfe0ff;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-mark svg {
            width: 14px;
            height: 14px;
        }

        .shop-name {
            margin: 10px 0 0;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .shop-meta {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--muted);
        }

        .receipt-title {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -0.02em;
            text-align: right;
        }

        .receipt-sub {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
            text-align: right;
        }

        .receipt-pill {
            margin-top: 9px;
            display: inline-flex;
            float: right;
            align-items: center;
            gap: 6px;
            border: 1px solid #cfe0ff;
            background: #eff5ff;
            color: #133f7d;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .receipt-body {
            padding: 18px 20px 20px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 14px;
        }

        .detail-card {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            padding: 12px 13px;
            background: #fff;
        }

        .detail-label {
            margin: 0 0 8px;
            font-size: 11px;
            font-weight: 700;
            color: #325387;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .detail-name {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .detail-text {
            margin: 4px 0 0;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.45;
        }

        .meta-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
            margin: 0 0 14px;
        }

        .meta-box {
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            background: #fff;
            padding: 9px 10px 10px;
            min-width: 0;
        }

        .meta-box-label {
            margin: 0;
            font-size: 10px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .meta-box-value {
            margin: 4px 0 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ledger {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }

        .ledger-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            padding: 10px 14px;
            border-top: 1px solid var(--line-soft);
            font-size: 13px;
        }

        .ledger-row:first-child {
            border-top: none;
        }

        .ledger-row strong {
            font-weight: 700;
            color: var(--ink);
        }

        .ledger-total {
            background: linear-gradient(180deg, #f8fbff 0%, #f2f8ff 100%);
        }

        .ledger-total span {
            font-size: 12px;
            font-weight: 700;
            color: #1d4f96;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .ledger-total strong {
            font-size: 23px;
            color: var(--accent);
            letter-spacing: -0.02em;
        }

        .notes {
            margin-top: 12px;
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
            font-size: 12px;
            line-height: 1.45;
        }

        .notes-label {
            font-size: 10px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .notes-text {
            margin-top: 4px;
            color: #334155;
        }

        .actions {
            max-width: 760px;
            margin: 12px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .btn {
            border: 1px solid #c8d1dd;
            background: #fff;
            color: var(--ink);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
            transition: background 140ms ease, border-color 140ms ease;
        }

        .btn:hover {
            background: #f8fafc;
            border-color: #9aa8bb;
        }

        @media (max-width: 760px) {
            body {
                padding: 12px;
            }

            .receipt-head {
                padding: 14px;
                flex-direction: column;
                gap: 12px;
            }

            .receipt-title,
            .receipt-sub {
                text-align: left;
            }

            .receipt-pill {
                float: none;
            }

            .receipt-body {
                padding: 14px;
            }

            .detail-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .meta-strip {
                grid-template-columns: 1fr 1fr;
            }

            .ledger-total strong {
                font-size: 21px;
            }
        }

        @media print {
            body {
                background: #fff;
                padding: 0 !important;
            }

            .receipt-page {
                max-width: none;
            }

            .receipt {
                border: none;
                border-radius: 0;
                padding: 0;
                box-shadow: none;
            }

            .receipt-head,
            .detail-card,
            .meta-box,
            .ledger,
            .notes {
                break-inside: avoid;
            }

            .actions {
                display: none;
            }
        }
    </style>
</head>
<body>
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: config('app.name', 'Jewelflow');
        $shopContact = $shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile ?? null;
        $shopEmail = auth()->user()?->email;
        $shopAddress = $shop?->address ?? null;
        $customer = $plan->customer;
        $paymentDate = $payment->payment_date
            ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d M Y')
            : '—';
        $paymentTime = $payment->created_at
            ? $payment->created_at->format('h:i A')
            : now()->format('h:i A');
    @endphp

    <div class="receipt-page">
        <div class="receipt">
            <header class="receipt-head">
                <div>
                    <div class="brand-mark">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2 4 9l8 13 8-13-8-7Zm0 3.01L16.2 8H7.8L12 5.01ZM8.92 10h6.16L12 15.04 8.92 10Z"/>
                        </svg>
                        EMI RECEIPT
                    </div>
                    <p class="shop-name">{{ $shopName }}</p>
                    @if($shopAddress)
                        <p class="shop-meta">{{ $shopAddress }}</p>
                    @endif
                    <p class="shop-meta">
                        @if($shopContact) {{ $shopContact }} @endif
                        @if($shopContact && $shopEmail) · @endif
                        @if($shopEmail) {{ $shopEmail }} @endif
                    </p>
                </div>
                <div>
                    <h1 class="receipt-title">EMI Payment Receipt</h1>
                    <div class="receipt-sub">Generated on {{ now()->format('d M Y') }}, {{ $paymentTime }}</div>
                    <span class="receipt-pill">Plan #{{ $plan->id }}</span>
                </div>
            </header>

            <div class="receipt-body">
                <section class="detail-grid">
                    <article class="detail-card">
                        <p class="detail-label">Receipt From</p>
                        <p class="detail-name">{{ $shopName }}</p>
                        @if($shopAddress)
                            <p class="detail-text">{{ $shopAddress }}</p>
                        @endif
                    </article>
                    <article class="detail-card">
                        <p class="detail-label">Receipt To</p>
                        <p class="detail-name">{{ $customer?->name ?? 'Walk-in Customer' }}</p>
                        <p class="detail-text">
                            {{ $customer?->mobile ?? 'No mobile available' }}
                            @if($customer?->address)
                                <br>{{ $customer->address }}
                            @endif
                        </p>
                    </article>
                </section>

                <section class="meta-strip">
                    <div class="meta-box">
                        <p class="meta-box-label">Receipt No</p>
                        <p class="meta-box-value">EMI-{{ str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT) }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Invoice</p>
                        <p class="meta-box-value">{{ $plan->invoice?->invoice_number ?? ('#' . $plan->invoice_id) }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Payment Date</p>
                        <p class="meta-box-value">{{ $paymentDate }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Method</p>
                        <p class="meta-box-value">{{ ucfirst(str_replace('_', ' ', $payment->payment_method)) }}</p>
                    </div>
                </section>

                <section class="ledger">
                    <div class="ledger-row"><span>Total Invoice</span><strong>₹{{ number_format((float) $plan->total_amount, 2) }}</strong></div>
                    <div class="ledger-row"><span>Principal</span><strong>₹{{ number_format((float) ($plan->principal_amount ?? 0), 2) }}</strong></div>
                    <div class="ledger-row"><span>Interest</span><strong>{{ number_format((float) ($plan->interest_rate_annual ?? 0), 2) }}% · ₹{{ number_format((float) ($plan->interest_amount ?? 0), 2) }}</strong></div>
                    <div class="ledger-row"><span>Total Payable</span><strong>₹{{ number_format((float) ($plan->total_payable ?? 0), 2) }}</strong></div>
                    <div class="ledger-row"><span>EMI Amount</span><strong>₹{{ number_format((float) $plan->emi_amount, 2) }}</strong></div>
                    <div class="ledger-row ledger-total"><span>Paid This Receipt</span><strong>₹{{ number_format((float) $payment->amount, 2) }}</strong></div>
                </section>

                @if($payment->notes)
                    <section class="notes">
                        <div class="notes-label">Notes</div>
                        <div class="notes-text">{{ $payment->notes }}</div>
                    </section>
                @endif
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn" onclick="window.print()">Print</button>
            <a class="btn" href="{{ route('installments.show', $plan) }}">Back to Plan</a>
        </div>
    </div>
</body>
</html>
