{{-- The one way a Dhiran loan or item status is shown (owner 2026-11-12): one label and one colour tone per state.
     Colours come from dhiran-tokens.css through .dh-status--{tone} in dhiran.css. --}}
@props(['value'])
@php
    $tone = [
        'pending_evidence' => 'warning', 'active' => 'success', 'closed' => 'muted', 'renewed' => 'info',
        'forfeited' => 'danger', 'cancelled' => 'muted', 'pledged' => 'warning', 'released' => 'success',
    ][$value] ?? 'muted';
    $label = (\App\Services\Dhiran\DhiranListFilters::STATUSES + ['pledged' => 'Pledged', 'released' => 'Released'])[$value]
        ?? ucfirst(str_replace('_', ' ', (string) $value));
@endphp
<span {{ $attributes->class(['dh-status', "dh-status--{$tone}"]) }}>{{ $label }}</span>
