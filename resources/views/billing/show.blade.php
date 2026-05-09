<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Invoice {{ $invoice->invoice_number }}</h1>
                <p class="mt-1 text-sm text-gray-500">Issued {{ $invoice->issued_at->format('d M Y, h:i A') }}</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('billing.invoices.index') }}"
                   class="text-sm text-gray-500 hover:text-gray-700">← Back to Billing</a>
                <button onclick="window.print()"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-900 text-white text-sm font-medium rounded-lg hover:bg-gray-700 transition-colors print:hidden">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print / Save PDF
                </button>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 print:shadow-none print:border-0">

            {{-- Invoice header --}}
            <div class="flex justify-between items-start mb-8 pb-6 border-b border-gray-100">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">{{ config('app.name') }}</h2>
                    <p class="text-sm text-gray-500 mt-1">Tax Invoice / Receipt</p>
                </div>
                <div class="text-right">
                    <div class="text-lg font-bold text-gray-900">{{ $invoice->invoice_number }}</div>
                    <div class="text-sm text-gray-500 mt-1">{{ $invoice->issued_at->format('d M Y') }}</div>
                    @if($invoice->status === 'issued')
                        <span class="inline-block mt-2 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">PAID</span>
                    @else
                        <span class="inline-block mt-2 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">CANCELLED</span>
                    @endif
                </div>
            </div>

            {{-- Bill to --}}
            <div class="mb-8">
                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Billed To</div>
                <div class="text-sm font-semibold text-gray-900">{{ $shop->name }}</div>
                @if($shop->owner_first_name || $shop->owner_last_name)
                    <div class="text-sm text-gray-600">{{ trim($shop->owner_first_name . ' ' . $shop->owner_last_name) }}</div>
                @endif
                @if($shop->owner_email)
                    <div class="text-sm text-gray-500">{{ $shop->owner_email }}</div>
                @endif
                @if($shop->gst_number)
                    <div class="text-sm text-gray-500">GST: {{ $shop->gst_number }}</div>
                @endif
            </div>

            {{-- Items table --}}
            <table class="w-full text-sm mb-6">
                <thead>
                    <tr class="border-b-2 border-gray-200">
                        <th class="pb-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Description</th>
                        <th class="pb-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Cycle</th>
                        <th class="pb-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Period</th>
                        <th class="pb-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-100">
                        <td class="py-4 text-gray-900 font-medium">{{ $invoice->plan?->name ?? 'Subscription Plan' }}</td>
                        <td class="py-4 text-center text-gray-600 capitalize">{{ $invoice->billing_cycle }}</td>
                        <td class="py-4 text-center text-gray-500 text-xs">
                            {{ $invoice->billing_period_start->format('d M Y') }} – {{ $invoice->billing_period_end->format('d M Y') }}
                        </td>
                        <td class="py-4 text-right text-gray-900">₹{{ number_format($invoice->amount_before_tax, 2) }}</td>
                    </tr>
                </tbody>
            </table>

            {{-- Totals --}}
            <div class="ml-auto max-w-xs space-y-2 text-sm">
                <div class="flex justify-between text-gray-600">
                    <span>Subtotal</span>
                    <span>₹{{ number_format($invoice->amount_before_tax, 2) }}</span>
                </div>
                @if($invoice->gst_rate > 0)
                    <div class="flex justify-between text-gray-600">
                        <span>GST ({{ number_format($invoice->gst_rate, 0) }}%)</span>
                        <span>₹{{ number_format($invoice->gst_amount, 2) }}</span>
                    </div>
                @endif
                <div class="flex justify-between font-bold text-base text-gray-900 pt-2 border-t-2 border-gray-900">
                    <span>Total Paid</span>
                    <span>₹{{ number_format($invoice->total_amount, 2) }}</span>
                </div>
            </div>

            {{-- Payment info --}}
            <div class="mt-8 p-4 bg-gray-50 rounded-lg text-xs text-gray-500 space-y-1">
                <div><span class="font-medium text-gray-700">Payment Method:</span> {{ ucfirst($invoice->payment_method) }}</div>
                @if($invoice->razorpay_payment_id)
                    <div><span class="font-medium text-gray-700">Payment ID:</span> {{ $invoice->razorpay_payment_id }}</div>
                @endif
                @if($invoice->razorpay_order_id)
                    <div><span class="font-medium text-gray-700">Order ID:</span> {{ $invoice->razorpay_order_id }}</div>
                @endif
            </div>

            {{-- Footer --}}
            <div class="mt-10 pt-6 border-t border-gray-100 text-xs text-gray-400 text-center">
                {{ config('app.name') }} · System-generated invoice · No signature required.
            </div>

        </div>
    </div>

    <style>
        @media print {
            body * { visibility: hidden; }
            .max-w-3xl, .max-w-3xl * { visibility: visible; }
            .max-w-3xl { position: absolute; top: 0; left: 0; width: 100%; }
        }
    </style>
</x-app-layout>
