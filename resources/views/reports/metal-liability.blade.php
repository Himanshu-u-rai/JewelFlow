<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Metal Liability</h1>
            <p class="text-sm text-gray-500 mt-1">Fine gold owed to customers from advance deposits — vs gold on hand. All figures in fine grams.</p>
        </div>
        <div class="page-actions">
            <x-print-button />
            <a href="{{ route('report.metal-liability.csv') }}" class="btn btn-success btn-sm">Export CSV</a>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Advance Liability (net)</p><p class="text-lg font-semibold text-rose-600">{{ number_format($data->totalAdvanceLiability, 3) }} g</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Deposited (gross)</p><p class="text-lg font-semibold">{{ number_format($data->totalDeposited, 3) }} g</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Old Gold Accepted</p><p class="text-lg font-semibold text-gray-500">{{ number_format($data->oldGoldAcceptedFine, 3) }} g</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Vault On Hand</p><p class="text-lg font-semibold text-emerald-600">{{ number_format($data->vaultOnHandFine, 3) }} g</p></div>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <p><span class="font-semibold">Net liability</span> is the remaining balance in the pooled customer-advance gold lot — the gold you still owe customers.
            The per-customer figures below are <span class="font-semibold">gross deposited</span> (advance consumption is pooled and can't be split per customer).
            Old gold accepted at the counter is shop stock, not a liability.</p>
        </div>

        @if($data->totalAdvanceLiability > $data->vaultOnHandFine + 0.001)
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
            ⚠ Advance liability exceeds gold on hand — investigate.
        </div>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Customer Advance Deposits (gross)</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Fine Gold Deposited</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 text-gray-800">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-right font-semibold">{{ number_format($r->fine_deposited, 3) }} g</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-10 text-center text-gray-400">No customer gold advances on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
