<x-app-layout>
    <x-page-header class="invoices-page-header" title="Invoices" subtitle="View and manage all sales invoices">
        <x-slot:actions>
            <a href="{{ route('pos.index') }}"
               class="btn btn-success btn-sm invoices-open-pos-btn">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
                Open POS
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner invoices-index-page jf-skeleton-host is-loading">
        <x-app-alerts class="mb-6" />

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 invoices-kpi-grid">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm invoices-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Invoices</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats->total_count) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm invoices-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Today's Invoices</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats->today_count) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm invoices-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Today's Sales</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">₹{{ number_format($stats->today_total, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm invoices-kpi-card">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2v-9a2 2 0 012-2h2m10 0V6a3 3 0 10-6 0v2m6 0H7"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">This Month</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">₹{{ number_format($stats->month_total, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6 invoices-filters-card">
            <form method="GET" action="{{ route('invoices.index') }}" class="invoices-filters-form">
                <div class="invoices-search-field">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Search</label>
                    <div class="invoices-search-control relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 inline-flex items-center text-amber-500">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        </span>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Invoice number or customer..."
                               class="w-full rounded-xl border-2 border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-700 placeholder-slate-400 shadow-sm transition focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none"
                               data-suggest="invoices" autocomplete="off">
                    </div>
                </div>

                <div class="invoices-date-row">
                    <div class="invoices-date-field">
                        <label>From Date</label>
                        <input type="date" name="from_date" value="{{ request('from_date') }}"
                               class="invoices-filter-control">
                    </div>
                    <div class="invoices-date-field">
                        <label>To Date</label>
                        <input type="date" name="to_date" value="{{ request('to_date') }}"
                               class="invoices-filter-control">
                    </div>
                    <div class="invoices-filter-actions">
                        @if(request()->hasAny(['search', 'from_date', 'to_date']))
                            <a href="{{ route('invoices.index') }}" class="btn btn-secondary btn-sm invoices-filter-clear">
                                Clear
                            </a>
                        @else
                            <button type="submit" class="btn btn-primary btn-sm invoices-filter-apply">
                                Filter
                            </button>
                        @endif
                    </div>
                </div>
            </form>
        </div>

        <!-- Invoices Table -->
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden invoices-table-card invoices-table-card--main">
            <div class="overflow-x-auto invoices-table-shell">
                <table class="w-full min-w-[1100px] invoices-data-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-8 pr-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Invoice #</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                            <th class="px-7 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-7 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Subtotal</th>
                            <th class="px-7 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Discount</th>
                            <th class="px-7 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">GST</th>
                            <th class="px-7 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</th>
                            <th class="px-7 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Payment</th>
                            <th class="px-7 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-7 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($invoices as $invoice)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-8 pr-6 py-5 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono font-medium text-slate-700">{{ $invoice->invoice_number }}</span>
                                        @if(str_starts_with($invoice->invoice_number, 'REP-'))
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-100 text-sky-800 uppercase tracking-wide">
                                                Repair
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-sm text-slate-500">
                                    {{ $invoice->created_at->format('d M Y, h:i A') }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap">
                                    @if($invoice->customer)
                                        <div class="text-sm font-medium text-slate-700">{{ $invoice->customer->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $invoice->customer->mobile }}</div>
                                    @else
                                        <span class="text-slate-400">Walk-in</span>
                                    @endif
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-sm text-right text-slate-700">
                                    ₹{{ number_format($invoice->subtotal, 2) }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-sm text-right text-rose-600">
                                    {{ $invoice->discount > 0 ? '−₹' . number_format($invoice->discount, 2) : '—' }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-sm text-right text-slate-500">
                                    ₹{{ number_format($invoice->gst, 2) }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-sm text-right font-medium text-slate-800">
                                    ₹{{ number_format($invoice->total, 2) }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-center">
                                    @if($invoice->payments->count())
                                        <div class="flex flex-wrap justify-center gap-1">
                                            @foreach($invoice->payments->pluck('mode')->unique() as $mode)
                                                @php
                                                    $colors = [
                                                        'cash' => 'bg-emerald-100 text-emerald-800',
                                                        'upi' => 'bg-violet-100 text-violet-800',
                                                        'bank' => 'bg-blue-100 text-blue-800',
                                                        'old_gold' => 'bg-amber-100 text-amber-800',
                                                        'old_silver' => 'bg-gray-100 text-gray-800',
                                                        'other' => 'bg-slate-100 text-slate-800',
                                                    ];
                                                @endphp
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $colors[$mode] ?? 'bg-gray-100 text-gray-700' }}">
                                                    {{ ucfirst(str_replace('_', ' ', $mode)) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-slate-400 text-xs">—</span>
                                    @endif
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-center">
                                    @if($invoice->status === \App\Models\Invoice::STATUS_FINALIZED)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">
                                            Finalized
                                        </span>
                                    @elseif($invoice->status === \App\Models\Invoice::STATUS_CANCELLED)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-rose-100 text-rose-800">
                                            Cancelled
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                            {{ ucfirst($invoice->status) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-center invoice-actions-cell">
                                    <details class="invoice-action-menu relative inline-block text-left">
                                        <summary class="invoice-action-trigger list-none cursor-pointer inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white p-2 text-slate-600 shadow-sm hover:bg-slate-50" style="list-style:none;" title="Actions" aria-label="Invoice actions">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <circle cx="12" cy="12" r="1"></circle>
                                                <circle cx="19" cy="12" r="1"></circle>
                                                <circle cx="5" cy="12" r="1"></circle>
                                            </svg>
                                        </summary>
                                        <div class="invoice-action-list absolute right-0 mt-2 w-40 rounded-xl border border-slate-200 bg-white shadow-lg">
                                            <a href="{{ route('invoices.show', $invoice) }}" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                                View
                                            </a>
                                            <a href="{{ route('invoices.edit', $invoice) }}" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                                                </svg>
                                                Edit
                                            </a>
                                            <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                </svg>
                                                Print
                                            </a>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-6 py-12 text-center text-slate-500">
                                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No invoices found</p>
                                    <p class="text-sm">Create your first sale from the POS</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($invoices->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $invoices->links() }}
                </div>
            @endif
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const actionMenus = Array.from(document.querySelectorAll('.invoice-action-menu'));

            if (!actionMenus.length) {
                return;
            }

            const positionActionMenu = (menu) => {
                const trigger = menu.querySelector('.invoice-action-trigger');
                const list = menu.querySelector('.invoice-action-list');

                if (!trigger || !list || !menu.open) {
                    return;
                }

                // Render against viewport so dropdown is never clipped by table overflow containers.
                list.style.position = 'fixed';
                list.style.marginTop = '0';
                list.style.top = '0px';
                list.style.left = '0px';
                list.style.right = 'auto';
                list.style.bottom = 'auto';

                const triggerRect = trigger.getBoundingClientRect();
                const listRect = list.getBoundingClientRect();
                const gap = 8;
                const viewportPadding = 12;
                const canOpenDown = triggerRect.bottom + gap + listRect.height <= window.innerHeight - viewportPadding;
                const canOpenUp = triggerRect.top - gap - listRect.height >= viewportPadding;
                const openUp = !canOpenDown && canOpenUp;

                let top = openUp
                    ? triggerRect.top - listRect.height - gap
                    : triggerRect.bottom + gap;
                let left = triggerRect.right - listRect.width;

                top = Math.max(viewportPadding, Math.min(top, window.innerHeight - listRect.height - viewportPadding));
                left = Math.max(viewportPadding, Math.min(left, window.innerWidth - listRect.width - viewportPadding));

                list.style.top = `${Math.round(top)}px`;
                list.style.left = `${Math.round(left)}px`;
            };

            const closeMenu = (menu) => {
                if (!menu.open) {
                    return;
                }

                menu.removeAttribute('open');
            };

            const closeOtherMenus = (currentMenu) => {
                actionMenus.forEach((otherMenu) => {
                    if (otherMenu !== currentMenu) {
                        closeMenu(otherMenu);
                    }
                });
            };

            const repositionOpenMenus = () => {
                actionMenus.forEach((menu) => {
                    if (menu.open) {
                        positionActionMenu(menu);
                    }
                });
            };

            actionMenus.forEach((menu) => {
                menu.addEventListener('toggle', () => {
                    if (!menu.open) {
                        return;
                    }

                    closeOtherMenus(menu);
                    positionActionMenu(menu);
                });
            });

            document.addEventListener('click', (event) => {
                actionMenus.forEach((menu) => {
                    if (menu.open && !menu.contains(event.target)) {
                        closeMenu(menu);
                    }
                });
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    actionMenus.forEach((menu) => closeMenu(menu));
                }
            });

            window.addEventListener('resize', repositionOpenMenus);
            window.addEventListener('scroll', repositionOpenMenus, true);
        });
    </script>
</x-app-layout>
