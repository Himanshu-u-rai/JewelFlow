{{--
    One section rendered as a clean table (frozen §4.2).
    - Table headers repeat on every page (thead + table layout).
    - Rows never split across a page break (CSS on tr).
    - Numeric columns right-aligned with tabular-nums.
    - Section subtotals row when the section carries totals.

    Expected variables:
      $section   ReportSection
      $columns   ColumnDefinition[]   (resolved, ordered)
      $formatter ValueFormatter
--}}
@php
    use App\Services\Reporting\Definition\ColumnType;
@endphp
<section class="report-section">
    @if($section->title !== '')
        <h2 class="report-section-title">{{ $section->title }}</h2>
    @endif

    <table class="report-table">
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th class="{{ $column->type->isNumeric() ? 'num' : 'text' }}">{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($section->rows as $row)
                <tr>
                    @foreach($columns as $column)
                        <td class="{{ $column->type->isNumeric() ? 'num' : 'text' }}">{{ $formatter->format($row[$column->key] ?? null, $column->type) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr class="report-empty-row">
                    <td colspan="{{ count($columns) }}">No rows for this selection.</td>
                </tr>
            @endforelse
        </tbody>
        @if($section->hasTotals())
            <tfoot>
                <tr class="report-subtotal">
                    @foreach($columns as $index => $column)
                        @if($index === 0)
                            <td class="text report-total-label">Total</td>
                        @else
                            <td class="{{ $column->type->isNumeric() ? 'num' : 'text' }}">{{ array_key_exists($column->key, $section->totals) ? $formatter->format($section->totals[$column->key], $column->type) : '' }}</td>
                        @endif
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</section>
