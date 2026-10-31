<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Loyalty History — {{ $customer->name }}</h1>
            <p class="text-sm text-gray-500 mt-1">Current balance: {{ number_format($customer->loyalty_points) }} points (₹{{ number_format($customer->loyalty_points * 0.25, 2) }})</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <a href="{{ route('loyalty.adjust.form', $customer) }}" class="btn btn-dark btn-sm"><x-icon name="sliders-vertical" size="14" class="mr-1" />Adjust Points</a>
            <a href="{{ route('loyalty.index') }}" class="btn btn-secondary btn-sm"><x-icon name="arrow-left" size="14" class="mr-1" />Back</a>
        </div>
    </x-page-header>

    <div class="content-inner">
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full jf-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Points</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance After</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($transactions as $txn)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-sm text-gray-500">{{ $txn->created_at->format('d M Y, h:i A') }}</td>
                            <td class="px-6 py-3">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $txn->type === 'earn' ? 'bg-green-100 text-green-800' : 'bg-rose-100 text-rose-800' }}">
                                    {{ $txn->type === 'earn' ? '+Earned' : '-Redeemed' }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-sm text-right font-medium {{ $txn->type === 'earn' ? 'text-green-700' : 'text-rose-700' }}">
                                {{ $txn->type === 'earn' ? '+' : '-' }}{{ number_format($txn->points) }}
                            </td>
                            <td class="px-6 py-3 text-sm text-right text-gray-700">{{ number_format($txn->balance_after) }}</td>
                            <td class="px-6 py-3 text-sm text-gray-600">{{ $txn->description }}</td>
                            <td class="px-6 py-3 text-sm">
                                @if($txn->invoice)
                                    <a href="{{ route('invoices.show', $txn->invoice_id) }}" class="text-amber-600 hover:underline">#{{ $txn->invoice_id }}</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="jf-table-empty">No loyalty transactions yet.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($transactions->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">{{ $transactions->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
