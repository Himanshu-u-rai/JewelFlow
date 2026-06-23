{{-- Generic report screen - any spine report (multi-section aware).
     Consumes the same canonical dataset as the exports; totals match the PDF.
     Rigid compliance reports show no profile selector and no sensitive toggle. --}}
@php
    $reportKey = (string) $definition->key;
    $isCashFlowReport = $reportKey === 'cash-flow';
    $isDailyClosingReport = $reportKey === 'daily-closing';
    $isGstReport = $reportKey === 'gst';
    $isRegisterReport = ! $isCashFlowReport && ! $isDailyClosingReport && ! $isGstReport;
    $meta = $view['meta'];
    $sections = collect($view['sections']);

    $cashSummaryMap = collect();
    if ($isCashFlowReport) {
        $summarySection = $sections->firstWhere('key', 'summary');
        $cashSummaryMap = collect($summarySection['rows'] ?? [])->mapWithKeys(function ($row) {
            $cells = collect($row);
            $labelCell = $cells->firstWhere('key', 'particular');
            $valueCell = $cells->firstWhere('key', 'value');
            $label = (string) ($labelCell['display'] ?? '');

            return [
                strtolower($label) => [
                    'label' => $label,
                    'display' => (string) ($valueCell['display'] ?? ''),
                    'raw' => $valueCell['raw'] ?? null,
                ],
            ];
        });
    }

    $cashKpis = [
        ['key' => 'opening balance', 'label' => 'Opening balance', 'tone' => 'neutral', 'icon' => 'book'],
        ['key' => 'cash in', 'label' => 'Cash in', 'tone' => 'in', 'icon' => 'in'],
        ['key' => 'cash out', 'label' => 'Cash out', 'tone' => 'out', 'icon' => 'out'],
        ['key' => 'closing balance', 'label' => 'Closing balance', 'tone' => 'closing', 'icon' => 'balance'],
    ];

    $closingMetricMap = collect();
    if ($isDailyClosingReport) {
        $closingMetricMap = $sections->flatMap(fn ($section) => $section['rows'] ?? [])->mapWithKeys(function ($row) {
            $cells = collect($row);
            $labelCell = $cells->firstWhere('key', 'metric');
            $amountCell = $cells->firstWhere('key', 'amount');
            $label = (string) ($labelCell['display'] ?? '');

            return [
                strtolower($label) => [
                    'label' => $label,
                    'display' => (string) ($amountCell['display'] ?? ''),
                    'raw' => $amountCell['raw'] ?? null,
                ],
            ];
        });
    }

    $closingKpis = [
        ['key' => 'total sales', 'label' => 'Total sales', 'tone' => 'sales', 'icon' => 'sales'],
        ['key' => 'gst collected', 'label' => 'GST collected', 'tone' => 'tax', 'icon' => 'tax'],
        ['key' => 'cash in', 'label' => 'Cash in', 'tone' => 'in', 'icon' => 'in'],
        ['key' => 'closing balance', 'label' => 'Closing balance', 'tone' => 'closing', 'icon' => 'balance'],
    ];
    $closingDateValue = request('date', now()->toDateString());

    $gstSection = $isGstReport ? ($sections->firstWhere('key', 'gst') ?? $sections->first()) : null;
    $gstTotals = collect($gstSection['totals'] ?? []);
    $gstKpis = [
        ['key' => 'total', 'label' => 'Total sales', 'tone' => 'sales', 'icon' => 'sales'],
        ['key' => 'taxable', 'label' => 'Taxable value', 'tone' => 'taxable', 'icon' => 'taxable'],
        ['key' => 'total_gst', 'label' => 'GST collected', 'tone' => 'gst', 'icon' => 'gst'],
        ['key' => 'count', 'label' => 'Invoices', 'tone' => 'count', 'icon' => 'count'],
    ];

    $activeFilters = [];
    foreach (($filterControls ?? []) as $fc) {
        if ($fc['current'] !== '') {
            $match = collect($fc['options'])->firstWhere('value', $fc['current']);
            $activeFilters[] = $fc['label'] . ': ' . ($match['label'] ?? $fc['current']);
        }
    }
    if (! $isRigid && request('profile') && request('profile') !== 'detailed') {
        $activeFilters[] = 'Profile: ' . \Illuminate\Support\Str::headline((string) request('profile'));
    }
    if (request()->boolean('include_sensitive')) {
        $activeFilters[] = 'Sensitive columns';
    }
@endphp

