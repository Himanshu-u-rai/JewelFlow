{{-- A section's filters in the standard Dhiran pattern (owner 2026-11-11: no chip rows thrown on the page): a Filters
     button with a count opens the same drawer / bottom sheet as the list filters; active filters show as removable chips.
     mode="button" draws the button + panel (put it in the section head); mode="chips" draws the active chips.
     groups: name => ['legend' => ..., 'options' => [value => [label, count|null]], 'any' => 'All']
     Other query parameters (another section's filters and pages) are kept. --}}
@props(['groups', 'title' => 'Filters', 'fragment' => null, 'pageName' => 'page', 'mode' => 'button'])
@php
    $names = array_keys($groups);
    $cur = fn (string $n) => (string) request()->query($n, '');
    $active = array_values(array_filter($names, fn ($n) => $cur($n) !== '' && isset($groups[$n]['options'][$cur($n)])));
    $hash = $fragment ? '#' . $fragment : '';
    $reset = array_fill_keys([...$names, $pageName, 'export'], null);
    $uid = 'dfp' . substr(md5(implode(',', $names) . $fragment), 0, 6);
@endphp

@if($mode === 'chips')
    @if($active)
        <div class="dh-fbar-chips dh-fbar-chips--section" data-active-filters>
            @foreach($active as $n)
                <a href="{{ request()->fullUrlWithQuery([$n => null, $pageName => null, 'export' => null]) . $hash }}" class="dh-fchip"
                   aria-label="Remove filter: {{ $groups[$n]['options'][$cur($n)][0] }}">
                    <span>{{ $groups[$n]['options'][$cur($n)][0] }}</span><x-icon name="x" size="12" />
                </a>
            @endforeach
            @if(count($active) > 1)
                <a href="{{ request()->fullUrlWithQuery($reset) . $hash }}" class="dh-fbar-clearall">Clear all</a>
            @endif
        </div>
    @endif
@else
    {{-- A plain GET submit drops the #section anchor, so "Show results" builds the URL (without empty choices) and
         visits it with the anchor; without JavaScript the form still submits normally. --}}
    {{-- Turbo leaves Dhiran's inner scroll area at the top on a visit, so the section itself scrolls into view when
         the URL's anchor names it (after filters, chip removal or paging). --}}
    <form method="GET" action="{{ url()->current() }}" class="dh-fpanel-form" x-data="{ applying: false }"
          x-init="@if($fragment) if (location.hash === @js($hash)) requestAnimationFrame(() => document.getElementById(@js($fragment))?.scrollIntoView({ block: 'start' })) @endif"
          @submit.prevent="applying = true;
              const q = new URLSearchParams([...new FormData($el)].filter(([k, v]) => v !== ''));
              const url = $el.action + (q.toString() ? '?' + q : '') + @js($hash);
              window.Turbo ? Turbo.visit(url) : (location.href = url)"
          @turbo:before-cache.window="$refs.panel?.open && $refs.panel.close()">
        @foreach(collect(request()->query())->except([...$names, $pageName, 'export'])->filter(fn ($v) => is_scalar($v) && $v !== '') as $k => $v)
            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
        @endforeach
        <button type="button" class="dh-fbar-open dh-fbar-open--sm {{ $active ? 'is-on' : '' }}" @click="$refs.panel.showModal()" aria-haspopup="dialog" aria-controls="{{ $uid }}">
            <x-icon name="list-filter" size="16" />
            <span>Filters</span>
            @if($active)<span class="dh-fbar-badge">{{ count($active) }}</span>@endif
        </button>

        <dialog x-ref="panel" id="{{ $uid }}" class="dh-fpanel" data-filter-panel aria-labelledby="{{ $uid }}-title"
                @click.self="$el.close()" @close="if (!applying) $el.closest('form').reset()">
            <div class="dh-fpanel-inner">
                <div class="dh-fpanel-handle" aria-hidden="true"></div>
                <header class="dh-fpanel-head">
                    <h2 id="{{ $uid }}-title">{{ $title }}</h2>
                    <button type="button" class="dh-fpanel-x" @click="$refs.panel.close()" aria-label="Close filters"><x-icon name="x" size="18" /></button>
                </header>
                <div class="dh-fpanel-body">
                    @foreach($groups as $n => $g)
                        <fieldset class="dh-fsec">
                            <legend>{{ $g['legend'] }}</legend>
                            <div class="dh-fopts">
                                <label class="dh-fopt"><input type="radio" name="{{ $n }}" value="" @checked($cur($n) === '')><span>{{ $g['any'] ?? 'All' }}</span></label>
                                @foreach($g['options'] as $value => [$label, $count])
                                    <label class="dh-fopt"><input type="radio" name="{{ $n }}" value="{{ $value }}" @checked($cur($n) === (string) $value)><span>{{ $label }}@if($count !== null) <em class="dh-fopt-count">{{ $count }}</em>@endif</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
                <footer class="dh-fpanel-foot">
                    <a href="{{ request()->fullUrlWithQuery($reset) . $hash }}" class="btn btn-secondary">Reset</a>
                    <button type="submit" class="btn btn-primary">Show results</button>
                </footer>
            </div>
        </dialog>
    </form>
@endif
