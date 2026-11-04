<x-app-layout>
    @php
        $vaultFine = (float) $balances->sum('in_vault_fine');
        $karigarFine = (float) $balances->sum('with_karigar_fine');
        $totalFine = (float) $balances->sum('total_fine');
        $activeLots = $lots->filter(fn ($lot) => (float) $lot->fine_weight_remaining > 0)->count();
        $initialSection = $lots->isNotEmpty() ? 'lots' : ($openJobs->isNotEmpty() ? 'jobs' : 'movements');
    @endphp

    <x-page-header title="Bullion Vault" />

    <div class="content-inner vault-page">
        @unless(auth()->user()->can('vault.manage'))
            @include('partials.view-only-banner', ['permission' => 'vault.manage', 'message' => 'vault management'])
        @endunless

        <section class="vault-surface" aria-labelledby="vault-position-title">
            <header class="vault-heading">
                <h2 id="vault-position-title">Current position</h2>
                <div class="vault-actions">
                    <a href="{{ route('vault.ledger') }}" class="btn btn-secondary btn-sm">
                        <x-icon name="chart-no-axes-column-increasing" size="16" /> Full Ledger
                    </a>
                    @can('vault.manage')
                        <a href="{{ route('vault.lots.create') }}" class="btn btn-secondary btn-sm">
                            <x-icon name="plus" size="16" /> Add Bullion
                        </a>
                    @endcan
                    @can('job_order.manage')
                        <a href="{{ route('job-orders.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="activity" size="16" /> Issue to Karigar
                        </a>
                    @endcan
                </div>
            </header>
            <dl class="vault-summary" aria-label="Vault summary">
                <div><dt>In Vault</dt><dd data-vault-summary="in-vault">{{ number_format($vaultFine, 3) }} g</dd></div>
                <div><dt>With Karigar</dt><dd data-vault-summary="karigar">{{ number_format($karigarFine, 3) }} g</dd></div>
                <div><dt>Total Fine</dt><dd data-vault-summary="total">{{ number_format($totalFine, 3) }} g</dd></div>
                <div><dt>Active Lots</dt><dd>{{ $activeLots }}</dd></div>
            </dl>
        </section>

        <section class="vault-surface" aria-labelledby="vault-purity-title">
            <header class="vault-heading">
                <div>
                    <h2 id="vault-purity-title">Purity balances <span class="vault-count">{{ $balances->count() }}</span></h2>
                    <p>Fine weight in grams, grouped by material and purity.</p>
                </div>
            </header>
            @if($balances->isEmpty())
                <div class="vault-empty">
                    <h3>No purity balances yet</h3>
                    <p>Add a bullion lot to begin tracking balances.</p>
                    @can('vault.manage')
                        <a href="{{ route('vault.lots.create') }}" class="btn btn-secondary btn-sm">Add your first lot</a>
                    @endcan
                </div>
            @else
                @if($primaryBalances->isNotEmpty())
                    @include('vault.partials.balance-table', ['rows' => $primaryBalances, 'tableId' => 'vault-primary-balances', 'label' => 'Gold and silver purity balances'])
                @endif
                @if($otherBalances->isNotEmpty())
                    <details class="vault-other-materials" data-vault-other-materials open>
                        <summary>Other materials <span class="vault-count">{{ $otherBalances->count() }}</span></summary>
                        @include('vault.partials.balance-table', ['rows' => $otherBalances, 'tableId' => 'vault-other-balances', 'label' => 'Other material purity balances'])
                    </details>
                @endif
            @endif
        </section>

        <section class="vault-surface vault-workspace" x-data="{ vaultSection: @js($initialSection) }" aria-labelledby="vault-records-title">
            <header class="vault-heading">
                <h2 id="vault-records-title">Vault records</h2>
                <div class="vault-switch" role="group" aria-label="Vault sections">
                    @foreach(['lots' => ['Lots', $lots->count()], 'jobs' => ['Open Jobs', $openJobs->count()], 'movements' => ['Recent Movements', $recentMovements->count()]] as $section => [$label, $count])
                        <button type="button" aria-controls="vault-panel-{{ $section }}"
                                aria-pressed="{{ $initialSection === $section ? 'true' : 'false' }}"
                                :aria-pressed="vaultSection === '{{ $section }}'"
                                @click="vaultSection = '{{ $section }}'">
                            {{ $label }} <span class="vault-count">{{ $count }}</span>
                        </button>
                    @endforeach
                </div>
            </header>

            <div id="vault-panel-lots" x-show="vaultSection === 'lots'" x-cloak>
                <div class="vault-section-note"><p>All bullion lots · {{ $lots->count() }} records</p></div>
                @if($lots->isEmpty())
                    <div class="vault-empty"><h3>No lots available</h3><p>New bullion lots will appear here with their source, purity and remaining fine weight.</p></div>
                @else
                    <div class="vault-scroll vault-record-scroll" data-list-scroll tabindex="0" role="region" aria-label="Bullion lots">
                        <table id="vault-lots-table" class="jf-table vault-table" role="table" aria-label="Bullion lots">
                            <thead role="rowgroup"><tr role="row">
                                <th scope="col" role="columnheader" class="jf-table-index" aria-label="Row number">#</th>
                                <th scope="col" role="columnheader">Lot #</th>
                                <th scope="col" role="columnheader">Source</th>
                                <th scope="col" role="columnheader">Vendor</th>
                                <th scope="col" role="columnheader">Metal</th>
                                <th scope="col" role="columnheader">Purity</th>
                                <th scope="col" role="columnheader" class="jf-num">Total fine</th>
                                <th scope="col" role="columnheader" class="jf-num">Remaining fine</th>
                                <th scope="col" role="columnheader" class="jf-num">Issued</th>
                                <th scope="col" role="columnheader">Status</th>
                            </tr></thead>
                            <tbody role="rowgroup">
                                @foreach($lots as $lot)
                                    @php
                                        $issued = (float) $lot->fine_weight_total - (float) $lot->fine_weight_remaining;
                                        $pct = $lot->fine_weight_total > 0 ? round((float) $lot->fine_weight_remaining / (float) $lot->fine_weight_total * 100) : 0;
                                        $isEmpty = (float) $lot->fine_weight_remaining <= 0;
                                    @endphp
                                    <tr role="row">
                                        <td role="cell" class="jf-table-index">{{ $loop->iteration }}</td>
                                        <td role="cell" data-label="Lot #"><a class="vault-record-link" href="{{ route('vault.lots.show', $lot) }}">#{{ $lot->lot_number }}</a></td>
                                        <td role="cell" data-label="Source" class="capitalize">{{ str_replace('_', ' ', $lot->source) }}</td>
                                        <td role="cell" data-label="Vendor">{{ $lot->vendor?->name ?? '—' }}</td>
                                        <td role="cell" data-label="Metal" class="capitalize">{{ $lot->metal_type ?? '—' }}</td>
                                        <td role="cell" data-label="Purity">{{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}{{ $lot->metal_type === 'gold' ? 'K' : '' }}</td>
                                        <td role="cell" data-label="Total fine" class="jf-num">{{ number_format($lot->fine_weight_total, 3) }} g</td>
                                        <td role="cell" data-label="Remaining fine" class="jf-num"><strong>{{ number_format($lot->fine_weight_remaining, 3) }} g</strong><span class="vault-meta">{{ $pct }}% left</span></td>
                                        <td role="cell" data-label="Issued" class="jf-num">{{ $issued > 0 ? number_format($issued, 3).' g' : '—' }}</td>
                                        <td role="cell" data-label="Status"><span class="vault-status {{ $isEmpty ? 'is-depleted' : ($issued > 0 ? 'is-partial' : 'is-available') }}">{{ $isEmpty ? 'Depleted' : ($issued > 0 ? 'Partial' : 'Available') }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div id="vault-panel-jobs" x-show="vaultSection === 'jobs'" x-cloak>
                <div class="vault-section-note">
                    <p>Up to 10 latest open jobs · {{ $openJobs->count() }} shown</p>
                    @can('job_order.view')<a href="{{ route('job-orders.index') }}">View all job orders</a>@endcan
                </div>
                @if($openJobs->isEmpty())
                    <div class="vault-empty"><h3>No open job orders</h3><p>Bullion issued to karigars will appear here until returned or settled.</p></div>
                @else
                    <div class="vault-scroll vault-record-scroll" data-list-scroll tabindex="0" role="region" aria-label="Open job orders">
                        <table id="vault-jobs-table" class="jf-table vault-table" role="table" aria-label="Open job orders">
                            <thead role="rowgroup"><tr role="row">
                                <th scope="col" role="columnheader" class="jf-table-index" aria-label="Row number">#</th>
                                <th scope="col" role="columnheader">Job #</th><th scope="col" role="columnheader">Karigar</th>
                                <th scope="col" role="columnheader">Purity</th><th scope="col" role="columnheader" class="jf-num">Issued fine</th>
                                <th scope="col" role="columnheader" class="jf-num">Outstanding fine</th><th scope="col" role="columnheader">Status</th>
                            </tr></thead>
                            <tbody role="rowgroup">
                                @foreach($openJobs as $jo)
                                    <tr role="row">
                                        <td role="cell" class="jf-table-index">{{ $loop->iteration }}</td>
                                        <td role="cell" data-label="Job #">
                                            @can('job_order.view')<a class="vault-record-link" href="{{ route('job-orders.show', $jo) }}">{{ $jo->job_order_number }}</a>
                                            @else {{ $jo->job_order_number }} @endcan
                                        </td>
                                        <td role="cell" data-label="Karigar">{{ $jo->karigar?->name ?? '—' }}</td>
                                        <td role="cell" data-label="Purity">{{ rtrim(rtrim(number_format($jo->purity, 2), '0'), '.') }}{{ $jo->metal_type === 'gold' ? 'K' : '' }}</td>
                                        <td role="cell" data-label="Issued fine" class="jf-num">{{ number_format($jo->issued_fine_weight, 3) }} g</td>
                                        <td role="cell" data-label="Outstanding fine" class="jf-num"><strong>{{ number_format($jo->outstanding_fine, 3) }} g</strong></td>
                                        <td role="cell" data-label="Status"><span class="vault-status {{ $jo->status === 'partial_return' ? 'is-partial' : 'is-issued' }}">{{ str_replace('_', ' ', $jo->status) }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div id="vault-panel-movements" x-show="vaultSection === 'movements'" x-cloak>
                <div class="vault-section-note"><p>Up to 25 latest movements · {{ $recentMovements->count() }} shown</p><a href="{{ route('vault.ledger') }}">Full ledger</a></div>
                @if($recentMovements->isEmpty())
                    <div class="vault-empty"><h3>No movements yet</h3><p>Credits, debits, issues, returns and lot transfers will appear here.</p></div>
                @else
                    <div class="vault-scroll vault-record-scroll" data-list-scroll tabindex="0" role="region" aria-label="Recent vault movements">
                        <table id="vault-movements-table" class="jf-table vault-table" role="table" aria-label="Recent vault movements">
                            <thead role="rowgroup"><tr role="row">
                                <th scope="col" role="columnheader" class="jf-table-index" aria-label="Row number">#</th>
                                <th scope="col" role="columnheader">When</th><th scope="col" role="columnheader">Type</th>
                                <th scope="col" role="columnheader">From lot</th><th scope="col" role="columnheader">To lot</th>
                                <th scope="col" role="columnheader" class="jf-num">Fine weight</th><th scope="col" role="columnheader">Reference</th>
                            </tr></thead>
                            <tbody role="rowgroup">
                                @foreach($recentMovements as $mv)
                                    <tr role="row">
                                        <td role="cell" class="jf-table-index">{{ $loop->iteration }}</td>
                                        <td role="cell" data-label="When">{{ $mv->created_at->format('d M, H:i') }}</td>
                                        <td role="cell" data-label="Type" class="capitalize">{{ str_replace('_', ' ', $mv->type) }}</td>
                                        <td role="cell" data-label="From lot">{{ $mv->from_lot_id ?? '—' }}</td>
                                        <td role="cell" data-label="To lot">{{ $mv->to_lot_id ?? '—' }}</td>
                                        <td role="cell" data-label="Fine weight" class="jf-num"><strong>{{ number_format($mv->fine_weight, 3) }} g</strong></td>
                                        <td role="cell" data-label="Reference">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-app-layout>
