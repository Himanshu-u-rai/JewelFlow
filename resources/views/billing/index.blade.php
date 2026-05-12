<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Billing & Invoices</h1>
            <p class="mt-1 text-sm text-gray-500">History of all subscription payments for your shop.</p>
        </div>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Invoice #</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Plan</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Cycle</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Period</th>
                            <th class="px-5 py-3 text-right font-semibold text-gray-600">Amount</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Date</th>
                            <th class="px-5 py-3 text-left font-semibold text-gray-600">Status</th>
                            <th class="px-5 py-3 text-right font-semibold text-gray-600"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($invoices as $invoice)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-5 py-3 font-mono text-xs font-semibold text-gray-800">
                                    {{ $invoice->invoice_number }}
                                </td>
                                <td class="px-5 py-3 text-gray-800">
                                    {{ $invoice->plan?->name ?? '—' }}
                                </td>
                                <td class="px-5 py-3 capitalize text-gray-600">
                                    {{ $invoice->billing_cycle }}
                                </td>
                                <td class="px-5 py-3 text-gray-500 text-xs">
                                    {{ $invoice->billing_period_start->format('d M Y') }}
                                    –
                                    {{ $invoice->billing_period_end->format('d M Y') }}
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-gray-900">
                                    ₹{{ number_format($invoice->total_amount, 2) }}
                                </td>
                                <td class="px-5 py-3 text-gray-500 text-xs">
                                    {{ $invoice->issued_at->format('d M Y') }}
                                </td>
                                <td class="px-5 py-3">
                                    @if($invoice->status === 'issued')
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">Paid</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-rose-50 px-2.5 py-0.5 text-xs font-medium text-rose-700 ring-1 ring-rose-200">Cancelled</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('billing.invoices.show', $invoice) }}"
                                       class="text-xs font-medium text-indigo-600 hover:text-indigo-800">View</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-10 text-center text-sm text-gray-400">
                                    No invoices yet. Invoices appear here after each subscription payment.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($invoices->hasPages())
                <div class="px-5 py-4 border-t border-gray-100">
                    {{ $invoices->links() }}
                </div>
            @endif
        </div>

    </div>
</x-app-layout>
