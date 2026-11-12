{{-- Dhiran filters (owner 2026-11-08: modern, not rows of dropdowns). A search box and a Filters button; the
     choices are tap chips in a panel that is a bottom sheet on phones and a side drawer on desktop; active filters
     show as removable chips. App\Services\Dhiran\DhiranListFilters validates and applies everything on the server. --}}
@props([
    'filters',
    'action',
    'fields' => ['search', 'period'],
    'defaultPeriod' => null,
    'periodLabel' => 'Period',
    'statuses' => \App\Services\Dhiran\DhiranListFilters::STATUSES,
])
@php
    $F = \App\Services\Dhiran\DhiranListFilters::class;
    $has = fn (string $f) => in_array($f, $fields, true);
    $period = $filters->get('period') ?? '';
    $panelFields = array_values(array_diff($fields, ['search']));
    $count = $filters->count($defaultPeriod);
    $chips = $filters->chips($action, $defaultPeriod);
    $uid = 'dhf' . substr(md5($action), 0, 6);
    $periods = $defaultPeriod ? array_diff_key($F::PERIODS, ['' => 1]) : $F::PERIODS;
    $groups = array_filter([
        'status'   => $has('status') ? ['Status', $statuses] : null,
        'metal'    => $has('metal') ? ['Metal', $F::METALS] : null,
        'overdue'  => $has('overdue') ? ['Overdue', $F::OVERDUE] : null,
        'maturing' => $has('maturing') ? ['Maturing', $F::MATURING] : null,
        'cash'     => $has('cash') ? ['Money', $F::CASH_TYPES] : null,
    ]);
    $amountPresets = [['Under ₹25k', '', 25000], ['₹25k – 1L', 25000, 100000], ['₹1L – 5L', 100000, 500000], ['Over ₹5L', 500000, '']];
