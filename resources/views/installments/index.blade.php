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
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19" stroke-width="2" stroke-linecap="round" />
                        <line x1="5" y1="12" x2="19" y2="12" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <span class="installments-create-label-full">Create EMI Plan</span>
                    <span class="installments-create-label-short">Create</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page installments-index-page jf-skeleton-host is-loading">
        <section class="installments-index-kpi-grid" aria-label="Installment summary">
            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--gold">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.5 3A2.5 2.5 0 0 0 3 5.5v9A2.5 2.5 0 0 0 5.5 17h9a2.5 2.5 0 0 0 2.5-2.5v-9A2.5 2.5 0 0 0 14.5 3h-9Zm1.75 4.25a.75.75 0 0 1 .75-.75h4a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75Zm0 3.5a.75.75 0 0 1 .75-.75h4a.75.75 0 0 1 0 1.5H8a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                    </svg>
                </span>
                <div>
                    <span>Active Plans</span>
                    <strong>{{ number_format($activePlansCount ?? 0) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--danger">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm.75 3.75a.75.75 0 0 0-1.5 0v3.44c0 .2.08.39.22.53l2.25 2.25a.75.75 0 1 0 1.06-1.06l-2.03-2.03V6.25Z" clip-rule="evenodd" />
                    </svg>
                </span>
                <div>
                    <span>Overdue</span>
                    <strong class="is-danger">{{ number_format($overduePlans ?? 0) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--count">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--slate">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.5 2.5A2.5 2.5 0 0 0 3 5v10a2.5 2.5 0 0 0 2.5 2.5h9A2.5 2.5 0 0 0 17 15V5a2.5 2.5 0 0 0-2.5-2.5h-9Zm.75 6a.75.75 0 0 1 .75-.75h6a.75.75 0 0 1 0 1.5H7a.75.75 0 0 1-.75-.75Zm0 3a.75.75 0 0 1 .75-.75h3.5a.75.75 0 0 1 0 1.5H7a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                    </svg>
                </span>
                <div>
                    <span>Due Next 7 Days</span>
                    <strong>{{ number_format($upcomingDues ?? 0) }}</strong>
                </div>
            </article>

            <article class="installments-index-kpi-card installments-index-kpi-card--money">
                <span class="installments-index-kpi-icon installments-index-kpi-icon--success">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm3.03 6.47a.75.75 0 0 0-1.06-1.06L9.25 10.62l-1.22-1.22a.75.75 0 0 0-1.06 1.06l1.75 1.75a.75.75 0 0 0 1.06 0l3.25-3.25Z" clip-rule="evenodd" />
                    </svg>
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
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 2.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Zm0 3a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5.5Zm0 7a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd" />
                    </svg>
                </span>
                <div>
                    <span>Defaulted</span>
                    <strong class="is-danger">{{ number_format($defaultedPlans ?? 0) }}</strong>
                </div>
            </article>
        </section>

        <section class="customers-table-card installments-register-card">
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

            <div class="overflow-x-auto ui-table-shell customers-table-shell installments-table-shell">
                <table class="w-full customers-data-table installments-data-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th class="is-right">Total</th>
                            <th class="is-right">EMI</th>
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
                                <td class="is-right tabular-nums">₹{{ number_format($plan->total_amount, 2) }}</td>
                                <td class="is-right tabular-nums">₹{{ number_format($plan->emi_amount, 2) }}</td>
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
                                    <a href="{{ route('installments.show', $plan) }}" class="customers-row-action customers-row-action--primary">View</a>
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
