<x-app-layout>
    @php
        $invoiceFilterKeys = ['search', 'from_date', 'to_date', 'status', 'payment_mode'];
        $invoiceStatusOptions = [
            \App\Models\Invoice::STATUS_FINALIZED => 'Finalized',
            \App\Models\Invoice::STATUS_DRAFT => 'Draft',
            \App\Models\Invoice::STATUS_CANCELLED => 'Cancelled',
        ];
        $invoicePaymentModeOptions = [
            \App\Models\InvoicePayment::MODE_CASH => 'Cash',
            \App\Models\InvoicePayment::MODE_UPI => 'UPI',
            \App\Models\InvoicePayment::MODE_BANK => 'Bank',
            \App\Models\InvoicePayment::MODE_WALLET => 'Wallet',
            \App\Models\InvoicePayment::MODE_OLD_GOLD => 'Old gold',
            \App\Models\InvoicePayment::MODE_OLD_SILVER => 'Old silver',
            \App\Models\InvoicePayment::MODE_OTHER => 'Other',
            \App\Models\InvoicePayment::MODE_EMI => 'EMI',
            \App\Models\InvoicePayment::MODE_SCHEME => 'Scheme',
        ];
        $hasInvoiceFilters = request()->hasAny($invoiceFilterKeys);
        $activeInvoiceFilterCount = collect($invoiceFilterKeys)
            ->filter(fn ($key) => request()->filled($key))
            ->count();
        $invoiceResultTotal = $invoices->total();
        $invoiceResultStart = $invoiceResultTotal ? $invoices->firstItem() : 0;
        $invoiceResultEnd = $invoiceResultTotal ? $invoices->lastItem() : 0;
        // Repair bills share the INV- prefix, so flag them by the repairs->invoice_id
        // link. Preloaded once for this page to avoid a per-row query.
        $repairInvoiceIds = \App\Models\Repair::where('shop_id', auth()->user()->shop_id)
            ->whereIn('invoice_id', $invoices->pluck('id')->filter()->all())
            ->pluck('invoice_id')->flip();
        $invoiceDateSummary = null;
        $invoiceStatusSummary = $invoiceStatusOptions[request('status')] ?? null;
        $invoicePaymentModeSummary = $invoicePaymentModeOptions[request('payment_mode')] ?? null;

        if (request('from_date') && request('to_date')) {
            $invoiceDateSummary = request('from_date') . ' to ' . request('to_date');
        } elseif (request('from_date')) {
            $invoiceDateSummary = 'From ' . request('from_date');
        } elseif (request('to_date')) {
            $invoiceDateSummary = 'Until ' . request('to_date');
        }
    @endphp

    @php
        // Pages that open from here since they left the sidebar, each shown by
        // its own permission: Historical Sales (a retailer module) and Returns /
        // Exchange. They stand in the navigation row under the header; the
        // header keeps the invoice actions.
        $showHistoricalSales = (bool) auth()->user()->shop?->isRetailer() && auth()->user()->can('historical.view');
        $showReturns = auth()->user()->can('returns.view');
    @endphp
    <x-page-header class="invoices-page-header" title="Invoices" subtitle="View and manage all sales invoices">
        <x-slot:actions>
            @can('sales.pos')
            <a href="{{ route('pos.index') }}"
               class="btn btn-success btn-sm invoices-open-pos-btn">
                <x-icon name="shopping-cart" class="w-4 h-4 mr-2" />
                Open POS
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner invoices-index-page jf-skeleton-host is-loading" data-list-workspace
         x-data="{ invoiceFiltersOpen: false }"
         :class="{ 'invoices-filters-open': invoiceFiltersOpen }"
         x-effect="document.body.style.overflow = invoiceFiltersOpen ? 'hidden' : ''"
         @keydown.escape.window="invoiceFiltersOpen = false">

        @php
            $canModifyInvoices = auth()->user()->can('sales.void')
                || auth()->user()->can('sales.pos')
                || auth()->user()->can('sales.create');
        @endphp
        @unless($canModifyInvoices)
            @include('partials.view-only-banner', ['permission' => 'sales.void', 'message' => 'invoice management'])
        @endunless

        {{-- The Invoices page's navigation row: this page and the pages that open from it.
             They are pages of their own, so these are links and the current one is marked;
             a role with nowhere to go from here gets no row. --}}
        @if($showHistoricalSales || $showReturns)
        <nav class="invoices-page-nav" aria-label="Invoices navigation">
            <a href="{{ route('invoices.index') }}" class="invoices-page-nav-link" aria-current="page">
                <x-icon name="file-text" size="14" />
                Invoices
            </a>
            @if($showHistoricalSales)
            <a href="{{ route('historical.index') }}" class="invoices-page-nav-link">
                <x-icon name="history" size="14" />
                Historical Sales
            </a>
            @endif
            @if($showReturns)
            <a href="{{ route('returns.index') }}" class="invoices-page-nav-link">
                <x-icon name="corner-up-left" size="14" />
                Returns / Exchange
            </a>
            @endif
        </nav>
        @endif

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6 invoices-kpi-grid">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 invoices-kpi-card invoices-kpi-card--count">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <x-icon name="file-text" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Total Invoices</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats->total_count) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 invoices-kpi-card invoices-kpi-card--count">
                <div class="flex items-center gap-3">
                    <div class="bg-slate-100 text-slate-700 rounded-xl p-2.5">
                        <x-icon name="calendar" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Today's Invoices</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">{{ number_format($stats->today_count) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 invoices-kpi-card invoices-kpi-card--money">
                <div class="flex items-center gap-3">
                    <div class="bg-emerald-100 text-emerald-700 rounded-xl p-2.5">
                        <x-icon name="circle-dollar-sign" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Today's Sales</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">₹{{ number_format($stats->today_total, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 invoices-kpi-card invoices-kpi-card--money">
                <div class="flex items-center gap-3">
                    <div class="bg-amber-100 text-amber-700 rounded-xl p-2.5">
                        <x-icon name="briefcase" class="w-5 h-5" />
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">This Month</p>
                        <p class="text-2xl font-semibold text-slate-900 jf-skel jf-skel-value">₹{{ number_format($stats->month_total, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoices Table -->
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden invoices-table-card invoices-table-card--main" data-list-frame>
            <div class="invoices-register-head">
                <div class="invoices-register-titleblock">
                    <h2>Invoice register</h2>
                    <p>
                        @if($invoiceResultTotal)
                            Showing {{ number_format($invoiceResultStart) }}-{{ number_format($invoiceResultEnd) }} of {{ number_format($invoiceResultTotal) }}
                        @else
                            No invoices found
                        @endif
                    </p>
                </div>

                <form method="GET" action="{{ route('invoices.index') }}" class="invoices-register-toolbar">
                    <label class="invoices-filter-field invoices-filter-field--search">
                        <span>Search</span>
                        <span class="invoices-input-wrap">
                            <x-icon name="search" size="16" class="invoices-input-icon" />
                            <input type="text"
                                   name="search"
                                   value="{{ request('search') }}"
                                   placeholder="Invoice number or customer"
                                   class="invoices-control has-icon"
                                   data-suggest="invoices"
                                   autocomplete="off">
                        </span>
                    </label>

                    <label class="invoices-filter-field">
                        <span>From</span>
                        <input type="date" name="from_date" value="{{ request('from_date') }}" class="invoices-control">
                    </label>

                    <label class="invoices-filter-field">
                        <span>To</span>
                        <input type="date" name="to_date" value="{{ request('to_date') }}" class="invoices-control">
                    </label>

                    <label class="invoices-filter-field">
                        <span>Status</span>
                        <select name="status" class="invoices-control">
                            <option value="">All statuses</option>
                            @foreach($invoiceStatusOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="invoices-filter-field">
                        <span>Payment</span>
                        <select name="payment_mode" class="invoices-control">
                            <option value="">All payments</option>
                            @foreach($invoicePaymentModeOptions as $value => $label)
                                <option value="{{ $value }}" @selected(request('payment_mode') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <span class="invoices-toolbar-actions">
                        <button type="submit" class="invoices-filter-apply">Apply</button>
                        @if($hasInvoiceFilters)
                            <a href="{{ route('invoices.index') }}" class="invoices-filter-clear">Clear</a>
                        @endif
                    </span>
                </form>
            </div>

            <div class="invoices-mobile-tools">
                <form method="GET" action="{{ route('invoices.index') }}" class="invoices-mobile-search">
                    <input type="hidden" name="from_date" value="{{ request('from_date') }}">
                    <input type="hidden" name="to_date" value="{{ request('to_date') }}">
                    <input type="hidden" name="status" value="{{ request('status') }}">
                    <input type="hidden" name="payment_mode" value="{{ request('payment_mode') }}">
                    <label class="invoices-input-wrap">
                        <x-icon name="search" size="16" class="invoices-input-icon" />
                        <input type="text"
                               name="search"
                               value="{{ request('search') }}"
                               placeholder="Search invoices"
                               class="invoices-control has-icon"
                               data-suggest="invoices"
                               autocomplete="off"
                               aria-label="Search invoices">
                    </label>
                    <button type="submit" class="invoices-filter-apply invoices-mobile-search-btn">Search</button>
                </form>

                <div class="invoices-mobile-actions">
                    <button type="button" class="invoices-filter-trigger" @click="invoiceFiltersOpen = true">
                        <x-icon name="list-filter" size="16" />
                        <span>Filters</span>
                        @if($activeInvoiceFilterCount)
                            <span class="invoices-filter-count">{{ $activeInvoiceFilterCount }}</span>
                        @endif
                    </button>
                    @if($hasInvoiceFilters)
                        <a href="{{ route('invoices.index') }}" class="invoices-filter-clear">Clear</a>
                    @endif
                </div>
            </div>

            @if($hasInvoiceFilters)
                <div class="invoices-active-filters" aria-label="Active invoice filters">
                    @if(request('search'))
                        <span class="invoices-chip">Search <strong>{{ request('search') }}</strong></span>
                    @endif
                    @if($invoiceDateSummary)
                        <span class="invoices-chip">Date <strong>{{ $invoiceDateSummary }}</strong></span>
                    @endif
                    @if($invoiceStatusSummary)
                        <span class="invoices-chip">Status <strong>{{ $invoiceStatusSummary }}</strong></span>
                    @endif
                    @if($invoicePaymentModeSummary)
                        <span class="invoices-chip">Payment <strong>{{ $invoicePaymentModeSummary }}</strong></span>
                    @endif
                    <a href="{{ route('invoices.index') }}" class="invoices-chip invoices-chip-clear">Clear all</a>
                </div>
            @endif

            <div class="overflow-x-auto invoices-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Invoice records">
                <table class="w-full jf-table jf-table--sticky-end">
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
                                        @if($repairInvoiceIds->has($invoice->id) || str_starts_with($invoice->invoice_number, 'REP-'))
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-100 text-sky-800 uppercase tracking-wide">
                                                Repair
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-sm text-slate-500">
                                    {{ $invoice->created_at->format('d M Y') }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap">
                                    @if($invoice->customer)
                                        <div class="text-sm font-medium text-slate-700">{{ $invoice->customer->name }}</div>
                                        <div class="text-xs text-slate-500">{{ \App\Support\Mobile::forDisplay($invoice->customer->mobile) }}</div>
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
                                                        'wallet' => 'bg-cyan-100 text-cyan-800',
                                                        'old_gold' => 'bg-amber-100 text-amber-800',
                                                        'old_silver' => 'bg-gray-100 text-gray-800',
                                                        'emi' => 'bg-orange-100 text-orange-800',
                                                        'scheme' => 'bg-teal-100 text-teal-800',
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
                                    <div class="invoice-inline-actions">
                                        <a href="{{ route('invoices.show', $invoice) }}"
                                          
                                           aria-label="View invoice"
                                           class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                        @can('sales.void')
                                        <a href="{{ route('invoices.edit', $invoice) }}"
                                          
                                           aria-label="Edit invoice"
                                           class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>
                                        @endcan
                                        <a href="{{ route('invoices.print', $invoice) }}"
                                           target="_blank"
                                          
                                           aria-label="Print invoice"
                                           class="jf-row-action jf-row-action--neutral" data-tip="Print"><x-icon name="printer" size="16" /><span class="jf-sr-only">Print</span></a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    <x-icon name="file-text" class="w-12 h-12 mx-auto text-gray-300 mb-4" />
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No invoices found</p>
                                    <p class="text-sm">Create your first sale from the POS</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="invoices-mobile-cards">
                @forelse($invoices as $invoice)
                    @php
                        $paymentModes = $invoice->payments->pluck('mode')->unique();
                        $isRepairInvoice = $repairInvoiceIds->has($invoice->id) || str_starts_with($invoice->invoice_number, 'REP-');
                    @endphp
                    <article class="invoices-mobile-card">
                        <div class="invoices-mobile-card__top">
                            <div class="invoices-mobile-card__identity">
                                <span class="invoices-mobile-invoice">{{ $invoice->invoice_number }}</span>
                                <span class="invoices-mobile-sub">{{ $invoice->created_at->format('d M Y') }}</span>
                            </div>
                            <div class="invoices-mobile-status-wrap">
                                @if($isRepairInvoice)
                                    <span class="invoices-badge invoices-badge--info">Repair</span>
                                @endif
                                @if($invoice->status === \App\Models\Invoice::STATUS_FINALIZED)
                                    <span class="invoices-badge invoices-badge--success">Finalized</span>
                                @elseif($invoice->status === \App\Models\Invoice::STATUS_CANCELLED)
                                    <span class="invoices-badge invoices-badge--danger">Cancelled</span>
                                @else
                                    <span class="invoices-badge invoices-badge--warning">{{ ucfirst($invoice->status) }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="invoices-mobile-customer">
                            <span>Customer</span>
                            <strong>{{ $invoice->customer?->name ?: 'Walk-in' }}</strong>
                            @if($invoice->customer?->mobile)
                                <small>{{ \App\Support\Mobile::forDisplay($invoice->customer->mobile) }}</small>
                            @endif
                        </div>

                        <div class="invoices-mobile-metrics">
                            <div>
                                <span>Total</span>
                                <strong>₹{{ number_format($invoice->total, 2) }}</strong>
                            </div>
                            <div>
                                <span>GST</span>
                                <strong>₹{{ number_format($invoice->gst, 2) }}</strong>
                            </div>
                            <div>
                                <span>Discount</span>
                                <strong class="{{ $invoice->discount > 0 ? 'is-danger' : '' }}">
                                    {{ $invoice->discount > 0 ? '−₹' . number_format($invoice->discount, 2) : '—' }}
                                </strong>
                            </div>
                            <div>
                                <span>Payment</span>
                                <strong>
                                    @if($paymentModes->count())
                                        {{ $paymentModes->map(fn ($mode) => ucfirst(str_replace('_', ' ', $mode)))->join(', ') }}
                                    @else
                                        —
                                    @endif
                                </strong>
                            </div>
                        </div>

                        <div class="invoices-mobile-actions-row">
                            <a href="{{ route('invoices.show', $invoice) }}" class="invoices-row-action invoices-row-action--primary">
                                View
                            </a>
                            @can('sales.void')
                                <a href="{{ route('invoices.edit', $invoice) }}" class="invoices-row-action">
                                    Edit
                                </a>
                            @endcan
                            <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="invoices-row-action invoices-row-action--print">
                                Print
                            </a>
                        </div>
                    </article>
                @empty
                    <div class="invoices-mobile-empty">
                        <strong>No invoices found</strong>
                        <span>{{ $hasInvoiceFilters ? 'Try clearing the filters to see all invoices.' : 'Create your first sale from the POS.' }}</span>
                    </div>
                @endforelse
            </div>

            @if($invoices->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $invoices->links() }}
                </div>
            @endif

            <div x-show="invoiceFiltersOpen" x-transition.opacity x-cloak class="invoices-filter-backdrop" @click="invoiceFiltersOpen = false"></div>
            <aside x-show="invoiceFiltersOpen" x-transition x-cloak class="invoices-filter-sheet" role="dialog" aria-modal="true" aria-label="Invoice filters">
                <div class="invoices-filter-sheet__head">
                    <h3>Filters</h3>
                    <button type="button" class="invoices-filter-close" @click="invoiceFiltersOpen = false" aria-label="Close filters">
                        <x-icon name="x" size="18" />
                    </button>
                </div>

                <form method="GET" action="{{ route('invoices.index') }}">
                    <input type="hidden" name="search" value="{{ request('search') }}">
                    <div class="invoices-filter-sheet__body">
                        <label class="invoices-filter-field">
                            <span>From date</span>
                            <input type="date" name="from_date" value="{{ request('from_date') }}" class="invoices-control">
                        </label>

                        <label class="invoices-filter-field">
                            <span>To date</span>
                            <input type="date" name="to_date" value="{{ request('to_date') }}" class="invoices-control">
                        </label>

                        <label class="invoices-filter-field">
                            <span>Status</span>
                            <select name="status" class="invoices-control">
                                <option value="">All statuses</option>
                                @foreach($invoiceStatusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>

                        <label class="invoices-filter-field">
                            <span>Payment mode</span>
                            <select name="payment_mode" class="invoices-control">
                                <option value="">All payments</option>
                                @foreach($invoicePaymentModeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(request('payment_mode') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="invoices-filter-sheet__foot">
                        <a href="{{ route('invoices.index') }}" class="invoices-filter-clear">Clear</a>
                        <button type="submit" class="invoices-filter-apply">Apply filters</button>
                    </div>
                </form>
            </aside>
        </div>
    </div>

    <script>
        (function () {
            const resetInvoiceScrollLock = () => {
                if (document.querySelector('.invoices-index-page')) {
                    document.body.style.overflow = '';
                }
            };

            document.addEventListener('turbo:before-cache', resetInvoiceScrollLock);
            document.addEventListener('turbo:before-render', resetInvoiceScrollLock);
            window.addEventListener('beforeunload', resetInvoiceScrollLock);
        })();
    </script>
</x-app-layout>
