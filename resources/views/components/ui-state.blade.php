@props([
    'type' => 'empty',
    'title' => 'Nothing here yet',
    'description' => null,
    'compact' => false,
])

@php
    $toneMap = [
        'loading' => 'ui-state-loading',
        'error' => 'ui-state-error',
        'empty' => 'ui-state-empty',
    ];
    $tone = $toneMap[$type] ?? 'ui-state-empty';
@endphp

<div {{ $attributes->merge(['class' => 'ui-state ' . $tone . ($compact ? ' ui-state-compact' : '')]) }}>
    <div class="ui-state-icon" aria-hidden="true">
        @if($type === 'loading')
            <svg class="ui-state-spinner" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" opacity="0.25"/><path d="M21 12a9 9 0 0 0-9-9"/></svg>
        @elseif($type === 'error')
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        @else
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="8" y1="10" x2="16" y2="10"/><line x1="8" y1="14" x2="13" y2="14"/></svg>
        @endif
    </div>

    <h3 class="ui-state-title">{{ $title }}</h3>

    @if($description)
        <p class="ui-state-description">{{ $description }}</p>
    @endif

    @if(isset($action))
        <div class="ui-state-action">{{ $action }}</div>
    @endif
</div>
