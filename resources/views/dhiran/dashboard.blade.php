<x-dhiran-layout title="Dhiran Dashboard">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title dh-dashboard-mobile-title">
                Dhiran<span> &mdash; Pledge Loans</span>
            </h1>
            <p class="text-sm text-gray-500 mt-1">Overview of all pledge loan activity</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.create') }}" class="btn btn-dark btn-sm" data-dh-mobile-pin="true">
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

        {{-- KPI Cards --}}
        <div class="dd-kpi-grid">
            <div class="dd-kpi-card">
                <div class="dd-kpi-inner">
                    <div class="dd-kpi-icon bg-emerald-100 text-emerald-700">
                        <x-icon name="badge-check" />
                    </div>
                    <div>
                        <p class="dd-kpi-label">Active Loans</p>
                        <p class="dd-kpi-value">{{ number_format($stats['active_loans'] ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="dd-kpi-card">
                <div class="dd-kpi-inner">
                    <div class="dd-kpi-icon bg-rose-100 text-rose-700">
                        <x-icon name="clock" />
                    </div>
                    <div>
                        <p class="dd-kpi-label">Overdue Loans</p>
                        <p class="dd-kpi-value">{{ number_format($stats['overdue_loans'] ?? 0) }}</p>
                    </div>
                </div>
            </div>
            <div class="dd-kpi-card">
                <div class="dd-kpi-inner">
                    <div class="dd-kpi-icon bg-amber-100 text-amber-700">
                        <x-icon name="circle-dollar-sign" />
                    </div>
                    <div>
                        <p class="dd-kpi-label">Total Outstanding</p>
                        <p class="dd-kpi-value">{{ $currencySymbol ?? '₹' }}{{ number_format($stats['total_outstanding'] ?? 0, 2) }}</p>
                    </div>
                </div>
            </div>
            <div class="dd-kpi-card">
                <div class="dd-kpi-inner">
                    <div class="dd-kpi-icon bg-violet-100 text-violet-700">
                        <x-icon name="calculator" />
                    </div>
                    <div>
                        <p class="dd-kpi-label">This Month Interest</p>
                        <p class="dd-kpi-value">{{ $currencySymbol ?? '₹' }}{{ number_format($stats['month_interest'] ?? 0, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Loans Table --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="p-4 border-b border-slate-200">
                <h2 class="text-lg font-semibold text-slate-900">Recent Loans</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="pl-6 pr-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Loan #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Customer</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Principal</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Outstanding</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($recentLoans ?? [] as $loan)
                            <tr class="hover:bg-slate-50/70">
                                <td class="pl-6 pr-4 py-4 whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-slate-700">{{ $loan->customer->name ?? '---' }}</div>
                                    <div class="text-xs text-slate-500">{{ \App\Support\Mobile::forDisplay($loan->customer?->mobile) ?: '' }}</div>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-slate-700">
                                    {{ $currencySymbol ?? '₹' }}{{ number_format($loan->principal_amount, 2) }}
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-medium text-slate-800">
                                    {{ $currencySymbol ?? '₹' }}{{ number_format($loan->total_outstanding, 2) }}
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-center">
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
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-slate-500">
                                    {{ $loan->loan_date->format('d M Y') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-slate-500">
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
