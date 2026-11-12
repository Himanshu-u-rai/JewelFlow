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

        {{-- Owner 2026-11-11: small, simple figures (no big cards, icons, bars or panels); each opens the list behind it. --}}
        @php
            $inr = fn ($v) => '₹' . \App\Support\IndianNumber::format($v);
            $grams = fn ($v) => \App\Support\IndianNumber::format($v, 3) . ' g';
            $late90 = $dash['overdue']['90+'];
            $stats = [
                ['Open loans', $dash['open_count'], null, 'open-count', route('dhiran.loans', ['status' => 'active']), false, 'file-text'],
                ['Money lent out', $inr($dash['money_out']), null, 'money-out', route('dhiran.loans', ['status' => 'active']), false, 'banknote'],
                ['Total owed', $inr($dash['owed']), 'with interest and penalty', 'owed', route('dhiran.reports.active'), false, 'wallet'],
                ['Interest this month', $inr($dash['interest_month']), null, 'interest-month', route('dhiran.reports.interest', ['period' => 'this_month']), false, 'calculator'],
                ['Interest ' . $dash['fy_label'], $inr($dash['interest_fy']), null, 'interest-fy', route('dhiran.reports.interest', ['period' => 'this_fy']), false, 'calendar'],
                ['Awaiting evidence', $dash['pending'], null, 'pending', route('dhiran.loans', ['status' => 'pending_evidence']), $dash['pending'] > 0, 'camera'],
                ['Overdue', $dash['overdue_count'], $inr($dash['overdue_owed']) . ' owed', 'overdue-count', route('dhiran.reports.overdue'), $dash['overdue_count'] > 0, 'clock'],
                ['Over 90 days late', $late90['n'], $inr($late90['owed']) . ' owed', 'overdue-90+', $late90['url'], $late90['n'] > 0, 'triangle-alert'],
                ['Due in 7 days', $dash['maturing']['7']['n'], $inr($dash['maturing']['7']['owed']), 'maturing-7', $dash['maturing']['7']['url'], false, 'calendar-clock'],
                ['Due in 30 days', $dash['maturing']['30']['n'], $inr($dash['maturing']['30']['owed']), 'maturing-30', $dash['maturing']['30']['url'], false, 'calendar'],
                ['Gold held (fine)', $grams($dash['held']['gold']->fine ?? 0), null, 'fine-gold', route('dhiran.loans', ['metal' => 'gold', 'status' => 'active']), false, 'weight'],
                ['Silver held (fine)', $grams($dash['held']['silver']->fine ?? 0), null, 'fine-silver', route('dhiran.loans', ['metal' => 'silver', 'status' => 'active']), false, 'coins'],
            ];
        @endphp

        <section class="dd-glance" data-dash-glance aria-label="At a glance">
            @foreach($stats as [$label, $value, $sub, $key, $url, $flag, $icon])
                <a href="{{ $url }}" class="dd-stat {{ $flag ? 'is-flagged' : '' }}">
                    <span class="dd-stat-label"><x-icon :name="$icon" size="14" />{{ $label }}</span>
                    <span class="dd-stat-value" data-dash="{{ $key }}">{{ $value }}</span>
                    @if($sub)<span class="dd-stat-sub">{{ $sub }}</span>@endif
                </a>
            @endforeach
        </section>

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
                                    <x-dhiran.status :value="$loan->status" />
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
