<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forfeiture Notice - {{ $loan->loan_number }}</title>
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
            --danger: #991b1b;
            --danger-soft: #fef2f2;
            --danger-border: #fecaca;
        }

        body {
            font-family: "Inter", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 26px 16px;
            color: var(--ink);
        }

        .receipt-page { max-width: 760px; margin: 0 auto; }

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
            border: 1px solid var(--danger-border);
            background: var(--danger-soft);
            color: var(--danger);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-mark svg { width: 14px; height: 14px; }

        .shop-name { margin: 10px 0 0; font-size: 18px; font-weight: 800; letter-spacing: -0.01em; }
        .shop-meta { margin: 3px 0 0; font-size: 12px; color: var(--muted); }

        .receipt-title { margin: 0; font-size: 21px; font-weight: 800; letter-spacing: -0.02em; text-align: right; color: var(--danger); }
        .receipt-sub { margin-top: 6px; font-size: 12px; color: var(--muted); text-align: right; }

        .receipt-pill {
            margin-top: 9px;
            display: inline-flex;
            float: right;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--danger-border);
            background: var(--danger-soft);
            color: var(--danger);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        .receipt-body { padding: 18px 20px 20px; }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }

        .detail-card {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            padding: 12px 13px;
            background: #fff;
        }

        .detail-label { margin: 0 0 8px; font-size: 11px; font-weight: 700; color: #325387; text-transform: uppercase; letter-spacing: 0.08em; }
        .detail-name { margin: 0; font-size: 16px; font-weight: 700; }
        .detail-text { margin: 4px 0 0; font-size: 12px; color: var(--muted); line-height: 1.45; }

        .meta-strip { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin: 0 0 14px; }

        .meta-box {
            border: 1px solid var(--line-soft);
            border-radius: 10px;
            background: #fff;
            padding: 9px 10px 10px;
            min-width: 0;
        }

        .meta-box-label { margin: 0; font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; font-weight: 700; }
        .meta-box-value { margin: 4px 0 0; font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .ledger {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            margin-bottom: 14px;
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

        .ledger-row:first-child { border-top: none; }
        .ledger-row strong { font-weight: 700; color: var(--ink); }

        .ledger-total { background: linear-gradient(180deg, var(--danger-soft) 0%, #fee2e2 100%); }
        .ledger-total span { font-size: 12px; font-weight: 700; color: var(--danger); letter-spacing: 0.04em; text-transform: uppercase; }
        .ledger-total strong { font-size: 23px; color: var(--danger); letter-spacing: -0.02em; }

        .notice-body {
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            background: #fff;
            padding: 16px;
            margin-bottom: 14px;
            font-size: 14px;
            line-height: 1.75;
        }

        .notice-body p { margin: 0 0 12px; }
        .notice-body p:last-child { margin-bottom: 0; }

        .warning-box {
            border: 2px solid var(--danger-border);
            border-radius: 12px;
            background: var(--danger-soft);
            padding: 16px;
            margin-bottom: 14px;
            font-size: 13px;
            line-height: 1.6;
            color: var(--danger);
            font-weight: 600;
        }

        .signature-row { display: flex; justify-content: space-between; gap: 40px; margin-top: 40px; padding-top: 14px; }
        .signature-block { text-align: center; min-width: 160px; }
        .signature-line { border-top: 1px solid var(--ink); padding-top: 8px; font-size: 12px; font-weight: 600; color: var(--muted); }

        .actions { max-width: 760px; margin: 12px auto 0; display: flex; justify-content: flex-end; gap: 8px; }

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

        .btn:hover { background: #f8fafc; border-color: #9aa8bb; }

        @media (max-width: 760px) {
            body { padding: 12px; }
            .receipt-head { padding: 14px; flex-direction: column; gap: 12px; }
            .receipt-title, .receipt-sub { text-align: left; }
            .receipt-pill { float: none; }
            .receipt-body { padding: 14px; }
            .detail-grid { grid-template-columns: 1fr; gap: 10px; }
            .meta-strip { grid-template-columns: 1fr 1fr; }
            .ledger-total strong { font-size: 21px; }
        }

        @media print {
            body { background: #fff; padding: 0 !important; }
            .receipt-page { max-width: none; }
            .receipt { border: none; border-radius: 0; padding: 0; box-shadow: none; }
            .receipt-head, .detail-card, .meta-box, .ledger, .notice-body, .warning-box { break-inside: avoid; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    @php
        $shop = auth()->user()?->shop;
        $shopName = $shop?->name ?: config('app.name', 'JewelFlow');
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
                            {{ $customer?->mobile ?? 'No mobile available' }}
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
                            This notice is issued in connection with Gold Loan <strong>{{ $loan->loan_number }}</strong>
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
