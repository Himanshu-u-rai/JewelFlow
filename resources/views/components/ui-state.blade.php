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
            <x-icon name="loader-circle" class="ui-state-spinner" />
        @elseif($type === 'error')
            <x-icon name="circle-alert" />
        @else
            <x-icon name="inbox" />
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
