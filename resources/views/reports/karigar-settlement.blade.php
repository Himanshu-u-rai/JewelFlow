<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Karigar Settlement</h1>
            <p class="text-sm text-gray-500 mt-1">Gold out vs in (open jobs) and money invoiced vs paid, per karigar</p>
        </div>
        <div class="page-actions">
            <x-print-button />
            <a href="{{ route('report.karigar-settlement.csv') }}" class="btn btn-success btn-sm">Export CSV</a>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Gold With Karigars</p><p class="text-lg font-semibold text-amber-600">{{ number_format($data->totalOutstandingFine, 3) }} g</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Invoiced</p><p class="text-lg font-semibold">₹{{ number_format($data->totalInvoiced, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Paid</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->totalPaid, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Payable</p><p class="text-lg font-semibold text-rose-600">₹{{ number_format($data->totalOutstandingPayable, 2) }}</p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Per Karigar</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Karigar</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Open</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Issued</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Received</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Wastage</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">With Karigar</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Invoiced</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payable</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 text-gray-800">{{ $r->karigar_name }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->open_jobs }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($r->issued_fine, 3) }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($r->received_fine, 3) }}</td>
                                <td class="px-4 py-2 text-right text-gray-500">{{ number_format($r->wastage_fine, 3) }}</td>
                                <td class="px-4 py-2 text-right {{ $r->outstanding_fine > 0.0001 ? 'text-amber-600 font-medium' : 'text-gray-400' }}">{{ number_format($r->outstanding_fine, 3) }} g</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->invoiced, 0) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->paid, 0) }}</td>
                                <td class="px-4 py-2 text-right font-semibold {{ $r->outstanding_payable > 0.01 ? 'text-rose-600' : '' }}">₹{{ number_format($r->outstanding_payable, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">No karigar activity on record.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
