<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Customer Dues Aging</h1>
            <p class="text-sm text-gray-500 mt-1">Outstanding on finalized invoices, by age — as of {{ \Carbon\Carbon::parse($asOf)->format('d M Y') }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.dues-aging') }}" class="flex flex-wrap gap-2 items-end">
                <div>
                    <label class="block text-[11px] uppercase text-gray-500 mb-1">As of</label>
                    <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border-slate-200 text-sm h-10">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.dues-aging.csv', ['as_of' => $asOf]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        {{-- Bucket KPIs --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Current (0–30)</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->bucketCurrent, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">31–60 days</p><p class="text-lg font-semibold text-amber-600">₹{{ number_format($data->bucket3160, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">61–90 days</p><p class="text-lg font-semibold text-orange-600">₹{{ number_format($data->bucket6190, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">90+ days</p><p class="text-lg font-semibold text-rose-600">₹{{ number_format($data->bucket90plus, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total Due</p><p class="text-lg font-semibold">₹{{ number_format($data->totalOutstanding, 2) }}</p></div>
        </div>

        <p class="text-sm text-gray-500">{{ $data->customerCount }} customer(s) · {{ $data->invoiceCount }} unpaid invoice(s)</p>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Outstanding by Customer</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Mobile</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Inv</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">0–30</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">31–60</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">61–90</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">90+</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 text-gray-800">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-gray-500 font-mono">{{ $r->mobile ?? '—' }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->invoice_count }}</td>
                                <td class="px-4 py-2 text-right">{{ $r->current > 0 ? '₹' . number_format($r->current, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right {{ $r->d3160 > 0 ? 'text-amber-600' : '' }}">{{ $r->d3160 > 0 ? '₹' . number_format($r->d3160, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right {{ $r->d6190 > 0 ? 'text-orange-600' : '' }}">{{ $r->d6190 > 0 ? '₹' . number_format($r->d6190, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right {{ $r->d90plus > 0 ? 'text-rose-600 font-medium' : '' }}">{{ $r->d90plus > 0 ? '₹' . number_format($r->d90plus, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-10 text-center text-gray-400">No outstanding dues — all finalized invoices are fully paid.</td></tr>
                        @endforelse
                    </tbody>
                    @if($data->rows->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2" colspan="3">Total</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->bucketCurrent, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->bucket3160, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->bucket6190, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->bucket90plus, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalOutstanding, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
