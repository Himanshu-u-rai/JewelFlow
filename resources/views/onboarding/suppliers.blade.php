<x-app-layout>
    <div style="max-width:720px;margin:0 auto;padding:24px;">
        <h1 style="font-size:20px;font-weight:700;margin-bottom:4px;">Supplier opening outstanding</h1>
        <p style="color:#64748b;font-size:13px;margin-bottom:16px;">Seeded at onboarding. Positive = shop owes supplier (payable); negative = supplier owes shop.</p>

        @if ($rows->isEmpty())
            <p style="color:#64748b;">No supplier opening balances recorded.</p>
        @else
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid #e2e8f0;">
                        <th style="padding:8px;">Supplier</th>
                        <th style="padding:8px;text-align:right;">Net payable (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:8px;">{{ $r->vendor->name ?? 'Unknown vendor' }}</td>
                            <td style="padding:8px;text-align:right;">{{ number_format((float) $r->net_payable, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p style="margin-top:24px;"><a href="{{ route('onboarding.index') }}" style="color:#0F766E;">&larr; Back to onboarding</a></p>
    </div>
</x-app-layout>
