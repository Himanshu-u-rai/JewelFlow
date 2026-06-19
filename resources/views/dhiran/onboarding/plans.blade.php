<x-dhiran-layout title="Choose your Dhiran plan">
    <style>
        .dop-wrap { max-width: 480px; margin: 12px auto 0; }
        .dop-head { text-align: center; margin-bottom: 26px; }
        .dop-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 6px; }
        .dop-head p { color: var(--dh-muted); font-size: 15px; margin: 0; }
        .dop-card {
            background: #fff;
            border: 1px solid var(--dh-line);
            border-radius: 18px;
            box-shadow: 0 14px 40px rgba(20, 40, 75, 0.08);
            overflow: hidden;
        }
        .dop-card-top {
            padding: 24px 26px 20px;
            background: linear-gradient(135deg, #fff8ec, #fff);
            border-bottom: 1px solid var(--dh-line);
        }
        .dop-badge {
            display: inline-block;
            font-size: 11px; font-weight: 700; letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--dh-gold-deep);
            background: #fff3df;
            padding: 4px 10px; border-radius: 999px;
            margin-bottom: 12px;
        }
        .dop-plan-name { font-size: 19px; font-weight: 800; margin: 0 0 4px; }
        .dop-price { display: flex; align-items: baseline; gap: 8px; margin-top: 10px; }
        .dop-price .amt { font-size: 34px; font-weight: 800; letter-spacing: -0.02em; }
        .dop-price .per { color: var(--dh-muted); font-size: 14px; font-weight: 600; }
        .dop-feat { list-style: none; padding: 22px 26px; margin: 0; display: flex; flex-direction: column; gap: 12px; }
        .dop-feat li { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: #334155; }
        .dop-feat svg { width: 18px; height: 18px; color: var(--dh-gold-deep); flex-shrink: 0; margin-top: 1px; }
        .dop-foot { padding: 0 26px 26px; }
        .dop-btn {
            width: 100%;
            padding: 14px 18px;
            border: 0; border-radius: 12px;
            background: linear-gradient(135deg, var(--dh-gold), var(--dh-gold-deep));
            color: #fff; font-size: 15px; font-weight: 700;
            cursor: pointer;
            transition: transform 150ms var(--dh-ease), box-shadow 150ms var(--dh-ease);
            box-shadow: 0 8px 20px rgba(217, 139, 0, 0.28);
        }
        .dop-btn:active { transform: scale(0.97); }
        .dop-note { text-align: center; color: var(--dh-muted); font-size: 12px; margin-top: 14px; }
    </style>

    <div class="dop-wrap">
        <div class="dop-head">
            <h1>Start your Dhiran subscription</h1>
            <p>Run your gold-loan business with confidence.</p>
        </div>

        <div class="dop-card">
            <div class="dop-card-top">
                <span class="dop-badge">Yearly plan</span>
                <p class="dop-plan-name">{{ $plan->name }}</p>
                <div class="dop-price">
                    <span class="amt">₹{{ number_format((float) $plan->price_yearly, 0) }}</span>
                    <span class="per">per year</span>
                </div>
            </div>

            <ul class="dop-feat">
                <li>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Track every gold loan, interest, and repayment in one place.
                </li>
                <li>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Printable loan receipts and closure certificates.
                </li>
                <li>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Renewal, pre-close, and forfeiture handling built in.
                </li>
                <li>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Reports for the whole year, ready when you need them.
                </li>
            </ul>

            <div class="dop-foot">
                <form method="POST" action="{{ route('dhiran.subscribe') }}" data-turbo-frame="_top">
                    @csrf
                    <button type="submit" class="dop-btn">Continue to payment</button>
                </form>
                <p class="dop-note">You pay for one year. Set up your business right after payment.</p>
            </div>
        </div>
    </div>
</x-dhiran-layout>
