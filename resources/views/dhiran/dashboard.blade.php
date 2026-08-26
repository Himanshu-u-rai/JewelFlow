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
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Loan
            </a>
            <a href="{{ route('dhiran.settings') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.573-1.066z"/><circle cx="12" cy="12" r="3"/></svg>
                Settings
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-dash">

        {{-- Cross-promotion (Phase 4): a calm suggestion for a Dhiran customer who
             doesn't yet run a Retail ERP store. Links to the SEPARATE JewelFlows ERP
             register — never grants an edition or links accounts. --}}
        @php
            $promoUser = auth()->user();
            $promoShop = $promoUser?->shop;
            $hasErpEdition = $promoShop
                && ($promoShop->hasEdition(\App\Support\ShopEdition::RETAILER)
                    || $promoShop->hasEdition(\App\Support\ShopEdition::MANUFACTURER));
            $showErpPromo = config('platform.cross_promotion.enabled')
                && $promoUser?->isDhiran()
                && ! $hasErpEdition;
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
                :url="config('platform.cross_promotion.erp_register_url')"
            />
        @endif

        {{-- KPI Cards --}}
        <div class="dd-kpi-grid">
            <div class="dd-kpi-card">
                <div class="dd-kpi-inner">
                    <div class="dd-kpi-icon bg-emerald-100 text-emerald-700">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
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
                                    <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33"/>
                                    </svg>
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
