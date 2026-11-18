@php
    $hasRunningBalance = collect($section['columns'])->contains(fn ($c) => $c['key'] === 'running_balance');
    $compactLayout = count($section['columns']) <= 3;
@endphp
@if(!$compactLayout)<p class="ir-table-hint">Scroll sideways to see all columns.</p>@endif
@if($hasRunningBalance)<p class="ir-note" data-balance-note>Running balances are recorded in chronological order, not recalculated when sorted.</p>@endif
<div class="ir-scroll jf-scrollbar" data-list-scroll tabindex="0" role="region" aria-label="{{ $section['title'] }} records">
    <table class="jf-table ir-table {{ $compactLayout ? 'jf-table--fit' : '' }}" role="table" data-report-table data-has-running-balance="{{ $hasRunningBalance ? '1' : '0' }}" aria-label="{{ $section['title'] }}">
        <thead role="rowgroup"><tr role="row">
            <th scope="col" role="columnheader" class="ir-index" aria-label="Row number">#</th>
            @foreach($section['columns'] as $col)
                <th scope="col" role="columnheader" data-col-key="{{ $col['key'] }}" data-numeric="{{ $col['numeric'] ? '1' : '0' }}" class="{{ $col['numeric'] ? 'jf-num' : '' }}" aria-sort="none">
                    <button type="button" class="ir-sort" data-report-sort aria-label="Sort by {{ $col['label'] }}">{{ $col['label'] }}<x-icon name="arrow-down" size="12" /></button>
                </th>
            @endforeach
        </tr></thead>
        <tbody role="rowgroup">
            @forelse($section['rows'] as $row)
                <tr role="row" data-report-row>
                    <td role="cell" class="ir-index" data-row-index data-label="#">{{ $loop->iteration }}</td>
                    @foreach($row as $cell)
                        @php
                            $raw = $cell['raw'] ?? null;
                            $label = $section['columns'][$loop->index]['label'];
                            $sortValue = $raw instanceof \DateTimeInterface ? $raw->getTimestamp() : (is_scalar($raw) ? $raw : '');
                        @endphp
                        <td role="cell" data-col-key="{{ $cell['key'] }}" data-label="{{ $label }}" data-sort-value="{{ $sortValue }}" class="{{ $cell['numeric'] ? 'jf-num' : '' }}">{{ $cell['display'] !== '' ? $cell['display'] : '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr role="row" data-empty><td role="cell" colspan="{{ count($section['columns']) + 1 }}" class="jf-table-empty">No data for this scope. Try a different period or clear the filters.</td></tr>
            @endforelse
        </tbody>
        @if($section['hasTotals'])
            <tfoot role="rowgroup"><tr role="row">
                <th scope="row" role="rowheader" class="ir-index">Total</th>
                @foreach($section['columns'] as $col)
                    <td role="cell" data-col-key="{{ $col['key'] }}" data-label="{{ $col['label'] }}" class="{{ $col['numeric'] ? 'jf-num' : '' }}">{{ $section['totals'][$col['key']] ?? '' }}</td>
                @endforeach
            </tr></tfoot>
        @endif
    </table>
</div>
