<x-app-layout>
    <x-page-header title="Stock Purchases" subtitle="Record and manage incoming stock from suppliers">
        <x-slot:actions>
            <a href="{{ route('inventory.purchases.create') }}" class="btn btn-success btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                </svg>
                New Purchase
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-6" />

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Purchases</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats->total_confirmed ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">This Month</p>
                        <p class="text-2xl font-semibold text-slate-900">₹{{ number_format($stats->month_amount ?? 0, 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-blue-100 text-blue-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Items This Month</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($monthItems ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="bg-orange-100 text-orange-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Drafts Pending</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats->drafts_pending ?? 0) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6">
            <form method="GET" action="{{ route('inventory.purchases.index') }}" class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Purchase #, invoice #, supplier..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Status</label>
                    <select name="status" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All</option>
                        <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Vendor</label>
                    <select name="vendor_id" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All Vendors</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor->id }}" {{ request('vendor_id') == $vendor->id ? 'selected' : '' }}>{{ $vendor->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">From</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">To</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <div class="flex gap-2">
                    @if(request()->hasAny(['search','status','vendor_id','date_from','date_to']))
                        <a href="{{ route('inventory.purchases.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Clear</a>
                    @else
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Search</button>
                    @endif
                </div>
            </form>
        </div>

        {{-- Table --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Purchase #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Supplier</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Invoice #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Date</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Items</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Total</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($purchases as $purchase)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3">
                                <a href="{{ route('inventory.purchases.show', $purchase) }}" class="font-mono text-sm font-semibold text-amber-600 hover:underline">{{ $purchase->purchase_number }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-700">{{ $purchase->supplier_label }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $purchase->invoice_number ?: '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-600">{{ $purchase->purchase_date->format('d M Y') }}</td>
                            <td class="px-4 py-3 text-right text-sm text-slate-700">{{ $purchase->lines_count }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-slate-900">₹{{ number_format($purchase->total_amount, 2) }}</td>
                            <td class="px-4 py-3 text-center">
                                @if($purchase->isDraft())
                                    <span class="inline-flex items-center rounded-full bg-orange-100 px-2.5 py-0.5 text-xs font-semibold text-orange-700">Draft</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Confirmed</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('inventory.purchases.show', $purchase) }}" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">View</a>
                                    @if($purchase->isDraft())
                                        <a href="{{ route('inventory.purchases.edit', $purchase) }}" class="inline-flex items-center gap-1 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700 shadow-sm hover:bg-amber-100">Edit</a>
                                        <form method="POST" action="{{ route('inventory.purchases.destroy', $purchase) }}" onsubmit="return confirm('Delete this draft purchase?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-600 shadow-sm hover:bg-red-100">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-slate-400">
                                <svg class="mx-auto mb-3 w-12 h-12 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <p class="text-sm font-medium text-slate-500">No purchases found</p>
                                <p class="text-xs text-slate-400 mt-1">Create your first stock purchase to get started</p>
                                <a href="{{ route('inventory.purchases.create') }}" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">New Purchase</a>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($purchases->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $purchases->links() }}
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
