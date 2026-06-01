<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Payment Reconciliation</h1>
            <p class="text-sm text-gray-500 mt-1">Invoice totals vs collected payments — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.payment-reconciliation') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>@endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)<option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>@endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.payment-reconciliation.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        {{-- Reconciliation status --}}
        <div class="rounded-xl border p-4 {{ $data->reconciled ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200' }}">
            <p class="font-semibold {{ $data->reconciled ? 'text-emerald-800' : 'text-rose-800' }}">
                @if($data->reconciled)
                    ✓ Reconciled — no over-collections detected.
                @else
                    ⚠ {{ $data->overCollectedCount }} invoice(s) collected MORE than their total — investigate.
                @endif
            </p>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Invoiced</p><p class="text-lg font-semibold">₹{{ number_format($data->invoiceTotal, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Collected</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->collected, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Pending</p><p class="text-lg font-semibold text-amber-600">₹{{ number_format($data->pending, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Fully Paid</p><p class="text-lg font-semibold">{{ $data->fullyPaidCount }}/{{ $data->invoiceCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Unpaid</p><p class="text-lg font-semibold {{ $data->unpaidCount ? 'text-rose-600' : '' }}">{{ $data->unpaidCount }}</p></div>
        </div>

        {{-- Mode breakdown --}}
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <h2 class="font-semibold text-gray-900 mb-3">Collections by Mode</h2>
            <div class="flex flex-wrap gap-3">
                @forelse($data->modeBreakdown as $mode => $amount)
                    <div class="px-3 py-2 rounded-lg bg-gray-50 border border-gray-200">
                        <span class="text-xs uppercase text-gray-500">{{ str_replace('_', ' ', $mode) }}</span>
                        <span class="ml-2 font-semibold">₹{{ number_format($amount, 2) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No payments recorded this period.</p>
                @endforelse
            </div>
        </div>

        {{-- Mismatches first (surface drift) --}}
        @if($data->mismatches->isNotEmpty())
        <div class="bg-white rounded-xl border border-rose-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-rose-100 bg-rose-50"><h2 class="font-semibold text-rose-800">Needs Attention ({{ $data->mismatches->count() }})</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Collected</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Issue</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($data->mismatches as $r)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $r->invoice_number }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->total, 2) }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->collected, 2) }}</td>
                                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs {{ $r->status === 'over_collected' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-800' }}">{{ $r->status === 'over_collected' ? 'Over-collected' : 'Unpaid' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Full per-invoice list --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">All Invoices</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Collected</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Pending</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $r->invoice_number }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ \Carbon\Carbon::parse($r->doc_date)->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->total, 2) }}</td>
                                <td class="px-4 py-2 text-right text-emerald-600">₹{{ number_format($r->collected, 2) }}</td>
                                <td class="px-4 py-2 text-right {{ $r->pending > 0 ? 'text-amber-600' : 'text-gray-400' }}">₹{{ number_format($r->pending, 2) }}</td>
                                <td class="px-4 py-2"><span class="text-xs text-gray-600">{{ str_replace('_', ' ', $r->status) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">No sales this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
