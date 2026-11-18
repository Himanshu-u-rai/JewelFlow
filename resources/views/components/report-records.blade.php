@props(['title', 'columns', 'rows', 'numeric' => [], 'totals' => []])
@php
    // Adapt legacy read-only lists to the same table furniture, not a new calculation path.
    $section = [
        'title' => $title,
        'columns' => collect($columns)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'numeric' => in_array($key, $numeric, true)])->values()->all(),
        'rows' => collect($rows)->map(fn ($row) => collect($columns)->map(function ($label, $key) use ($row, $numeric) {
            $value = $row[$key] ?? '';
            return ['key' => $key, 'display' => (string) (is_array($value) ? ($value['display'] ?? '') : $value),
                'raw' => is_array($value) ? ($value['raw'] ?? '') : $value, 'numeric' => in_array($key, $numeric, true)];
        })->values()->all())->all(),
        'rowCount' => count($rows), 'totals' => $totals, 'hasTotals' => count($totals) > 0,
    ];
@endphp
@include('reporting.partials.screen-table')
