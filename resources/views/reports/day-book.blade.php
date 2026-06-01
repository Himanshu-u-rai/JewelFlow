<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Day Book / Journal</h1>
            <p class="text-sm text-gray-500 mt-1">Chronological accounting events — {{ $period->label() }}</p>
        </div>
        <div class="page-actions">
            <form method="GET" action="{{ route('report.day-book') }}" class="flex flex-wrap gap-2 items-end">
                <select name="month" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" {{ $month === $i ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($i)->format('F') }}</option>@endfor
                </select>
                <select name="year" class="rounded-lg border-slate-200 text-sm h-10">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)<option value="{{ $y }}" {{ $year === $y ? 'selected' : '' }}>{{ $y }}</option>@endfor
                </select>
                <button type="submit" class="btn btn-secondary btn-sm">View</button>
                <a href="{{ route('report.day-book.csv', ['month' => $month, 'year' => $year]) }}" class="btn btn-success btn-sm">Export CSV</a>
            </form>
        </div>
    </x-page-header>

    <div class="content-inner space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Sales</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->salesTotal, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Refunds (CN)</p><p class="text-lg font-semibold text-rose-600">−₹{{ number_format($data->refundTotal, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Cash In</p><p class="text-lg font-semibold text-emerald-600">₹{{ number_format($data->cashIn, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Cash Out</p><p class="text-lg font-semibold text-rose-600">−₹{{ number_format($data->cashOut, 2) }}</p></div>
            <div class="bg-white rounded-xl border border-gray-200 p-4"><p class="text-xs uppercase text-gray-500">Events</p><p class="text-lg font-semibold">{{ $data->eventCount }}</p></div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50"><tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date/Time</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Party</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($data->events as $e)
                            <tr>
                                <td class="px-4 py-2 text-gray-600 whitespace-nowrap">{{ \Carbon\Carbon::parse($e->occurred_at)->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs {{ $e->direction === 'credit' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">{{ $e->event_type }}</span>
                                </td>
                                <td class="px-4 py-2 font-mono text-gray-600">{{ $e->reference }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $e->party }}</td>
                                <td class="px-4 py-2 text-right {{ $e->direction === 'credit' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $e->direction === 'credit' ? '' : '−' }}₹{{ number_format($e->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 text-sm">No accounting events this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
