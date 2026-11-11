<x-dhiran-layout title="Dhiran Dashboard">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title dh-dashboard-mobile-title">
                Dhiran<span> &mdash; Pledge Loans</span>
            </h1>
            <p class="text-sm text-gray-500 mt-1">Overview of all pledge loan activity</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.create') }}" class="btn btn-primary btn-sm" data-dh-mobile-pin="true">
                <x-icon name="plus" size="14" class="mr-1" />
                New Loan
            </a>
            <a href="{{ route('dhiran.settings') }}" class="btn btn-secondary btn-sm">
                <x-icon name="settings" size="14" class="mr-1" />
                Settings
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-dash">

        {{-- Owner-only introduction, atomically claimed once on the server. --}}
        @php
            $erpRegisterUrl = app(\App\Services\ProductPromotionService::class)->claimIntroduction(request());
            $showErpPromo = $erpRegisterUrl !== null;
        @endphp
        {{-- Admin-editable offers/deals banner (platform announcements, type=banner). --}}
        <x-promo-banner realm="dhiran" />

        @if($showErpPromo)
            <x-cross-promo-card
                key="erp"
                realm="dhiran"
                heading="Running a jewellery store too?"
                body="Use JewelFlows ERP for inventory, POS billing, returns, karigar work and reports — as its own separate account."
                cta="Explore JewelFlows ERP"
                :url="$erpRegisterUrl"
            />
        @endif

        {{-- Owner 2026-11-11: the figures a lender runs the day on; every number links to the list behind it. --}}
        @php
            $inr = fn ($v) => '₹' . \App\Support\IndianNumber::format($v);
            $grams = fn ($v) => \App\Support\IndianNumber::format($v, 3) . ' g';
            $maxBand = max(1, ...array_column($dash['overdue'], 'n'));
        @endphp

        @if($dash['pending'] > 0)
            <a href="{{ route('dhiran.loans', ['status' => 'pending_evidence']) }}" class="dd-alert">
                <x-icon name="triangle-alert" size="16" />
                <span><strong>{{ $dash['pending'] }} {{ \Illuminate\Support\Str::plural('loan', $dash['pending']) }}</strong> awaiting evidence: upload the item photo and ID proof to activate {{ $dash['pending'] > 1 ? 'them' : 'it' }}.</span>
                <x-icon name="chevron-right" size="16" />
            </a>
        @endif

        <div class="dd-kpi-grid">
            <a href="{{ route('dhiran.loans', ['status' => 'active']) }}" class="dd-kpi-card dd-kpi-link">
                <span class="dd-kpi-icon bg-emerald-100 text-emerald-700"><x-icon name="banknote" /></span>
                <span class="dd-kpi-label">Money lent out</span>
                <span class="dd-kpi-value" data-dash="money-out">{{ $inr($dash['money_out']) }}</span>
                <span class="dd-kpi-sub"><span data-dash="open-count">{{ $dash['open_count'] }}</span> open {{ \Illuminate\Support\Str::plural('loan', $dash['open_count']) }}</span>
            </a>
            <a href="{{ route('dhiran.reports.active') }}" class="dd-kpi-card dd-kpi-link">
                <span class="dd-kpi-icon bg-amber-100 text-amber-700"><x-icon name="circle-dollar-sign" /></span>
                <span class="dd-kpi-label">Total outstanding</span>
                <span class="dd-kpi-value" data-dash="owed">{{ $inr($dash['owed']) }}</span>
                <span class="dd-kpi-sub">Principal with interest and penalty due</span>
            </a>
            <div class="dd-kpi-card">
                <span class="dd-kpi-icon bg-violet-100 text-violet-700"><x-icon name="calculator" /></span>
                <a href="{{ route('dhiran.reports.interest', ['period' => 'this_month']) }}" class="dd-kpi-label dd-kpi-labellink">Interest this month</a>
                <span class="dd-kpi-value" data-dash="interest-month">{{ $inr($dash['interest_month']) }}</span>
                <a href="{{ route('dhiran.reports.interest', ['period' => 'this_fy']) }}" class="dd-kpi-sub dd-kpi-sublink"><span data-dash="interest-fy">{{ $inr($dash['interest_fy']) }}</span> in {{ $dash['fy_label'] }}</a>
            </div>
            <a href="{{ route('dhiran.reports.overdue') }}" class="dd-kpi-card dd-kpi-link {{ $dash['overdue_count'] ? 'is-alert' : '' }}">
                <span class="dd-kpi-icon bg-rose-100 text-rose-700"><x-icon name="clock" /></span>
                <span class="dd-kpi-label">Overdue</span>
                <span class="dd-kpi-value" data-dash="overdue-count">{{ $dash['overdue_count'] }}</span>
                <span class="dd-kpi-sub">{{ $inr($dash['overdue_owed']) }} owed</span>
            </a>
        </div>

        <div class="dd-panels">
            <section class="dd-panel">
                <h2 class="dd-panel-title"><x-icon name="calendar-clock" size="16" /> Due soon</h2>
                @foreach(['7' => 'Next 7 days', '30' => 'Next 30 days'] as $days => $label)
                    <a href="{{ $dash['maturing'][$days]['url'] }}" class="dd-row">
                        <span class="dd-row-label">{{ $label }}</span>
                        <span class="dd-row-count" data-dash="maturing-{{ $days }}">{{ $dash['maturing'][$days]['n'] }}</span>
                        <span class="dd-row-amount">{{ $inr($dash['maturing'][$days]['owed']) }}</span>
                    </a>
                @endforeach
            </section>

            <section class="dd-panel">
                <h2 class="dd-panel-title"><x-icon name="triangle-alert" size="16" /> Overdue by age</h2>
                @foreach(\App\Services\Dhiran\DhiranListFilters::OVERDUE as $band => $label)
                    <a href="{{ $dash['overdue'][$band]['url'] }}" class="dd-row dd-row--bar">
                        <span class="dd-row-label">{{ str_replace(' late', '', $label) }}</span>
                        <span class="dd-row-count" data-dash="overdue-{{ $band }}">{{ $dash['overdue'][$band]['n'] }}</span>
                        <span class="dd-row-amount">{{ $inr($dash['overdue'][$band]['owed']) }}</span>
                        <span class="dd-bar" aria-hidden="true"><span class="dd-bar-fill dd-bar-fill--{{ $loop->index }}" style="width: {{ round(100 * $dash['overdue'][$band]['n'] / $maxBand) }}%"></span></span>
                    </a>
                @endforeach
            </section>

            <section class="dd-panel">
                <h2 class="dd-panel-title"><x-icon name="weight" size="16" /> Gold &amp; silver held</h2>
                @foreach(['gold' => 'Gold (fine)', 'silver' => 'Silver (fine)'] as $metal => $label)
                    <a href="{{ route('dhiran.loans', ['metal' => $metal, 'status' => 'active']) }}" class="dd-row">
                        <span class="dd-row-label">{{ $label }}</span>
                        <span class="dd-row-count" data-dash="fine-{{ $metal }}">{{ $grams($dash['held'][$metal]->fine ?? 0) }}</span>
                        <span class="dd-row-amount" title="Value when pledged">{{ $inr($dash['held'][$metal]->value ?? 0) }}</span>
                    </a>
                @endforeach
                <p class="dd-panel-note">Items still pledged on open loans; value as at pledge.</p>
            </section>
        </div>

        {{-- Recent Loans Table --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="dh-section-head">
                <h2 class="text-lg font-semibold text-slate-900">Recent Loans</h2>
                <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary btn-sm">View all loans <x-icon name="chevron-right" size="14" /></a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px] jf-table dh-stack">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="dh-sno-th">#</th>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Mobile</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Outstanding</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($recentLoans ?? [] as $loan)
                            <tr class="hover:bg-slate-50/70">
                                <x-dhiran.sno :rows="null" :n="$loop->iteration" />
                                <td data-label="Loan #" class="pl-6 pr-4 py-4 whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="px-4 py-4 whitespace-nowrap text-sm font-medium text-slate-700">{{ $loan->customer->name ?? '---' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Principal" class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">
                                    {{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}
                                </td>
                                <td data-label="Outstanding" class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">
                                    {{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding, 2) }}
                                </td>
                                <td data-label="Status" class="px-4 py-4 whitespace-nowrap text-center">
                                    @php
                                        $statusColors = [
                                            'pending_evidence' => 'bg-amber-100 text-amber-800',
                                            'active' => 'bg-emerald-100 text-emerald-800',
                                            'overdue' => 'bg-rose-100 text-rose-800',
                                            'closed' => 'bg-slate-100 text-slate-600',
                                            'renewed' => 'bg-sky-100 text-sky-800',
                                            'forfeited' => 'bg-red-100 text-red-800',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ $statusColors[$loan->status] ?? 'bg-slate-100 text-slate-600' }}">
                                        {{ $loan->status === 'pending_evidence' ? 'Awaiting Evidence' : ucfirst($loan->status) }}
                                    </span>
                                </td>
                                <td data-label="Date" class="px-4 py-4 whitespace-nowrap text-sm text-slate-500">
                                    {{ $loan->loan_date->format('d M Y') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="jf-table-empty">
                                    <x-icon name="dollar-sign" class="w-12 h-12 mx-auto text-gray-300 mb-4" />
                                    <p class="text-lg font-semibold mb-1 text-slate-700">No loans yet</p>
                                    <p class="text-sm">Create your first pledge loan to get started</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-dhiran-layout>
