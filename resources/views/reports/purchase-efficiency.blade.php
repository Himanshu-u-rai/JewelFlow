<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Purchase Efficiency</h1>
            <p class="text-sm text-gray-500 mt-1">Rate paid on stock purchases vs your recorded daily market rate — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.purchase-efficiency') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>@endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)<option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>@endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.purchase-efficiency.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Lines</p><p class="text-lg font-semibold">{{ $data->lineCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Spent</p><p class="text-lg font-semibold">₹{{ number_format($data->totalPurchaseCost, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Market Value</p><p class="text-lg font-semibold">₹{{ number_format($data->totalMarketCost, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Premium (paid − market)</p><p class="text-lg font-semibold {{ $data->totalPremium > 0 ? 'text-rose-600' : 'text-emerald-600' }}">₹{{ number_format($data->totalPremium, 2) }}</p></div>
        </div>

        @if($data->linesNoMarket > 0)
            <p class="text-xs text-amber-600">{{ $data->linesNoMarket }} line(s) were on a date with no recorded market rate and are excluded from the premium. Enter daily rates to compare them.</p>
        @endif

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">By Metal</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Metal</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Lines</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Gross (g)</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Market</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Premium</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Premium %</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr>
                                <td class="px-4 py-2 text-gray-800 capitalize">{{ $r->metal_type }}@if($r->lines_no_market > 0)<span class="ml-2 text-xs text-amber-500">{{ $r->lines_no_market }} no-rate</span>@endif</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->line_count }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($r->total_gross, 3) }}</td>
                                <td class="px-4 py-2 text-right">₹{{ number_format($r->purchase_cost, 0) }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">₹{{ number_format($r->market_cost, 0) }}</td>
                                <td class="px-4 py-2 text-right font-semibold {{ $r->premium > 0 ? 'text-rose-600' : 'text-emerald-600' }}">₹{{ number_format($r->premium, 2) }}</td>
                                <td class="px-4 py-2 text-right {{ $r->premium_pct > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ number_format($r->premium_pct, 2) }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No stock purchases in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
