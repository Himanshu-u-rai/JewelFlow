<x-app-layout>
    @php $hasFilters = filled($type) || filled($from) || filled($to); @endphp
    <x-page-header class="vault-record-page-header" title="Vault Ledger" subtitle="All bullion movements across this shop">
        <x-slot:actions>
            <a href="{{ route('vault.index') }}" class="btn btn-secondary btn-sm"><x-icon name="chevron-left" size="16" /> Vault</a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner vault-ledger-shell" data-list-workspace>
        <section class="vault-ledger-card" data-list-frame aria-labelledby="vault-ledger-title">
            <header class="vault-ledger-head">
                <div>
                    <h2 id="vault-ledger-title">Movement history</h2>
                    <dl class="vault-ledger-summary"><div><dt>{{ $hasFilters ? 'Matching movements' : 'Movements' }}</dt><dd>{{ number_format($movements->total()) }}</dd></div></dl>
                </div>
                <x-responsive-filter-panel id="vault-ledger-filters" label="Ledger filters">
                    <form method="GET" action="{{ route('vault.ledger') }}" class="vault-ledger-filter"
                          data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                          x-data="{ dirty: false, hasFilters: @js($hasFilters) }" @input="dirty = true" @change="dirty = true">
                        <div class="vault-ledger-type-field">
                            <label for="ledger-type">Movement type</label>
                            <select id="ledger-type" name="type" class="vault-ledger-control">
                                <option value="">All movements</option>
                                @foreach($types as $t)
                                    <option value="{{ $t }}" @selected($type === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><label for="ledger-from">From</label><input id="ledger-from" type="date" name="from" value="{{ $from }}" class="vault-ledger-control" aria-label="From date" data-date-editable></div>
                        <div><label for="ledger-to">To</label><input id="ledger-to" type="date" name="to" value="{{ $to }}" class="vault-ledger-control" aria-label="To date" data-date-editable></div>
                        <div class="vault-ledger-filter-actions">
                            <button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button>
                            <a href="{{ route('vault.ledger') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a>
                            @if($hasFilters)<noscript><a href="{{ route('vault.ledger') }}" class="btn btn-secondary btn-sm">Clear</a></noscript>@endif
                        </div>
                    </form>
                </x-responsive-filter-panel>
            </header>

            <div class="vault-ledger-scroll" data-list-scroll tabindex="0" role="region" aria-label="Bullion movement records">
                <table id="vault-ledger-table" class="vault-ledger-table jf-table" role="table" aria-label="Bullion movement history">
                    <thead role="rowgroup"><tr role="row">
                        <th scope="col" role="columnheader" class="jf-table-index" aria-label="Row number">#</th>
                        <th scope="col" role="columnheader">When</th>
                        <th scope="col" role="columnheader">Type</th>
                        <th scope="col" role="columnheader">From lot</th>
                        <th scope="col" role="columnheader">To lot</th>
                        <th scope="col" role="columnheader" class="jf-num">Fine weight</th>
                        <th scope="col" role="columnheader">Reference</th>
                        <th scope="col" role="columnheader" class="jf-num">By</th>
                    </tr></thead>
                    <tbody role="rowgroup">
                        @forelse($movements as $mv)
                            <tr role="row">
                                <td role="cell" class="jf-table-index" data-label="#">{{ ($movements->firstItem() ?? 1) + $loop->index }}</td>
                                <td role="cell" data-label="When">{{ $mv->created_at->format('d M Y, H:i') }}</td>
                                <td role="cell" data-label="Type"><span class="vault-ledger-type">{{ ucfirst(str_replace('_', ' ', $mv->type)) }}</span></td>
                                <td role="cell" data-label="From lot">{{ $mv->from_lot_id ?? '—' }}</td>
                                <td role="cell" data-label="To lot">{{ $mv->to_lot_id ?? '—' }}</td>
                                <td role="cell" data-label="Fine weight" class="jf-num"><strong>{{ number_format($mv->fine_weight, 3) }} g</strong></td>
                                <td role="cell" data-label="Reference">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                <td role="cell" data-label="By" class="jf-num">{{ $mv->user_id }}</td>
                            </tr>
                        @empty
                            <tr role="row"><td role="cell" colspan="8" class="jf-table-empty">No movements match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <footer class="vault-ledger-footer">
                @if($movements->hasPages())
                    {{ $movements->links() }}
                @else
                    <p>{{ number_format($movements->total()) }} {{ Str::plural('movement', $movements->total()) }}</p>
                @endif
            </footer>
        </section>
    </div>
</x-app-layout>
