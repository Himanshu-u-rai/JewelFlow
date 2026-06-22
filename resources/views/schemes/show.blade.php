@php
    $typeLabels = [
        'gold_savings' => 'Gold Savings',
        'festival_sale' => 'Festival Sale',
        'discount_offer' => 'Discount Offer',
    ];
    $statusLabel = $scheme->isRunning() ? 'Running' : ($scheme->is_active ? 'Active' : 'Inactive');
    $statusTone = $scheme->isRunning() ? 'active' : ($scheme->is_active ? 'neutral' : 'inactive');
    $enrollmentTotal = method_exists($enrollments, 'total') ? $enrollments->total() : $enrollments->count();
    $periodLabel = ($scheme->start_date?->format('d M Y') ?? 'Not set') . ' - ' . ($scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open ended');
    $discountLabel = 'Not configured';

    if ($scheme->discount_type === 'percentage') {
        $discountLabel = rtrim(rtrim(number_format((float) $scheme->discount_value, 2), '0'), '.') . '%';
    } elseif ($scheme->discount_type === 'flat') {
        $discountLabel = '₹' . number_format((float) $scheme->discount_value, 2);
    }
@endphp

<x-app-layout>
    <x-page-header
        class="customers-page-header schemes-page-header schemes-show-header schemes-show-header-mobile-fab"
        title="Scheme Details"
        :subtitle="$scheme->name"
    >
        <x-slot:actions>
            <a href="{{ route('schemes.index') }}" class="customers-row-action schemes-header-action schemes-header-action--neutral schemes-show-back-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" />
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span class="schemes-action-label-full">Back to Schemes</span>
                <span class="schemes-action-label-short">Back</span>
            </a>

            <a href="{{ route('schemes.edit', $scheme) }}" class="customers-row-action schemes-header-action scheme-edit-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Edit
            </a>

            @if($scheme->isGoldSavings())
                @can('sales.create')
                    <a href="{{ route('schemes.enroll.form', $scheme) }}" class="customers-row-action customers-row-action--primary schemes-header-action schemes-header-action--primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" stroke-linecap="round" stroke-linejoin="round" />
                            <circle cx="8.5" cy="7" r="4" />
                            <line x1="20" y1="8" x2="20" y2="14" stroke-linecap="round" />
                            <line x1="23" y1="11" x2="17" y2="11" stroke-linecap="round" />
                        </svg>
                        Enroll
                    </a>
                @endcan
            @endif

            @can('catalog.manage')
                <button type="button"
                        onclick="document.getElementById('delete-scheme-modal').classList.remove('hidden')"
                        class="customers-row-action schemes-header-action schemes-header-action--danger scheme-delete-action">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <polyline points="3 6 5 6 21 6" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M19 6l-1 14H6L5 6" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M10 11v6M14 11v6M9 6V4h6v2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Delete
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div x-data="{ schemeQuickFabOpen: false }" class="invoice-emi-mobile-fab">
        <div class="invoice-emi-mobile-fab-shell" x-bind:class="{ 'is-open': schemeQuickFabOpen }" @click.outside="schemeQuickFabOpen = false">
            <nav class="invoice-emi-mobile-fab-nav" aria-label="Scheme quick actions">
                <a href="{{ route('schemes.edit', $scheme) }}" class="invoice-emi-mobile-fab-link" @click="schemeQuickFabOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Edit Scheme</span>
                </a>
                @can('catalog.manage')
                    <button type="button"
                            class="invoice-emi-mobile-fab-link scheme-mobile-fab-delete"
                            x-on:click="schemeQuickFabOpen = false; document.getElementById('delete-scheme-modal').classList.remove('hidden')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><polyline points="3 6 5 6 21 6" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6l-1 14H6L5 6" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 11v6M14 11v6M9 6V4h6v2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Delete Scheme</span>
                    </button>
                @endcan
            </nav>
            <button type="button" class="invoice-emi-mobile-fab-toggle" x-on:click="schemeQuickFabOpen = !schemeQuickFabOpen" x-bind:aria-expanded="schemeQuickFabOpen.toString()" aria-label="Toggle scheme actions">
                <span class="invoice-emi-mobile-fab-bars" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
        </div>
    </div>

    <div id="delete-scheme-modal" class="hidden fixed inset-0 z-50 scheme-delete-modal flex items-center justify-center bg-black/40">
        <div class="schemes-modal-card">
            <h3>Delete scheme?</h3>
            <p>
                This will permanently delete <strong>{{ $scheme->name }}</strong>.
                Schemes with active or matured enrollments cannot be deleted.
            </p>
            <div class="schemes-modal-actions">
                <button type="button"
                        onclick="document.getElementById('delete-scheme-modal').classList.add('hidden')"
                        class="schemes-btn schemes-btn--secondary">
                    Cancel
                </button>
                <form method="POST" action="{{ route('schemes.destroy', $scheme) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="schemes-btn schemes-btn--danger">Yes, Delete</button>
                </form>
            </div>
        </div>
    </div>

    <div class="content-inner schemes-detail-page">
        @unless(auth()->user()->can('catalog.manage') || auth()->user()->can('sales.create'))
            @include('partials.view-only-banner', ['permission' => 'catalog.manage', 'message' => 'schemes and enrollments'])
        @endunless

        <section class="schemes-detail-kpis" aria-label="Scheme summary">
            <article class="schemes-detail-kpi">
                <span>Status</span>
                <strong><span class="schemes-detail-status schemes-detail-status--{{ $statusTone }}">{{ $statusLabel }}</span></strong>
            </article>
            <article class="schemes-detail-kpi">
                <span>Enrollments</span>
                <strong>{{ number_format($enrollmentTotal) }}</strong>
            </article>
            <article class="schemes-detail-kpi">
                <span>{{ $scheme->isGoldSavings() ? 'Installments' : 'Discount' }}</span>
                <strong>{{ $scheme->isGoldSavings() ? ($scheme->total_installments ?? 11) : $discountLabel }}</strong>
            </article>
            <article class="schemes-detail-kpi">
                <span>Period</span>
                <strong>{{ $scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open ended' }}</strong>
            </article>
        </section>

        <div class="schemes-detail-layout">
            <main class="schemes-detail-main">
                <section class="schemes-card schemes-card--flush">
                    <div class="schemes-card-head">
                        <div>
                            <h2>{{ $scheme->name }}</h2>
                            <p>{{ $typeLabels[$scheme->type] ?? str_replace('_', ' ', ucfirst($scheme->type)) }} · Rules, schedule, and customer-facing conditions.</p>
                        </div>
                    </div>

                    <dl class="schemes-detail-list">
                        <div>
                            <dt>Type</dt>
                            <dd>{{ $typeLabels[$scheme->type] ?? str_replace('_', ' ', ucfirst($scheme->type)) }}</dd>
                        </div>
                        <div>
                            <dt>Start date</dt>
                            <dd>{{ $scheme->start_date?->format('d M Y') ?? 'Not set' }}</dd>
                        </div>
                        <div>
                            <dt>End date</dt>
                            <dd>{{ $scheme->end_date ? $scheme->end_date->format('d M Y') : 'Open ended' }}</dd>
                        </div>

                        @if($scheme->isGoldSavings())
                            <div>
                                <dt>Total installments</dt>
                                <dd>{{ $scheme->total_installments ?? 11 }}</dd>
                            </div>
                            <div>
                                <dt>Bonus amount</dt>
                                <dd>{{ $scheme->bonus_month_value ? '₹' . number_format((float) $scheme->bonus_month_value, 2) : 'Equals 1 month' }}</dd>
                            </div>
                        @else
                            <div>
                                <dt>Discount</dt>
                                <dd>{{ $discountLabel }}</dd>
                            </div>
                            <div>
                                <dt>Minimum purchase</dt>
                                <dd>{{ $scheme->min_purchase_amount ? '₹' . number_format((float) $scheme->min_purchase_amount, 2) : 'None' }}</dd>
                            </div>
                            <div>
                                <dt>Maximum discount</dt>
                                <dd>{{ $scheme->max_discount_amount ? '₹' . number_format((float) $scheme->max_discount_amount, 2) : 'No cap' }}</dd>
                            </div>
                            <div>
                                <dt>Offer target</dt>
                                <dd>
                                    @if(($scheme->applies_to ?? 'all_items') === 'all_items')
                                        All items
                                    @elseif(($scheme->applies_to ?? 'all_items') === 'category')
                                        Category: {{ $scheme->applies_to_value }}
                                    @else
                                        Sub-category: {{ $scheme->applies_to_value }}
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Auto apply</dt>
                                <dd>{{ $scheme->auto_apply ? 'Yes' : 'No' }}</dd>
                            </div>
                            <div>
                                <dt>Priority</dt>
                                <dd>{{ $scheme->priority ?? 100 }}</dd>
                            </div>
                            <div>
                                <dt>Max uses / customer</dt>
                                <dd>{{ $scheme->max_uses_per_customer ?? 'No limit' }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if($scheme->description)
                        <div class="schemes-detail-copy">
                            <span>Description</span>
                            <p>{{ $scheme->description }}</p>
                        </div>
                    @endif
                </section>

                @if($scheme->isGoldSavings())
                    <section class="schemes-card schemes-enrollment-register">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Enrollments</h2>
                                <p>{{ number_format($enrollmentTotal) }} {{ Str::plural('customer', $enrollmentTotal) }} in this scheme.</p>
                            </div>
                            @can('sales.create')
                                <a href="{{ route('schemes.enroll.form', $scheme) }}" class="customers-row-action customers-row-action--primary schemes-card-action">Enroll Customer</a>
                            @endcan
                        </div>

                        @if($enrollments->count())
                            <div class="schemes-table-shell">
                                <table class="schemes-enrollments-table">
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th class="text-right">Monthly</th>
                                            <th class="text-center">Paid</th>
                                            <th class="text-right">Total Paid</th>
                                            <th class="text-center">Status</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($enrollments as $enrollment)
                                            <tr>
                                                <td>
                                                    <strong>{{ $enrollment->customer->name ?? 'Unknown' }}</strong>
                                                    <span>{{ $enrollment->customer->mobile ?? 'No mobile' }}</span>
                                                </td>
                                                <td class="text-right">₹{{ number_format((float) $enrollment->monthly_amount, 2) }}</td>
                                                <td class="text-center">{{ $enrollment->installments_paid }}/{{ $enrollment->total_installments }}</td>
                                                <td class="text-right">₹{{ number_format((float) $enrollment->total_paid, 2) }}</td>
                                                <td class="text-center">
                                                    <span class="schemes-enrollment-status schemes-enrollment-status--{{ $enrollment->status }}">{{ ucfirst($enrollment->status) }}</span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="{{ route('schemes.enrollment.show', $enrollment) }}" class="customers-row-action">View</a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="schemes-enrollments-mobile">
                                @foreach($enrollments as $enrollment)
                                    <article class="schemes-enrollment-mobile-card">
                                        <div>
                                            <strong>{{ $enrollment->customer->name ?? 'Unknown' }}</strong>
                                            <span>{{ $enrollment->customer->mobile ?? 'No mobile' }}</span>
                                        </div>
                                        <span class="schemes-enrollment-status schemes-enrollment-status--{{ $enrollment->status }}">{{ ucfirst($enrollment->status) }}</span>
                                        <dl>
                                            <div><dt>Monthly</dt><dd>₹{{ number_format((float) $enrollment->monthly_amount, 2) }}</dd></div>
                                            <div><dt>Paid</dt><dd>{{ $enrollment->installments_paid }}/{{ $enrollment->total_installments }}</dd></div>
                                            <div><dt>Total paid</dt><dd>₹{{ number_format((float) $enrollment->total_paid, 2) }}</dd></div>
                                        </dl>
                                        <a href="{{ route('schemes.enrollment.show', $enrollment) }}" class="customers-row-action customers-row-action--primary">View enrollment</a>
                                    </article>
                                @endforeach
                            </div>

                            @if($enrollments->hasPages())
                                <div class="schemes-pagination">{{ $enrollments->links() }}</div>
                            @endif
                        @else
                            <div class="schemes-empty-state">
                                <strong>No enrollments yet</strong>
                                <span>Enroll the first customer when they join this savings plan.</span>
                            </div>
                        @endif
                    </section>
                @endif
            </main>

            <aside class="schemes-detail-side">
                <section class="schemes-card">
                    <div class="schemes-card-head">
                        <div>
                            <h2>Operating Status</h2>
                            <p>{{ $periodLabel }}</p>
                        </div>
                    </div>
                    <dl class="schemes-side-list">
                        <div>
                            <dt>Status</dt>
                            <dd><span class="schemes-detail-status schemes-detail-status--{{ $statusTone }}">{{ $statusLabel }}</span></dd>
                        </div>
                        <div>
                            <dt>Active flag</dt>
                            <dd>{{ $scheme->is_active ? 'Enabled' : 'Disabled' }}</dd>
                        </div>
                        @if(!$scheme->isGoldSavings())
                            <div>
                                <dt>Stackable</dt>
                                <dd>{{ $scheme->stackable ? 'Allowed' : 'Not allowed' }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>

                @if($scheme->terms)
                    <section class="schemes-card">
                        <div class="schemes-card-head">
                            <div>
                                <h2>Terms</h2>
                                <p>Shown during customer enrollment or offer use.</p>
                            </div>
                        </div>
                        <p class="schemes-terms-copy">{{ $scheme->terms }}</p>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-app-layout>
