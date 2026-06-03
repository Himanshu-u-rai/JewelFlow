<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Suspicious Activity</h1>
            <p class="text-sm text-gray-500 mt-1">Compliance alerts to review — split bills, missing PAN, threshold breaches — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.suspicious-activity') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>@endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)<option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>@endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <x-print-button />
                <a href="{{ route('report.suspicious-activity.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Total Alerts</p><p class="text-lg font-semibold">{{ $data->totalCount }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Still To Review</p><p class="text-lg font-semibold {{ $data->unresolvedCount > 0 ? 'text-rose-600' : 'text-green-600' }}">{{ $data->unresolvedCount }}</p></div>
            @foreach($data->countsByType as $type => $count)
                <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">{{ ucfirst(str_replace('_', ' ', $type)) }}</p><p class="text-lg font-semibold">{{ $count }}</p></div>
            @endforeach
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100"><h2 class="font-semibold text-gray-900">Alerts</h2></div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bill</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Reviewed</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->rows as $r)
                            <tr class="{{ $r->resolved ? '' : 'bg-rose-50/40' }}">
                                <td class="px-4 py-2 text-gray-800">{{ $r->label }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->customer_name }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->invoice_number ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ \Carbon\Carbon::parse($r->created_at)->format('d M Y') }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if($r->resolved)
                                        <span class="text-green-600">Yes</span>
                                    @else
                                        <span class="text-rose-600 font-medium">No</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No alerts in this period — nothing to review.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-xs text-gray-400">These are warnings the system raised while you were billing. Reviewing them keeps your shop clean for tax checks.</p>
    </div>
</x-app-layout>
