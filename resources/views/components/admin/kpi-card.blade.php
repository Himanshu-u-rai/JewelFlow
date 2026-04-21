@props([
    'label' => '',
    'value' => '',
    'description' => '',
    'tone' => 'slate',
])

@php
    $toneMap = [
        'emerald' => 'admin-tone-emerald',
        'amber' => 'admin-tone-amber',
        'rose' => 'admin-tone-rose',
        'sky' => 'admin-tone-sky',
        'slate' => 'admin-tone-slate',
    ];
    $toneClass = $toneMap[$tone] ?? $toneMap['slate'];
@endphp

<div class="admin-kpi {{ $toneClass }}">
    <div class="admin-kpi__label">{{ $label }}</div>
    <div class="admin-kpi__value">{{ $value }}</div>
    @if($description)
        <div class="admin-kpi__description">{{ $description }}</div>
    @endif
</div>
