{{-- Link chips that filter one section of a page (owner 2026-11-11). Each keeps the page's other parameters, resets
     this section's page and lands back on the section. options: value => [label, count|null]; '' is "All". --}}
@props(['param', 'options', 'current' => null, 'pageName' => 'page', 'fragment' => null, 'label' => 'Filter'])
<nav class="dh-segment" aria-label="{{ $label }}">
    @foreach($options as $value => [$text, $count])
        @php
            $value = (string) $value;
            $on = $value === (string) ($current ?? '');
            $url = request()->fullUrlWithQuery([$param => $value === '' ? null : $value, $pageName => null, 'export' => null]) . ($fragment ? '#' . $fragment : '');
        @endphp
        <a href="{{ $url }}" class="dh-segment-chip {{ $on ? 'is-on' : '' }}" @if($on) aria-current="page" @endif>
            {{ $text }}@if($count !== null) <span class="dh-segment-count">{{ $count }}</span>@endif
        </a>
    @endforeach
</nav>
