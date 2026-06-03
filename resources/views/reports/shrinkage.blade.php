<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Metal Loss / Shrinkage</h1>
            <p class="text-sm text-gray-500 mt-1">Gold sent out for making vs what came back — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.shrinkage') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>@endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)<option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>@endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.shrinkage.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Jobs Done</p><p class="text-lg font-semibold">{{ $data->jobCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Gold Sent Out</p><p class="text-lg font-semibold">{{ number_format($data->totalIssued, 3) }}g</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Came Back In Items</p><p class="text-lg font-semibold">{{ number_format($data->totalReturned, 3) }}g</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Wastage</p><p class="text-lg font-semibold text-amber-600">{{ number_format($data->totalWastage, 3) }}g · {{ number_format($data->wastagePct, 2) }}%</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Unaccounted</p><p class="text-lg font-semibold {{ abs($data->totalUnaccounted) > 0.01 ? 'text-rose-600' : 'text-green-600' }}">{{ number_format($data->totalUnaccounted, 3) }}g</p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">By Karigar</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Karigar</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Jobs</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Sent Out</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">In Items</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Leftover</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Wastage</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unaccounted</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr class="{{ abs($r->unaccounted_fine) > 0.01 ? 'bg-rose-50/40' : '' }}">
                                <td class="px-4 py-2 text-gray-800">{{ $r->karigar_name }}</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ $r->job_count }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($r->issued_fine, 3) }}g</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ number_format($r->returned_fine, 3) }}g</td>
                                <td class="px-4 py-2 text-right text-gray-600">{{ number_format($r->leftover_fine, 3) }}g</td>
                                <td class="px-4 py-2 text-right text-amber-600">{{ number_format($r->wastage_fine, 3) }}g · {{ number_format($r->wastage_pct, 1) }}%</td>
                                <td class="px-4 py-2 text-right {{ abs($r->unaccounted_fine) > 0.01 ? 'text-rose-600 font-semibold' : 'text-gray-400' }}">{{ number_format($r->unaccounted_fine, 3) }}g</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">No jobs completed in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($data->rows->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-2">Total</td>
                            <td class="px-4 py-2 text-right">{{ $data->jobCount }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($data->totalIssued, 3) }}g</td>
                            <td class="px-4 py-2 text-right">{{ number_format($data->totalReturned, 3) }}g</td>
                            <td class="px-4 py-2 text-right">{{ number_format($data->totalLeftover, 3) }}g</td>
                            <td class="px-4 py-2 text-right">{{ number_format($data->totalWastage, 3) }}g</td>
                            <td class="px-4 py-2 text-right">{{ number_format($data->totalUnaccounted, 3) }}g</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        @if($data->byMetal->isNotEmpty())
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">By Metal</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Metal</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Sent Out</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Wastage</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unaccounted</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($data->byMetal as $m)
                            <tr>
                                <td class="px-4 py-2 text-gray-800 capitalize">{{ $m->metal_type }}</td>
                                <td class="px-4 py-2 text-right">{{ number_format($m->issued_fine, 3) }}g</td>
                                <td class="px-4 py-2 text-right text-amber-600">{{ number_format($m->wastage_fine, 3) }}g · {{ number_format($m->wastage_pct, 1) }}%</td>
                                <td class="px-4 py-2 text-right {{ abs($m->unaccounted_fine) > 0.01 ? 'text-rose-600 font-semibold' : 'text-gray-400' }}">{{ number_format($m->unaccounted_fine, 3) }}g</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        <p class="text-xs text-gray-400"><strong>Wastage</strong> is the gold the karigar used up while making — normal for jewellery work. <strong>Unaccounted</strong> should be near zero; if it isn't, the job's weights don't add up and need a second look. This report only reads finished jobs — it changes nothing.</p>
    </div>
</x-app-layout>