<x-app-layout>
    <x-page-header
        class="{{ $isCashFlowReport ? 'cash-report-header' : ($isDailyClosingReport ? 'closing-report-header' : ($isGstReport ? 'gst-report-header' : 'report-register-header')) }}"
        :title="$definition->title"
        :subtitle="$meta->periodLabel . ' - ' . $meta->profileLabel"
    >
        <x-slot:actions>
            @if ($isCashFlowReport)
                <a href="{{ route('cashbook.index') }}" class="cash-report-header-btn jf-header-action--neutral" aria-label="Open Cash Ledger">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M4 5h16M4 12h16M4 19h10" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="cash-report-action-label">Cash Ledger</span>
                </a>
                <a href="{{ route('reporting.export.panel', ['report' => $definition->key]) }}" class="cash-report-export-btn jf-header-action--gold" aria-label="Export Cash Flow">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="cash-report-action-label">Export</span>
                </a>
            @elseif ($isDailyClosingReport)
                <a href="{{ route('report.hub') }}" class="closing-report-header-btn jf-header-action--neutral" aria-label="Open Reports">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h10" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="closing-report-action-label">Reports</span>
                </a>
                <a href="{{ route('reporting.export.panel', ['report' => $definition->key]) }}" class="closing-report-export-btn jf-header-action--gold" aria-label="Export Daily Closing">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="closing-report-action-label">Export</span>
                </a>
            @elseif ($isGstReport)
                <a href="{{ route('report.hub') }}" class="gst-report-header-btn jf-header-action--neutral" aria-label="Open Reports">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h10" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="gst-report-action-label">Reports</span>
                </a>
                <a href="{{ route('reporting.export.panel', ['report' => $definition->key]) }}" class="gst-report-export-btn jf-header-action--gold" aria-label="Export GST Summary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="gst-report-action-label">Export</span>
                </a>
            @else
                <a href="{{ route('report.hub') }}" class="report-register-header-btn jf-header-action--neutral" aria-label="Open Reports">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h10" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span class="report-register-action-label">Reports</span>
                </a>
                <a href="{{ route('reporting.export.panel', ['report' => $definition->key]) }}" class="report-register-export-btn jf-header-action--gold" aria-label="Export {{ $definition->title }}">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="report-register-action-label">Export</span>
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($isCashFlowReport)
        <div class="content-inner cash-report-page" x-data="{ filtersOpen: false }" x-effect="document.body.classList.toggle('cash-report-filter-lock', filtersOpen)" @keydown.escape.window="filtersOpen = false">
            <div class="cash-report-kpis" aria-label="Cash flow summary">
                @foreach ($cashKpis as $kpi)
                    @php $metric = $cashSummaryMap->get($kpi['key'], ['display' => '']); @endphp
                    <div class="cash-report-kpi cash-report-kpi--{{ $kpi['tone'] }}">
                        <span class="cash-report-kpi-icon" aria-hidden="true">
                            @if ($kpi['icon'] === 'in')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5M5 12l7-7 7 7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @elseif ($kpi['icon'] === 'out')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 5v14M5 12l7 7 7-7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @elseif ($kpi['icon'] === 'balance')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M7 12h10M10 17h4" stroke-width="2" stroke-linecap="round"/></svg>
                            @else
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @endif
                        </span>
                        <span class="cash-report-kpi-copy">
                            <span class="cash-report-kpi-label">{{ $kpi['label'] }}</span>
                            <span class="cash-report-kpi-value">{{ $metric['display'] !== '' ? $metric['display'] : '--' }}</span>
                        </span>
                    </div>
                @endforeach
            </div>

            <section class="cash-report-toolbar" aria-label="Cash report filters">
                <div class="cash-report-toolbar-head">
                    <div>
                        <h2>Report controls</h2>
                        <p>{{ $meta->periodLabel }}</p>
                    </div>
                    <button type="button" class="cash-report-filter-trigger" @click="filtersOpen = true" :aria-expanded="filtersOpen.toString()" aria-controls="cash-report-filter-sheet">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M4 6h16M7 12h10M10 18h4" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Filters
                        @if (count($activeFilters) > 0)
                            <span>{{ count($activeFilters) }}</span>
                        @endif
                    </button>
                </div>

                @if (count($activeFilters) > 0)
                    <div class="cash-report-active-filters" aria-label="Active filters">
                        @foreach ($activeFilters as $filter)
                            <span>{{ $filter }}</span>
                        @endforeach
                        <a href="{{ route('report.cash') }}">Clear</a>
                    </div>
                @endif

                <button type="button" class="cash-report-filter-backdrop" x-show="filtersOpen" x-cloak @click="filtersOpen = false" aria-label="Close filters"></button>
                <form method="GET" id="cash-report-filter-sheet" class="cash-report-filter-form" :class="{ 'is-open': filtersOpen }" @submit="filtersOpen = false">
                    <div class="cash-report-filter-sheet-head">
                        <div>
                            <p>Filters</p>
                            <span>Refine Cash Flow entries</span>
                        </div>
                        <button type="button" @click="filtersOpen = false" aria-label="Close filters">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 6 6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/></svg>
                        </button>
                    </div>

                    <div class="cash-report-filter-field">
                        <label>Period</label>
                        <div class="cash-report-select">
                            <select name="date_preset" class="cash-report-input">
                                @foreach ($presets as $preset)
                                    <option value="{{ $preset->value }}" @selected(request('date_preset', 'this_month') === $preset->value)>
                                        {{ \Illuminate\Support\Str::headline($preset->value) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @foreach (($filterControls ?? []) as $fc)
                        <div class="cash-report-filter-field">
                            <label>{{ $fc['label'] }}</label>
                            <div class="cash-report-select">
                                <select name="{{ $fc['key'] }}" class="cash-report-input">
                                    <option value="">All</option>
                                    @foreach ($fc['options'] as $opt)
                                        <option value="{{ $opt['value'] }}" @selected($fc['current'] === (string) $opt['value'])>{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach

                    @unless ($isRigid)
                        <div class="cash-report-filter-field">
                            <label>Profile</label>
                            <div class="cash-report-select">
                                <select name="profile" class="cash-report-input">
                                    @foreach ($definition->profiles as $p)
                                        <option value="{{ $p->value }}" @selected($profile->value === $p->value)>{{ \Illuminate\Support\Str::headline($p->value) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($canExportSensitive && $definition->hasSensitiveColumns())
                            <label class="cash-report-sensitive-toggle">
                                <input type="checkbox" name="include_sensitive" value="1" @checked(request()->boolean('include_sensitive'))>
                                <span class="cash-report-toggle-track" aria-hidden="true">
                                    <span class="cash-report-toggle-thumb"></span>
                                </span>
                                <span class="cash-report-toggle-label">Sensitive columns</span>
                            </label>
                        @endif
                    @endunless

                    <div class="cash-report-filter-actions">
                        @if (request()->query())
                            <a href="{{ route('report.cash') }}" class="cash-report-clear">Clear</a>
                        @endif
                        <button type="submit" class="cash-report-apply">Apply</button>
                    </div>
                </form>
            </section>

            @if ($isRigid)
                <p class="cash-report-compliance-note">Compliance report - fixed statutory format. Columns and layout are not adjustable.</p>
            @endif

            @foreach ($view['sections'] as $section)
                @continue(($section['key'] ?? '') === 'summary')

                <section class="cash-report-section cash-report-section--{{ $section['key'] }}">
                    <div class="cash-report-section-head">
                        <div>
                            <h2>{{ $section['title'] }}</h2>
                            <p>{{ number_format($section['rowCount']) }} {{ \Illuminate\Support\Str::plural('row', $section['rowCount']) }}</p>
                        </div>
                    </div>

                    @if ($section['rowCount'] === 0)
                        <div class="cash-report-empty">No data for this scope.</div>
                    @else
                        @php
                            $hasRunningBalance = collect($section['columns'])->contains(fn ($c) => $c['key'] === 'running_balance');
                        @endphp
                        <div class="cash-report-table-wrap">
                            <table class="cash-report-table js-report-table" data-has-running-balance="{{ $hasRunningBalance ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        @foreach ($section['columns'] as $col)
                                            <th data-col-key="{{ $col['key'] }}" data-numeric="{{ $col['numeric'] ? '1' : '0' }}"
                                                class="js-sort-th {{ $col['numeric'] ? 'text-right' : 'text-left' }}">
                                                {{ $col['label'] }}<span class="js-sort-arrow"></span>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['rows'] as $row)
                                        @php
                                            $rowType = (string) (collect($row)->firstWhere('key', 'type')['display'] ?? '');
                                            $cashDirection = \Illuminate\Support\Str::contains(\Illuminate\Support\Str::lower($rowType), 'out') ? 'out' : 'in';
                                        @endphp
                                        <tr class="cash-report-ledger-row cash-report-ledger-row--{{ $cashDirection }}">
                                            @foreach ($row as $cell)
                                                @php
                                                    $cellKey = (string) ($cell['key'] ?? '');
                                                    $displayValue = (string) ($cell['display'] ?? '');
                                                    $raw = $cell['raw'] ?? null;
                                                    if (is_numeric($raw)) {
                                                        $sortVal = (string) $raw;
                                                    } elseif ($raw instanceof \DateTimeInterface) {
                                                        $sortVal = (string) $raw->getTimestamp();
                                                    } elseif (is_string($raw) && strtotime($raw) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
                                                        $sortVal = (string) strtotime($raw);
                                                    } else {
                                                        $sortVal = $displayValue;
                                                    }

                                                    $cellClasses = trim(($cell['numeric'] ? 'text-right tabular-nums' : 'text-left') . ' cash-report-cell cash-report-cell--' . \Illuminate\Support\Str::slug($cellKey));
                                                    $humanDisplay = \Illuminate\Support\Str::headline(str_replace(['_', '-'], ' ', $displayValue));
                                                    $dateParts = $cellKey === 'datetime' ? array_map('trim', explode(',', $displayValue, 2)) : [];
                                                @endphp
                                                <td data-col-key="{{ $cellKey }}" data-sort-value="{{ $sortVal }}" class="{{ $cellClasses }}">
                                                    @if ($cellKey === 'datetime')
                                                        <span class="cash-report-date-cell">
                                                            <span>{{ $dateParts[0] ?? $displayValue }}</span>
                                                            @if (! empty($dateParts[1]))
                                                                <small>{{ $dateParts[1] }}</small>
                                                            @endif
                                                        </span>
                                                    @elseif ($cellKey === 'type')
                                                        <span class="cash-report-flow-pill cash-report-flow-pill--{{ $cashDirection }}">
                                                            <span aria-hidden="true">{{ $cashDirection === 'out' ? '-' : '+' }}</span>
                                                            {{ $displayValue }}
                                                        </span>
                                                    @elseif ($cellKey === 'source')
                                                        <span class="cash-report-source-pill">{{ $humanDisplay }}</span>
                                                    @elseif ($cellKey === 'payment_mode')
                                                        <span class="cash-report-mode-pill">{{ $humanDisplay ?: '--' }}</span>
                                                    @elseif ($cellKey === 'amount')
                                                        <span class="cash-report-money cash-report-money--{{ $cashDirection }}">{{ $displayValue }}</span>
                                                    @elseif ($cellKey === 'running_balance')
                                                        <span class="cash-report-money cash-report-money--balance">{{ $displayValue }}</span>
                                                    @elseif ($cellKey === 'description')
                                                        <span class="cash-report-description">{{ $displayValue ?: '--' }}</span>
                                                    @else
                                                        {{ $displayValue }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if ($section['hasTotals'])
                                    <tfoot>
                                        <tr>
                                            @foreach ($section['columns'] as $col)
                                                <td class="{{ $col['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">
                                                    {{ $section['totals'][$col['key']] ?? ($loop->first ? 'Total' : '') }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @elseif ($isDailyClosingReport)
        <div class="content-inner closing-report-page">
            <section class="closing-report-toolbar" aria-label="Daily closing controls">
                <div class="closing-report-toolbar-copy">
                    <h2>Closing date</h2>
                    <p>{{ $meta->periodLabel }} · {{ $meta->profileLabel }}</p>
                </div>
                <form method="GET" action="{{ route('report.closing') }}" class="closing-report-date-form">
                    <label for="closing-report-date">Date</label>
                    <input id="closing-report-date" type="date" name="date" value="{{ $closingDateValue }}" class="closing-report-date-input">
                    @unless ($isRigid)
                        <input type="hidden" name="profile" value="{{ $profile->value }}">
                    @endunless
                    @if (request()->query())
                        <a href="{{ route('report.closing') }}" class="closing-report-clear">Clear</a>
                    @endif
                    <button type="submit" class="closing-report-apply">View day</button>
                </form>
            </section>

            <div class="closing-report-kpis" aria-label="Daily closing summary">
                @foreach ($closingKpis as $kpi)
                    @php $metric = $closingMetricMap->get($kpi['key'], ['display' => '']); @endphp
                    <div class="closing-report-kpi closing-report-kpi--{{ $kpi['tone'] }}">
                        <span class="closing-report-kpi-icon" aria-hidden="true">
                            @if ($kpi['icon'] === 'sales')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 3h10v18H7zM10 7h4M10 11h4M10 15h2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @elseif ($kpi['icon'] === 'tax')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 5 5 19M7.5 8.5h.01M16.5 15.5h.01" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @elseif ($kpi['icon'] === 'in')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 19V5M5 12l7-7 7 7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @else
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M7 12h10M10 17h4" stroke-width="2" stroke-linecap="round"/></svg>
                            @endif
                        </span>
                        <span class="closing-report-kpi-copy">
                            <span class="closing-report-kpi-label">{{ $kpi['label'] }}</span>
                            <span class="closing-report-kpi-value">{{ $metric['display'] !== '' ? $metric['display'] : '--' }}</span>
                        </span>
                    </div>
                @endforeach
            </div>

            @if ($isRigid)
                <p class="closing-report-compliance-note">Compliance report - fixed statutory format. Columns and layout are not adjustable.</p>
            @endif

            <div class="closing-report-sections">
                @foreach ($view['sections'] as $section)
                    @php
                        $sectionKey = (string) ($section['key'] ?? '');
                        $sectionCopy = $sectionKey === 'sales_tax'
                            ? 'Sales, discount, taxable value, and GST for the selected closing day.'
                            : 'Opening cash, cash movement, and closing balance for the selected closing day.';
                    @endphp
                    <section class="closing-report-section closing-report-section--{{ $sectionKey }}">
                        <div class="closing-report-section-head">
                            <div>
                                <h2>{{ $section['title'] }}</h2>
                                <p>{{ $sectionCopy }}</p>
                            </div>
                            <span>{{ number_format($section['rowCount']) }} {{ \Illuminate\Support\Str::plural('row', $section['rowCount']) }}</span>
                        </div>

                        @if ($section['rowCount'] === 0)
                            <div class="closing-report-empty">No data for this closing day.</div>
                        @else
                            <div class="closing-report-ledger">
                                @foreach ($section['rows'] as $row)
                                    @php
                                        $labelCell = collect($row)->firstWhere('key', 'metric');
                                        $amountCell = collect($row)->firstWhere('key', 'amount');
                                        $label = (string) ($labelCell['display'] ?? '');
                                        $amount = (string) ($amountCell['display'] ?? '');
                                        $metricKey = \Illuminate\Support\Str::lower($label);
                                        $tone = 'neutral';
                                        if (\Illuminate\Support\Str::contains($metricKey, ['cash in'])) {
                                            $tone = 'in';
                                        } elseif (\Illuminate\Support\Str::contains($metricKey, ['cash out', 'discount'])) {
                                            $tone = 'out';
                                        } elseif (\Illuminate\Support\Str::contains($metricKey, ['closing balance'])) {
                                            $tone = 'closing';
                                        } elseif (\Illuminate\Support\Str::contains($metricKey, ['gst', 'cgst', 'sgst', 'igst'])) {
                                            $tone = 'tax';
                                        } elseif (\Illuminate\Support\Str::contains($metricKey, ['total sales', 'taxable value'])) {
                                            $tone = 'sales';
                                        }
                                    @endphp
                                    <div class="closing-report-row closing-report-row--{{ $tone }}">
                                        <span class="closing-report-row-label">{{ $label }}</span>
                                        <span class="closing-report-row-value">{{ $amount }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>
    @elseif ($isGstReport)
        <div class="content-inner gst-report-page">
            <div class="gst-report-kpis" aria-label="GST report summary">
                @foreach ($gstKpis as $kpi)
                    <div class="gst-report-kpi gst-report-kpi--{{ $kpi['tone'] }}">
                        <span class="gst-report-kpi-icon" aria-hidden="true">
                            @if ($kpi['icon'] === 'sales')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M7 3h10v18H7zM10 7h4M10 11h4M10 15h2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @elseif ($kpi['icon'] === 'taxable')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M5 5h14M7 9h10M9 13h6M11 17h2" stroke-width="2" stroke-linecap="round"/></svg>
                            @elseif ($kpi['icon'] === 'gst')
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M19 5 5 19M7.5 8.5h.01M16.5 15.5h.01" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @else
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M8 7h8M8 12h8M8 17h5M5 3h14v18H5z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            @endif
                        </span>
                        <span class="gst-report-kpi-copy">
                            <span class="gst-report-kpi-label">{{ $kpi['label'] }}</span>
                            <span class="gst-report-kpi-value">{{ $gstTotals->get($kpi['key'], '--') }}</span>
                        </span>
                    </div>
                @endforeach
            </div>

            @if ($isRigid)
                <p class="gst-report-compliance-note">Compliance report - fixed statutory format. Columns and layout are not adjustable.</p>
            @endif

            @foreach ($view['sections'] as $section)
                <section class="gst-report-section">
                    <div class="gst-report-section-head">
                        <div>
                            <h2>{{ $section['title'] }}</h2>
                            <p>Rate-wise taxable value, GST split, totals, and invoice count.</p>
                        </div>

                        <form method="GET" action="{{ route('report.gst') }}" class="gst-report-filter-form" aria-label="GST report period">
                            <label for="gst-report-period">Period</label>
                            <div class="gst-report-select">
                                <select id="gst-report-period" name="date_preset" class="gst-report-input">
                                    @foreach ($presets as $preset)
                                        <option value="{{ $preset->value }}" @selected(request('date_preset', 'this_month') === $preset->value)>
                                            {{ \Illuminate\Support\Str::headline($preset->value) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            @if (request()->query())
                                <a href="{{ route('report.gst') }}" class="gst-report-clear">Clear</a>
                            @endif
                            <button type="submit" class="gst-report-apply">Apply</button>
                        </form>
                    </div>

                    @if ($section['rowCount'] === 0)
                        <div class="gst-report-empty">No GST transactions for this scope.</div>
                    @else
                        @php
                            $hasRunningBalance = collect($section['columns'])->contains(fn ($c) => $c['key'] === 'running_balance');
                        @endphp
                        <div class="gst-report-table-wrap">
                            <table class="gst-report-table js-report-table" data-has-running-balance="{{ $hasRunningBalance ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        @foreach ($section['columns'] as $col)
                                            <th data-col-key="{{ $col['key'] }}" data-numeric="{{ $col['numeric'] ? '1' : '0' }}"
                                                class="js-sort-th {{ $col['numeric'] ? 'text-right' : 'text-left' }}">
                                                {{ $col['label'] }}<span class="js-sort-arrow"></span>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['rows'] as $row)
                                        <tr>
                                            @foreach ($row as $cell)
                                                @php
                                                    $cellKey = (string) ($cell['key'] ?? '');
                                                    $displayValue = (string) ($cell['display'] ?? '');
                                                    $raw = $cell['raw'] ?? null;
                                                    if (is_numeric($raw)) {
                                                        $sortVal = (string) $raw;
                                                    } elseif ($raw instanceof \DateTimeInterface) {
                                                        $sortVal = (string) $raw->getTimestamp();
                                                    } elseif (is_string($raw) && strtotime($raw) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
                                                        $sortVal = (string) strtotime($raw);
                                                    } else {
                                                        $sortVal = $displayValue;
                                                    }
                                                @endphp
                                                <td data-col-key="{{ $cellKey }}" data-sort-value="{{ $sortVal }}" class="{{ $cell['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">
                                                    @if ($cellKey === 'rate')
                                                        <span class="gst-report-rate-pill">{{ $displayValue }}</span>
                                                    @elseif (in_array($cellKey, ['cgst', 'sgst', 'igst', 'total_gst'], true))
                                                        <span class="gst-report-money gst-report-money--tax">{{ $displayValue }}</span>
                                                    @elseif (in_array($cellKey, ['taxable', 'total'], true))
                                                        <span class="gst-report-money">{{ $displayValue }}</span>
                                                    @else
                                                        {{ $displayValue }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if ($section['hasTotals'])
                                    <tfoot>
                                        <tr>
                                            @foreach ($section['columns'] as $col)
                                                <td class="{{ $col['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">
                                                    @if ($loop->first)
                                                        <span class="gst-report-total-label">Total</span>
                                                    @elseif (isset($section['totals'][$col['key']]))
                                                        <span class="{{ in_array($col['key'], ['cgst', 'sgst', 'igst', 'total_gst'], true) ? 'gst-report-money gst-report-money--tax' : 'gst-report-money' }}">
                                                            {{ $section['totals'][$col['key']] }}
                                                        </span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>

                        <div class="gst-report-mobile-list" aria-label="GST rate cards">
                            @foreach ($section['rows'] as $row)
                                @php
                                    $cells = collect($row)->keyBy('key');
                                @endphp
                                <article class="gst-report-mobile-card">
                                    <div class="gst-report-mobile-card-head">
                                        <span class="gst-report-rate-pill">{{ $cells->get('rate')['display'] ?? '--' }}</span>
                                        <strong>{{ $cells->get('total')['display'] ?? '--' }}</strong>
                                    </div>
                                    <dl class="gst-report-mobile-grid">
                                        <div><dt>Taxable</dt><dd>{{ $cells->get('taxable')['display'] ?? '--' }}</dd></div>
                                        <div><dt>Total GST</dt><dd class="gst-report-tax-text">{{ $cells->get('total_gst')['display'] ?? '--' }}</dd></div>
                                        <div><dt>CGST</dt><dd>{{ $cells->get('cgst')['display'] ?? '--' }}</dd></div>
                                        <div><dt>SGST</dt><dd>{{ $cells->get('sgst')['display'] ?? '--' }}</dd></div>
                                        <div><dt>IGST</dt><dd>{{ $cells->get('igst')['display'] ?? '--' }}</dd></div>
                                        <div><dt>Invoices</dt><dd>{{ $cells->get('count')['display'] ?? '--' }}</dd></div>
                                    </dl>
                                </article>
                            @endforeach

                            @if ($section['hasTotals'])
                                <article class="gst-report-mobile-card gst-report-mobile-card--total">
                                    <div class="gst-report-mobile-card-head">
                                        <span class="gst-report-total-label">Total</span>
                                        <strong>{{ $section['totals']['total'] ?? '--' }}</strong>
                                    </div>
                                    <dl class="gst-report-mobile-grid">
                                        <div><dt>Taxable</dt><dd>{{ $section['totals']['taxable'] ?? '--' }}</dd></div>
                                        <div><dt>Total GST</dt><dd class="gst-report-tax-text">{{ $section['totals']['total_gst'] ?? '--' }}</dd></div>
                                        <div><dt>CGST</dt><dd>{{ $section['totals']['cgst'] ?? '--' }}</dd></div>
                                        <div><dt>SGST</dt><dd>{{ $section['totals']['sgst'] ?? '--' }}</dd></div>
                                        <div><dt>IGST</dt><dd>{{ $section['totals']['igst'] ?? '--' }}</dd></div>
                                        <div><dt>Invoices</dt><dd>{{ $section['totals']['count'] ?? '--' }}</dd></div>
                                    </dl>
                                </article>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @else
        <div class="content-inner report-register-page" x-data="{ filtersOpen: false }" x-effect="document.body.classList.toggle('report-register-filter-lock', filtersOpen)" @keydown.escape.window="filtersOpen = false">
            <section class="report-register-toolbar" aria-label="Report filters">
                <div class="report-register-toolbar-head">
                    <div>
                        <h2>Report scope</h2>
                        <p>{{ $meta->periodLabel }} · {{ $meta->profileLabel }}</p>
                    </div>
                    <button type="button" class="report-register-filter-trigger" @click="filtersOpen = true" :aria-expanded="filtersOpen.toString()" aria-controls="report-register-filter-sheet">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path d="M4 6h16M7 12h10M10 18h4" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Filters
                        @if (count($activeFilters) > 0)
                            <span>{{ count($activeFilters) }}</span>
                        @endif
                    </button>
                </div>

                @if (count($activeFilters) > 0)
                    <div class="report-register-active-filters" aria-label="Active filters">
                        @foreach ($activeFilters as $filter)
                            <span>{{ $filter }}</span>
                        @endforeach
                        <a href="{{ url()->current() }}">Clear</a>
                    </div>
                @endif

                <button type="button" class="report-register-filter-backdrop" x-show="filtersOpen" x-cloak @click="filtersOpen = false" aria-label="Close filters"></button>
                <form method="GET" action="{{ url()->current() }}" id="report-register-filter-sheet" class="report-register-filter-form" :class="{ 'is-open': filtersOpen }" @submit="filtersOpen = false">
                    <div class="report-register-filter-sheet-head">
                        <div>
                            <p>Filters</p>
                            <span>Refine {{ $definition->title }}</span>
                        </div>
                        <button type="button" @click="filtersOpen = false" aria-label="Close filters">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 6 6 18M6 6l12 12" stroke-width="2" stroke-linecap="round"/></svg>
                        </button>
                    </div>

                    <div class="report-register-filter-field">
                        <label>Period</label>
                        <div class="report-register-select">
                            <select name="date_preset" class="report-register-input">
                                @foreach ($presets as $preset)
                                    <option value="{{ $preset->value }}" @selected(request('date_preset', 'this_month') === $preset->value)>
                                        {{ \Illuminate\Support\Str::headline($preset->value) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    @foreach (($filterControls ?? []) as $fc)
                        <div class="report-register-filter-field">
                            <label>{{ $fc['label'] }}</label>
                            <div class="report-register-select">
                                <select name="{{ $fc['key'] }}" class="report-register-input">
                                    <option value="">All</option>
                                    @foreach ($fc['options'] as $opt)
                                        <option value="{{ $opt['value'] }}" @selected($fc['current'] === (string) $opt['value'])>{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endforeach

                    @unless ($isRigid)
                        <div class="report-register-filter-field">
                            <label>Profile</label>
                            <div class="report-register-select">
                                <select name="profile" class="report-register-input">
                                    @foreach ($definition->profiles as $p)
                                        <option value="{{ $p->value }}" @selected($profile->value === $p->value)>{{ \Illuminate\Support\Str::headline($p->value) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @if ($canExportSensitive && $definition->hasSensitiveColumns())
                            <label class="report-register-sensitive-toggle">
                                <input type="checkbox" name="include_sensitive" value="1" @checked(request()->boolean('include_sensitive'))>
                                <span class="report-register-toggle-track" aria-hidden="true">
                                    <span class="report-register-toggle-thumb"></span>
                                </span>
                                <span class="report-register-toggle-label">Sensitive columns</span>
                            </label>
                        @endif
                    @endunless

                    <div class="report-register-filter-actions">
                        @if (request()->query())
                            <a href="{{ url()->current() }}" class="report-register-clear">Clear</a>
                        @endif
                        <button type="submit" class="report-register-apply">Apply filters</button>
                    </div>
                </form>
            </section>

            @if ($isRigid)
                <p class="report-register-compliance-note">Compliance report - fixed statutory format. Columns and layout are not adjustable.</p>
            @endif

            @foreach ($view['sections'] as $section)
                <section class="report-register-section">
                    <div class="report-register-section-head">
                        <div>
                            <h2>{{ $section['title'] }}</h2>
                            <p>{{ number_format($section['rowCount']) }} {{ \Illuminate\Support\Str::plural('row', $section['rowCount']) }}</p>
                        </div>
                    </div>

                    @if ($section['rowCount'] === 0)
                        <div class="report-register-empty">No data for this scope.</div>
                    @else
                        @php
                            $hasRunningBalance = collect($section['columns'])->contains(fn ($c) => $c['key'] === 'running_balance');
                        @endphp
                        <div class="report-register-table-wrap">
                            <table class="report-register-table js-report-table" data-has-running-balance="{{ $hasRunningBalance ? '1' : '0' }}">
                                <thead>
                                    <tr>
                                        @foreach ($section['columns'] as $col)
                                            <th data-col-key="{{ $col['key'] }}" data-numeric="{{ $col['numeric'] ? '1' : '0' }}"
                                                class="js-sort-th {{ $col['numeric'] ? 'text-right' : 'text-left' }}">
                                                {{ $col['label'] }}<span class="js-sort-arrow"></span>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['rows'] as $row)
                                        <tr>
                                            @foreach ($row as $cell)
                                                @php
                                                    $cellKey = (string) ($cell['key'] ?? '');
                                                    $displayValue = (string) ($cell['display'] ?? '');
                                                    $raw = $cell['raw'] ?? null;
                                                    if (is_numeric($raw)) {
                                                        $sortVal = (string) $raw;
                                                    } elseif ($raw instanceof \DateTimeInterface) {
                                                        $sortVal = (string) $raw->getTimestamp();
                                                    } elseif (is_string($raw) && strtotime($raw) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
                                                        $sortVal = (string) strtotime($raw);
                                                    } else {
                                                        $sortVal = $displayValue;
                                                    }
                                                @endphp
                                                <td data-col-key="{{ $cellKey }}" data-sort-value="{{ $sortVal }}" class="{{ $cell['numeric'] ? 'text-right tabular-nums report-register-numeric-cell' : 'text-left' }}">
                                                    {{ $displayValue !== '' ? $displayValue : '--' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                                @if ($section['hasTotals'])
                                    <tfoot>
                                        <tr>
                                            @foreach ($section['columns'] as $col)
                                                <td class="{{ $col['numeric'] ? 'text-right tabular-nums report-register-numeric-cell' : 'text-left' }}">
                                                    {{ $section['totals'][$col['key']] ?? ($loop->first ? 'Total' : '') }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                        </div>

                        <div class="report-register-mobile-list" aria-label="{{ $section['title'] }} records">
                            @foreach ($section['rows'] as $row)
                                @php
                                    $mobileCells = collect($row);
                                    $primaryCell = $mobileCells->first(function ($cell) {
                                        return ! ($cell['numeric'] ?? false) && trim((string) ($cell['display'] ?? '')) !== '';
                                    }) ?? $mobileCells->first();
                                    $amountCell = $mobileCells->first(function ($cell) {
                                        return ($cell['numeric'] ?? false) && trim((string) ($cell['display'] ?? '')) !== '';
                                    });
                                    $dateCell = $mobileCells->first(function ($cell) {
                                        return \Illuminate\Support\Str::contains((string) ($cell['key'] ?? ''), ['date', 'datetime']);
                                    });
                                @endphp
                                <article class="report-register-mobile-card">
                                    <div class="report-register-mobile-card-head">
                                        <div>
                                            <strong>{{ $primaryCell['display'] ?? 'Record' }}</strong>
                                            @if ($dateCell && ($dateCell['display'] ?? '') !== ($primaryCell['display'] ?? null))
                                                <span>{{ $dateCell['display'] }}</span>
                                            @endif
                                        </div>
                                        @if ($amountCell)
                                            <span class="report-register-mobile-amount">{{ $amountCell['display'] }}</span>
                                        @endif
                                    </div>
                                    <dl class="report-register-mobile-grid">
                                        @foreach ($section['columns'] as $col)
                                            @php
                                                $cell = $mobileCells->firstWhere('key', $col['key']);
                                                $displayValue = (string) ($cell['display'] ?? '');
                                            @endphp
                                            <div>
                                                <dt>{{ $col['label'] }}</dt>
                                                <dd class="{{ $col['numeric'] ? 'tabular-nums report-register-mobile-number' : '' }}">{{ $displayValue !== '' ? $displayValue : '--' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </article>
                            @endforeach

                            @if ($section['hasTotals'])
                                <article class="report-register-mobile-card report-register-mobile-card--total">
                                    <div class="report-register-mobile-card-head">
                                        <div>
                                            <strong>Total</strong>
                                            <span>{{ $section['title'] }}</span>
                                        </div>
                                    </div>
                                    <dl class="report-register-mobile-grid">
                                        @foreach ($section['columns'] as $col)
                                            @if (isset($section['totals'][$col['key']]))
                                                <div>
                                                    <dt>{{ $col['label'] }}</dt>
                                                    <dd class="{{ $col['numeric'] ? 'tabular-nums report-register-mobile-number' : '' }}">{{ $section['totals'][$col['key']] }}</dd>
                                                </div>
                                            @endif
                                        @endforeach
                                    </dl>
                                </article>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <script>
    (function () {
        function sortTable(table, th) {
            const headers = Array.from(table.tHead.rows[0].cells);
            const colIndex = headers.indexOf(th);
            const isNumeric = th.dataset.numeric === '1';
            const colKey = th.dataset.colKey;
            const dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';

            headers.forEach(h => {
                h.dataset.sortDir = '';
                const a = h.querySelector('.js-sort-arrow');
                if (a) a.textContent = '';
            });
            th.dataset.sortDir = dir;
            const arrow = th.querySelector('.js-sort-arrow');
            if (arrow) arrow.textContent = dir === 'asc' ? '▲' : '▼';

            const tbody = table.tBodies[0];
            const rows = Array.from(tbody.rows);
            rows.sort((ra, rb) => {
                const a = ra.cells[colIndex]?.dataset.sortValue ?? '';
                const b = rb.cells[colIndex]?.dataset.sortValue ?? '';
                let cmp;
                if (isNumeric || (a !== '' && b !== '' && !isNaN(a) && !isNaN(b))) {
                    cmp = parseFloat(a || 0) - parseFloat(b || 0);
                } else {
                    cmp = String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
                }
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach(r => tbody.appendChild(r));

            if (table.dataset.hasRunningBalance === '1') {
                const sortedByDate = colKey === 'datetime';
                const rbIndex = headers.findIndex(h => h.dataset.colKey === 'running_balance');
                if (rbIndex !== -1) {
                    Array.from(tbody.rows).forEach(r => {
                        const cell = r.cells[rbIndex];
                        if (!cell) return;
                        if (sortedByDate) {
                            if (cell.dataset.rbDisplay !== undefined) {
                                cell.textContent = cell.dataset.rbDisplay;
                                cell.classList.remove('text-slate-300');
                            }
                        } else {
                            if (cell.dataset.rbDisplay === undefined) cell.dataset.rbDisplay = cell.textContent;
                            cell.textContent = '—';
                            cell.classList.add('text-slate-300');
                        }
                    });
                    const rbHeader = headers[rbIndex];
                    if (rbHeader) rbHeader.title = sortedByDate ? '' : 'Running balance is shown only when sorted by date';
                }
            }
        }

        document.querySelectorAll('.js-report-table').forEach(table => {
            if (!table.tHead || !table.tBodies[0]) return;
            table.querySelectorAll('.js-sort-th').forEach(th => {
                th.addEventListener('click', () => sortTable(table, th));
            });
        });
    })();
    </script>

    @if ($isCashFlowReport)
        <style>
            [x-cloak] { display: none !important; }
            body.cash-report-filter-lock { overflow: hidden; }

            .cash-report-header,
            .cash-report-page {
                --cr-border: #cbd5e1;
                --cr-border-soft: #e2e8f0;
                --cr-surface: #ffffff;
                --cr-surface-muted: #f8fafc;
                --cr-surface-nested: #f3f5f8;
                --cr-ink: #1f2430;
                --cr-ink-2: #4a4334;
                --cr-muted: #64748b;
                --cr-gold: #b45309;
                --cr-gold-hover: #92400e;
                --cr-pos: #047857;
                --cr-neg: #b42318;
                --cr-focus: rgba(245, 158, 11, .2);
                --cr-ease: cubic-bezier(0.23, 1, 0.32, 1);
            }

            .cash-report-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 10px;
                border-bottom: 1px solid var(--cr-border-soft);
                background: #ffffff;
                box-shadow: none !important;
            }
            .cash-report-header > .min-w-0 { min-width: 0; }
            .cash-report-header .page-actions {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 8px;
                margin-left: auto;
            }
            .cash-report-header .page-subtitle,
            .cash-report-header-copy {
                margin: 4px 0 0;
                color: var(--cr-muted);
                font-size: 13px;
                line-height: 1.35;
            }
            .cash-report-header-btn,
            .cash-report-export-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                min-height: 38px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                line-height: 1;
                text-decoration: none;
                white-space: nowrap;
                box-shadow: none !important;
                transition: background-color 140ms var(--cr-ease), border-color 140ms var(--cr-ease), transform 120ms var(--cr-ease);
            }
            .cash-report-header-btn svg,
            .cash-report-export-btn svg {
                display: block;
                flex: 0 0 16px;
                width: 16px !important;
                height: 16px !important;
                stroke-width: 2.2;
            }
            .cash-report-header-btn {
                border: 1px solid var(--cr-border);
                background: #ffffff;
                color: var(--cr-ink-2);
            }
            .cash-report-export-btn {
                border: 1px solid var(--cr-gold);
                background: var(--cr-gold);
                color: #ffffff;
            }
            .cash-report-header-btn:hover {
                border-color: #94a3b8;
                background: var(--cr-surface-muted);
                color: var(--cr-ink);
            }
            .cash-report-export-btn:hover {
                border-color: var(--cr-gold-hover);
                background: var(--cr-gold-hover);
                color: #ffffff;
            }
            .cash-report-header-btn:active,
            .cash-report-export-btn:active,
            .cash-report-filter-trigger:active,
            .cash-report-apply:active,
            .cash-report-clear:active {
                transform: scale(.98);
            }

            .cash-report-page {
                display: grid;
                gap: 16px;
                width: 100%;
                max-width: none;
                color: var(--cr-ink);
            }
            .cash-report-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }
            .cash-report-kpi {
                display: flex;
                align-items: center;
                gap: 12px;
                min-height: 76px;
                min-width: 0;
                padding: 14px 15px;
                border: 1px solid var(--cr-border-soft);
                border-radius: 12px;
                background: #ffffff;
                box-shadow: none;
            }
            .cash-report-kpi-icon {
                display: inline-flex;
                flex: 0 0 36px;
                width: 36px;
                height: 36px;
                align-items: center;
                justify-content: center;
                border-radius: 10px;
                border: 1px solid var(--cr-border-soft);
                background: var(--cr-surface-muted);
                color: var(--cr-muted);
            }
            .cash-report-kpi--in .cash-report-kpi-icon {
                border-color: #bbf7d0;
                background: #f0fdf4;
                color: var(--cr-pos);
            }
            .cash-report-kpi--out .cash-report-kpi-icon {
                border-color: #fecdca;
                background: #fef2f2;
                color: var(--cr-neg);
            }
            .cash-report-kpi--closing .cash-report-kpi-icon {
                border-color: #f3dcb6;
                background: #fdf6ec;
                color: var(--cr-gold);
            }
            .cash-report-kpi-copy {
                min-width: 0;
                flex: 1 1 auto;
            }
            .cash-report-kpi-label {
                display: block;
                margin-bottom: 6px;
                color: var(--cr-muted);
                font-size: 12px;
                font-weight: 500;
                line-height: 1.25;
            }
            .cash-report-kpi-value {
                display: block;
                color: var(--cr-ink);
                font-size: 21px;
                font-weight: 650;
                line-height: 1.15;
                font-variant-numeric: tabular-nums;
                overflow-wrap: normal;
                word-break: normal;
            }
            .cash-report-kpi--in .cash-report-kpi-value { color: var(--cr-pos); }
            .cash-report-kpi--out .cash-report-kpi-value { color: var(--cr-neg); }

            .cash-report-toolbar,
            .cash-report-section {
                border: 1px solid var(--cr-border-soft);
                border-radius: 14px;
                background: #ffffff;
                box-shadow: none;
                overflow: hidden;
            }
            .cash-report-toolbar-head,
            .cash-report-section-head {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: 16px;
                padding: 16px 18px;
                border-bottom: 1px solid var(--cr-border-soft);
                background: #ffffff;
            }
            .cash-report-toolbar-head h2,
            .cash-report-section-head h2 {
                margin: 0;
                color: var(--cr-ink);
                font-size: 17px;
                font-weight: 650;
                line-height: 1.2;
                letter-spacing: -0.12px;
            }
            .cash-report-toolbar-head p,
            .cash-report-section-head p {
                margin: 4px 0 0;
                color: var(--cr-muted);
                font-size: 13px;
                line-height: 1.35;
            }
            .cash-report-filter-trigger {
                display: none;
                align-items: center;
                justify-content: center;
                gap: 7px;
                min-height: 36px;
                padding: 0 12px;
                border: 1px solid var(--cr-border);
                border-radius: 10px;
                background: #ffffff;
                color: var(--cr-ink-2);
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: background-color 140ms var(--cr-ease), border-color 140ms var(--cr-ease), transform 120ms var(--cr-ease);
            }
            .cash-report-filter-trigger span {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 18px;
                height: 18px;
                border-radius: 999px;
                background: var(--cr-gold);
                color: #ffffff;
                font-size: 11px;
                line-height: 1;
            }
            .cash-report-active-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                padding: 12px 18px;
                border-bottom: 1px solid var(--cr-border-soft);
                background: var(--cr-surface-muted);
            }
            .cash-report-active-filters span,
            .cash-report-active-filters a {
                display: inline-flex;
                align-items: center;
                min-height: 28px;
                padding: 0 10px;
                border: 1px solid var(--cr-border-soft);
                border-radius: 999px;
                background: #ffffff;
                color: var(--cr-ink-2);
                font-size: 12px;
                font-weight: 500;
                text-decoration: none;
            }
            .cash-report-active-filters a {
                color: var(--cr-gold);
                border-color: #f3dcb6;
            }
            .cash-report-filter-backdrop,
            .cash-report-filter-sheet-head {
                display: none;
            }
            .cash-report-filter-form {
                display: flex;
                flex-wrap: wrap;
                align-items: end;
                gap: 12px;
                padding: 16px 18px;
                background: #ffffff;
            }
            .cash-report-filter-field {
                min-width: 136px;
            }
            .cash-report-filter-field label {
                display: block;
                margin-bottom: 7px;
                color: var(--cr-ink-2);
                font-size: 12.5px;
                font-weight: 600;
                line-height: 1.2;
            }
            .cash-report-select {
                position: relative;
            }
            .cash-report-select::after {
                content: "";
                position: absolute;
                top: 50%;
                right: 14px;
                width: 8px;
                height: 8px;
                border-right: 2px solid #64748b;
                border-bottom: 2px solid #64748b;
                transform: translateY(-65%) rotate(45deg);
                pointer-events: none;
            }
            .cash-report-page .cash-report-input {
                width: 100%;
                min-height: 40px;
                padding: 0 38px 0 12px;
                border: 1px solid var(--cr-border) !important;
                border-radius: 10px;
                background: #ffffff !important;
                color: var(--cr-ink) !important;
                font-size: 14px;
                box-shadow: none !important;
                appearance: none !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                background-image: none !important;
            }
            .cash-report-page .cash-report-input:focus {
                border-color: var(--cr-gold) !important;
                box-shadow: 0 0 0 3px var(--cr-focus) !important;
                outline: none;
            }
            .cash-report-sensitive-toggle {
                display: inline-flex;
                align-items: center;
                gap: 9px;
                min-height: 40px;
                padding: 0 8px;
                color: var(--cr-ink-2);
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                user-select: none;
            }
            .cash-report-sensitive-toggle input {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                opacity: 0;
                pointer-events: none;
            }
            .cash-report-toggle-track {
                position: relative;
                display: inline-flex;
                width: 38px;
                height: 22px;
                flex: 0 0 38px;
                align-items: center;
                border: 1px solid var(--cr-border);
                border-radius: 999px;
                background: var(--cr-surface-nested);
                transition: background-color 140ms var(--cr-ease), border-color 140ms var(--cr-ease);
            }
            .cash-report-toggle-thumb {
                position: absolute;
                left: 3px;
                width: 16px;
                height: 16px;
                border-radius: 999px;
                background: #ffffff;
                border: 1px solid var(--cr-border);
                transition: transform 160ms var(--cr-ease), border-color 140ms var(--cr-ease);
            }
            .cash-report-sensitive-toggle input:checked + .cash-report-toggle-track {
                border-color: var(--cr-gold);
                background: var(--cr-gold);
            }
            .cash-report-sensitive-toggle input:checked + .cash-report-toggle-track .cash-report-toggle-thumb {
                border-color: #ffffff;
                transform: translateX(16px);
            }
            .cash-report-sensitive-toggle input:focus-visible + .cash-report-toggle-track {
                box-shadow: 0 0 0 3px var(--cr-focus);
            }
            .cash-report-toggle-label {
                white-space: nowrap;
            }
            .cash-report-filter-actions {
                display: flex;
                align-items: end;
                gap: 8px;
                margin-left: auto;
            }
            .cash-report-apply,
            .cash-report-clear {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                transition: background-color 140ms var(--cr-ease), border-color 140ms var(--cr-ease), transform 120ms var(--cr-ease);
            }
            .cash-report-apply {
                border: 1px solid var(--cr-gold);
                background: var(--cr-gold);
                color: #ffffff;
            }
            .cash-report-apply:hover {
                border-color: var(--cr-gold-hover);
                background: var(--cr-gold-hover);
            }
            .cash-report-clear {
                border: 1px solid var(--cr-border);
                background: #ffffff;
                color: var(--cr-ink-2);
            }
            .cash-report-clear:hover {
                border-color: #94a3b8;
                background: var(--cr-surface-muted);
                color: var(--cr-ink);
            }
            .cash-report-compliance-note {
                margin: 0;
                color: var(--cr-muted);
                font-size: 12px;
            }

            .cash-report-table-wrap {
                overflow-x: auto;
                scrollbar-color: #cbd5e1 #f8fafc;
                scrollbar-width: thin;
            }
            .cash-report-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 14px;
                min-width: 920px;
            }
            .cash-report-table thead th {
                position: relative;
                padding: 11px 16px;
                border-bottom: 1px solid var(--cr-border-soft);
                background: var(--cr-surface-muted);
                color: #475569;
                font-size: 11.5px;
                font-weight: 600;
                letter-spacing: .02em;
                text-transform: uppercase;
                white-space: nowrap;
                cursor: pointer;
                user-select: none;
            }
            .cash-report-table tbody td,
            .cash-report-table tfoot td {
                padding: 14px 16px;
                border-bottom: 1px solid #edf2f7;
                color: var(--cr-ink);
                font-weight: 400;
                line-height: 1.35;
                vertical-align: middle;
            }
            .cash-report-table tbody tr:nth-child(even) {
                background: #fbfdff;
            }
            .cash-report-table tbody tr:hover {
                background: #fffaf2;
            }
            .cash-report-table tbody tr:last-child td {
                border-bottom: 0;
            }
            .cash-report-table tfoot td {
                background: var(--cr-surface-muted);
                font-weight: 650;
            }
            .cash-report-table .text-right {
                text-align: right;
            }
            .cash-report-table .text-left {
                text-align: left;
            }
            .cash-report-table [data-col-key="amount"],
            .cash-report-table [data-col-key="running_balance"],
            .cash-report-table [data-col-key="value"] {
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .cash-report-table [data-col-key="datetime"] {
                position: sticky;
                left: 0;
                z-index: 2;
                width: 158px;
                min-width: 158px;
                border-right: 1px solid #edf2f7;
                background: inherit;
            }
            .cash-report-table thead [data-col-key="datetime"] {
                z-index: 3;
                background: var(--cr-surface-muted);
            }
            .cash-report-date-cell {
                display: grid;
                gap: 2px;
                color: var(--cr-ink);
                font-size: 13px;
                line-height: 1.25;
                white-space: nowrap;
            }
            .cash-report-date-cell small {
                color: var(--cr-muted);
                font-size: 12px;
            }
            .cash-report-flow-pill,
            .cash-report-source-pill,
            .cash-report-mode-pill {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                min-height: 28px;
                max-width: 100%;
                padding: 0 10px;
                border: 1px solid var(--cr-border-soft);
                border-radius: 999px;
                background: #ffffff;
                color: var(--cr-ink-2);
                font-size: 12.5px;
                font-weight: 600;
                white-space: nowrap;
            }
            .cash-report-flow-pill span {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 16px;
                height: 16px;
                border-radius: 999px;
                font-size: 13px;
                line-height: 1;
            }
            .cash-report-flow-pill--in {
                border-color: #bbf7d0;
                background: #f0fdf4;
                color: var(--cr-pos);
            }
            .cash-report-flow-pill--in span {
                background: #dcfce7;
            }
            .cash-report-flow-pill--out {
                border-color: #fecdca;
                background: #fff7f7;
                color: var(--cr-neg);
            }
            .cash-report-flow-pill--out span {
                background: #fee2e2;
            }
            .cash-report-source-pill {
                border-color: #dbe4ef;
                background: #f8fafc;
                color: #334155;
                justify-content: flex-start;
            }
            .cash-report-mode-pill {
                min-width: 62px;
                border-color: #f3dcb6;
                background: #fdf6ec;
                color: #8a4b0f;
            }
            .cash-report-money {
                display: inline-block;
                font-size: 14.5px;
                font-weight: 650;
                line-height: 1.2;
                white-space: nowrap;
            }
            .cash-report-money--in {
                color: var(--cr-pos);
            }
            .cash-report-money--out {
                color: var(--cr-neg);
            }
            .cash-report-money--balance {
                color: var(--cr-ink);
                font-weight: 600;
            }
            .cash-report-description {
                display: block;
                max-width: 320px;
                color: #475569;
                font-size: 13px;
                line-height: 1.35;
                white-space: normal;
            }
            .cash-report-cell--description {
                min-width: 260px;
            }
            .cash-report-cell--source {
                min-width: 126px;
            }
            .cash-report-cell--payment-mode {
                min-width: 112px;
            }
            .cash-report-cell--amount,
            .cash-report-cell--running-balance {
                min-width: 138px;
            }
            .js-sort-arrow {
                display: inline-block;
                min-width: 10px;
                margin-left: 4px;
                color: var(--cr-muted);
                font-size: 10px;
            }
            .cash-report-empty {
                padding: 42px 20px;
                color: var(--cr-muted);
                text-align: center;
                font-size: 13px;
            }

            @media (max-width: 900px) {
                .cash-report-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767px) {
                .content-header.cash-report-header {
                    flex-wrap: nowrap;
                    align-items: center;
                    gap: 8px;
                }
                .cash-report-header .content-header-nav {
                    margin-right: 0;
                }
                .cash-report-header .page-title {
                    font-size: 17px;
                    line-height: 1.15;
                }
                .cash-report-header .page-subtitle,
                .cash-report-header-copy {
                    display: none;
                }
                .cash-report-header .page-actions {
                    width: auto;
                    flex: 0 0 auto;
                    gap: 7px;
                }
                .cash-report-header-btn,
                .cash-report-export-btn {
                    width: 36px !important;
                    min-width: 36px !important;
                    height: 36px !important;
                    min-height: 36px !important;
                    padding: 0 !important;
                    border-radius: 10px;
                }
                .cash-report-header-btn svg,
                .cash-report-export-btn svg {
                    flex-basis: 17px;
                    width: 17px !important;
                    height: 17px !important;
                }
                .cash-report-action-label {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                }
                .cash-report-page {
                    gap: 12px;
                }
                .cash-report-kpis {
                    gap: 10px;
                }
                .cash-report-kpi {
                    display: grid;
                    grid-template-columns: 1fr;
                    grid-template-columns: 22px minmax(0, 1fr);
                    grid-template-rows: 22px minmax(31px, 1fr);
                    grid-template-areas:
                        "icon label"
                        "value value";
                    align-items: stretch;
                    min-height: 82px;
                    padding: 8px 10px;
                    row-gap: 4px;
                }
                .cash-report-kpi-icon {
                    grid-area: icon;
                    justify-self: start;
                    width: 22px;
                    height: 22px;
                    flex-basis: 22px;
                    border-radius: 7px;
                }
                .cash-report-kpi-icon svg {
                    width: 13px !important;
                    height: 13px !important;
                }
                .cash-report-kpi-copy {
                    display: contents;
                }
                .cash-report-kpi-label {
                    grid-area: label;
                    align-self: center;
                    justify-self: start;
                    margin-bottom: 0;
                    font-size: 10.5px;
                    line-height: 1.2;
                    text-align: left;
                    overflow-wrap: anywhere;
                }
                .cash-report-kpi-value {
                    grid-area: value;
                    align-self: center;
                    justify-self: center;
                    max-width: 100%;
                    font-size: 15px;
                    line-height: 1.15;
                    text-align: center;
                    white-space: normal;
                    overflow-wrap: anywhere;
                    word-break: normal;
                }
                .cash-report-toolbar-head,
                .cash-report-section-head {
                    align-items: center;
                    padding: 13px 14px;
                }
                .cash-report-toolbar-head h2,
                .cash-report-section-head h2 {
                    font-size: 15px;
                }
                .cash-report-toolbar-head p,
                .cash-report-section-head p {
                    font-size: 11.5px;
                }
                .cash-report-filter-trigger {
                    display: inline-flex;
                }
                .cash-report-active-filters {
                    padding: 10px 14px;
                    gap: 6px;
                }
                .cash-report-active-filters span,
                .cash-report-active-filters a {
                    min-height: 26px;
                    font-size: 11.5px;
                }
                .cash-report-filter-backdrop {
                    position: fixed;
                    inset: 0;
                    z-index: 60;
                    display: block;
                    width: 100%;
                    height: 100%;
                    border: 0;
                    background: rgba(15, 23, 42, .38);
                }
                .cash-report-filter-form {
                    position: fixed;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 70;
                    display: grid;
                    grid-template-columns: 1fr;
                    gap: 12px;
                    max-height: 86dvh;
                    padding: 14px;
                    overflow-y: auto;
                    border: 1px solid var(--cr-border);
                    border-bottom: 0;
                    border-radius: 16px 16px 0 0;
                    background: #ffffff;
                    transform: translateY(105%);
                    opacity: 0;
                    pointer-events: none;
                    transition: transform 220ms var(--cr-ease), opacity 180ms var(--cr-ease);
                }
                .cash-report-filter-form.is-open {
                    transform: translateY(0);
                    opacity: 1;
                    pointer-events: auto;
                }
                .cash-report-filter-sheet-head {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                }
                .cash-report-filter-sheet-head p {
                    margin: 0;
                    color: var(--cr-ink);
                    font-size: 15px;
                    font-weight: 650;
                }
                .cash-report-filter-sheet-head span {
                    display: block;
                    margin-top: 2px;
                    color: var(--cr-muted);
                    font-size: 12px;
                }
                .cash-report-filter-sheet-head button {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 34px;
                    height: 34px;
                    border: 1px solid var(--cr-border);
                    border-radius: 10px;
                    background: #ffffff;
                    color: var(--cr-ink-2);
                }
                .cash-report-filter-field {
                    min-width: 0;
                }
                .cash-report-input {
                    min-height: 42px;
                }
                .cash-report-sensitive-toggle {
                    min-height: 38px;
                    padding: 0;
                }
                .cash-report-filter-actions {
                    display: grid;
                    grid-template-columns: 1fr;
                    margin-left: 0;
                    gap: 8px;
                }
                .cash-report-apply,
                .cash-report-clear {
                    width: 100%;
                    min-height: 42px;
                }
                .cash-report-table {
                    font-size: 13px;
                    min-width: 720px;
                }
                .cash-report-table thead th,
                .cash-report-table tbody td,
                .cash-report-table tfoot td {
                    padding: 11px 12px;
                }
            }

            @media (max-width: 380px) {
                .cash-report-kpis {
                    grid-template-columns: 1fr;
                }
                .cash-report-kpi-value {
                    font-size: 12.5px;
                }
            }
        </style>
    @endif

    @if ($isDailyClosingReport)
        <style>
            .closing-report-header,
            .closing-report-page {
                --clr-border: #cbd5e1;
                --clr-border-soft: #e2e8f0;
                --clr-surface: #ffffff;
                --clr-muted-surface: #f8fafc;
                --clr-nested: #f3f5f8;
                --clr-ink: #1f2430;
                --clr-muted: #64748b;
                --clr-gold: #b45309;
                --clr-gold-hover: #92400e;
                --clr-pos: #047857;
                --clr-neg: #b42318;
                --clr-focus: rgba(245, 158, 11, .2);
                --clr-ease: cubic-bezier(0.23, 1, 0.32, 1);
            }

            .closing-report-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 10px;
                border-bottom: 1px solid var(--clr-border-soft);
                background: #ffffff;
                box-shadow: none !important;
            }
            .closing-report-header > .min-w-0 {
                min-width: 0;
            }
            .closing-report-header .page-actions {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 8px;
                margin-left: auto;
            }
            .closing-report-header-btn,
            .closing-report-export-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                min-height: 38px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                line-height: 1;
                text-decoration: none;
                white-space: nowrap;
                box-shadow: none !important;
                transition: background-color 140ms var(--clr-ease), border-color 140ms var(--clr-ease), transform 120ms var(--clr-ease);
            }
            .closing-report-header-btn svg,
            .closing-report-export-btn svg {
                display: block;
                flex: 0 0 16px;
                width: 16px !important;
                height: 16px !important;
                stroke-width: 2.2;
            }
            .closing-report-header-btn {
                border: 1px solid var(--clr-border);
                background: #ffffff;
                color: #4a4334;
            }
            .closing-report-export-btn {
                border: 1px solid var(--clr-gold);
                background: var(--clr-gold);
                color: #ffffff;
            }
            .closing-report-header-btn:hover {
                border-color: #94a3b8;
                background: var(--clr-muted-surface);
                color: var(--clr-ink);
            }
            .closing-report-export-btn:hover {
                border-color: var(--clr-gold-hover);
                background: var(--clr-gold-hover);
                color: #ffffff;
            }
            .closing-report-header-btn:active,
            .closing-report-export-btn:active,
            .closing-report-apply:active,
            .closing-report-clear:active {
                transform: scale(.98);
            }

            .closing-report-page {
                display: grid;
                gap: 16px;
                width: 100%;
                max-width: none;
                color: var(--clr-ink);
            }
            .closing-report-toolbar,
            .closing-report-section {
                border: 1px solid var(--clr-border-soft);
                border-radius: 14px;
                background: var(--clr-surface);
                box-shadow: none;
                overflow: hidden;
            }
            .closing-report-toolbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                padding: 16px 18px;
            }
            .closing-report-toolbar-copy {
                min-width: 0;
            }
            .closing-report-toolbar-copy h2,
            .closing-report-section-head h2 {
                margin: 0;
                color: var(--clr-ink);
                font-size: 17px;
                font-weight: 650;
                line-height: 1.2;
                letter-spacing: 0;
            }
            .closing-report-toolbar-copy p,
            .closing-report-section-head p,
            .closing-report-compliance-note {
                margin: 4px 0 0;
                color: var(--clr-muted);
                font-size: 13px;
                line-height: 1.35;
            }
            .closing-report-date-form {
                display: flex;
                align-items: end;
                justify-content: flex-end;
                gap: 8px;
                min-width: 0;
            }
            .closing-report-date-form label {
                display: grid;
                min-height: 40px;
                place-items: center;
                color: #4a4334;
                font-size: 12.5px;
                font-weight: 600;
                line-height: 1;
            }
            .closing-report-page .closing-report-date-input,
            .closing-report-date-form .jf-date-picker-input {
                width: 168px;
                min-height: 40px;
                padding: 0 12px;
                border: 1px solid var(--clr-border) !important;
                border-radius: 10px !important;
                background: #ffffff !important;
                color: var(--clr-ink) !important;
                font-size: 14px;
                box-shadow: none !important;
            }
            .closing-report-page .closing-report-date-input:focus,
            .closing-report-date-form .jf-date-picker-input:focus {
                border-color: var(--clr-gold) !important;
                box-shadow: 0 0 0 3px var(--clr-focus) !important;
                outline: none;
            }
            .closing-report-apply,
            .closing-report-clear {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                white-space: nowrap;
                cursor: pointer;
                transition: background-color 140ms var(--clr-ease), border-color 140ms var(--clr-ease), transform 120ms var(--clr-ease);
            }
            .closing-report-apply {
                border: 1px solid var(--clr-gold);
                background: var(--clr-gold);
                color: #ffffff;
            }
            .closing-report-apply:hover {
                border-color: var(--clr-gold-hover);
                background: var(--clr-gold-hover);
                color: #ffffff;
            }
            .closing-report-clear {
                border: 1px solid var(--clr-border);
                background: #ffffff;
                color: #4a4334;
            }
            .closing-report-clear:hover {
                border-color: #94a3b8;
                background: var(--clr-muted-surface);
                color: var(--clr-ink);
            }

            .closing-report-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }
            .closing-report-kpi {
                display: flex;
                align-items: center;
                gap: 12px;
                min-height: 76px;
                min-width: 0;
                padding: 14px 15px;
                border: 1px solid var(--clr-border-soft);
                border-radius: 12px;
                background: #ffffff;
                box-shadow: none;
            }
            .closing-report-kpi-icon {
                display: inline-flex;
                flex: 0 0 36px;
                width: 36px;
                height: 36px;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--clr-border-soft);
                border-radius: 10px;
                background: var(--clr-muted-surface);
                color: var(--clr-muted);
            }
            .closing-report-kpi--sales .closing-report-kpi-icon,
            .closing-report-kpi--tax .closing-report-kpi-icon,
            .closing-report-kpi--closing .closing-report-kpi-icon {
                border-color: #f3dcb6;
                background: #fdf6ec;
                color: var(--clr-gold);
            }
            .closing-report-kpi--in .closing-report-kpi-icon {
                border-color: #bbf7d0;
                background: #f0fdf4;
                color: var(--clr-pos);
            }
            .closing-report-kpi-copy {
                min-width: 0;
                flex: 1 1 auto;
            }
            .closing-report-kpi-label {
                display: block;
                margin-bottom: 6px;
                color: var(--clr-muted);
                font-size: 12px;
                font-weight: 500;
                line-height: 1.25;
            }
            .closing-report-kpi-value {
                display: block;
                color: var(--clr-ink);
                font-size: 21px;
                font-weight: 650;
                line-height: 1.15;
                font-variant-numeric: tabular-nums;
                overflow-wrap: normal;
                word-break: normal;
            }
            .closing-report-kpi--in .closing-report-kpi-value {
                color: var(--clr-pos);
            }

            .closing-report-sections {
                display: grid;
                grid-template-columns: minmax(0, 1.25fr) minmax(320px, .75fr);
                gap: 14px;
                align-items: start;
            }
            .closing-report-section-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 14px;
                padding: 16px 18px;
                border-bottom: 1px solid var(--clr-border-soft);
                background: #ffffff;
            }
            .closing-report-section-head span {
                display: inline-flex;
                align-items: center;
                min-height: 26px;
                padding: 0 10px;
                border: 1px solid var(--clr-border-soft);
                border-radius: 999px;
                background: var(--clr-muted-surface);
                color: var(--clr-muted);
                font-size: 12px;
                font-weight: 600;
                white-space: nowrap;
            }
            .closing-report-ledger {
                display: grid;
            }
            .closing-report-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                min-height: 54px;
                padding: 0 18px;
                border-bottom: 1px solid #edf2f7;
                background: #ffffff;
            }
            .closing-report-row:nth-child(even) {
                background: #fbfdff;
            }
            .closing-report-row:last-child {
                border-bottom: 0;
            }
            .closing-report-row-label {
                color: #334155;
                font-size: 14px;
                font-weight: 500;
                line-height: 1.3;
            }
            .closing-report-row-value {
                color: var(--clr-ink);
                font-size: 15px;
                font-weight: 650;
                line-height: 1.2;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .closing-report-row--in .closing-report-row-value {
                color: var(--clr-pos);
            }
            .closing-report-row--out .closing-report-row-value {
                color: var(--clr-neg);
            }
            .closing-report-row--tax .closing-report-row-value,
            .closing-report-row--sales .closing-report-row-value,
            .closing-report-row--closing .closing-report-row-value {
                color: #8a4b0f;
            }
            .closing-report-empty {
                padding: 42px 20px;
                color: var(--clr-muted);
                text-align: center;
                font-size: 13px;
            }
            .closing-report-compliance-note {
                margin: 0;
            }

            @media (max-width: 1040px) {
                .closing-report-sections {
                    grid-template-columns: 1fr;
                }
                .closing-report-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767px) {
                .content-header.closing-report-header {
                    flex-wrap: nowrap;
                    align-items: center;
                    gap: 8px;
                }
                .closing-report-header .content-header-nav {
                    margin-right: 0;
                }
                .closing-report-header .page-title {
                    font-size: 17px;
                    line-height: 1.15;
                }
                .closing-report-header .page-subtitle {
                    display: none;
                }
                .closing-report-header .page-actions {
                    width: auto;
                    flex: 0 0 auto;
                    gap: 7px;
                }
                .closing-report-header-btn,
                .closing-report-export-btn {
                    width: 36px !important;
                    min-width: 36px !important;
                    height: 36px !important;
                    min-height: 36px !important;
                    padding: 0 !important;
                    border-radius: 10px;
                }
                .closing-report-header-btn svg,
                .closing-report-export-btn svg {
                    flex-basis: 17px;
                    width: 17px !important;
                    height: 17px !important;
                }
                .closing-report-action-label {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                }
                .closing-report-page {
                    gap: 12px;
                }
                .closing-report-toolbar {
                    display: grid;
                    gap: 12px;
                    padding: 13px 14px;
                }
                .closing-report-toolbar-copy h2 {
                    font-size: 15px;
                }
                .closing-report-toolbar-copy p {
                    font-size: 11.5px;
                }
                .closing-report-date-form {
                    display: grid;
                    grid-template-columns: minmax(0, 1fr) auto;
                    gap: 8px;
                }
                .closing-report-date-form label {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                }
                .closing-report-page .closing-report-date-input,
                .closing-report-date-form .jf-date-picker-input {
                    width: 100%;
                    min-height: 42px;
                    font-size: 13px;
                }
                .closing-report-clear {
                    grid-column: 1 / -1;
                    width: 100%;
                    min-height: 40px;
                }
                .closing-report-apply {
                    min-height: 42px;
                    padding-inline: 12px;
                }
                .closing-report-kpis {
                    gap: 10px;
                }
                .closing-report-kpi {
                    display: grid;
                    grid-template-columns: 1fr;
                    grid-template-columns: 22px minmax(0, 1fr);
                    grid-template-rows: 22px minmax(31px, 1fr);
                    grid-template-areas:
                        "icon label"
                        "value value";
                    align-items: stretch;
                    min-height: 82px;
                    padding: 8px 10px;
                    row-gap: 4px;
                }
                .closing-report-kpi-icon {
                    grid-area: icon;
                    justify-self: start;
                    width: 22px;
                    height: 22px;
                    flex-basis: 22px;
                    border-radius: 7px;
                }
                .closing-report-kpi-icon svg {
                    width: 13px !important;
                    height: 13px !important;
                }
                .closing-report-kpi-copy {
                    display: contents;
                }
                .closing-report-kpi-label {
                    grid-area: label;
                    align-self: center;
                    justify-self: start;
                    margin-bottom: 0;
                    font-size: 10.5px;
                    line-height: 1.2;
                    text-align: left;
                    overflow-wrap: anywhere;
                }
                .closing-report-kpi-value {
                    grid-area: value;
                    align-self: center;
                    justify-self: center;
                    max-width: 100%;
                    font-size: 15px;
                    line-height: 1.15;
                    text-align: center;
                    white-space: normal;
                    overflow-wrap: anywhere;
                    word-break: normal;
                }
                .closing-report-section-head {
                    padding: 13px 14px;
                }
                .closing-report-section-head h2 {
                    font-size: 15px;
                }
                .closing-report-section-head p {
                    font-size: 11.5px;
                }
                .closing-report-section-head span {
                    min-height: 24px;
                    padding-inline: 8px;
                    font-size: 11px;
                }
                .closing-report-row {
                    min-height: 50px;
                    padding: 0 14px;
                    gap: 10px;
                }
                .closing-report-row-label {
                    font-size: 13px;
                }
                .closing-report-row-value {
                    font-size: 15px;
                }
            }

            @media (max-width: 380px) {
                .closing-report-kpis {
                    grid-template-columns: 1fr;
                }
                .closing-report-date-form {
                    grid-template-columns: 1fr;
                }
                .closing-report-apply {
                    width: 100%;
                }
            }
        </style>
    @endif

    @if ($isGstReport)
        <style>
            .gst-report-header,
            .gst-report-page {
                --gr-border: #cbd5e1;
                --gr-border-soft: #e2e8f0;
                --gr-surface: #ffffff;
                --gr-muted-surface: #f8fafc;
                --gr-ink: #1f2430;
                --gr-muted: #64748b;
                --gr-gold: #b45309;
                --gr-gold-hover: #92400e;
                --gr-pos: #047857;
                --gr-focus: rgba(245, 158, 11, .2);
                --gr-ease: cubic-bezier(0.23, 1, 0.32, 1);
            }

            .gst-report-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 10px;
                border-bottom: 1px solid var(--gr-border-soft);
                background: #ffffff;
                box-shadow: none !important;
            }
            .gst-report-header > .min-w-0 {
                min-width: 0;
            }
            .gst-report-header .page-actions {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 8px;
                margin-left: auto;
            }
            .gst-report-header-btn,
            .gst-report-export-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                min-height: 38px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                line-height: 1;
                text-decoration: none;
                white-space: nowrap;
                box-shadow: none !important;
                transition: background-color 140ms var(--gr-ease), border-color 140ms var(--gr-ease), transform 120ms var(--gr-ease);
            }
            .gst-report-header-btn svg,
            .gst-report-export-btn svg {
                display: block;
                flex: 0 0 16px;
                width: 16px !important;
                height: 16px !important;
                stroke-width: 2.2;
            }
            .gst-report-header-btn {
                border: 1px solid var(--gr-border);
                background: #ffffff;
                color: #4a4334;
            }
            .gst-report-export-btn {
                border: 1px solid var(--gr-gold);
                background: var(--gr-gold);
                color: #ffffff;
            }
            .gst-report-header-btn:hover {
                border-color: #94a3b8;
                background: var(--gr-muted-surface);
                color: var(--gr-ink);
            }
            .gst-report-export-btn:hover {
                border-color: var(--gr-gold-hover);
                background: var(--gr-gold-hover);
                color: #ffffff;
            }
            .gst-report-header-btn:active,
            .gst-report-export-btn:active,
            .gst-report-apply:active,
            .gst-report-clear:active {
                transform: scale(.98);
            }

            .gst-report-page {
                display: grid;
                gap: 16px;
                width: 100%;
                max-width: none;
                color: var(--gr-ink);
            }
            .gst-report-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }
            .gst-report-kpi {
                display: flex;
                align-items: center;
                gap: 12px;
                min-height: 76px;
                min-width: 0;
                padding: 14px 15px;
                border: 1px solid var(--gr-border-soft);
                border-radius: 12px;
                background: #ffffff;
                box-shadow: none;
            }
            .gst-report-kpi-icon {
                display: inline-flex;
                flex: 0 0 36px;
                width: 36px;
                height: 36px;
                align-items: center;
                justify-content: center;
                border: 1px solid #f3dcb6;
                border-radius: 10px;
                background: #fdf6ec;
                color: var(--gr-gold);
            }
            .gst-report-kpi--gst .gst-report-kpi-icon {
                border-color: #bbf7d0;
                background: #f0fdf4;
                color: var(--gr-pos);
            }
            .gst-report-kpi-copy {
                min-width: 0;
                flex: 1 1 auto;
            }
            .gst-report-kpi-label {
                display: block;
                margin-bottom: 6px;
                color: var(--gr-muted);
                font-size: 12px;
                font-weight: 500;
                line-height: 1.25;
            }
            .gst-report-kpi-value {
                display: block;
                color: var(--gr-ink);
                font-size: 21px;
                font-weight: 650;
                line-height: 1.15;
                font-variant-numeric: tabular-nums;
                overflow-wrap: normal;
                word-break: normal;
            }
            .gst-report-kpi--gst .gst-report-kpi-value {
                color: var(--gr-pos);
            }

            .gst-report-compliance-note {
                margin: 0;
                color: var(--gr-muted);
                font-size: 12px;
            }
            .gst-report-section {
                border: 1px solid var(--gr-border-soft);
                border-radius: 14px;
                background: #ffffff;
                box-shadow: none;
                overflow: hidden;
            }
            .gst-report-section-head {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: 16px;
                padding: 16px 18px;
                border-bottom: 1px solid var(--gr-border-soft);
                background: #ffffff;
            }
            .gst-report-section-head h2 {
                margin: 0;
                color: var(--gr-ink);
                font-size: 17px;
                font-weight: 650;
                line-height: 1.2;
                letter-spacing: 0;
            }
            .gst-report-section-head p {
                margin: 4px 0 0;
                color: var(--gr-muted);
                font-size: 13px;
                line-height: 1.35;
            }
            .gst-report-filter-form {
                display: flex;
                align-items: end;
                justify-content: flex-end;
                gap: 8px;
                min-width: 0;
            }
            .gst-report-filter-form label {
                display: grid;
                min-height: 40px;
                place-items: center;
                color: #4a4334;
                font-size: 12.5px;
                font-weight: 600;
                line-height: 1;
            }
            .gst-report-select {
                position: relative;
                width: 160px;
            }
            .gst-report-select::after {
                content: "";
                position: absolute;
                top: 50%;
                right: 14px;
                width: 8px;
                height: 8px;
                border-right: 2px solid #64748b;
                border-bottom: 2px solid #64748b;
                transform: translateY(-65%) rotate(45deg);
                pointer-events: none;
            }
            .content-inner.gst-report-page .gst-report-filter-form select.gst-report-input,
            .gst-report-input {
                width: 100%;
                min-height: 40px;
                padding: 0 38px 0 12px;
                border: 1px solid var(--gr-border) !important;
                border-radius: 10px !important;
                background: #ffffff !important;
                color: var(--gr-ink) !important;
                font-size: 14px;
                box-shadow: none !important;
                appearance: none !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                background-image: none !important;
                background-repeat: initial !important;
                background-position: initial !important;
                background-size: initial !important;
            }
            .gst-report-input:focus {
                border-color: var(--gr-gold) !important;
                box-shadow: 0 0 0 3px var(--gr-focus) !important;
                outline: none;
            }
            .gst-report-apply,
            .gst-report-clear {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                white-space: nowrap;
                cursor: pointer;
                transition: background-color 140ms var(--gr-ease), border-color 140ms var(--gr-ease), transform 120ms var(--gr-ease);
            }
            .gst-report-apply {
                border: 1px solid var(--gr-gold);
                background: var(--gr-gold);
                color: #ffffff;
            }
            .gst-report-apply:hover {
                border-color: var(--gr-gold-hover);
                background: var(--gr-gold-hover);
                color: #ffffff;
            }
            .gst-report-clear {
                border: 1px solid var(--gr-border);
                background: #ffffff;
                color: #4a4334;
            }
            .gst-report-clear:hover {
                border-color: #94a3b8;
                background: var(--gr-muted-surface);
                color: var(--gr-ink);
            }
            .gst-report-table-wrap {
                overflow-x: auto;
                scrollbar-color: #cbd5e1 #f8fafc;
                scrollbar-width: thin;
            }
            .gst-report-table {
                width: 100%;
                min-width: 920px;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 14px;
            }
            .gst-report-table thead th {
                position: relative;
                padding: 11px 16px;
                border-bottom: 1px solid var(--gr-border-soft);
                background: var(--gr-muted-surface);
                color: #475569;
                font-size: 11.5px;
                font-weight: 600;
                letter-spacing: .02em;
                text-transform: uppercase;
                white-space: nowrap;
                cursor: pointer;
                user-select: none;
            }
            .gst-report-table tbody td,
            .gst-report-table tfoot td {
                padding: 14px 16px;
                border-bottom: 1px solid #edf2f7;
                color: var(--gr-ink);
                font-weight: 400;
                line-height: 1.35;
                vertical-align: middle;
                white-space: nowrap;
            }
            .gst-report-table tbody tr:nth-child(even) {
                background: #fbfdff;
            }
            .gst-report-table tbody tr:hover {
                background: #fffaf2;
            }
            .gst-report-table tbody tr:last-child td {
                border-bottom: 0;
            }
            .gst-report-table tfoot td {
                border-top: 1px solid var(--gr-border-soft);
                background: var(--gr-muted-surface);
                font-weight: 650;
            }
            .gst-report-table .text-right { text-align: right; }
            .gst-report-table .text-left { text-align: left; }
            .gst-report-rate-pill {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 28px;
                padding: 0 10px;
                border: 1px solid #f3dcb6;
                border-radius: 999px;
                background: #fdf6ec;
                color: #8a4b0f;
                font-size: 12.5px;
                font-weight: 650;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .gst-report-money {
                display: inline-block;
                color: var(--gr-ink);
                font-size: 14.5px;
                font-weight: 600;
                line-height: 1.2;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .gst-report-money--tax,
            .gst-report-tax-text {
                color: var(--gr-pos);
            }
            .gst-report-total-label {
                color: #334155;
                font-size: 12.5px;
                font-weight: 650;
            }
            .gst-report-empty {
                padding: 42px 20px;
                color: var(--gr-muted);
                text-align: center;
                font-size: 13px;
            }
            .gst-report-mobile-list {
                display: none;
                padding: 12px 14px 14px;
                border-top: 1px solid var(--gr-border-soft);
                background: #ffffff;
            }
            .gst-report-mobile-card {
                padding: 13px 14px;
                border: 1px solid var(--gr-border-soft);
                border-radius: 12px;
                background: #fbfdff;
            }
            .gst-report-mobile-card + .gst-report-mobile-card {
                margin-top: 10px;
            }
            .gst-report-mobile-card--total {
                border-color: #f3dcb6;
                background: #fffaf2;
            }
            .gst-report-mobile-card-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding-bottom: 10px;
                margin-bottom: 10px;
                border-bottom: 1px solid var(--gr-border-soft);
            }
            .gst-report-mobile-card-head strong {
                color: var(--gr-ink);
                font-size: 14px;
                font-weight: 650;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .gst-report-mobile-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 9px 14px;
                margin: 0;
            }
            .gst-report-mobile-grid dt {
                margin: 0 0 2px;
                color: var(--gr-muted);
                font-size: 11px;
                font-weight: 600;
                line-height: 1.2;
                text-transform: uppercase;
                letter-spacing: .02em;
            }
            .gst-report-mobile-grid dd {
                margin: 0;
                color: var(--gr-ink);
                font-size: 13px;
                font-weight: 600;
                line-height: 1.25;
                font-variant-numeric: tabular-nums;
                word-break: break-word;
            }

            @media (max-width: 900px) {
                .gst-report-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
                .gst-report-section-head {
                    align-items: flex-start;
                    flex-direction: column;
                }
                .gst-report-filter-form {
                    width: 100%;
                    justify-content: flex-start;
                }
            }

            @media (max-width: 767px) {
                .content-header.gst-report-header {
                    flex-wrap: nowrap;
                    align-items: center;
                    gap: 8px;
                }
                .gst-report-header .content-header-nav {
                    margin-right: 0;
                }
                .gst-report-header .page-title {
                    font-size: 17px;
                    line-height: 1.15;
                }
                .gst-report-header .page-subtitle {
                    display: none;
                }
                .gst-report-header .page-actions {
                    width: auto;
                    flex: 0 0 auto;
                    gap: 7px;
                }
                .gst-report-header-btn,
                .gst-report-export-btn {
                    width: 36px !important;
                    min-width: 36px !important;
                    height: 36px !important;
                    min-height: 36px !important;
                    padding: 0 !important;
                    border-radius: 10px;
                }
                .gst-report-header-btn svg,
                .gst-report-export-btn svg {
                    flex-basis: 17px;
                    width: 17px !important;
                    height: 17px !important;
                }
                .gst-report-action-label {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                }
                .gst-report-page {
                    gap: 12px;
                }
                .gst-report-kpis {
                    gap: 10px;
                }
                .gst-report-kpi {
                    display: grid;
                    grid-template-columns: 1fr;
                    grid-template-columns: 22px minmax(0, 1fr);
                    grid-template-rows: 22px minmax(31px, 1fr);
                    grid-template-areas:
                        "icon label"
                        "value value";
                    align-items: stretch;
                    min-height: 82px;
                    padding: 8px 10px;
                    row-gap: 4px;
                }
                .gst-report-kpi-icon {
                    grid-area: icon;
                    justify-self: start;
                    width: 22px;
                    height: 22px;
                    flex-basis: 22px;
                    border-radius: 7px;
                }
                .gst-report-kpi-icon svg {
                    width: 13px !important;
                    height: 13px !important;
                }
                .gst-report-kpi-copy {
                    display: contents;
                }
                .gst-report-kpi-label {
                    grid-area: label;
                    align-self: center;
                    justify-self: start;
                    margin-bottom: 0;
                    font-size: 10.5px;
                    line-height: 1.2;
                    text-align: left;
                    overflow-wrap: anywhere;
                }
                .gst-report-kpi-value {
                    grid-area: value;
                    align-self: center;
                    justify-self: center;
                    max-width: 100%;
                    font-size: 15px;
                    line-height: 1.15;
                    text-align: center;
                    white-space: normal;
                    overflow-wrap: anywhere;
                    word-break: normal;
                }
                .gst-report-section-head {
                    padding: 13px 14px;
                    gap: 12px;
                }
                .gst-report-section-head h2 {
                    font-size: 15px;
                }
                .gst-report-section-head p {
                    font-size: 11.5px;
                }
                .gst-report-filter-form {
                    display: grid;
                    grid-template-columns: minmax(0, 1fr) auto;
                    gap: 8px;
                }
                .gst-report-filter-form label {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                }
                .gst-report-select {
                    width: 100%;
                }
                .gst-report-input,
                .gst-report-apply {
                    min-height: 42px;
                }
                .gst-report-clear {
                    grid-column: 1 / -1;
                    width: 100%;
                    min-height: 40px;
                }
                .gst-report-table-wrap {
                    display: none;
                }
                .gst-report-mobile-list {
                    display: block;
                }
            }

            @media (max-width: 380px) {
                .gst-report-kpis {
                    grid-template-columns: 1fr;
                }
                .gst-report-filter-form {
                    grid-template-columns: 1fr;
                }
                .gst-report-apply {
                    width: 100%;
                }
                .gst-report-mobile-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    @endif

    @if ($isRegisterReport)
        <style>
            [x-cloak] { display: none !important; }
            body.report-register-filter-lock { overflow: hidden; }

            .report-register-header,
            .report-register-page {
                --rr-border: #cbd5e1;
                --rr-border-soft: #e2e8f0;
                --rr-surface: #ffffff;
                --rr-muted-surface: #f8fafc;
                --rr-nested: #f3f5f8;
                --rr-ink: #1f2430;
                --rr-ink-2: #4a4334;
                --rr-muted: #64748b;
                --rr-gold: #b45309;
                --rr-gold-hover: #92400e;
                --rr-focus: rgba(245, 158, 11, .2);
                --rr-ease: cubic-bezier(0.23, 1, 0.32, 1);
            }

            .report-register-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 10px;
                border-bottom: 1px solid var(--rr-border-soft);
                background: #ffffff;
                box-shadow: none !important;
            }
            .report-register-header > .min-w-0 { min-width: 0; }
            .report-register-header .page-actions {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 8px;
                margin-left: auto;
            }
            .report-register-header-btn,
            .report-register-export-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                min-height: 38px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                line-height: 1;
                text-decoration: none;
                white-space: nowrap;
                box-shadow: none !important;
                transition: background-color 140ms var(--rr-ease), border-color 140ms var(--rr-ease), transform 120ms var(--rr-ease);
            }
            .report-register-header-btn svg,
            .report-register-export-btn svg {
                display: block;
                flex: 0 0 16px;
                width: 16px !important;
                height: 16px !important;
                stroke-width: 2.2;
            }
            .report-register-header-btn {
                border: 1px solid var(--rr-border);
                background: #ffffff;
                color: var(--rr-ink-2);
            }
            .report-register-export-btn {
                border: 1px solid var(--rr-gold);
                background: var(--rr-gold);
                color: #ffffff;
            }
            .report-register-header-btn:hover {
                border-color: #94a3b8;
                background: var(--rr-muted-surface);
                color: var(--rr-ink);
            }
            .report-register-export-btn:hover {
                border-color: var(--rr-gold-hover);
                background: var(--rr-gold-hover);
                color: #ffffff;
            }
            .report-register-header-btn:active,
            .report-register-export-btn:active,
            .report-register-filter-trigger:active,
            .report-register-apply:active,
            .report-register-clear:active {
                transform: scale(.98);
            }

            .report-register-page {
                display: grid;
                gap: 16px;
                width: 100%;
                max-width: none;
                color: var(--rr-ink);
            }
            .report-register-toolbar,
            .report-register-section {
                border: 1px solid var(--rr-border-soft);
                border-radius: 14px;
                background: #ffffff;
                box-shadow: none;
                overflow: hidden;
            }
            .report-register-toolbar-head,
            .report-register-section-head {
                display: flex;
                align-items: flex-end;
                justify-content: space-between;
                gap: 16px;
                padding: 16px 18px;
                border-bottom: 1px solid var(--rr-border-soft);
                background: #ffffff;
            }
            .report-register-toolbar-head h2,
            .report-register-section-head h2 {
                margin: 0;
                color: var(--rr-ink);
                font-size: 17px;
                font-weight: 650;
                line-height: 1.2;
                letter-spacing: 0;
            }
            .report-register-toolbar-head p,
            .report-register-section-head p {
                margin: 4px 0 0;
                color: var(--rr-muted);
                font-size: 13px;
                line-height: 1.35;
            }
            .report-register-filter-trigger {
                display: none;
                align-items: center;
                justify-content: center;
                gap: 7px;
                min-height: 36px;
                padding: 0 12px;
                border: 1px solid var(--rr-border);
                border-radius: 10px;
                background: #ffffff;
                color: var(--rr-ink-2);
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                transition: background-color 140ms var(--rr-ease), border-color 140ms var(--rr-ease), transform 120ms var(--rr-ease);
            }
            .report-register-filter-trigger span {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 18px;
                height: 18px;
                border-radius: 999px;
                background: var(--rr-gold);
                color: #ffffff;
                font-size: 11px;
                line-height: 1;
            }
            .report-register-active-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                padding: 12px 18px;
                border-bottom: 1px solid var(--rr-border-soft);
                background: var(--rr-muted-surface);
            }
            .report-register-active-filters span,
            .report-register-active-filters a {
                display: inline-flex;
                align-items: center;
                min-height: 28px;
                padding: 0 10px;
                border: 1px solid var(--rr-border-soft);
                border-radius: 999px;
                background: #ffffff;
                color: var(--rr-ink-2);
                font-size: 12px;
                font-weight: 500;
                text-decoration: none;
            }
            .report-register-active-filters a {
                color: var(--rr-gold);
                border-color: #f3dcb6;
            }
            .report-register-filter-backdrop,
            .report-register-filter-sheet-head {
                display: none;
            }
            .report-register-filter-form {
                display: flex;
                flex-wrap: wrap;
                align-items: end;
                gap: 12px;
                padding: 16px 18px;
                background: #ffffff;
            }
            .report-register-filter-field {
                min-width: 148px;
            }
            .report-register-filter-field label {
                display: block;
                margin-bottom: 7px;
                color: var(--rr-ink-2);
                font-size: 12.5px;
                font-weight: 600;
                line-height: 1.2;
            }
            .report-register-select {
                position: relative;
            }
            .report-register-select::after {
                content: "";
                position: absolute;
                top: 50%;
                right: 14px;
                width: 8px;
                height: 8px;
                border-right: 2px solid #64748b;
                border-bottom: 2px solid #64748b;
                transform: translateY(-65%) rotate(45deg);
                pointer-events: none;
            }
            .content-inner.report-register-page .report-register-filter-form select.report-register-input,
            .report-register-page .report-register-input {
                width: 100%;
                min-height: 40px;
                padding: 0 38px 0 12px;
                border: 1px solid var(--rr-border) !important;
                border-radius: 10px !important;
                background: #ffffff !important;
                color: var(--rr-ink) !important;
                font-size: 14px;
                box-shadow: none !important;
                appearance: none !important;
                -webkit-appearance: none !important;
                -moz-appearance: none !important;
                background-image: none !important;
                background-repeat: initial !important;
                background-position: initial !important;
                background-size: initial !important;
            }
            .report-register-page .report-register-input:focus {
                border-color: var(--rr-gold) !important;
                box-shadow: 0 0 0 3px var(--rr-focus) !important;
                outline: none;
            }
            .report-register-sensitive-toggle {
                display: inline-flex;
                align-items: center;
                gap: 9px;
                min-height: 40px;
                padding: 0 8px;
                color: var(--rr-ink-2);
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                user-select: none;
            }
            .report-register-sensitive-toggle input {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                opacity: 0;
                pointer-events: none;
            }
            .report-register-toggle-track {
                position: relative;
                display: inline-flex;
                width: 38px;
                height: 22px;
                flex: 0 0 38px;
                align-items: center;
                border: 1px solid var(--rr-border);
                border-radius: 999px;
                background: var(--rr-nested);
                transition: background-color 140ms var(--rr-ease), border-color 140ms var(--rr-ease);
            }
            .report-register-toggle-thumb {
                position: absolute;
                left: 3px;
                width: 16px;
                height: 16px;
                border-radius: 999px;
                background: #ffffff;
                border: 1px solid var(--rr-border);
                transition: transform 160ms var(--rr-ease), border-color 140ms var(--rr-ease);
            }
            .report-register-sensitive-toggle input:checked + .report-register-toggle-track {
                border-color: var(--rr-gold);
                background: var(--rr-gold);
            }
            .report-register-sensitive-toggle input:checked + .report-register-toggle-track .report-register-toggle-thumb {
                border-color: #ffffff;
                transform: translateX(16px);
            }
            .report-register-sensitive-toggle input:focus-visible + .report-register-toggle-track {
                box-shadow: 0 0 0 3px var(--rr-focus);
            }
            .report-register-toggle-label {
                white-space: nowrap;
            }
            .report-register-filter-actions {
                display: flex;
                align-items: end;
                gap: 8px;
                margin-left: auto;
            }
            .report-register-apply,
            .report-register-clear {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 0 14px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                transition: background-color 140ms var(--rr-ease), border-color 140ms var(--rr-ease), transform 120ms var(--rr-ease);
            }
            .report-register-apply {
                border: 1px solid var(--rr-gold);
                background: var(--rr-gold);
                color: #ffffff;
            }
            .report-register-apply:hover {
                border-color: var(--rr-gold-hover);
                background: var(--rr-gold-hover);
            }
            .report-register-clear {
                border: 1px solid var(--rr-border);
                background: #ffffff;
                color: var(--rr-ink-2);
            }
            .report-register-clear:hover {
                border-color: #94a3b8;
                background: var(--rr-muted-surface);
                color: var(--rr-ink);
            }
            .report-register-compliance-note {
                margin: 0;
                color: var(--rr-muted);
                font-size: 12px;
            }

            .report-register-table-wrap {
                overflow-x: auto;
                scrollbar-color: #cbd5e1 #f8fafc;
                scrollbar-width: thin;
            }
            .report-register-table {
                width: 100%;
                min-width: 920px;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 14px;
            }
            .report-register-table thead th {
                position: relative;
                padding: 11px 16px;
                border-bottom: 1px solid var(--rr-border-soft);
                background: var(--rr-muted-surface);
                color: #475569;
                font-size: 11.5px;
                font-weight: 600;
                letter-spacing: .02em;
                text-transform: uppercase;
                white-space: nowrap;
                cursor: pointer;
                user-select: none;
            }
            .report-register-table tbody td,
            .report-register-table tfoot td {
                padding: 14px 16px;
                border-bottom: 1px solid #edf2f7;
                color: var(--rr-ink);
                font-weight: 400;
                line-height: 1.35;
                vertical-align: middle;
            }
            .report-register-table tbody tr:nth-child(even) {
                background: #fbfdff;
            }
            .report-register-table tbody tr:hover {
                background: #fffaf2;
            }
            .report-register-table tbody tr:last-child td {
                border-bottom: 0;
            }
            .report-register-table tfoot td {
                border-top: 1px solid var(--rr-border-soft);
                background: var(--rr-muted-surface);
                font-weight: 650;
            }
            .report-register-table .text-right { text-align: right; }
            .report-register-table .text-left { text-align: left; }
            .report-register-numeric-cell {
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }
            .report-register-mobile-list {
                display: none;
                padding: 12px 14px 14px;
                border-top: 1px solid var(--rr-border-soft);
                background: #ffffff;
            }
            .report-register-mobile-card {
                padding: 13px 14px;
                border: 1px solid var(--rr-border-soft);
                border-radius: 12px;
                background: #fbfdff;
            }
            .report-register-mobile-card + .report-register-mobile-card {
                margin-top: 10px;
            }
            .report-register-mobile-card--total {
                border-color: #f3dcb6;
                background: #fffaf2;
            }
            .report-register-mobile-card-head {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                padding-bottom: 10px;
                margin-bottom: 10px;
                border-bottom: 1px solid var(--rr-border-soft);
            }
            .report-register-mobile-card-head div {
                min-width: 0;
                display: grid;
                gap: 2px;
            }
            .report-register-mobile-card-head strong {
                color: var(--rr-ink);
                font-size: 14px;
                font-weight: 650;
                line-height: 1.25;
                overflow-wrap: anywhere;
            }
            .report-register-mobile-card-head span {
                color: var(--rr-muted);
                font-size: 12px;
                line-height: 1.25;
            }
            .report-register-mobile-amount {
                flex: 0 1 auto;
                color: #8a4b0f !important;
                font-size: 13px !important;
                font-weight: 650;
                line-height: 1.25;
                text-align: right;
                font-variant-numeric: tabular-nums;
                overflow-wrap: anywhere;
            }
            .report-register-mobile-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 9px 14px;
                margin: 0;
            }
            .report-register-mobile-grid dt {
                margin: 0 0 2px;
                color: var(--rr-muted);
                font-size: 11px;
                font-weight: 600;
                line-height: 1.2;
                text-transform: uppercase;
                letter-spacing: .02em;
            }
            .report-register-mobile-grid dd {
                margin: 0;
                color: var(--rr-ink);
                font-size: 13px;
                font-weight: 500;
                line-height: 1.25;
                font-variant-numeric: tabular-nums;
                overflow-wrap: anywhere;
            }
            .report-register-mobile-number {
                color: #1f2430;
                font-weight: 600 !important;
            }
            .report-register-empty {
                padding: 42px 20px;
                color: var(--rr-muted);
                text-align: center;
                font-size: 13px;
            }

            @media (max-width: 980px) {
                .report-register-filter-form {
                    align-items: stretch;
                }
                .report-register-filter-actions {
                    margin-left: 0;
                }
            }

            @media (max-width: 767px) {
                .content-header.report-register-header {
                    flex-wrap: nowrap;
                    align-items: center;
                    gap: 8px;
                }
                .report-register-header .content-header-nav {
                    margin-right: 0;
                }
                .report-register-header .page-title {
                    font-size: 17px;
                    line-height: 1.15;
                }
                .report-register-header .page-subtitle {
                    display: none;
                }
                .report-register-header .page-actions {
                    width: auto;
                    flex: 0 0 auto;
                    gap: 7px;
                }
                .report-register-header-btn,
                .report-register-export-btn {
                    width: 36px !important;
                    min-width: 36px !important;
                    height: 36px !important;
                    min-height: 36px !important;
                    padding: 0 !important;
                    border-radius: 10px;
                }
                .report-register-header-btn svg,
                .report-register-export-btn svg {
                    flex-basis: 17px;
                    width: 17px !important;
                    height: 17px !important;
                }
                .report-register-action-label {
                    position: absolute;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                    clip: rect(0, 0, 0, 0);
                    white-space: nowrap;
                }
                .report-register-page {
                    gap: 12px;
                }
                .report-register-toolbar-head,
                .report-register-section-head {
                    align-items: center;
                    padding: 13px 14px;
                }
                .report-register-toolbar-head h2,
                .report-register-section-head h2 {
                    font-size: 15px;
                }
                .report-register-toolbar-head p,
                .report-register-section-head p {
                    font-size: 11.5px;
                }
                .report-register-filter-trigger {
                    display: inline-flex;
                }
                .report-register-active-filters {
                    padding: 10px 14px;
                    gap: 6px;
                }
                .report-register-active-filters span,
                .report-register-active-filters a {
                    min-height: 26px;
                    font-size: 11.5px;
                }
                .report-register-filter-backdrop {
                    position: fixed;
                    inset: 0;
                    z-index: 60;
                    display: block;
                    width: 100%;
                    height: 100%;
                    border: 0;
                    background: rgba(15, 23, 42, .38);
                }
                .report-register-filter-form {
                    position: fixed;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 70;
                    display: grid;
                    grid-template-columns: 1fr;
                    gap: 12px;
                    max-height: 86dvh;
                    padding: 14px;
                    overflow-y: auto;
                    border: 1px solid var(--rr-border);
                    border-bottom: 0;
                    border-radius: 16px 16px 0 0;
                    background: #ffffff;
                    transform: translateY(105%);
                    opacity: 0;
                    pointer-events: none;
                    transition: transform 220ms var(--rr-ease), opacity 180ms var(--rr-ease);
                }
                .report-register-filter-form.is-open {
                    transform: translateY(0);
                    opacity: 1;
                    pointer-events: auto;
                }
                .report-register-filter-sheet-head {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                }
                .report-register-filter-sheet-head p {
                    margin: 0;
                    color: var(--rr-ink);
                    font-size: 15px;
                    font-weight: 650;
                }
                .report-register-filter-sheet-head span {
                    display: block;
                    margin-top: 2px;
                    color: var(--rr-muted);
                    font-size: 12px;
                }
                .report-register-filter-sheet-head button {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 34px;
                    height: 34px;
                    border: 1px solid var(--rr-border);
                    border-radius: 10px;
                    background: #ffffff;
                    color: var(--rr-ink-2);
                }
                .report-register-filter-field {
                    min-width: 0;
                }
                .report-register-input {
                    min-height: 42px;
                }
                .report-register-sensitive-toggle {
                    min-height: 38px;
                    padding: 0;
                }
                .report-register-filter-actions {
                    display: grid;
                    grid-template-columns: 1fr;
                    margin-left: 0;
                    gap: 8px;
                }
                .report-register-apply,
                .report-register-clear {
                    width: 100%;
                    min-height: 42px;
                }
                .report-register-table-wrap {
                    display: none;
                }
                .report-register-mobile-list {
                    display: block;
                }
            }

            @media (max-width: 380px) {
                .report-register-mobile-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    @endif
</x-app-layout>
