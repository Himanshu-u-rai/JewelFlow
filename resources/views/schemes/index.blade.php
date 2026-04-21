<x-app-layout>
    <x-page-header class="schemes-page-header ops-treatment-header">
        <div>
            <h1 class="page-title">Schemes & Offers</h1>
            <p class="text-sm text-gray-600 mt-1">Gold savings schemes, festival sales & discount offers</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('schemes.create') }}"
               class="inline-flex items-center px-4 py-2 rounded-full transition-colors text-sm font-semibold shadow-sm schemes-create-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create Scheme
            </a>
        </div>
    </x-page-header>

    <div class="content-inner ops-treatment-page">
        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-4 mb-6">{{ session('success') }}</div>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 0v8m0 5v-1"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Gold Savings</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ $stats->gold_savings_count ?? 0 }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm ops-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-rose-100 text-rose-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Offers / Sales</p>
                        <p class="text-2xl font-semibold text-slate-900">{{ $stats->offers_count ?? 0 }}</p>
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
                        <p class="text-2xl font-semibold text-slate-900">{{ $stats->active_count ?? 0 }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('schemes.index') }}" class="flex flex-wrap gap-3 items-end schemes-filter-form" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Type</label>
                    <select name="type" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500 schemes-filter-control">
                        <option value="">All Types</option>
                        <option value="gold_savings" {{ request('type') === 'gold_savings' ? 'selected' : '' }}>Gold Savings</option>
                        <option value="festival_sale" {{ request('type') === 'festival_sale' ? 'selected' : '' }}>Festival Sale</option>
                        <option value="discount_offer" {{ request('type') === 'discount_offer' ? 'selected' : '' }}>Discount Offer</option>
                    </select>
                </div>
                @if(filled(request('type')))
                    <a href="{{ route('schemes.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 schemes-filter-btn">
                        Clear
                    </a>
                @else
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 schemes-filter-btn">
                        Filter
                    </button>
                @endif
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Scheme</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Period</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Enrollments</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($schemes as $scheme)
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-sm font-semibold text-slate-900">{{ $scheme->name }}</div>
                                @if($scheme->description)
                                    <div class="text-xs text-slate-500 truncate max-w-xs">{{ $scheme->description }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $typeColors = ['gold_savings' => 'bg-amber-100 text-amber-800', 'festival_sale' => 'bg-rose-100 text-rose-800', 'discount_offer' => 'bg-blue-100 text-blue-800'];
                                    $typeLabels = ['gold_savings' => 'Gold Savings', 'festival_sale' => 'Festival Sale', 'discount_offer' => 'Discount'];
                                @endphp
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $typeColors[$scheme->type] ?? 'bg-gray-100 text-gray-800' }}">
                                    {{ $typeLabels[$scheme->type] ?? $scheme->type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-500">
                                {{ $scheme->start_date->format('d M Y') }}
                                @if($scheme->end_date)
                                    — {{ $scheme->end_date->format('d M Y') }}
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center text-sm font-medium text-slate-700">{{ $scheme->enrollments_count ?? 0 }}</td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $scheme->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $scheme->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <a href="{{ route('schemes.show', $scheme) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View</a>
                                    @if($scheme->isGoldSavings())
                                        <a href="{{ route('schemes.enroll.form', $scheme) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>Enroll</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                                <p class="text-lg font-semibold mb-1 text-slate-700">No schemes yet</p>
                                <p class="text-sm">Create a gold savings scheme or discount offer to get started.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($schemes->hasPages())
                <div class="px-6 py-4 border-t border-slate-200">{{ $schemes->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
