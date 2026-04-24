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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>
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
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5V4H2v16h5m10 0v-2a4 4 0 00-8 0v2m8 0H7"/></svg>
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
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Customer name or mobile..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                    </div>
                </div>
                @if(filled(request('search')))
                    <a href="{{ route('loyalty.index') }}" class="btn btn-secondary btn-sm"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Clear</a>
                @else
                    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
                @endif
            </form>
        </div>

        <div class="bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
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
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $customer->mobile }}</td>
                            <td class="px-6 py-4 text-sm text-right">
                                <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800">
                                    {{ number_format($customer->loyalty_points) }} pts
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-right text-gray-700">₹{{ number_format($customer->loyalty_points * 0.25, 2) }}</td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('loyalty.history', $customer) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>History</a>
                                    <a href="{{ route('loyalty.adjust.form', $customer) }}" class="btn btn-secondary btn-xs"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>Adjust</a>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
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
