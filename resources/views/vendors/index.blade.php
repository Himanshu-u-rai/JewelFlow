<x-app-layout>
    <x-page-header class="vendors-page-header ops-treatment-header" title="Vendors / Suppliers" subtitle="Manage your jewellery suppliers and vendors">
        <x-slot:actions>
            <a href="{{ route('vendors.create') }}" class="btn btn-success btn-sm vendors-add-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <span class="vendors-add-label-full">Add Vendor</span>
                <span class="vendors-add-label-short">Add</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Vendors</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats->total_count ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Active</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats->active_count ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">With GST</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ number_format($stats->gst_count ?? 0) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('vendors.index') }}" class="vendors-filter-form flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="vendors-search-field flex-1 min-w-[220px]">
                    <label class="vendors-search-label block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input
                            type="text"
                            name="search"
                            value="{{ request('search') }}"
                            placeholder="Name, contact, mobile, GST..."
                            class="vendors-search-input w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none"
                            data-suggest="vendors"
                            autocomplete="off"
                        >
                    </div>
                </div>
                <div class="vendors-status-field">
                    <label class="vendors-status-label block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Status</label>
                    <select name="status" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div class="vendors-action-field flex gap-2">
                    @if(request()->hasAny(['search', 'status']))
                        <a href="{{ route('vendors.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                            Clear
                        </a>
                    @else
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                            Search
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Vendor</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">GST</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-400">
                        @forelse($vendors as $vendor)
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-slate-900">{{ $vendor->name }}</div>
                                    @if($vendor->city)
                                        <div class="text-xs text-slate-500">{{ $vendor->city }}{{ $vendor->state ? ', ' . $vendor->state : '' }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($vendor->contact_person)
                                        <div class="text-sm text-slate-700">{{ $vendor->contact_person }}</div>
                                    @endif
                                    @if($vendor->mobile)
                                        <div class="text-xs text-slate-500">{{ $vendor->mobile }}</div>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                    {{ $vendor->gst_number ?? '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $vendor->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="{{ route('vendors.show', $vendor) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                                        <a href="{{ route('vendors.edit', $vendor) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="border-b border-slate-400">
                                <td colspan="5" class="px-6 py-12 text-center text-slate-500">
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No vendors found</p>
                                    <p class="text-sm">Add your first vendor to get started.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($vendors->hasPages())
                <div class="px-6 py-4 border-t border-slate-200">{{ $vendors->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
