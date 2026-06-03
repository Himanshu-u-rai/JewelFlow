<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Operator Performance</h1>
            <p class="text-sm text-gray-500 mt-1">Sales, discounts and returns by who handled them — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.operator-performance') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>@endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)<option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>@endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.operator-performance.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Operators</p><p class="text-lg font-semibold">{{ $data->operatorCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total Sales</p><p class="text-lg font-semibold">₹{{ number_format($data->totalSales, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Discount Given</p><p class="text-lg font-semibold text-amber-600">₹{{ number_format($data->totalDiscount, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Returns Value</p><p class="text-lg font-semibold text-rose-600">₹{{ number_format($data->totalReturnsValue, 2) }}</p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">By Operator</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Operator</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Invoices</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Sales</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Discount</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Returns</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Net Sales</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr class="{{ $r->operator_name === 'Unattributed' ? 'bg-gray-50/60' : '' }}">
                                <td class="px-4 py-2 text-gray-800">{{ $r->operator_name }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->invoice_count }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->total_sales, 2) }}</td>
                                <td class="px-4 py-2 text-right text-amber-600">{{ $r->total_discount > 0 ? '₹' . number_format($r->total_discount, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right text-rose-600">{{ $r->returns_count > 0 ? $r->returns_count . ' · ₹' . number_format($r->returns_value, 2) : '—' }}</td>
                                <td class="px-4 py-2 text-right font-semibold">₹{{ number_format($r->net_sales, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">No sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($data->rows->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2">Total</td>
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalSales, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalDiscount, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalReturnsValue, 2) }}</td>
                            <td class="px-4 py-2 text-right">₹{{ number_format($data->totalSales - $data->totalReturnsValue, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-400">"Unattributed" = invoices created before operator tracking was enabled. New invoices record the operator automatically.</p>
    </div>
</x-app-layout>
