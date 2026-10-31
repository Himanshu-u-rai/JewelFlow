<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Daily Gold Movement</h1>
            <p class="text-sm text-gray-500 mt-1">Summary for {{ $date }}</p>
        </div>
        <div class="page-actions flex flex-wrap items-center gap-2">
            <form method="GET" action="{{ url('/report/daily') }}" class="inline-flex items-center gap-2">
                <input type="date" name="date" value="{{ $date }}" class="border border-gray-300 px-3 py-2 text-sm">
                @if(request()->filled('date'))
                    <a href="{{ url('/report/daily') }}" class="btn btn-secondary btn-sm">
                        <x-icon name="x" size="14" class="mr-1" />Clear
                    </a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="calendar" size="14" class="mr-1" />View Date</button>
                @endif
            </form>
            <a href="{{ url()->current() }}" class="btn btn-secondary btn-sm"><x-icon name="rotate-cw" size="14" class="mr-1.5" />Refresh</a>
            <a href="{{ url('/ledger') }}" class="btn btn-secondary btn-sm"><x-icon name="book-open" size="14" class="mr-1.5" />View Ledger</a>
        </div>
    </x-page-header>

    <div class="content-inner">
        @php
            $totalGold = $rows->sum('total');
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="bg-white shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 p-2">
                        <x-icon name="circle-dollar-sign" class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Fine Gold</p>
                        <p class="text-xl font-semibold text-gray-900">{{ number_format($totalGold, 6) }} g</p>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-100 text-blue-700 p-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h3.586a1 1 0 01.707.293l1.414 1.414a1 1 0 00.707.293H20a1 1 0 011 1v2H3V4zM3 9h18v11a1 1 0 01-1 1H4a1 1 0 01-1-1V9z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Movement Types</p>
                        <p class="text-xl font-semibold text-gray-900">{{ $rows->count() }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Movement Summary</h2>
                <p class="text-sm text-gray-500 mt-1">Totals grouped by movement type</p>
            </div>

            <div class="overflow-x-auto p-4">
                <table class="w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Fine Gold (g)</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse($rows as $r)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-sm text-gray-900">{{ ucfirst($r->type) }}</td>
                                <td class="px-6 py-3 text-right text-sm font-medium">{{ number_format($r->total, 6) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="px-6 py-12 text-center text-gray-500">
                                    No movements found for today.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($rows->isNotEmpty())
                    <tfoot>
                        <tr class="border-t">
                            <td class="px-6 py-3 text-sm font-semibold text-gray-900">Total</td>
                            <td class="px-6 py-3 text-right text-sm font-semibold text-gray-900">{{ number_format($rows->sum('total'), 6) }} g</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
