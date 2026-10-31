<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">Loyalty Points</h1>
            <p class="text-sm text-gray-500 mt-1">Customer loyalty rewards program</p>
        </div>
    </x-page-header>

    <div class="content-inner jf-skeleton-host is-loading">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 p-2">
                        <x-icon name="star" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Issued</p>
                        <p class="text-xl font-semibold text-gray-900 jf-skel jf-skel-value">{{ number_format($totalPointsIssued) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="bg-rose-100 text-rose-700 p-2">
                        <x-icon name="gift" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Total Redeemed</p>
                        <p class="text-xl font-semibold text-gray-900 jf-skel jf-skel-value">{{ number_format($totalPointsRedeemed) }}</p>
                    </div>
                </div>
            </div>
            <div class="bg-white shadow-sm border border-gray-200 p-4">
                <div class="flex items-center gap-3">
                    <div class="bg-green-100 text-green-700 p-2">
                        <x-icon name="award" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-gray-500">Members with Points</p>
                        <p class="text-xl font-semibold text-gray-900 jf-skel jf-skel-value">{{ $customers->total() }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 p-4 mb-6">
            <form method="GET" action="{{ route('loyalty.index') }}" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <x-icon name="search" size="16" />
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Customer name or mobile..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                    </div>
                </div>
                @if(filled(request('search')))
                    <a href="{{ route('loyalty.index') }}" class="btn btn-secondary btn-sm"><x-icon name="x" size="14" class="mr-1" />Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
                @endif
            </form>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full jf-table">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Mobile</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Points</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value (₹)</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($customers as $customer)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $customer->name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ \App\Support\Mobile::forDisplay($customer->mobile) }}</td>
                            <td class="px-6 py-4 text-sm text-right">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800">
                                    {{ number_format($customer->loyalty_points) }} pts
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-right text-gray-700">₹{{ number_format($customer->loyalty_points * 0.25, 2) }}</td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('loyalty.history', $customer) }}" class="btn btn-secondary btn-xs"><x-icon name="clock" size="12" class="mr-1" />History</a>
                                    <a href="{{ route('loyalty.adjust.form', $customer) }}" class="btn btn-secondary btn-xs"><x-icon name="sliders-vertical" size="12" class="mr-1" />Adjust</a>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="jf-table-empty">
                                <p class="text-lg font-medium mb-1">No loyalty members yet</p>
                                <p class="text-sm">Points are automatically earned when customers make purchases.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($customers->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">{{ $customers->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
