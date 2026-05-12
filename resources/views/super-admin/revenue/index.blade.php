<x-super-admin.layout>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h2 class="text-xl font-semibold text-white">Revenue Analytics</h2>
            <p class="text-sm text-slate-400 mt-0.5">Platform-wide subscription metrics</p>
        </div>
    </div>

    {{-- ── KPI Cards ────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <div class="admin-kpi">
            <div class="admin-kpi__label">MRR</div>
            <div class="admin-kpi__value text-xl">₹{{ number_format($mrr, 0) }}</div>
        </div>
        <div class="admin-kpi">
            <div class="admin-kpi__label">ARR</div>
            <div class="admin-kpi__value text-xl">₹{{ number_format($arr, 0) }}</div>
        </div>
        <div class="admin-kpi">
            <div class="admin-kpi__label">Active Shops</div>
            <div class="admin-kpi__value text-xl">{{ number_format($activeShops) }}</div>
        </div>
        <div class="admin-kpi">
            <div class="admin-kpi__label">ARPU</div>
            <div class="admin-kpi__value text-xl">₹{{ number_format($arpu, 0) }}</div>
        </div>
        <div class="admin-kpi">
            <div class="admin-kpi__label">Churn Rate</div>
            <div class="admin-kpi__value text-xl">{{ number_format($churnRate, 1) }}%</div>
        </div>
        <div class="admin-kpi">
            <div class="admin-kpi__label">New Signups (30d)</div>
            <div class="admin-kpi__value text-xl">{{ number_format($newSignups) }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

        {{-- ── MRR Trend (6 months) ─────────────────────────── --}}
        <div class="lg:col-span-2 admin-panel overflow-hidden">
            <div class="admin-panel-header">
                <h3 class="font-semibold text-white">MRR Trend (Last 6 Months)</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm admin-table">
                    <thead class="bg-slate-800/80 text-slate-300">
                        <tr>
                            @foreach($mrrTrend as $point)
                                <th class="px-4 py-2 text-center font-medium">{{ $point['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="text-slate-100">
                            @foreach($mrrTrend as $point)
                                <td class="px-4 py-3 text-center font-semibold">₹{{ number_format($point['mrr'], 0) }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Trial Conversion ─────────────────────────────── --}}
        <div class="admin-panel p-4">
            <h3 class="font-semibold text-white mb-4">Trial Conversion</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">Conversion Rate</dt>
                    <dd class="text-slate-100 font-semibold text-right">
                        @if($trialConversionRate !== null)
                            {{ number_format($trialConversionRate, 1) }}%
                        @else
                            <span class="text-slate-500">N/A</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">Trials Started (mo)</dt>
                    <dd class="text-slate-100 font-semibold text-right">{{ number_format($trialsStartedThisMonth) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">Converted to Paid (mo)</dt>
                    <dd class="text-slate-100 font-semibold text-right">{{ number_format($trialsPaidThisMonth) }}</dd>
                </div>
                <div class="border-t border-slate-700 pt-3 flex justify-between gap-3">
                    <dt class="text-slate-400">Churned (mo)</dt>
                    <dd class="text-rose-300 font-semibold text-right">{{ number_format($churned) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-400">Active (start of mo)</dt>
                    <dd class="text-slate-100 font-semibold text-right">{{ number_format($startActive) }}</dd>
                </div>
            </dl>
        </div>

    </div>

    {{-- ── Plan Breakdown ───────────────────────────────────── --}}
    <div class="admin-panel overflow-hidden">
        <div class="admin-panel-header">
            <h3 class="font-semibold text-white">Plan Breakdown</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm admin-table">
                <thead class="bg-slate-800/80 text-slate-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Plan</th>
                        <th class="px-4 py-2 text-right">Active Subscriptions</th>
                        <th class="px-4 py-2 text-right">Monthly Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($planBreakdown as $plan)
                        <tr class="border-t border-slate-800 text-slate-200">
                            <td class="px-4 py-3 font-medium">{{ $plan->name }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format($plan->count) }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-white">₹{{ number_format($plan->revenue, 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-slate-500">No active subscriptions found.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($planBreakdown->isNotEmpty())
                    <tfoot class="bg-slate-800/50">
                        <tr class="border-t border-slate-700">
                            <td class="px-4 py-2 text-slate-300 font-semibold">Total</td>
                            <td class="px-4 py-2 text-right text-slate-300 font-semibold">{{ number_format($planBreakdown->sum('count')) }}</td>
                            <td class="px-4 py-2 text-right text-white font-semibold">₹{{ number_format($planBreakdown->sum('revenue'), 0) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</x-super-admin.layout>
