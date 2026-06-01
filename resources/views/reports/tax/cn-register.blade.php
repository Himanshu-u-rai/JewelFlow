<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Credit / Debit Note Register</h1>
            <p class="text-sm text-gray-500 mt-1">Returns &amp; cancellations issued — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.cn-register') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)
                        <option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>
                    @endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)
                        <option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.cn-register.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Credit Notes</p><p class="text-lg font-semibold">{{ $data->count }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Taxable Reversed</p><p class="text-lg font-semibold">₹{{ number_format($data->totalTaxable, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">GST Reversed</p><p class="text-lg font-semibold text-rose-600">−₹{{ number_format($data->totalGst, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total Value</p><p class="text-lg font-semibold">₹{{ number_format($data->totalValue, 2) }}</p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">CN Number</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Original Invoice</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Taxable</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">GST</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">CN Total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $r->credit_note_number }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ \Carbon\Carbon::parse($r->issued_at)->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs {{ $r->cn_type === 'full_cancellation' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-800' }}">
                                        {{ $r->cn_type === 'full_cancellation' ? 'Full cancellation' : 'Partial return' }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 font-mono text-gray-600">{{ $r->original_invoice_number ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->taxable, 2) }}</td>
                                <td class="px-4 py-2 text-right text-rose-600">−₹{{ number_format($r->gst, 2) }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">No credit notes issued this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
