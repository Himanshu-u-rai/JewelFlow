<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; margin: 0; padding: 24px; color: #0f172a;">
    <div style="max-width: 580px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 36px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">

        {{-- Header --}}
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0;">
            <div>
                <h2 style="margin: 0 0 4px; font-size: 22px; font-weight: 700; color: #0f172a;">{{ config('app.name') }}</h2>
                <p style="margin: 0; font-size: 13px; color: #64748b;">Tax Invoice / Receipt</p>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 15px; font-weight: 700; color: #0f172a;">{{ $invoice->invoice_number }}</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">{{ $invoice->issued_at->format('d M Y') }}</div>
            </div>
        </div>

        {{-- Greeting --}}
        <p style="margin: 0 0 16px; font-size: 14px; color: #334155; line-height: 1.6;">
            Dear {{ $shop?->name ?? 'Valued Customer' }},
        </p>
        <p style="margin: 0 0 24px; font-size: 14px; color: #475569; line-height: 1.6;">
            Thank you for your subscription. Please find your invoice details below.
        </p>

        {{-- Invoice details table --}}
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 24px;">
            <thead>
                <tr style="background: #f1f5f9;">
                    <th style="padding: 10px 12px; text-align: left; font-weight: 600; color: #475569; border-radius: 6px 0 0 6px;">Description</th>
                    <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: #475569;">Billing Cycle</th>
                    <th style="padding: 10px 12px; text-align: center; font-weight: 600; color: #475569;">Period</th>
                    <th style="padding: 10px 12px; text-align: right; font-weight: 600; color: #475569; border-radius: 0 6px 6px 0;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #f1f5f9; color: #0f172a; font-weight: 500;">
                        {{ $plan?->name ?? 'Subscription Plan' }}
                    </td>
                    <td style="padding: 12px; border-bottom: 1px solid #f1f5f9; text-align: center; color: #475569; text-transform: capitalize;">
                        {{ $invoice->billing_cycle }}
                    </td>
                    <td style="padding: 12px; border-bottom: 1px solid #f1f5f9; text-align: center; color: #475569; font-size: 12px;">
                        {{ $invoice->billing_period_start->format('d M Y') }} – {{ $invoice->billing_period_end->format('d M Y') }}
                    </td>
                    <td style="padding: 12px; border-bottom: 1px solid #f1f5f9; text-align: right; color: #0f172a;">
                        ₹{{ number_format($invoice->amount_before_tax, 2) }}
                    </td>
                </tr>
            </tbody>
        </table>

        {{-- Totals --}}
        <div style="margin-left: auto; max-width: 260px; font-size: 13px;">
            <div style="display: flex; justify-content: space-between; padding: 6px 0; color: #475569;">
                <span>Subtotal</span>
                <span>₹{{ number_format($invoice->amount_before_tax, 2) }}</span>
            </div>
            @if($invoice->gst_rate > 0)
            <div style="display: flex; justify-content: space-between; padding: 6px 0; color: #475569;">
                <span>GST ({{ number_format($invoice->gst_rate, 0) }}%)</span>
                <span>₹{{ number_format($invoice->gst_amount, 2) }}</span>
            </div>
            @endif
            <div style="display: flex; justify-content: space-between; padding: 10px 0; margin-top: 4px; border-top: 2px solid #0f172a; font-weight: 700; font-size: 15px; color: #0f172a;">
                <span>Total Paid</span>
                <span>₹{{ number_format($invoice->total_amount, 2) }}</span>
            </div>
        </div>

        {{-- Payment info --}}
        <div style="margin-top: 24px; padding: 16px 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; color: #166534;">
            <strong>Payment received.</strong>
            @if($invoice->razorpay_payment_id)
                Payment ID: {{ $invoice->razorpay_payment_id }}
            @elseif($invoice->payment_method === 'manual')
                Recorded manually by administrator.
            @endif
        </div>

        {{-- View online link --}}
        <div style="margin-top: 24px; text-align: center;">
            <a href="{{ route('billing.invoices.show', $invoice) }}"
               style="display: inline-block; padding: 10px 24px; background: #0f172a; color: #fff; text-decoration: none; border-radius: 8px; font-size: 13px; font-weight: 600;">
                View Invoice Online
            </a>
        </div>

        {{-- Footer --}}
        <div style="margin-top: 32px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; text-align: center;">
            <p style="margin: 0;">{{ config('app.name') }} · This is a system-generated invoice.</p>
            <p style="margin: 6px 0 0;">If you have questions, reply to this email or contact support.</p>
        </div>

    </div>
</body>
</html>
