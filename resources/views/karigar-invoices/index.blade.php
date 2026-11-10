<x-app-layout>
    @php
        $pageInvoices = $invoices->getCollection();
        $paidCount = $pageInvoices->where('payment_status', 'paid')->count();
        $partialCount = $pageInvoices->where('payment_status', 'partial')->count();
        $unpaidCount = $pageInvoices->where('payment_status', 'unpaid')->count();
        $pageTotal = (float) $pageInvoices->sum('total_after_tax');
        $statusLabels = ['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid'];
        $hasFilters = filled($filterStatus) || filled($filterKarigar);
    @endphp

    <x-page-header title="Karigar Invoices" subtitle="Tax invoices received from karigars" class="ki-page-header">
        <x-slot:actions>
            @can('karigar_invoice.manage')
                <a href="{{ route('karigar-invoices.create') }}" class="btn btn-primary btn-sm ki-header-action" aria-label="Add Invoice">
                    <x-icon name="plus" size="16" /><span class="ki-header-action-label">Add Invoice</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner karigar-invoices-index-page" data-list-workspace>
        <x-app-alerts />
        @unless(auth()->user()->can('karigar_invoice.manage'))
            @include('partials.view-only-banner', ['permission' => 'karigar_invoice.manage', 'message' => 'karigar invoices'])
        @endunless

        <section class="ki-register" data-list-frame aria-labelledby="ki-register-title">
            <header class="ki-register-head" data-ki-register-head>
                <h2 id="ki-register-title">Invoice Register</h2>
                <div class="ki-metrics">
                    <dl class="ki-metric" data-ki-metric="total"><dt>Matching invoices</dt><dd>{{ number_format($invoices->total()) }}</dd></dl>
                    @can('reports.money')
                        <dl class="ki-metric" data-ki-metric="value"><dt>This page value</dt><dd>₹{{ number_format($pageTotal, 0) }}</dd></dl>
                    @endcan
                    <dl class="ki-metric" data-ki-metric="paid"><dt>Paid / partial · page</dt><dd>{{ $paidCount }} / {{ $partialCount }}</dd></dl>
                    <dl class="ki-metric" data-ki-metric="unpaid"><dt>Unpaid · page</dt><dd>{{ $unpaidCount }}</dd></dl>
                </div>
                <x-responsive-filter-panel id="karigar-invoice-filters" label="Karigar invoice filters" :desktop-popup="true">
                    <form method="GET" action="{{ route('karigar-invoices.index') }}" class="ki-filters"
                          x-data="{ dirty: false, hasFilters: @js($hasFilters) }" @input="dirty = true" @change="dirty = true">
                        <div>
                            <label for="ki-payment-status" class="ki-filter-label">Payment status</label>
                            <select id="ki-payment-status" name="payment_status" class="ki-filter-control">
                                <option value="">All statuses</option>
                                @foreach($statusLabels as $value => $label)
                                    <option value="{{ $value }}" @selected($filterStatus === $value)>{{ $label }}</option>
                                @endforeach
                                @if(filled($filterStatus) && !array_key_exists($filterStatus, $statusLabels))
                                    <option value="{{ $filterStatus }}" selected>{{ ucfirst($filterStatus) }}</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <label for="ki-karigar" class="ki-filter-label">Karigar</label>
                            <select id="ki-karigar" name="karigar_id" class="ki-filter-control">
                                <option value="">All karigars</option>
                                @foreach($karigars as $karigar)
                                    <option value="{{ $karigar->id }}" @selected((string) $filterKarigar === (string) $karigar->id)>{{ $karigar->name }}</option>
                                @endforeach
                                @if(filled($filterKarigar) && !$karigars->contains('id', (int) $filterKarigar))
                                    <option value="{{ $filterKarigar }}" selected>Selected karigar</option>
                                @endif
                            </select>
                        </div>
                        <div class="ki-filter-actions">
                            <button type="submit" class="btn btn-primary btn-sm" x-show="dirty || !hasFilters">Apply</button>
                            <a href="{{ route('karigar-invoices.index') }}" class="btn btn-primary btn-sm" x-show="!dirty && hasFilters" x-cloak>Clear</a>
                            @if($hasFilters)
                                <noscript><a href="{{ route('karigar-invoices.index') }}" class="btn btn-secondary btn-sm">Clear</a></noscript>
                            @endif
                        </div>
                    </form>
                </x-responsive-filter-panel>
            </header>

            @if($invoices->isEmpty())
                <div class="ki-empty">
                    <p>{{ $hasFilters ? 'No karigar invoices match your filters.' : 'No karigar invoices yet.' }}</p>
                    @if($hasFilters)
                        <a href="{{ route('karigar-invoices.index') }}" class="btn btn-secondary btn-sm">Clear filters</a>
                    @else
                        @can('karigar_invoice.manage')
                            <a href="{{ route('karigar-invoices.create') }}" class="btn btn-primary btn-sm">Add Invoice</a>
                        @endcan
                    @endif
                </div>
            @else
                <div class="ki-records jf-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Karigar invoice records">
                    <table class="ki-register-table jf-table">
                        <thead>
                            <tr>
                                <th scope="col" class="jf-table-index">#</th>
                                <th scope="col" class="text-left">Invoice #</th>
                                <th scope="col" class="text-left">Karigar</th>
                                <th scope="col" class="text-left">Mode</th>
                                <th scope="col" class="text-left">Date</th>
                                <th scope="col" class="text-left">Job Order</th>
                                <th scope="col" class="text-right">Net Wt (g)</th>
                                <th scope="col" class="text-right">Total</th>
                                <th scope="col" class="text-center">Payment</th>
                                <th scope="col" class="text-left">Flags</th>
                                <th scope="col" class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $inv)
                                <tr role="row" onclick="window.location='{{ route('karigar-invoices.show', $inv) }}'">
                                    <td class="jf-table-index">{{ $invoices->firstItem() + $loop->index }}</td>
                                    <td><a href="{{ route('karigar-invoices.show', $inv) }}" class="ki-invoice-number">{{ $inv->karigar_invoice_number }}</a></td>
                                    <td class="ki-karigar-name">{{ $inv->karigar?->name }}</td>
                                    <td>{{ str_replace('_', ' ', $inv->mode) }}</td>
                                    <td class="ki-date">{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                    <td>
                                        @if($inv->jobOrder)
                                            <a href="{{ route('job-orders.show', $inv->jobOrder) }}" onclick="event.stopPropagation()">{{ $inv->jobOrder->job_order_number }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-right">{{ number_format($inv->total_net_weight, 3) }}g</td>
                                    <td class="text-right">₹{{ number_format($inv->total_after_tax, 2) }}</td>
                                    <td class="text-center"><span class="ki-status {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span></td>
                                    <td><div class="ki-flags">
                                        @forelse($inv->discrepancy_flags ?? [] as $flag)
                                            <span>{{ str_replace('_', ' ', $flag) }}</span>
                                        @empty
                                            —
                                        @endforelse
                                    </div></td>
                                    <td class="text-right">
                                        <a href="{{ route('karigar-invoices.show', $inv) }}" onclick="event.stopPropagation()" class="jf-row-action jf-row-action--view" data-tip="View" aria-label="View invoice {{ $inv->karigar_invoice_number }}"><x-icon name="eye" size="16" /></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="ki-phone-list">
                    @foreach($invoices as $inv)
                        <article class="ki-phone-card">
                            <div class="ki-phone-head">
                                <div>
                                    <span class="ki-phone-index" data-ki-mobile-index>{{ $invoices->firstItem() + $loop->index }}</span>
                                    <a href="{{ route('karigar-invoices.show', $inv) }}" class="ki-invoice-number">{{ $inv->karigar_invoice_number }}</a>
                                </div>
                                <span class="ki-status {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span>
                            </div>
                            <p class="ki-phone-karigar">{{ $inv->karigar?->name }}</p>
                            <dl class="ki-phone-meta">
                                <div><dt>Date</dt><dd>{{ $inv->karigar_invoice_date->format('d M Y') }}</dd></div>
                                <div><dt>Mode</dt><dd>{{ str_replace('_', ' ', $inv->mode) }}</dd></div>
                                <div><dt>Net weight</dt><dd>{{ number_format($inv->total_net_weight, 3) }}g</dd></div>
                                <div><dt>Total</dt><dd>₹{{ number_format($inv->total_after_tax, 2) }}</dd></div>
                                <div><dt>Job Order</dt><dd>
                                    @if($inv->jobOrder)
                                        <a href="{{ route('job-orders.show', $inv->jobOrder) }}">{{ $inv->jobOrder->job_order_number }}</a>
                                    @else
                                        —
                                    @endif
                                </dd></div>
                            </dl>
                            @if(!empty($inv->discrepancy_flags))
                                <div class="ki-flags">@foreach($inv->discrepancy_flags as $flag)<span>{{ str_replace('_', ' ', $flag) }}</span>@endforeach</div>
                            @endif
                            <div class="ki-phone-actions"><a href="{{ route('karigar-invoices.show', $inv) }}" class="jf-row-action jf-row-action--view" data-tip="View" aria-label="View invoice {{ $inv->karigar_invoice_number }}"><x-icon name="eye" size="16" /></a></div>
                        </article>
                    @endforeach
                </div>

                <footer class="ki-pagination" data-ki-pagination>{{ $invoices->links() }}</footer>
            @endif
        </section>
    </div>
</x-app-layout>
