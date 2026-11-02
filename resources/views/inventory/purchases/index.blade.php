<x-app-layout>
    @php
        $statusLabels = [
            'draft' => 'Draft',
            'confirmed' => 'Confirmed',
            'stocked' => 'Stocked',
        ];
        $activeFilters = [
            'search' => request('search'),
            'status' => request('status'),
            'vendor_id' => request('vendor_id'),
            'date_from' => request('date_from'),
            'date_to' => request('date_to'),
        ];
        $hasActiveFilters = collect($activeFilters)->contains(fn ($value) => filled($value));
        $searchActive = request()->filled('search');
    @endphp

    <x-page-header class="customers-page-header customers-directory-header" title="Stock Purchases" subtitle="Record and manage incoming stock from suppliers">
        <x-slot:actions>
            @can('inventory.create')
            <a href="{{ route('inventory.purchases.create') }}" class="btn btn-success btn-sm customers-add-btn" aria-label="New Purchase" title="New Purchase">
                <x-icon name="plus" class="w-4 h-4 mr-2" />
                <span class="customers-add-label">New Purchase</span>
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page inventory-purchases-page" data-list-workspace
         x-data="{ draft: null, confirming: false }"
         @turbo:before-cache.document="$refs.confirmDraft?.close(); draft = null; confirming = false">
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden customers-table-card" data-list-frame>
            <div class="customers-table-card-header customers-register-head customers-directory-head sp-register-head">
                <div class="customers-register-titleblock">
                    <h2>Purchase Register</h2>
                    <dl class="customers-directory-summary" aria-label="Stock purchase summary">
                        <div><dt>Total Purchases</dt><dd>{{ number_format($stats->total_confirmed ?? 0) }}</dd></div>
                        <div><dt>This Month</dt><dd>₹{{ number_format($stats->month_amount ?? 0, 0) }}</dd></div>
                        <div><dt>Items This Month</dt><dd>{{ number_format($monthItems ?? 0) }}</dd></div>
                        <div><dt>Drafts Pending</dt><dd>{{ number_format($stats->drafts_pending ?? 0) }}</dd></div>
                    </dl>
                </div>

                <x-responsive-filter-panel id="purchases-filter-panel" label="Stock purchase filters">
                    <form method="GET" action="{{ route('inventory.purchases.index') }}" class="customers-directory-controls"
                          x-data="{ searchOpen: {{ $searchActive ? 'true' : 'false' }} || window.innerWidth <= 768 }"
                          @resize.window="if (window.innerWidth <= 768) searchOpen = true">
                        <div class="customers-directory-search" :class="{ 'is-search-open': searchOpen }"
                             @keydown.escape.stop.prevent="const panel = $el.closest('dialog'); if (panel?.matches(':modal')) panel.close(); else { searchOpen = false; $refs.searchToggle.focus(); }">
                            <button type="button" data-purchase-search-toggle x-cloak x-ref="searchToggle"
                                    class="customers-directory-search-toggle" aria-label="Search purchases"
                                    aria-controls="purchases-search-panel" aria-expanded="{{ $searchActive ? 'true' : 'false' }}"
                                    :aria-expanded="searchOpen" :aria-label="searchOpen ? 'Hide purchase search' : 'Search purchases'"
                                    :title="searchOpen ? 'Hide search' : 'Search purchases'"
                                    @click="searchOpen = !searchOpen; if (searchOpen) $nextTick(() => $refs.searchInput.focus())">
                                <x-icon name="search" size="18" />
                            </button>
                            <div id="purchases-search-panel" class="customers-directory-search-panel" x-show="searchOpen">
                                <label for="purchases-search" class="jf-sr-only">Search purchases</label>
                                <input id="purchases-search" type="search" x-ref="searchInput" name="search"
                                       value="{{ request('search') }}" placeholder="Purchase #, invoice #, supplier..."
                                       autocomplete="off" enterkeyhint="search">
                            </div>
                        </div>
                        <div class="customers-directory-status" role="group" aria-label="Purchase status"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="purchases-status" class="jf-sr-only">Status</label>
                            <select id="purchases-status" name="status">
                                <option value="">All statuses</option>
                                @foreach($statusLabels as $value => $label)
                                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="customers-directory-status" role="group" aria-label="Purchase vendor"
                             data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport">
                            <label for="purchases-vendor" class="jf-sr-only">Vendor</label>
                            <select id="purchases-vendor" name="vendor_id">
                                <option value="">All Vendors</option>
                                @foreach($vendors as $vendor)
                                    <option value="{{ $vendor->id }}" @selected((string) request('vendor_id') === (string) $vendor->id)>{{ $vendor->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sp-date-range">
                            <div class="sp-date-field">
                                <label for="purchases-from-date">From</label>
                                <input id="purchases-from-date" type="date" name="date_from" value="{{ request('date_from') }}">
                            </div>
                            <div class="sp-date-field">
                                <label for="purchases-to-date">To</label>
                                <input id="purchases-to-date" type="date" name="date_to" value="{{ request('date_to') }}">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm customers-directory-apply">Apply</button>
                        @if($hasActiveFilters)
                            <a href="{{ route('inventory.purchases.index') }}" class="btn btn-secondary btn-sm customers-directory-clear">Clear</a>
                        @endif
                    </form>
                </x-responsive-filter-panel>
            </div>
            @if($purchases->isEmpty())
                <div class="customers-mobile-empty sp-empty-state">
                    <span class="sp-empty-icon">
                        <x-icon name="file-text" class="h-7 w-7" />
                    </span>
                    <p class="sp-empty-title">{{ $hasActiveFilters ? 'No purchases match these filters' : 'No stock purchases recorded yet' }}</p>
                    <p class="sp-empty-copy">{{ $hasActiveFilters ? 'Clear or adjust filters to see purchase entries.' : 'Create a purchase entry when supplier stock arrives.' }}</p>
                    @can('inventory.create')
                    <a href="{{ route('inventory.purchases.create') }}" class="btn btn-primary btn-sm mt-4">New Purchase</a>
                    @endcan
                </div>
            @else
                <div class="overflow-x-auto ui-table-shell customers-table-shell sp-register-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Stock purchase records">
                    <table class="sp-register-table jf-table">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th scope="col" class="jf-table-index" aria-label="Row number">#</th>
                                <th scope="col" class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Purchase #</th>
                                <th scope="col" class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Supplier</th>
                                <th scope="col" class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Invoice #</th>
                                <th scope="col" class="px-3.5 py-3 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Date</th>
                                <th scope="col" class="px-3.5 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Items</th>
                                <th scope="col" class="px-3.5 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Total</th>
                                <th scope="col" class="px-3.5 py-3 text-center text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Status</th>
                                <th scope="col" class="px-3.5 py-3 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($purchases as $purchase)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="jf-table-index">{{ $purchases->firstItem() + $loop->index }}</td>
                                    <td class="px-3.5 py-3">
                                        <a href="{{ route('inventory.purchases.show', $purchase) }}" class="sp-purchase-link font-mono text-sm">{{ $purchase->purchase_number }}</a>
                                    </td>
                                    <td class="px-3.5 py-3 text-sm text-slate-700">{{ $purchase->supplier_label }}</td>
                                    <td class="px-3.5 py-3 text-sm text-slate-500">{{ $purchase->invoice_number ?: '—' }}</td>
                                    <td class="px-3.5 py-3 text-sm text-slate-600 whitespace-nowrap">{{ $purchase->purchase_date->format('d M Y') }}</td>
                                    <td class="px-3.5 py-3 text-right text-sm text-slate-700">{{ $purchase->lines_count }}</td>
                                    <td class="px-3.5 py-3 text-right text-sm font-semibold text-slate-900 whitespace-nowrap">₹{{ number_format($purchase->total_amount, 2) }}</td>
                                    <td class="px-3.5 py-3 text-center">
                                        @if($purchase->isDraft())
                                            <span class="sp-status-pill sp-status-pill--draft">Draft</span>
                                        @elseif($purchase->isStocked())
                                            <span class="sp-status-pill sp-status-pill--stocked">Stocked</span>
                                        @else
                                            <span class="sp-status-pill sp-status-pill--confirmed">Confirmed</span>
                                        @endif
                                    </td>
                                    <td class="px-3.5 py-3 text-right">
                                        <div class="jf-table-actions">
                                            <a href="{{ route('inventory.purchases.show', $purchase) }}" class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                            @if($purchase->isDraft())
                                                @can('inventory.edit')
                                                @include('inventory.purchases.partials.confirm-draft-action')
                                                <a href="{{ route('inventory.purchases.edit', $purchase) }}" class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>
                                                @endcan
                                                @can('inventory.delete')
                                                <form method="POST" action="{{ route('inventory.purchases.destroy', $purchase) }}" onsubmit="return confirm('Delete this draft purchase?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="jf-row-action jf-row-action--danger" data-tip="Delete"><x-icon name="trash-2" size="16" /><span class="jf-sr-only">Delete</span></button>
                                                </form>
                                                @endcan
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="customers-mobile-cards sp-mobile-list">
                    @foreach($purchases as $purchase)
                        <article class="customers-mobile-card sp-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('inventory.purchases.show', $purchase) }}" class="sp-purchase-link font-mono text-sm"><span class="jf-record-index">#{{ $purchases->firstItem() + $loop->index }}</span> {{ $purchase->purchase_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $purchase->supplier_label }}</p>
                                </div>
                                @if($purchase->isDraft())
                                    <span class="sp-status-pill sp-status-pill--draft">Draft</span>
                                @elseif($purchase->isStocked())
                                    <span class="sp-status-pill sp-status-pill--stocked">Stocked</span>
                                @else
                                    <span class="sp-status-pill sp-status-pill--confirmed">Confirmed</span>
                                @endif
                            </div>

                            <div class="sp-mobile-grid text-sm">
                                <div>
                                    <p class="sp-mobile-label">Invoice #</p>
                                    <p class="text-slate-700">{{ $purchase->invoice_number ?: '—' }}</p>
                                </div>
                                <div>
                                    <p class="sp-mobile-label">Date</p>
                                    <p class="text-slate-700">{{ $purchase->purchase_date->format('d M Y') }}</p>
                                </div>
                                <div>
                                    <p class="sp-mobile-label">Items</p>
                                    <p class="text-slate-800 font-semibold">{{ $purchase->lines_count }}</p>
                                </div>
                                <div>
                                    <p class="sp-mobile-label">Total</p>
                                    <p class="text-slate-900 font-semibold">₹{{ number_format($purchase->total_amount, 2) }}</p>
                                </div>
                            </div>

                            <div class="mt-3 border-t border-slate-100 pt-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('inventory.purchases.show', $purchase) }}" class="btn btn-primary btn-sm">View</a>
                                    @if($purchase->isDraft())
                                        @can('inventory.edit')
                                        @include('inventory.purchases.partials.confirm-draft-action')
                                        <a href="{{ route('inventory.purchases.edit', $purchase) }}" class="btn btn-secondary btn-sm">Edit</a>
                                        @endcan
                                        @can('inventory.delete')
                                        <form method="POST" action="{{ route('inventory.purchases.destroy', $purchase) }}" onsubmit="return confirm('Delete this draft purchase?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                        @endcan
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if($purchases->hasPages())
                    <div class="border-t border-slate-200 px-4 py-3">
                        {{ $purchases->links() }}
                    </div>
                @endif
            @endif
        </div>
        @can('inventory.edit')
        <dialog id="purchase-confirm-dialog" x-ref="confirmDraft" class="sp-confirm-dialog"
                aria-labelledby="purchase-confirm-title" aria-describedby="purchase-confirm-description"
                @close="draft = null; confirming = false"
                @cancel.prevent="if (!confirming) $el.close()">
            <form method="POST" :action="draft?.confirmUrl"
                  @submit="if (confirming || !draft) $event.preventDefault(); else confirming = true">
                @csrf
                @method('PATCH')
                <div class="p-5">
                    <h3 id="purchase-confirm-title" class="text-base font-semibold text-slate-900">Confirm draft purchase?</h3>
                    <dl class="sp-confirm-summary">
                        <div><dt>Purchase</dt><dd x-text="draft?.number"></dd></div>
                        <div><dt>Supplier</dt><dd x-text="draft?.supplier"></dd></div>
                        <div><dt>Total</dt><dd x-text="draft?.total"></dd></div>
                    </dl>
                    <p id="purchase-confirm-description" class="text-sm text-slate-600">This locks the invoice details. Adding items to stock is a separate step.</p>
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 p-4">
                    <button type="button" class="btn btn-secondary" autofocus :disabled="confirming" @click="$refs.confirmDraft.close()">Cancel</button>
                    <button type="submit" class="btn btn-primary" :disabled="confirming || !draft" x-text="confirming ? 'Confirming…' : 'Confirm'">Confirm</button>
                </div>
            </form>
        </dialog>
        @endcan
    </div>
</x-app-layout>
