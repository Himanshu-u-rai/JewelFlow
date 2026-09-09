<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt - {{ $loan->loan_number }}</title>
    @vite(['resources/css/dhiran-documents.css'])
</head>
<body class="dh-doc-body dh-doc-payment-receipt">
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: 'Dhiran';
        $shopAddress = $shop?->address ?? null;
        $shopContact = $shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile ?? null;
        $shopEmail = auth()->user()?->email;
        $customer = $loan->customer;
        $paymentDate = $payment->payment_date
            ? \Illuminate\Support\Carbon::parse($payment->payment_date)->format('d M Y')
            : '-';
        $paymentTime = $payment->created_at
            ? $payment->created_at->format('h:i A')
            : now()->format('h:i A');
        $receiptNo = 'DPR-' . str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT);
    @endphp

    <div class="receipt-page">
        <div class="receipt">
            <header class="receipt-head">
                <div>
                    <div class="brand-mark">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2 4 9l8 13 8-13-8-7Zm0 3.01L16.2 8H7.8L12 5.01ZM8.92 10h6.16L12 15.04 8.92 10Z"/>
                        </svg>
                        LOAN PAYMENT
                    </div>
                    <p class="shop-name">{{ $shopName }}</p>
                    @if($shopAddress)
                        <p class="shop-meta">{{ $shopAddress }}</p>
                    @endif
                    <p class="shop-meta">
                        @if($shopContact) {{ $shopContact }} @endif
                        @if($shopContact && $shopEmail) &middot; @endif
                        @if($shopEmail) {{ $shopEmail }} @endif
                    </p>
                </div>
                <div>
                    <h1 class="receipt-title">Payment Receipt</h1>
                    <div class="receipt-sub">{{ $paymentDate }}, {{ $paymentTime }}</div>
                    <span class="receipt-pill">{{ $receiptNo }}</span>
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
                        <p class="detail-label">Received From</p>
                        <p class="detail-name">{{ $customer?->name ?? 'Walk-in Customer' }}</p>
                        <p class="detail-text">
                            {{ \App\Support\Mobile::forDisplay($customer?->mobile) ?: 'No mobile available' }}
                            @if($customer?->address)
                                <br>{{ $customer->address }}
                            @endif
                        </p>
                    </article>
                </section>

                <section class="meta-strip">
                    <div class="meta-box">
                        <p class="meta-box-label">Receipt No</p>
                        <p class="meta-box-value">{{ $receiptNo }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Loan No.</p>
                        <p class="meta-box-value">{{ $loan->loan_number }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Payment Date</p>
                        <p class="meta-box-value">{{ $paymentDate }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Method</p>
                        <p class="meta-box-value">{{ ucfirst(str_replace('_', ' ', $payment->payment_method ?? 'cash')) }}</p>
                    </div>
                </section>

                <section class="ledger">
                    <div class="ledger-row"><span>Principal Component</span><strong>{{ number_format((float) $payment->principal_component, 2) }}</strong></div>
                    <div class="ledger-row"><span>Interest Component</span><strong>{{ number_format((float) $payment->interest_component, 2) }}</strong></div>
                    <div class="ledger-row"><span>Penalty Component</span><strong>{{ number_format((float) $payment->penalty_component, 2) }}</strong></div>
                    @if((float) ($payment->processing_fee_component ?? 0) > 0)
                        <div class="ledger-row"><span>Processing Fee</span><strong>{{ number_format((float) $payment->processing_fee_component, 2) }}</strong></div>
                    @endif
                    <div class="ledger-row ledger-total"><span>Amount Paid</span><strong>{{ number_format((float) $payment->amount, 2) }}</strong></div>
                </section>

                <section class="balance-section">
                    <div class="balance-row"><span>Outstanding Principal After</span><strong>{{ number_format((float) $payment->outstanding_principal_after, 2) }}</strong></div>
                    <div class="balance-row"><span>Outstanding Interest After</span><strong>{{ number_format((float) $payment->outstanding_interest_after, 2) }}</strong></div>
                    <div class="balance-row"><span>Outstanding Penalty After</span><strong>{{ number_format((float) $payment->outstanding_penalty_after, 2) }}</strong></div>
                    <div class="balance-row dh-doc-balance-total">
                        <span class="dh-doc-strong">Total Balance After Payment</span>
                        <strong>{{ number_format(
                            (float) $payment->outstanding_principal_after
                            + (float) $payment->outstanding_interest_after
                            + (float) $payment->outstanding_penalty_after, 2
                        ) }}</strong>
                    </div>
                </section>

                <div class="signature-row">
                    <div class="signature-block">
                        <div class="signature-line">Customer Signature</div>
                    </div>
                    <div class="signature-block">
                        <div class="signature-line">For {{ $shopName }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="button" class="btn" onclick="window.print()">Print</button>
            <a class="btn" href="{{ route('dhiran.show', $loan) }}">Back to Loan</a>
        </div>
    </div>
</body>
</html>
