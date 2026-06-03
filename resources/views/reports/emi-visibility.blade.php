<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Pending EMI / Installments</h1>
            <p class="text-sm text-gray-500 mt-1">Active installment plans and what's outstanding — as of {{ \Carbon\Carbon::parse($asOf)->format('d M Y') }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.emi') }}" class="flex flex-wrap gap-2 items-end">
                <div>
                    <label class="block text-[11px] uppercase text-gray-500 mb-1">As of</label>
                    <input type="date" name="as_of" value="{{ $asOf }}" class="rounded-lg border-slate-200 text-sm h-10">
                </div>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <x-print-button />
                <a href="{{ route('report.emi.csv', ['as_of' => $asOf]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Active Plans</p><p class="text-lg font-semibold">{{ $data->planCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total Outstanding</p><p class="text-lg font-semibold">₹{{ number_format($data->totalOutstanding, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Overdue</p><p class="text-lg font-semibold {{ $data->overdueCount ? 'text-rose-600' : '' }}">₹{{ number_format($data->overdueAmount, 2) }} <span class="text-xs font-normal text-gray-400">({{ $data->overdueCount }})</span></p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Due ≤ 7 days</p><p class="text-lg font-semibold text-amber-600">₹{{ number_format($data->upcomingAmount, 2) }} <span class="text-xs font-normal text-gray-400">({{ $data->upcomingCount }})</span></p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Active Plans</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Payable</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Remaining</th>
                        <th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">EMIs</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Next Due</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr class="{{ $r->overdue ? 'bg-rose-50/40' : '' }}">
                                <td class="px-4 py-2 text-gray-800">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 font-mono text-gray-500">{{ $r->invoice_number ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->total_payable, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->paid, 2) }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->remaining, 2) }}</td>
                                <td class="px-4 py-2 text-center text-gray-600">{{ $r->emis_paid }}/{{ $r->total_emis }}</td>
                                <td class="px-4 py-2">
                                    {{ $r->next_due_date ? \Carbon\Carbon::parse($r->next_due_date)->format('d M Y') : '—' }}
                                    @if($r->overdue)<span class="ml-2 px-2 py-0.5 rounded text-xs bg-rose-100 text-rose-700">{{ $r->days_overdue }}d overdue</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No active installment plans.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
