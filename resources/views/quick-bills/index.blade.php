<x-app-layout>
    <x-page-header
        class="ops-treatment-header"
        title="Quick Bill Generator"
        subtitle="Flexible jewellery bills with a separate mini register for this shop."
    >
        <x-slot:actions>
            <a href="{{ route('quick-bills.create') }}"
               class="btn btn-success btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14m7-7H5"/>
                </svg>
                New Quick Bill
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner max-w-[1380px] mx-auto ops-treatment-page jf-skeleton-host is-loading">
        <x-app-alerts class="mb-6" />

        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:col-span-1 ops-kpi-card">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Bills</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats['total_count']) }}</p>
                <p class="mt-1 text-xs text-slate-500">Quick bill register entries</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:col-span-1 ops-kpi-card">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Issued</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats['issued_count']) }}</p>
                <p class="mt-1 text-xs text-slate-500">Live quick bills</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:col-span-1 ops-kpi-card">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Drafts</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats['draft_count']) }}</p>
                <p class="mt-1 text-xs text-slate-500">Editable working bills</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:col-span-1 ops-kpi-card">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Today Value</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 jf-skel jf-skel-value">₹{{ number_format((float) $stats['today_total'], 2) }}</p>
                <p class="mt-1 text-xs text-slate-500">Non-void bills dated today</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:col-span-1 ops-kpi-card">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Outstanding</p>
                <p class="mt-2 text-3xl font-semibold text-slate-900 jf-skel jf-skel-value">₹{{ number_format((float) $stats['outstanding_total'], 2) }}</p>
                <p class="mt-1 text-xs text-slate-500">Due inside quick bills only</p>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('quick-bills.index') }}" class="flex flex-wrap gap-3 items-end" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="flex-1 min-w-[220px]">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Bill number, customer, mobile..." class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none" data-suggest="quick-bills" autocomplete="off">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Status</label>
                    <select name="status" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                        <option value="">All</option>
                        <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                        <option value="issued" @selected(request('status') === 'issued')>Issued</option>
                        <option value="void" @selected(request('status') === 'void')>Void</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">From</label>
                    <input type="date" name="from_date" value="{{ request('from_date') }}" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">To</label>
                    <input type="date" name="to_date" value="{{ request('to_date') }}" class="rounded-xl border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-amber-500">
                </div>
                <div class="flex gap-2">
                    @if(request()->hasAny(['search', 'status', 'from_date', 'to_date']))
                        <a href="{{ route('quick-bills.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                            Clear
                        </a>
                    @else
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                            Filter
                        </button>
                    @endif
                </div>
            </form>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-[1040px] w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Bill</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Bill Date</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Due</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-7 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse($quickBills as $quickBill)
                            <tr class="transition hover:bg-slate-50/60">
                                <td class="pl-8 pr-6 py-5">
                                    <div class="font-mono text-sm font-medium text-slate-700">{{ $quickBill->bill_number }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $quickBill->creator?->mobile_number ?? 'Quick bill register' }}</div>
                                </td>
                                <td class="px-7 py-5">
                                    <div class="text-sm font-medium text-slate-700">{{ $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in Customer') }}</div>
                                    <div class="mt-1 text-xs text-slate-500">{{ $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: 'No mobile') }}</div>
                                </td>
                                <td class="px-7 py-5 text-sm text-slate-700">
                                    {{ $quickBill->bill_date?->format('d M Y') }}
                                </td>
                                <td class="px-7 py-5 text-sm font-medium text-slate-800">
                                    ₹{{ number_format((float) $quickBill->total_amount, 2) }}
                                </td>
                                <td class="px-7 py-5 text-sm {{ (float) $quickBill->due_amount > 0 ? 'text-amber-700 font-medium' : 'text-emerald-700 font-medium' }}">
                                    ₹{{ number_format((float) $quickBill->due_amount, 2) }}
                                </td>
                                <td class="px-7 py-5">
                                    @php
                                        $badge = match($quickBill->status) {
                                            'issued' => 'bg-emerald-100 text-emerald-800',
                                            'void' => 'bg-rose-100 text-rose-800',
                                            default => 'bg-amber-100 text-amber-800',
                                        };
                                    @endphp
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $badge }}">
                                        {{ ucfirst($quickBill->status) }}
                                    </span>
                                </td>
                                <td class="px-7 py-5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <a href="{{ route('quick-bills.show', $quickBill) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            View
                                        </a>
                                        <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                                            Print
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-14 text-center">
                                    <x-empty-state
                                        title="No quick bills yet"
                                        description="Create a small flexible jewellery bill without affecting the main invoice system."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($quickBills->hasPages())
                <div class="border-t border-slate-200 px-6 py-4">
                    {{ $quickBills->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
