<x-app-layout>
    <style>
        .onboarding-summary-page {
            max-width: 1040px !important;
            margin: 0 auto !important;
            padding: 22px !important;
            color: #334155;
        }
        .onboarding-summary-hero {
            margin-bottom: 18px;
            padding: 18px 20px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: linear-gradient(180deg, #fff 0%, #fffaf5 100%);
        }
        .onboarding-summary-kicker {
            display: inline-flex;
            width: fit-content;
            margin-bottom: 8px;
            padding: 4px 9px;
            border: 1px solid #fed7aa;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .onboarding-summary-page h1 {
            margin: 0 0 6px !important;
            color: #0f172a !important;
            font-size: clamp(22px, 3vw, 28px) !important;
            line-height: 1.15 !important;
            font-weight: 800 !important;
        }
        .onboarding-summary-card {
            margin-top: 18px;
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
        }
        .onboarding-summary-page table {
            width: 100%;
            min-width: 520px;
            border-collapse: collapse;
        }
        .onboarding-summary-page th {
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .onboarding-summary-page td {
            color: #334155;
            font-size: 13px;
            font-weight: 400;
        }
        .onboarding-summary-page a {
            display: inline-flex;
            align-items: center;
            min-height: 38px;
            padding: 9px 13px;
            border: 1px solid #f3dcb6;
            border-radius: 9px;
            background: #fff;
            color: #92400e !important;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
        }
        .onboarding-summary-page a:hover {
            border-color: #d97706;
            background: #fff7ed;
        }
        @media (max-width: 760px) {
            .onboarding-summary-page {
                padding: 14px 10px !important;
            }
        }
    </style>

    <div class="onboarding-summary-page" style="max-width:1040px;margin:0 auto;padding:22px;">
        <div class="onboarding-summary-hero">
            <span class="onboarding-summary-kicker">Opening setup</span>
            <h1 style="font-size:24px;font-weight:800;margin-bottom:6px;">Supplier opening outstanding</h1>
            <p style="color:#64748b;font-size:13px;margin:0;">Seeded at onboarding. Positive = shop owes supplier (payable); negative = supplier owes shop.</p>
        </div>

        @if ($rows->isEmpty())
            <p style="color:#64748b;">No supplier opening balances recorded.</p>
        @else
            <div class="onboarding-summary-card">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid #e2e8f0;background:#f8fafc;">
                            <th style="padding:10px 12px;width:36px;">#</th>
                            <th style="padding:10px 12px;">Supplier</th>
                            <th style="padding:10px 12px;text-align:right;">Net payable (₹)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 12px;color:#94a3b8;">{{ $loop->iteration }}</td>
                                <td style="padding:10px 12px;">{{ $r->vendor->name ?? 'Unknown vendor' }}</td>
                                <td style="padding:10px 12px;text-align:right;">{{ number_format((float) $r->net_payable, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p style="margin-top:24px;"><a href="{{ route('onboarding.index') }}" style="color:#b45309;">&larr; Back to onboarding</a></p>
    </div>
</x-app-layout>
