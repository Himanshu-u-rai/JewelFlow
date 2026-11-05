<x-app-layout>
    @php
        $statusLabels = ['issued' => 'Issued', 'partial_return' => 'Partial return', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        $statusStyles = ['issued' => 'bg-amber-100 text-amber-800', 'partial_return' => 'bg-blue-100 text-blue-800', 'completed' => 'bg-emerald-100 text-emerald-800', 'cancelled' => 'bg-gray-200 text-gray-600'];
        $orderRows = $orders->getCollection();
        $hasFilters = filled($filterStatus) || filled($filterKarigar) || filled($filterFrom) || filled($filterTo);
        $metrics = [
            'total' => ['Matching orders', $orders->total()],
            'open' => ['Open · page', $orderRows->whereIn('status', ['issued', 'partial_return'])->count()],
            'completed' => ['Completed · page', $orderRows->where('status', 'completed')->count()],
            'flagged' => ['Flagged · page', $orderRows->filter(fn ($order) => !empty($order->discrepancy_flags))->count()],
        ];
    @endphp

    <x-page-header title="Job Orders" subtitle="Bullion issued to karigars" class="jo-page-header">
        <x-slot:actions>
            @can('job_order.manage')
                <a href="{{ route('job-orders.create') }}" class="btn btn-primary btn-sm jo-issue" aria-label="Issue Bullion">
                    <x-icon name="plus" size="16" /><span class="jo-issue-label">Issue Bullion</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner job-orders-index-page" data-list-workspace>
        @unless(auth()->user()->can('job_order.manage'))
            @include('partials.view-only-banner', ['permission' => 'job_order.manage', 'message' => 'job orders'])
        @endunless

        <section class="jo-register" data-list-frame aria-labelledby="job-order-register-title">
            <header class="jo-register-head" data-job-register-head>
                <h2 id="job-order-register-title">Job Order Register</h2>
                <div class="jo-summary">
                    @foreach($metrics as $key => [$label, $count])
                        <dl class="jo-metric" data-job-metric="{{ $key }}"><dt>{{ $label }}</dt><dd>{{ number_format($count) }}</dd></dl>
                    @endforeach
                </div>
                <x-responsive-filter-panel id="job-orders-filter-panel" label="Job order filters">
                    <form method="GET" action="{{ route('job-orders.index') }}" class="jo-filters"
                          data-enhance-selects="true" data-enhance-selects-variant="standard" data-enhance-selects-placement="viewport"
                          x-data="{ dirty: false, hasFilters: @js($hasFilters) }" @input="dirty = true" @change="dirty = true">
                        <div class="jo-filter-field">
                            <label for="job-orders-status">Status</label>
                            <select id="job-orders-status" name="status" class="jo-filter-input">
                                <option value="">All statuses</option>
                                @foreach($statusLabels as $value => $label)
                                    <option value="{{ $value }}" @selected($filterStatus === $value)>{{ $label }}</option>
                                @endforeach
                                @if(filled($filterStatus) && !array_key_exists($filterStatus, $statusLabels))
                                    <option value="{{ $filterStatus }}" selected>{{ str_replace('_', ' ', $filterStatus) }}</option>
                                @endif
                            </select>
                        </div>
                        <div class="jo-filter-field">
                            <label for="job-orders-karigar">Karigar</label>
                            <select id="job-orders-karigar" name="karigar_id" class="jo-filter-input">
                                <option value="">All karigars</option>
                                @foreach($karigars as $karigar)
                                    <option value="{{ $karigar->id }}" @selected((string) $filterKarigar === (string) $karigar->id)>{{ $karigar->name }}</option>
                                @endforeach
                                @if(filled($filterKarigar) && !$karigars->contains('id', (int) $filterKarigar))
                                    <option value="{{ $filterKarigar }}" selected>Selected karigar</option>
                                @endif
                            </select>
                        </div>
                        <div class="jo-filter-field">
                            <label for="job-orders-from">From</label>
                            <input id="job-orders-from" type="date" name="from" value="{{ $filterFrom }}" class="jo-filter-input">
                        </div>
                        <div class="jo-filter-field">
                            <label for="job-orders-to">To</label>
                            <input id="job-orders-to" type="date" name="to" value="{{ $filterTo }}" class="jo-filter-input">
                        </div>
                        <div class="jo-filter-actions">
                            <button type="submit" class="btn btn-primary btn-sm" data-job-filter-apply x-show="dirty || !hasFilters">Apply</button>
                            <a href="{{ route('job-orders.index') }}" class="btn btn-primary btn-sm" data-job-filter-clear x-show="!dirty && hasFilters" x-cloak>Clear</a>
                            @if($hasFilters)
                                <noscript><a class="btn btn-secondary btn-sm" href="{{ route('job-orders.index') }}">Clear</a></noscript>
                            @endif
                        </div>
                    </form>
                </x-responsive-filter-panel>
            </header>

            @if($orders->isEmpty())
                <div class="jo-empty">
                    <p>{{ $hasFilters ? 'No job orders match your filters.' : 'No job orders yet.' }}</p>
                    @if($hasFilters)
                        <a href="{{ route('job-orders.index') }}" class="btn btn-secondary btn-sm">Clear filters</a>
                    @else
                        @can('job_order.manage')
                            <a href="{{ route('job-orders.create') }}" class="btn btn-primary btn-sm">Issue your first job order</a>
                        @endcan
                    @endif
                </div>
            @else
                <div class="jo-records jf-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Job order records">
                    <table class="jo-register-table jf-table">
                        <thead>
                            <tr>
                                <th scope="col" class="jf-table-index">#</th>
                                <th scope="col">Job #</th>
                                <th scope="col">Karigar</th>
                                <th scope="col">Issued</th>
                                <th scope="col" class="text-right">Gross / Fine</th>
                                <th scope="col" class="text-right">Returned (fine)</th>
                                <th scope="col" class="text-right">Wastage</th>
                                <th scope="col">Status</th>
                                <th scope="col">Flags</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orders as $jo)
                                <tr onclick="window.location='{{ route('job-orders.show', $jo) }}'">
                                    <td class="jf-table-index">{{ $orders->firstItem() + $loop->index }}</td>
                                    <td>
                                        <a href="{{ route('job-orders.show', $jo) }}" class="jo-number">{{ $jo->job_order_number }}</a>
                                        <span class="jo-challan">DC: {{ $jo->challan_number ?: '—' }}</span>
                                    </td>
                                    <td class="jo-name">{{ $jo->karigar?->name ?: '—' }}</td>
                                    <td class="jo-date">{{ $jo->issue_date->format('d M Y') }}</td>
                                    <td class="text-right">{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                    <td class="text-right">{{ number_format($jo->returned_fine_weight, 3) }}g</td>
                                    <td class="text-right">{{ number_format($jo->actual_wastage_fine, 3) }}g</td>
                                    <td><span class="jo-status {{ $statusStyles[$jo->status] ?? 'bg-gray-100 text-gray-800' }}">{{ $statusLabels[$jo->status] ?? str_replace('_', ' ', $jo->status) }}</span></td>
                                    <td>
                                        <div class="jo-flags">
                                            @forelse($jo->discrepancy_flags ?? [] as $flag)
                                                <span class="jo-flag">{{ str_replace('_', ' ', $flag) }}</span>
                                            @empty
                                                <span class="jo-no-flags">—</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="text-right" onclick="event.stopPropagation()">
                                        <div class="jf-table-actions">
                                            <a href="{{ route('job-orders.show', $jo) }}" class="jf-row-action jf-row-action--view" data-tip="View">
                                                <x-icon name="eye" size="16" /><span class="jf-sr-only">View {{ $jo->job_order_number }}</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="jo-phone-list">
                    @foreach($orders as $jo)
                        <article class="jo-record-card" aria-label="{{ $jo->job_order_number }}">
                            <div class="jo-mobile-head">
                                <div>
                                    <span class="jo-mobile-index">#<span data-job-mobile-index>{{ $orders->firstItem() + $loop->index }}</span></span>
                                    <a href="{{ route('job-orders.show', $jo) }}" class="jo-number">{{ $jo->job_order_number }}</a>
                                    <span class="jo-challan">DC: {{ $jo->challan_number ?: '—' }}</span>
                                </div>
                                <span class="jo-status {{ $statusStyles[$jo->status] ?? 'bg-gray-100 text-gray-800' }}">{{ $statusLabels[$jo->status] ?? str_replace('_', ' ', $jo->status) }}</span>
                            </div>
                            <p class="jo-mobile-karigar">{{ $jo->karigar?->name ?: '—' }}</p>
                            <dl class="jo-mobile-meta">
                                <div><dt>Issued</dt><dd>{{ $jo->issue_date->format('d M Y') }}</dd></div>
                                <div><dt>Gross / Fine</dt><dd>{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</dd></div>
                                <div><dt>Returned fine</dt><dd>{{ number_format($jo->returned_fine_weight, 3) }}g</dd></div>
                                <div><dt>Wastage</dt><dd>{{ number_format($jo->actual_wastage_fine, 3) }}g</dd></div>
                            </dl>
                            <div class="jo-mobile-actions">
                                <div class="jo-flags">
                                    @foreach($jo->discrepancy_flags ?? [] as $flag)
                                        <span class="jo-flag">{{ str_replace('_', ' ', $flag) }}</span>
                                    @endforeach
                                </div>
                                <a href="{{ route('job-orders.show', $jo) }}" class="jf-row-action jf-row-action--view" data-tip="View">
                                    <x-icon name="eye" size="16" /><span class="jf-sr-only">View {{ $jo->job_order_number }}</span>
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
                <footer class="jo-pagination" data-job-pagination>{{ $orders->links() }}</footer>
            @endif
        </section>
    </div>
</x-app-layout>
