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
        // Historical Sales stays Retail-only and uses its own view permission.
        $showHistoricalSales = (bool) auth()->user()->shop?->isRetailer() && auth()->user()->can('historical.view');
    @endphp
    <x-page-header class="invoices-page-header" title="Invoices" subtitle="View and manage all sales invoices">
        <x-slot:actions>
            @if($showHistoricalSales)
            <a href="{{ route('historical.index') }}"
               class="btn btn-success btn-sm invoices-historical-btn">
                <x-icon name="history" class="w-4 h-4 mr-2" />
                Historical Sales
            </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner invoices-index-page jf-skeleton-host is-loading" data-list-workspace>

        @php
            $canModifyInvoices = auth()->user()->can('sales.void')
                || auth()->user()->can('sales.pos')
                || auth()->user()->can('sales.create');
        @endphp
        @unless($canModifyInvoices)
            @include('partials.view-only-banner', ['permission' => 'sales.void', 'message' => 'invoice management'])
        @endunless

        <!-- Invoices Table -->
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden invoices-table-card invoices-table-card--main" data-list-frame>
            <div class="invoices-register-head">
                <div class="invoices-register-titleblock">
                    <h2>Invoice register</h2>
                    <dl class="customers-directory-summary" aria-label="Invoice summary">
                        <div><dt>Total Invoices</dt><dd>{{ number_format($stats->total_count) }}</dd></div>
                        <div><dt>Today's Invoices</dt><dd>{{ number_format($stats->today_count) }}</dd></div>
                    </dl>
                </div>

                <x-responsive-filter-panel id="invoices-filter-panel" label="Invoice filters">
                    <form method="GET" action="{{ route('invoices.index') }}" class="invoices-register-toolbar customers-directory-controls"
                          x-data="{ searchOpen: {{ request()->filled('search') ? 'true' : 'false' }} || window.innerWidth <= 768 }"
                          @resize.window="if (window.innerWidth <= 768) searchOpen = true">
                        <div class="customers-directory-search" :class="{ 'is-search-open': searchOpen }"
                             @keydown.escape.stop.prevent="const panel = $el.closest('dialog'); if (panel?.matches(':modal')) panel.close(); else { searchOpen = false; $refs.searchToggle.focus(); }">
                            <button type="button" data-invoice-search-toggle x-cloak x-ref="searchToggle"
                                    class="customers-directory-search-toggle" aria-label="Search invoices"
                                    aria-controls="invoices-search-panel" aria-expanded="{{ request()->filled('search') ? 'true' : 'false' }}"
                                    :aria-expanded="searchOpen" :aria-label="searchOpen ? 'Hide invoice search' : 'Search invoices'"
                                    :title="searchOpen ? 'Hide search' : 'Search invoices'"
                                    @click="searchOpen = !searchOpen; if (searchOpen) $nextTick(() => $refs.searchInput.focus())">
                                <x-icon name="search" size="18" />
                            </button>
                            <div id="invoices-search-panel" class="customers-directory-search-panel" x-show="searchOpen">
                                <label for="invoices-search" class="jf-sr-only">Search invoices</label>
                                <input id="invoices-search" type="search" x-ref="searchInput" name="search"
                                       value="{{ request('search') }}" placeholder="Invoice number or customer"
                                       data-suggest="invoices" autocomplete="off" enterkeyhint="search">
                            </div>
                        </div>
                        <div class="invoices-date-range">
                            <div class="invoices-filter-field">
                                <label for="invoices-from-date">From</label>
                                <input id="invoices-from-date" type="date" name="from_date" value="{{ request('from_date') }}" class="invoices-control">
                            </div>
                            <div class="invoices-filter-field">
                                <label for="invoices-to-date">To</label>
                                <input id="invoices-to-date" type="date" name="to_date" value="{{ request('to_date') }}" class="invoices-control">
                            </div>
                        </div>
                        <div class="customers-directory-status" role="group" aria-label="Invoice status"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="invoices-status" class="jf-sr-only">Status</label>
                            <select id="invoices-status" name="status">
                                <option value="">All statuses</option>
                                @foreach($invoiceStatusOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="customers-directory-status" role="group" aria-label="Payment mode"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="invoices-payment-mode" class="jf-sr-only">Payment mode</label>
                            <select id="invoices-payment-mode" name="payment_mode">
                                <option value="">All payments</option>
                                @foreach($invoicePaymentModeOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(request('payment_mode') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <span class="invoices-toolbar-actions">
                            <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                            @if($hasInvoiceFilters)
                                <a href="{{ route('invoices.index') }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                            @endif
                        </span>
                    </form>
                </x-responsive-filter-panel>
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

            <div class="overflow-x-auto ui-table-shell invoices-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Invoice records">
                <table class="w-full jf-table jf-table--sticky-end invoices-data-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
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
                                <td class="jf-table-index">{{ $invoices->firstItem() + $loop->index }}</td>
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
                                <td colspan="11" class="jf-table-empty">
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
                                <span class="invoices-mobile-invoice"><span class="jf-row-number"><span class="jf-sr-only">Row </span>#{{ $invoices->firstItem() + $loop->index }}</span>{{ $invoice->invoice_number }}</span>
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

        </div>
    </div>
</x-app-layout>
