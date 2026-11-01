@php
    $statusParam = request()->has('status') ? request('status') : 'active';
    $statusOptions = [
        'active' => 'Active',
        'completed' => 'Completed',
        'defaulted' => 'Defaulted',
    ];
    $statusTone = [
        'active' => 'is-active',
        'completed' => 'is-completed',
        'defaulted' => 'is-defaulted',
    ];
    $planTotal = method_exists($plans, 'total') ? $plans->total() : $plans->count();
    $currentStatusLabel = $statusOptions[$statusParam] ?? 'Active';
@endphp

<x-app-layout>
    <x-page-header
        class="customers-page-header installments-index-header"
        title="EMI / Installments"
        subtitle="Track installment plans and EMI payments"
    >
        <x-slot:actions>
            @can('sales.create')
                <a href="{{ route('installments.create') }}" class="btn btn-success btn-sm customers-add-btn installments-create-btn">
                    <x-icon name="plus" class="w-4 h-4 mr-2" />
                    <span class="installments-create-label-full">Create EMI Plan</span>
                    <span class="installments-create-label-short">Create</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page installments-index-page jf-skeleton-host is-loading" data-list-workspace>
        <section class="installments-index-kpi-grid" aria-label="Installment summary">
            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--gold">
                    <x-icon name="calendar-check" />
                </span>
                <div>
                    <span>Active Plans</span>
                    <strong>{{ number_format($activePlansCount ?? 0) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--danger">
                    <x-icon name="clock" />
                </span>
                <div>
                    <span>Overdue</span>
                    <strong class="is-danger">{{ number_format($overduePlans ?? 0) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--slate">
                    <x-icon name="calendar-clock" />
                </span>
                <div>
                    <span>Due Next 7 Days</span>
                    <strong>{{ number_format($upcomingDues ?? 0) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--money">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--success">
                    <x-icon name="circle-check" />
                </span>
                <div>
                    <span>Collected This Month</span>
                    <strong class="is-success">₹{{ number_format($thisMonthCollected ?? 0, 2) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--money">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--gold">₹</span>
                <div>
                    <span>Total Outstanding</span>
                    <strong>₹{{ number_format($totalOutstanding ?? 0, 2) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--danger">
                    <x-icon name="circle-alert" />
                </span>
                <div>
                    <span>Defaulted</span>
                    <strong class="is-danger">{{ number_format($defaultedPlans ?? 0) }}</strong>
                </div>
            </article>
        </section>

        <section class="customers-table-card installments-register-card" data-list-frame>
            <div class="customers-table-card-header customers-register-head installments-register-head">
                <div class="customers-register-titleblock">
                    <h2>EMI register</h2>
                    <p>
                        {{ number_format($planTotal) }} {{ Str::plural('plan', $planTotal) }} in this view
                        <span class="installments-filter-summary">{{ $currentStatusLabel }}</span>
                    </p>
                </div>

                <nav class="installments-status-tabs" aria-label="Installment status filter">
                    @foreach($statusOptions as $value => $label)
                        <a href="{{ route('installments.index', ['status' => $value]) }}"
                           class="installments-status-tab {{ $statusParam === $value ? 'is-active' : '' }}"
                           aria-current="{{ $statusParam === $value ? 'page' : 'false' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>
            </div>

            <div class="overflow-x-auto ui-table-shell customers-table-shell installments-table-shell" data-list-scroll tabindex="0" role="region" aria-label="Installment plan records">
                <table class="w-full customers-data-table installments-data-table jf-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="is-right text-right">Total</th>
                            <th class="is-right text-right">EMI</th>
                            <th class="is-center">Progress</th>
                            <th>Next Due</th>
                            <th class="is-center">Status</th>
                            <th class="is-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($plans as $plan)
                            @php
                                $isOverdue = $plan->next_due_date && $plan->next_due_date < now()->toDateString() && $plan->status === 'active';
                                $progressPercent = $plan->total_emis > 0 ? min(100, round(($plan->emis_paid / $plan->total_emis) * 100)) : 0;
                                $customerName = $plan->customer->name ?? 'Customer not linked';
                                $customerMobile = $plan->customer->mobile ?? null;
                                $invoiceLabel = $plan->invoice?->invoice_number ?? ($plan->invoice_id ? '#' . $plan->invoice_id : '—');
                                $rowStatusClass = $statusTone[$plan->status] ?? 'is-neutral';
                            @endphp
                            <tr class="{{ $isOverdue ? 'is-overdue' : '' }}">
                                <td>
                                    <div class="installments-customer-cell">
                                        <strong>{{ $customerName }}</strong>
                                        <span>{{ $customerMobile ?: $invoiceLabel }}</span>
                                    </div>
                                </td>
                                <td class="is-right tabular-nums text-right">₹{{ number_format($plan->total_amount, 2) }}</td>
                                <td class="is-right tabular-nums text-right">₹{{ number_format($plan->emi_amount, 2) }}</td>
                                <td class="is-center">
                                    <div class="installments-progress-cell">
                                        <span>{{ $plan->emis_paid }}/{{ $plan->total_emis }}</span>
                                        <div class="installments-progress-track" aria-hidden="true"><i style="width: {{ $progressPercent }}%"></i></div>
                                    </div>
                                </td>
                                <td class="{{ $isOverdue ? 'is-danger' : '' }}">
                                    {{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') : '—' }}
                                    @if($isOverdue)
                                        <span class="installments-overdue-note">Overdue</span>
                                    @endif
                                </td>
                                <td class="is-center">
                                    <span class="installments-status-badge {{ $rowStatusClass }}">{{ ucfirst($plan->status) }}</span>
                                </td>
                                <td class="is-center">
                                    <a href="{{ route('installments.show', $plan) }}" class="jf-row-action jf-row-action--view" data-tip="View"><x-icon name="eye" size="16" /><span class="jf-sr-only">View</span></a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <div class="customers-mobile-empty installments-empty-state">
                                        <strong>No installment plans found</strong>
                                        <span>Create a plan from an invoice or use the create button when the customer needs EMI tracking.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="customers-mobile-cards installments-mobile-cards">
                @forelse($plans as $plan)
                    @php
                        $isOverdue = $plan->next_due_date && $plan->next_due_date < now()->toDateString() && $plan->status === 'active';
                        $progressPercent = $plan->total_emis > 0 ? min(100, round(($plan->emis_paid / $plan->total_emis) * 100)) : 0;
                        $customerName = $plan->customer->name ?? 'Customer not linked';
                        $customerMobile = $plan->customer->mobile ?? null;
                        $invoiceLabel = $plan->invoice?->invoice_number ?? ($plan->invoice_id ? '#' . $plan->invoice_id : '—');
                        $rowStatusClass = $statusTone[$plan->status] ?? 'is-neutral';
                    @endphp
                    <article class="customers-mobile-card installments-mobile-card {{ $isOverdue ? 'is-warning' : '' }}">
                        <div class="customers-mobile-card__top">
                            <div class="customers-mobile-card__identity">
                                <span class="customers-mobile-avatar">{{ Str::of($customerName)->trim()->substr(0, 1)->upper() }}</span>
                                <div>
                                    <a href="{{ route('installments.show', $plan) }}" class="customers-mobile-card__title">{{ $customerName }}</a>
                                    <span class="customers-mobile-card__sub">{{ $customerMobile ? $customerMobile . ' · ' . $invoiceLabel : $invoiceLabel }}</span>
                                </div>
                            </div>
                            <span class="installments-status-badge {{ $rowStatusClass }}">{{ ucfirst($plan->status) }}</span>
                        </div>

                        <div class="customers-mobile-card__metrics">
                            <div>
                                <span>Total</span>
                                <strong>₹{{ number_format($plan->total_amount, 2) }}</strong>
                            </div>
                            <div>
                                <span>EMI</span>
                                <strong>₹{{ number_format($plan->emi_amount, 2) }}</strong>
                            </div>
                            <div>
                                <span>Progress</span>
                                <strong>{{ $plan->emis_paid }}/{{ $plan->total_emis }}</strong>
                            </div>
                            <div>
                                <span>Next due</span>
                                <strong class="{{ $isOverdue ? 'is-danger' : '' }}">{{ $plan->next_due_date ? \Carbon\Carbon::parse($plan->next_due_date)->format('d M Y') : '—' }}</strong>
                            </div>
                        </div>

                        <div class="installments-mobile-progress">
                            <span>{{ $progressPercent }}% complete</span>
                            @if($isOverdue)
                                <strong>Overdue</strong>
                            @endif
                            <div class="installments-progress-track" aria-hidden="true"><i style="width: {{ $progressPercent }}%"></i></div>
                        </div>

                        <div class="customers-mobile-card__actions">
                            <a href="{{ route('installments.show', $plan) }}" class="customers-row-action customers-row-action--primary">View Plan</a>
                        </div>
                    </article>
                @empty
                    <div class="customers-mobile-empty">
                        <strong>No installment plans found</strong>
                        <span>Create a plan from an invoice or use the create button when the customer needs EMI tracking.</span>
                    </div>
                @endforelse
            </div>

            @if($plans->hasPages())
                <div class="installments-pagination">{{ $plans->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
