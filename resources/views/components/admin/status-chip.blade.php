@props([
    'label' => '',
    'value' => 0,
    'tone' => 'slate',
])

@php
    $toneMap = [
        'emerald' => 'admin-chip admin-chip-emerald',
        'amber' => 'admin-chip admin-chip-amber',
        'rose' => 'admin-chip admin-chip-rose',
        'sky' => 'admin-chip admin-chip-sky',
        'slate' => 'admin-chip admin-chip-slate',
    ];
    $toneClass = $toneMap[$tone] ?? $toneMap['slate'];
@endphp

<div class="admin-panel flex items-center justify-between px-3 py-2">
    <span class="text-sm tracking-wide text-slate-300">{{ $label }}</span>
    <span class="{{ $toneClass }}">{{ $value }}</span>
</div>
