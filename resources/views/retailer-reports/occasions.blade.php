<x-app-layout>
    <x-page-header class="individual-report-header" title="Customer Occasions" subtitle="Upcoming birthdays and anniversaries">
        <x-slot:actions><a href="{{ route('report.hub') }}" class="btn btn-secondary btn-sm" aria-label="All reports"><x-icon name="arrow-left" size="16" /><span class="report-action-label">Reports</span></a></x-slot:actions>
    </x-page-header>
    @php($occasionRows = collect($upcoming ?? []))
    <div class="content-inner individual-report">
        <section class="ir-surface">
            <header class="ir-heading"><div><h2>Occasion register</h2><p>{{ $occasionRows->count() }} occasions in the next {{ (int) $daysAhead }} days</p></div>
                <x-responsive-filter-panel id="occasion-report-filters" label="Occasion report filters" :desktop-popup="true">
                    <form method="GET" action="{{ route('report.occasions') }}" class="ir-filters"
                          data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                          x-data="{ dirty: false, hasFilters: @js(request()->has('days')) }" @input="dirty = true" @change="dirty = true">
                        <div class="ir-field"><label for="occasion-window">Reminder window</label><select id="occasion-window" name="days" class="ir-control">
                            @foreach(collect([7, 15, 30, 60, 90, (int) $daysAhead])->unique()->sort() as $days)<option value="{{ $days }}" @selected((int) $daysAhead === $days)>Next {{ $days }} days</option>@endforeach
                        </select></div>
                        <div class="ir-filter-actions"><button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button><a href="{{ route('report.occasions') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a></div>
                    </form>
                </x-responsive-filter-panel>
            </header>
            <div class="ir-scroll jf-scrollbar" data-list-scroll tabindex="0" role="region" aria-label="Occasion records">
                <table class="jf-table ir-table ir-table--cards" role="table" aria-label="Customer occasions">
                    <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader" class="ir-index" aria-label="Row number">#</th><th scope="col" role="columnheader">Customer</th><th scope="col" role="columnheader">Occasion</th><th scope="col" role="columnheader">Date</th><th scope="col" role="columnheader">Due in</th><th scope="col" role="columnheader">Action</th></tr></thead>
                    <tbody role="rowgroup">
                        @forelse($occasionRows as $occasion)
                            <tr role="row">
                                <td role="cell" class="ir-index" data-label="#">{{ $loop->iteration }}</td>
                                <td role="cell" data-label="Customer">{{ $occasion['customer_name'] }}<span class="block ir-muted">{{ $occasion['mobile'] ?: 'No mobile' }}</span></td>
                                <td role="cell" data-label="Occasion">{{ $occasion['type'] }}</td>
                                <td role="cell" data-label="Date">{{ Carbon\Carbon::parse($occasion['date'])->format('d M Y') }}</td>
                                <td role="cell" data-label="Due in">{{ (int) $occasion['days_until'] === 0 ? 'Today' : (int) $occasion['days_until'].' days' }}</td>
                                <td role="cell" data-label="Action">@can('customers.view')<a href="{{ route('customers.show', $occasion['customer_id']) }}" class="jf-row-action jf-row-action--view" data-tip="View profile" aria-label="View {{ $occasion['customer_name'] }}"><x-icon name="eye" size="16" /></a>@else — @endcan</td>
                            </tr>
                        @empty
                            <tr role="row"><td role="cell" colspan="6" class="jf-table-empty">No occasions in this window. Increase the reminder window or add occasion dates to customer profiles.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
