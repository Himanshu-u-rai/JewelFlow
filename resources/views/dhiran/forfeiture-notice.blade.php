<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forfeiture Notice - {{ $loan->loan_number }}</title>
    @vite(['resources/css/dhiran-documents.css'])
</head>
<body class="dh-doc-body dh-doc-forfeiture-notice">
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: 'Dhiran';
        $shopAddress = $shop?->address ?? null;
        $shopContact = $shop?->mobile ?? $shop?->phone ?? auth()->user()?->mobile ?? null;
        $shopEmail = auth()->user()?->email;
        $shopGstin = $shop?->gst_number ?? null;
        $customer = $loan->customer;
        $settings = \App\Models\Dhiran\DhiranSettings::getForShop($shop->id);
        $loanDate = $loan->loan_date ? $loan->loan_date->format('d M Y') : '-';
        $maturityDate = $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '-';
        $graceEnd = ($loan->maturity_date && $loan->grace_period_days)
            ? $loan->maturity_date->copy()->addDays($loan->grace_period_days)->format('d M Y')
            : $maturityDate;
        $totalOutstanding = $loan->totalOutstanding();
        $forfeitureNoticeDays = $settings->forfeiture_notice_days ?? 30;
    @endphp

    <div class="receipt-page">
        <div class="receipt">
            <header class="receipt-head">
                <div>
                    <div class="brand-mark">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
                        </svg>
                        FORFEITURE NOTICE
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
                    @if($shopGstin)
                        <p class="shop-meta">GSTIN: {{ $shopGstin }}</p>
                    @endif
                </div>
                <div>
                    <h1 class="receipt-title">Forfeiture Notice</h1>
                    <div class="receipt-sub">Date: {{ now()->format('d M Y') }}</div>
                    <span class="receipt-pill">{{ $loan->loan_number }}</span>
                </div>
            </header>

            <div class="receipt-body">
                <section class="detail-grid">
                    <article class="detail-card">
                        <p class="detail-label">From</p>
                        <p class="detail-name">{{ $shopName }}</p>
                        @if($shopAddress)
                            <p class="detail-text">{{ $shopAddress }}</p>
                        @endif
                    </article>
                    <article class="detail-card">
                        <p class="detail-label">To</p>
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
                        <p class="meta-box-label">Loan No.</p>
                        <p class="meta-box-value">{{ $loan->loan_number }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Loan Date</p>
                        <p class="meta-box-value">{{ $loanDate }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Maturity Date</p>
                        <p class="meta-box-value">{{ $maturityDate }}</p>
                    </div>
                    <div class="meta-box">
                        <p class="meta-box-label">Days Overdue</p>
                        <p class="meta-box-value">{{ $loan->daysOverdue() }}</p>
                    </div>
                </section>

                <section class="ledger">
                    <div class="ledger-row"><span>Outstanding Principal</span><strong>{{ number_format((float) $loan->outstanding_principal, 2) }}</strong></div>
                    <div class="ledger-row"><span>Outstanding Interest</span><strong>{{ number_format((float) $loan->outstanding_interest, 2) }}</strong></div>
                    <div class="ledger-row"><span>Outstanding Penalty</span><strong>{{ number_format((float) $loan->outstanding_penalty, 2) }}</strong></div>
                    <div class="ledger-row ledger-total"><span>Total Outstanding</span><strong>{{ number_format($totalOutstanding, 2) }}</strong></div>
                </section>

                <section class="notice-body">
                    <p>Dear <strong>{{ $customer?->name ?? 'Sir/Madam' }}</strong>,</p>

                    @if($loan->forfeiture_notice_text)
                        {!! nl2br(e($loan->forfeiture_notice_text)) !!}
                    @else
                        <p>
                            This notice is issued in connection with Pledge Loan <strong>{{ $loan->loan_number }}</strong>
                            dated <strong>{{ $loanDate }}</strong> for a principal amount of
                            <strong>{{ number_format((float) $loan->principal_amount, 2) }}</strong>.
                        </p>
                        <p>
                            The above loan has matured on <strong>{{ $maturityDate }}</strong> and the grace period
                            ended on <strong>{{ $graceEnd }}</strong>. Despite reminders, the outstanding amount of
                            <strong>{{ number_format($totalOutstanding, 2) }}</strong> remains unpaid.
                        </p>
                        <p>
                            You are hereby requested to settle the total outstanding amount within
                            <strong>{{ $forfeitureNoticeDays }} days</strong> from the date of this notice,
                            failing which we shall be constrained to forfeit and dispose of the pledged ornaments
                            as per the terms of the loan agreement.
                        </p>
                    @endif
                </section>

                <section class="warning-box">
                    WARNING: If the outstanding amount is not settled within {{ $forfeitureNoticeDays }} days from the date
                    of this notice (i.e. by {{ now()->addDays($forfeitureNoticeDays)->format('d M Y') }}), the pledged
                    gold ornaments will be forfeited and disposed of to recover the dues without further notice.
                </section>

                <div class="signature-row">
                    <div class="signature-block">
                        <div class="signature-line">Date</div>
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
