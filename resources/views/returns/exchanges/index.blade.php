@php
    $statusLabel = fn ($status) => match ($status) {
        'pending_approval' => 'Pending Approval',
        'settled'          => 'Settled',
        'draft'            => 'Draft',
        'submitted'        => 'Submitted',
        'cancelled'        => 'Cancelled',
        default            => ucfirst(str_replace('_', ' ', (string) $status)),
    };
    $statusTone = fn ($status) => match ($status) {
        'pending_approval' => 'warning',
        'settled'          => 'success',
        'cancelled'        => 'danger',
        default            => 'neutral',
    };
    $hasFilters = request()->hasAny(['status', 'from', 'to', 'customer'])
        && (request()->filled('status') || request()->filled('from') || request()->filled('to') || request()->filled('customer'));
    $searchActive = request()->filled('customer');
    $netFlow = (float) $todayNetFlow;
@endphp

<x-app-layout>
    <x-page-header
        class="customers-page-header customers-directory-header exchanges-page-header"
        title="Exchanges"
        subtitle="Track items exchanged by customers">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="btn btn-secondary btn-sm" aria-label="Back to invoices">
                <x-icon name="arrow-left" size="15" />
                <span>Back to Invoices</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <style>
        .exchanges-index-page .exchanges-date-range { display: flex; flex-wrap: wrap; gap: 8px; min-width: 0; max-width: 100%; }
        .exchanges-index-page .exchanges-date-field { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .exchanges-index-page .exchanges-date-field label { font-size: 11px; font-weight: 600; color: #64748b; }
        .exchanges-index-page .exchanges-date-field input { min-height: 36px; max-width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; font-size: 13px; background: #fff; }
        .exchanges-index-page .exchanges-sub { display: block; font-size: 12px; color: #64748b; }
        .exchanges-index-page .exchanges-muted { color: #94a3b8; }
        .exchanges-index-page .exchanges-status { display: inline-block; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; background: #f1f5f9; color: #475569; }
        .exchanges-index-page .exchanges-status--success { background: #dcfce7; color: #166534; }
        .exchanges-index-page .exchanges-status--warning { background: #fef3c7; color: #92400e; }
        .exchanges-index-page .exchanges-status--danger { background: #fee2e2; color: #991b1b; }
        .exchanges-index-page .exchanges-pagination { padding: 12px 20px; }
        @media (max-width: 768px) {
            .exchanges-index-page .exchanges-date-field { flex: 1 1 130px; }
            .exchanges-index-page .exchanges-date-field input { width: 100%; }
        }
    </style>

    <div class="content-inner customers-index-page exchanges-index-page jf-skeleton-host is-loading" data-list-workspace>

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden customers-table-card exchanges-table-card" data-list-frame>
            <div class="customers-table-card-header customers-register-head customers-directory-head exchanges-register-head p-5 border-b border-slate-200">
                <div class="customers-register-titleblock">
                    <h2 class="text-lg font-semibold text-slate-900">Exchange register</h2>
                    <dl class="customers-directory-summary" aria-label="Exchange summary">
                        <div><dt>Total</dt><dd>{{ number_format($totalCount) }}</dd></div>
                        <div><dt>Settled</dt><dd>{{ number_format($settledCount) }}</dd></div>
                        @can('reports.money')
                        <div>
                            <dt>Today's net flow</dt>
                            <dd>
                                @if($netFlow == 0)
                                    ₹0
                                @elseif($netFlow > 0)
                                    ↑ ₹{{ number_format($netFlow, 0) }}
                                @else
                                    ↓ ₹{{ number_format(abs($netFlow), 0) }}
                                @endif
                            </dd>
                        </div>
                        @endcan
                    </dl>
                </div>

                <x-responsive-filter-panel id="exchanges-filter-panel" label="Exchange filters">
                    <form method="GET" action="{{ route('exchanges.index') }}" class="customers-directory-controls"
                          x-data="{ searchOpen: {{ $searchActive ? 'true' : 'false' }} || window.innerWidth <= 768 }"
                          @resize.window="if (window.innerWidth <= 768) searchOpen = true">
                        <div class="customers-directory-search" :class="{ 'is-search-open': searchOpen }"
                             @keydown.escape.stop.prevent="const panel = $el.closest('dialog'); if (panel?.matches(':modal')) panel.close(); else { searchOpen = false; $refs.searchToggle.focus(); }">
                            <button type="button" data-exchange-search-toggle x-cloak x-ref="searchToggle"
                                    class="customers-directory-search-toggle" aria-label="Search exchanges"
                                    aria-controls="exchanges-search-panel" aria-expanded="{{ $searchActive ? 'true' : 'false' }}"
                                    :aria-expanded="searchOpen" :aria-label="searchOpen ? 'Hide exchange search' : 'Search exchanges'"
                                    :title="searchOpen ? 'Hide search' : 'Search exchanges'"
                                    @click="searchOpen = !searchOpen; if (searchOpen) $nextTick(() => $refs.searchInput.focus())">
                                <x-icon name="search" size="18" />
                            </button>
                            <div id="exchanges-search-panel" class="customers-directory-search-panel" x-show="searchOpen">
                                <label for="exchanges-search" class="jf-sr-only">Search by customer</label>
                                <input id="exchanges-search" type="search" x-ref="searchInput" name="customer"
                                       value="{{ request('customer') }}" placeholder="Customer name or mobile..."
                                       autocomplete="off" enterkeyhint="search">
                            </div>
                        </div>
                        <div class="customers-directory-status" role="group" aria-label="Exchange status"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="exchanges-status" class="jf-sr-only">Status</label>
                            <select id="exchanges-status" name="status">
                                <option value="">All statuses</option>
                                <option value="settled" @selected(request('status') === 'settled')>Settled</option>
                                <option value="pending_approval" @selected(request('status') === 'pending_approval')>Pending Approval</option>
                                <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                            </select>
                        </div>
                        <div class="exchanges-date-range">
                            <div class="exchanges-date-field">
                                <label for="exchanges-from-date">From</label>
                                <input id="exchanges-from-date" type="date" name="from" value="{{ request('from') }}">
                            </div>
                            <div class="exchanges-date-field">
                                <label for="exchanges-to-date">To</label>
                                <input id="exchanges-to-date" type="date" name="to" value="{{ request('to') }}">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                        @if($hasFilters)
                            <a href="{{ route('exchanges.index') }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                        @endif
                    </form>
                </x-responsive-filter-panel>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell exchanges-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Exchange records">
                <table class="w-full customers-data-table exchanges-data-table jf-table">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Original Invoice</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">New Invoice</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Credit Note</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Net Settlement</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse($exchanges as $exchange)
                            @php
                                $customer = $exchange->customer;
                                $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
                                $originalInvoice = $exchange->returnOrder?->invoice;
                                $creditNote = $exchange->returnOrder?->creditNote;
                                $newInvoice = $exchange->newInvoice;
                                $netAmount = (float) ($exchange->net_amount ?? 0);
                                $tone = $statusTone($exchange->status);
                            @endphp
                            <tr>
                                <td class="jf-table-index">{{ $exchanges->firstItem() + $loop->index }}</td>
                                <td class="px-6 py-4">
                                    @if($customerName)
                                        <span class="font-semibold text-slate-900">{{ $customerName }}</span>
                                        <small class="exchanges-sub">{{ \App\Support\Mobile::forDisplay($customer->mobile) ?: 'No mobile' }}</small>
                                    @else
                                        <span class="exchanges-muted">Walk-in customer</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if($originalInvoice)
                                        <a class="font-semibold text-slate-900 hover:underline" href="{{ route('invoices.show', $originalInvoice) }}">{{ $originalInvoice->invoice_number }}</a>
                                        <small class="exchanges-sub">₹{{ number_format((float) $originalInvoice->total, 2) }}</small>
                                    @else
                                        <span class="exchanges-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if($newInvoice)
                                        <a class="font-semibold text-slate-900 hover:underline" href="{{ route('invoices.show', $newInvoice) }}">{{ $newInvoice->invoice_number }}</a>
                                        <small class="exchanges-sub">₹{{ number_format((float) $newInvoice->total, 2) }}</small>
                                    @else
                                        <span class="exchanges-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if($creditNote)
                                        <span class="font-semibold text-slate-900">{{ $creditNote->credit_note_number }}</span>
                                        <small class="exchanges-sub">₹{{ number_format((float) $creditNote->total, 2) }}</small>
                                    @else
                                        <span class="exchanges-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right tabular-nums">
                                    @if(abs($netAmount) < 0.005)
                                        <span class="text-gray-500 font-medium">= ₹0</span>
                                    @elseif($netAmount < 0)
                                        <span class="text-emerald-700 font-medium">↓ ₹{{ number_format(abs($netAmount), 2) }}</span>
                                    @else
                                        <span class="text-amber-700 font-medium">↑ ₹{{ number_format($netAmount, 2) }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <span class="exchanges-status exchanges-status--{{ $tone }}">{{ $statusLabel($exchange->status) }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span>{{ $exchange->created_at->format('d M Y') }}</span>
                                    <small class="exchanges-sub">{{ $exchange->createdBy?->name ?? '—' }}</small>
                                </td>
                                <td class="px-6 py-4 text-left">
                                    <div class="jf-table-actions">
                                        <a href="{{ route('exchanges.show', $exchange) }}" class="jf-row-action jf-row-action--view" data-tip="View" aria-label="View exchange {{ $exchange->display_number }}"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="jf-table-empty">
                                    <div class="customers-mobile-empty">
                                        <strong>No exchanges found</strong>
                                        <span>When a customer exchange is processed, it will appear here. Start an exchange from the original invoice.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="customers-mobile-cards exchanges-mobile-cards">
                @forelse($exchanges as $exchange)
                    @php
                        $customer = $exchange->customer;
                        $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in customer';
                        $originalInvoice = $exchange->returnOrder?->invoice;
                        $newInvoice = $exchange->newInvoice;
                        $netAmount = (float) ($exchange->net_amount ?? 0);
                        $tone = $statusTone($exchange->status);
                    @endphp
                    <article class="customers-mobile-card">
                        <div class="customers-mobile-card__top">
                            <div class="customers-mobile-card__identity">
                                <div>
                                    <a href="{{ route('exchanges.show', $exchange) }}" class="customers-mobile-card__title"><span class="jf-record-index">#{{ $exchanges->firstItem() + $loop->index }}</span> {{ $exchange->display_number }}</a>
                                    <span class="customers-mobile-card__sub">{{ $exchange->created_at->format('d M Y, h:i A') }}</span>
                                </div>
                            </div>
                            <span class="customers-mobile-pill {{ $tone === 'danger' ? 'is-danger' : '' }}">{{ $statusLabel($exchange->status) }}</span>
                        </div>

                        <div class="customers-mobile-card__metrics">
                            <div>
                                <span>Customer</span>
                                <strong>{{ $customerName }}</strong>
                            </div>
                            <div>
                                <span>Net</span>
                                <strong>
                                    @if(abs($netAmount) < 0.005)
                                        = ₹0
                                    @elseif($netAmount < 0)
                                        ↓ ₹{{ number_format(abs($netAmount), 2) }}
                                    @else
                                        ↑ ₹{{ number_format($netAmount, 2) }}
                                    @endif
                                </strong>
                            </div>
                            <div>
                                <span>Original Inv.</span>
                                <strong>
                                    @if($originalInvoice)
                                        <a href="{{ route('invoices.show', $originalInvoice) }}">{{ $originalInvoice->invoice_number }}</a>
                                    @else
                                        —
                                    @endif
                                </strong>
                            </div>
                            <div>
                                <span>New Invoice</span>
                                <strong>
                                    @if($newInvoice)
                                        <a href="{{ route('invoices.show', $newInvoice) }}">{{ $newInvoice->invoice_number }}</a>
                                    @else
                                        —
                                    @endif
                                </strong>
                            </div>
                        </div>

                        <div class="customers-mobile-card__actions">
                            <a href="{{ route('exchanges.show', $exchange) }}" class="customers-row-action customers-row-action--primary">View Details</a>
                        </div>
                    </article>
                @empty
                    <div class="customers-mobile-empty">
                        <strong>No exchanges found</strong>
                        <span>When a customer exchange is processed, it will appear here. Start an exchange from the original invoice.</span>
                    </div>
                @endforelse
            </div>

            @if($exchanges->hasPages())
                <div class="exchanges-pagination">{{ $exchanges->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
