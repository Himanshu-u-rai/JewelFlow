{{-- Every screen reads the canonical export dataset; never calculate financial totals here. --}}
@php
    $meta = $view['meta'];
    $hasFilters = count(request()->except(['page'])) > 0;
@endphp
<x-app-layout>
    <x-page-header class="individual-report-header" :title="$definition->title" :subtitle="$meta->periodLabel . ' · ' . $meta->profileLabel">
        <x-slot:actions>
            <a href="{{ route('report.hub') }}" class="btn btn-secondary btn-sm" aria-label="All reports"><x-icon name="arrow-left" size="16" /><span class="report-action-label">Reports</span></a>
            @can($definition->permissions->export)
                <a data-report-export href="{{ route('reporting.export.panel', ['report' => $definition->key] + $exportQuery) }}" class="btn btn-primary btn-sm" aria-label="Export {{ $definition->title }}" aria-haspopup="dialog" aria-controls="report-export-dialog" data-turbo-prefetch="false"><x-icon name="download" size="16" /><span class="report-action-label">Export</span></a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner individual-report" data-report-screen="{{ $definition->key }}">
        <section class="ir-surface ir-scope" aria-labelledby="report-scope-title">
            <header class="ir-heading">
                <div>
                    <h2 id="report-scope-title">Report scope</h2>
                    <p>{{ $meta->periodLabel }} · {{ $meta->profileLabel }}</p>
                </div>
                <x-responsive-filter-panel id="individual-report-filters" label="Report filters" :desktop-popup="true">
                    @include('reporting.partials.screen-filters')
                </x-responsive-filter-panel>
            </header>
            @if($isRigid)
                <p class="ir-note">Compliance report — fixed statutory format. Columns and layout are not adjustable.</p>
            @endif
            @if($definition->key === 'cash-flow')
                <p class="ir-note">Opening + cash in − cash out = closing. Filters narrow ledger entries; the summary and running balances still reflect the full cash ledger for this period.</p>
            @endif
            @if(in_array($definition->key, ['closing-stock', 'stock-register-weight', 'trading-account'], true))
                {{-- Financial-year stock reports: say which year and where the stock figures came from (snapshot or live). --}}
                @foreach(collect($meta->filtersApplied)->except('Period') as $label => $value)
                    <p class="ir-note"><strong>{{ $label }}:</strong> {{ $value }}</p>
                @endforeach
            @endif
            @if($meta->hasWatermark())
                <p class="ir-note">{{ $meta->watermark }}</p>
            @endif
            @if($errors->any())
                <div class="ir-errors" role="alert"><p>Please check the report filters.</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
        </section>

        @foreach($view['sections'] as $section)
            <section class="ir-surface" data-report-section="{{ $section['key'] }}" aria-labelledby="report-section-{{ $loop->index }}">
                <header class="ir-heading">
                    <div><h2 id="report-section-{{ $loop->index }}">{{ $section['title'] }}</h2><p>{{ number_format($section['rowCount']) }} {{ Str::plural('record', $section['rowCount']) }}</p></div>
                    @if($section['hasTotals'])<span class="ir-muted">Totals cover this report scope</span>@endif
                </header>
                @if($definition->key === 'cash-flow' && $section['key'] === 'summary')
                    <dl class="ir-metrics" aria-label="Cash position">
                        @foreach($section['rows'] as $row)
                            @php($cells = collect($row))
                            <div data-report-row><dt>{{ $cells->firstWhere('key', 'particular')['display'] ?? '' }}</dt><dd>{{ $cells->firstWhere('key', 'value')['display'] ?? '—' }}</dd></div>
                        @endforeach
                    </dl>
                @else
                    @include('reporting.partials.screen-table')
                @endif
            </section>
        @endforeach
        <footer class="ir-provenance">
            <span>{{ $meta->reportVersion }} · Generated {{ $meta->generatedAt->format('d M Y, g:i A') }} {{ $meta->generatedAt->format('T') }}</span>
            <span>Figures use the same dataset as exports.</span>
        </footer>
    </div>
</x-app-layout>
