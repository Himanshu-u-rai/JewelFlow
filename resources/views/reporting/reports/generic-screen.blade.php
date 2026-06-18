{{-- Generic report screen — any spine report (multi-section aware).
     Consumes the same canonical dataset as the exports; totals match the PDF.
     Rigid compliance reports show no profile selector and no sensitive toggle. --}}
<x-app-layout>
    <x-page-header>
        <div>
            <h1 class="page-title">{{ $definition->title }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $view['meta']->periodLabel }} · {{ $view['meta']->profileLabel }}</p>
        </div>
        <x-slot:actions>
            <a href="{{ route('reporting.export.panel', ['report' => $definition->key]) }}"
               class="inline-flex items-center rounded-lg bg-teal-600 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                Export
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
        <form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Period</label>
                <select name="date_preset" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($presets as $preset)
                        <option value="{{ $preset->value }}" @selected(request('date_preset', 'this_month') === $preset->value)>
                            {{ \Illuminate\Support\Str::headline($preset->value) }}
                        </option>
                    @endforeach
                </select>
            </div>
            @foreach (($filterControls ?? []) as $fc)
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">{{ $fc['label'] }}</label>
                    <select name="{{ $fc['key'] }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All</option>
                        @foreach ($fc['options'] as $opt)
                            <option value="{{ $opt['value'] }}" @selected($fc['current'] === (string) $opt['value'])>{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            @unless ($isRigid)
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Profile</label>
                    <select name="profile" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($definition->profiles as $p)
                            <option value="{{ $p->value }}" @selected($profile->value === $p->value)>{{ \Illuminate\Support\Str::headline($p->value) }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($canExportSensitive && $definition->hasSensitiveColumns())
                    <label class="flex items-center gap-2 text-sm text-slate-700 pb-2">
                        <input type="checkbox" name="include_sensitive" value="1" class="rounded border-slate-300" @checked(request()->boolean('include_sensitive'))>
                        Sensitive columns
                    </label>
                @endif
            @endunless
            <button type="submit" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Apply</button>
        </form>

        @if ($isRigid)
            <p class="text-xs text-slate-500">Compliance report — fixed statutory format. Columns and layout are not adjustable.</p>
        @endif

        @foreach ($view['sections'] as $section)
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-900">
                    {{ $section['title'] }} <span class="text-slate-400 font-normal">· {{ $section['rowCount'] }} row(s)</span>
                </div>
                @if ($section['rowCount'] === 0)
                    <div class="px-5 py-10 text-center text-sm text-slate-400">No data for this scope.</div>
                @else
                    @php
                        // The running-balance column is only meaningful in date order.
                        // It is marked so the client sorter can hide it when the table
                        // is sorted by any other column, and the date column is marked
                        // as the "natural order" column.
                        $hasRunningBalance = collect($section['columns'])->contains(fn ($c) => $c['key'] === 'running_balance');
                    @endphp
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm js-report-table" data-has-running-balance="{{ $hasRunningBalance ? '1' : '0' }}">
                            <thead class="bg-slate-50">
                                <tr>
                                    @foreach ($section['columns'] as $col)
                                        <th data-col-key="{{ $col['key'] }}" data-numeric="{{ $col['numeric'] ? '1' : '0' }}"
                                            class="js-sort-th group cursor-pointer select-none px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-600 hover:text-slate-900 {{ $col['numeric'] ? 'text-right' : 'text-left' }}">
                                            {{ $col['label'] }}<span class="js-sort-arrow ml-1 text-slate-400"></span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($section['rows'] as $row)
                                    <tr class="hover:bg-slate-50">
                                        @foreach ($row as $cell)
                                            @php
                                                $raw = $cell['raw'] ?? null;
                                                if (is_numeric($raw)) {
                                                    $sortVal = (string) $raw;
                                                } elseif ($raw instanceof \DateTimeInterface) {
                                                    $sortVal = (string) $raw->getTimestamp();
                                                } elseif (is_string($raw) && strtotime($raw) !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
                                                    $sortVal = (string) strtotime($raw);
                                                } else {
                                                    $sortVal = (string) ($cell['display'] ?? '');
                                                }
                                            @endphp
                                            <td data-col-key="{{ $cell['key'] ?? '' }}" data-sort-value="{{ $sortVal }}"
                                                class="px-4 py-2.5 text-slate-800 {{ $cell['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">{{ $cell['display'] }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($section['hasTotals'])
                                <tfoot class="bg-slate-50 font-semibold">
                                    <tr>
                                        @foreach ($section['columns'] as $col)
                                            <td class="px-4 py-2.5 text-slate-900 {{ $col['numeric'] ? 'text-right tabular-nums' : 'text-left' }}">
                                                {{ $section['totals'][$col['key']] ?? ($loop->first ? 'Total' : '') }}
                                            </td>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <script>
    (function () {
        // Client-side column sort for report tables. Reorders the rendered rows
        // only — never changes totals or the server-computed figures. The
        // running-balance column is meaningful only in chronological (date)
        // order, so when the table is sorted by any other column we hide its
        // values (showing a muted dash) and restore them when sorted by date.
        function sortTable(table, th) {
            const headers = Array.from(table.tHead.rows[0].cells);
            const colIndex = headers.indexOf(th);
            const isNumeric = th.dataset.numeric === '1';
            const colKey = th.dataset.colKey;
            const dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';

            headers.forEach(h => { h.dataset.sortDir = ''; const a = h.querySelector('.js-sort-arrow'); if (a) a.textContent = ''; });
            th.dataset.sortDir = dir;
            const arrow = th.querySelector('.js-sort-arrow');
            if (arrow) arrow.textContent = dir === 'asc' ? '▲' : '▼';

            const tbody = table.tBodies[0];
            const rows = Array.from(tbody.rows);
            rows.sort((ra, rb) => {
                const a = ra.cells[colIndex]?.dataset.sortValue ?? '';
                const b = rb.cells[colIndex]?.dataset.sortValue ?? '';
                let cmp;
                if (isNumeric || (a !== '' && b !== '' && !isNaN(a) && !isNaN(b))) {
                    cmp = parseFloat(a || 0) - parseFloat(b || 0);
                } else {
                    cmp = String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
                }
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach(r => tbody.appendChild(r));

            // Running-balance is only valid in date order.
            if (table.dataset.hasRunningBalance === '1') {
                const sortedByDate = colKey === 'datetime';
                const rbIndex = headers.findIndex(h => h.dataset.colKey === 'running_balance');
                if (rbIndex !== -1) {
                    Array.from(tbody.rows).forEach(r => {
                        const cell = r.cells[rbIndex];
                        if (!cell) return;
                        if (sortedByDate) {
                            if (cell.dataset.rbDisplay !== undefined) { cell.textContent = cell.dataset.rbDisplay; cell.classList.remove('text-slate-300'); }
                        } else {
                            if (cell.dataset.rbDisplay === undefined) cell.dataset.rbDisplay = cell.textContent;
                            cell.textContent = '—';
                            cell.classList.add('text-slate-300');
                        }
                    });
                    const rbHeader = headers[rbIndex];
                    if (rbHeader) rbHeader.title = sortedByDate ? '' : 'Running balance is shown only when sorted by date';
                }
            }
        }

        document.querySelectorAll('.js-report-table').forEach(table => {
            if (!table.tHead || !table.tBodies[0]) return;
            table.querySelectorAll('.js-sort-th').forEach(th => {
                th.addEventListener('click', () => sortTable(table, th));
            });
        });
    })();
    </script>
</x-app-layout>