@endphp
<div class="dh-fbar" data-dhiran-filters>
    <form method="GET" action="{{ $action }}" id="{{ $uid }}"
          x-data="{ period: @js($period), initialPeriod: @js($period), applying: false,
                    min: @js($filters->get('amount_min') ?? ''), max: @js($filters->get('amount_max') ?? '') }"
          @submit="applying = true; [...$el.elements].forEach(e => { if (e.name && e.value === '') e.disabled = true })"
          @turbo:before-cache.window="$refs.panel?.open && $refs.panel.close(); [...$el.elements].forEach(e => { if (e.name && !['from', 'to'].includes(e.name)) e.disabled = false })">
        <div class="dh-fbar-row">
            @if($has('search'))
                <x-dhiran.search :value="$filters->get('search') ?? ''" placeholder="Search loan number, name or mobile" label="Search loans" />
            @endif
            @if($panelFields)
                <button type="button" class="dh-fbar-open {{ $count ? 'is-on' : '' }}" @click="$refs.panel.showModal()" aria-haspopup="dialog" aria-controls="{{ $uid }}-panel">
                    <x-icon name="list-filter" size="16" />
                    <span>Filters</span>
                    @if($count)<span class="dh-fbar-badge" data-filter-count>{{ $count }}</span>@endif
                </button>
            @endif
        </div>

        @if($chips || ($has('period') && $filters->period))
            <div class="dh-fbar-chips" data-active-filters>
                @foreach($chips as $chip)
                    <a href="{{ $chip['url'] }}" class="dh-fchip" aria-label="Remove filter: {{ $chip['label'] }}">
                        <span>{{ $chip['label'] }}</span><x-icon name="x" size="12" />
                    </a>
                @endforeach
                @if(count($chips) > 1)
                    <a href="{{ $action }}" class="dh-fbar-clearall">Clear all</a>
                @endif
                @if($has('period') && $filters->period)
                    <span class="dh-fbar-range" data-filter-period>Showing {{ $filters->period->label }}</span>
                @endif
            </div>
        @endif

        @if($panelFields)
            {{-- Closing without "Show results" puts the choices back as they were. --}}
            <dialog x-ref="panel" id="{{ $uid }}-panel" class="dh-fpanel" data-filter-panel aria-labelledby="{{ $uid }}-title"
                    @click.self="$el.close()"
                    @close="if (!applying) { $el.closest('form').reset(); period = initialPeriod; min = @js($filters->get('amount_min') ?? ''); max = @js($filters->get('amount_max') ?? '') }">
                <div class="dh-fpanel-inner">
                    <div class="dh-fpanel-handle" aria-hidden="true"></div>
                    <header class="dh-fpanel-head">
                        <h2 id="{{ $uid }}-title">Filters</h2>
                        <button type="button" class="dh-fpanel-x" @click="$refs.panel.close()" aria-label="Close filters"><x-icon name="x" size="18" /></button>
                    </header>

                    <div class="dh-fpanel-body">
                        @if($has('period'))
                            <fieldset class="dh-fsec">
                                <legend>{{ $periodLabel }}</legend>
                                <div class="dh-fopts">
                                    @foreach($periods as $value => $label)
                                        <label class="dh-fopt"><input type="radio" name="period" value="{{ $value }}" x-model="period" @checked($value === $period)><span>{{ $label }}</span></label>
                                    @endforeach
                                </div>
                                <div class="dh-fdates" x-show="period === 'custom'" @if($period !== 'custom') style="display: none" @endif>
                                    <label><span>From</span><input type="date" name="from" value="{{ $period === 'custom' ? $filters->from() : '' }}" class="dh-form-control" :disabled="period !== 'custom'"></label>
                                    <label><span>To</span><input type="date" name="to" value="{{ $period === 'custom' ? $filters->to() : '' }}" class="dh-form-control" :disabled="period !== 'custom'"></label>
                                </div>
                            </fieldset>
                        @endif

                        @foreach($groups as $name => [$legend, $options])
                            <fieldset class="dh-fsec">
                                <legend>{{ $legend }}</legend>
                                <div class="dh-fopts">
                                    <label class="dh-fopt"><input type="radio" name="{{ $name }}" value="" @checked(! $filters->get($name))><span>Any</span></label>
                                    @foreach($options as $value => $label)
                                        <label class="dh-fopt"><input type="radio" name="{{ $name }}" value="{{ $value }}" @checked($filters->get($name) === (string) $value)><span>{{ $label }}</span></label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach

                        @if($has('amount'))
                            <fieldset class="dh-fsec">
                                <legend>Loan amount</legend>
                                <div class="dh-fopts">
                                    @foreach($amountPresets as [$label, $lo, $hi])
                                        <button type="button" class="dh-fopt dh-fopt--btn" :class="String(min) === @js((string) $lo) && String(max) === @js((string) $hi) && 'is-picked'"
                                                @click="min = @js((string) $lo); max = @js((string) $hi)"><span>{{ $label }}</span></button>
                                    @endforeach
                                </div>
                                <div class="dh-fdates">
                                    <label><span>From ₹</span><input type="number" name="amount_min" min="0" step="1" inputmode="numeric" x-model="min" value="{{ $filters->get('amount_min') }}" placeholder="0" class="dh-form-control"></label>
                                    <label><span>To ₹</span><input type="number" name="amount_max" min="0" step="1" inputmode="numeric" x-model="max" value="{{ $filters->get('amount_max') }}" placeholder="Any" class="dh-form-control"></label>
                                </div>
                            </fieldset>
                        @endif
                    </div>

                    <footer class="dh-fpanel-foot">
                        <a href="{{ $action }}{{ $filters->get('search') ? '?' . http_build_query(['search' => $filters->get('search')]) : '' }}" class="btn btn-secondary">Reset</a>
                        <button type="submit" class="btn btn-primary">Show results</button>
                    </footer>
                </div>
            </dialog>
        @endif
    </form>
</div>
