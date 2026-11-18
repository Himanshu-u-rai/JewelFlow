<x-app-layout>
    <x-page-header class="individual-report-header" title="Repairs Report" subtitle="Repair status and final charges for delivered jobs">
        <x-slot:actions><a href="{{ route('report.hub') }}" class="btn btn-secondary btn-sm" aria-label="All reports"><x-icon name="arrow-left" size="16" /><span class="report-action-label">Reports</span></a></x-slot:actions>
    </x-page-header>
    <div class="content-inner individual-report">
        <section class="ir-surface">
            <header class="ir-heading">
                <div><h2>Repair register</h2><p>Filtered by date received{{ $status ? ' · '.Str::headline($status) : '' }}</p></div>
                <x-responsive-filter-panel id="repair-report-filters" label="Repair report filters" :desktop-popup="true">
                    <form method="GET" action="{{ route('report.repairs') }}" class="ir-filters"
                          data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                          x-data="{ dirty: false, hasFilters: @js((bool) ($fromDate || $toDate || $status)) }" @input="dirty = true" @change="dirty = true">
                        <div class="ir-field"><label for="repair-report-from">From date</label><input id="repair-report-from" type="date" name="from_date" value="{{ $fromDate }}" class="ir-control" aria-label="From date" data-date-editable></div>
                        <div class="ir-field"><label for="repair-report-to">To date</label><input id="repair-report-to" type="date" name="to_date" value="{{ $toDate }}" class="ir-control" aria-label="To date" data-date-editable></div>
                        <div class="ir-field"><label for="repair-report-status">Status</label><select id="repair-report-status" name="status" class="ir-control">
                            <option value="">All statuses</option>
                            @foreach(['received' => 'Received', 'in_repair' => 'In repair', 'ready' => 'Ready', 'delivered' => 'Delivered'] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select></div>
                        <div class="ir-filter-actions"><button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button><a href="{{ route('report.repairs') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a></div>
                    </form>
                </x-responsive-filter-panel>
            </header>
            <dl class="ir-metrics"><div><dt>Matching repairs</dt><dd>{{ number_format($repairs->total()) }}</dd></div><div><dt>Delivered charges</dt><dd>₹{{ number_format((float) $totals->total_cash, 2) }}</dd></div></dl>
            <p class="ir-note">Final charges for delivered repairs, not payments collected. Pending jobs show a dash. Totals cover all matching repairs, not just this page.</p>
            <div class="ir-scroll jf-scrollbar" data-list-scroll tabindex="0" role="region" aria-label="Repair report records">
                <table class="jf-table ir-table ir-table--cards" role="table" aria-label="Repair report">
                    <thead role="rowgroup"><tr role="row">
                        <th scope="col" role="columnheader" class="ir-index" aria-label="Row number">#</th>
                        <th scope="col" role="columnheader">Received</th><th scope="col" role="columnheader">Customer</th><th scope="col" role="columnheader">Item</th><th scope="col" role="columnheader">Status</th><th scope="col" role="columnheader" class="jf-num">Delivered charges</th><th scope="col" role="columnheader">Invoice</th>
                    </tr></thead>
                    <tbody role="rowgroup">
                        @forelse($repairs as $r)
                            <tr role="row">
                                <td role="cell" class="ir-index" data-label="#">{{ ($repairs->firstItem() ?? 1) + $loop->index }}</td>
                                <td role="cell" data-label="Received">{{ $r->created_at->format('d M Y') }}</td>
                                <td role="cell" data-label="Customer">{{ trim(($r->customer->first_name ?? '').' '.($r->customer->last_name ?? '')) ?: ($r->customer->name ?? 'N/A') }}</td>
                                <td role="cell" data-label="Item">{{ $r->item_description }}</td>
                                <td role="cell" data-label="Status">{{ Str::headline($r->status) }}</td>
                                <td role="cell" data-label="Delivered charges" class="jf-num">{{ $r->status === 'delivered' ? '₹'.number_format((float) ($r->final_cost ?? 0), 2) : '—' }}</td>
                                <td role="cell" data-label="Invoice">
                                    @if($r->invoice_id)
                                        @can('sales.view')<a href="{{ route('invoices.show', $r->invoice_id) }}" class="jf-row-action jf-row-action--view" data-tip="View invoice" aria-label="View repair invoice"><x-icon name="eye" size="16" /></a>@else Invoice recorded @endcan
                                    @else — @endif
                                </td>
                            </tr>
                        @empty
                            <tr role="row"><td role="cell" colspan="7" class="jf-table-empty">No repair records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($repairs->hasPages())<footer class="ir-pagination">{{ $repairs->links() }}</footer>@endif
        </section>
    </div>
</x-app-layout>
