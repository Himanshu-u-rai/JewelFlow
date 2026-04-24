<x-app-layout>
    <style>
        .dhiran-reports-root {
            --dr-ink: #0f172a;
            --dr-muted: #64748b;
            --dr-border: #e2e8f0;
            --dr-accent: #d98b00;
            --dr-shadow: 0 10px 24px rgba(20, 40, 75, 0.08);
        }
        .dr-report-card {
            background: #fff;
            border: 1px solid var(--dr-border);
            border-radius: 14px;
            padding: 20px;
            box-shadow: var(--dr-shadow);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .dr-report-card:hover {
            border-color: var(--dr-accent);
            box-shadow: 0 12px 28px rgba(20, 40, 75, 0.12);
        }
        .dr-report-icon {
            display: flex; align-items: center; justify-content: center;
            width: 42px; height: 42px; border-radius: 12px;
            margin-bottom: 12px;
        }
        .dr-report-icon svg { width: 20px; height: 20px; }
        .dr-report-title { font-size: 14px; font-weight: 700; color: var(--dr-ink); margin-bottom: 4px; }
        .dr-report-desc { font-size: 12px; color: var(--dr-muted); line-height: 1.5; margin-bottom: 12px; }
    </style>

    <x-page-header>
        <div>
            <h1 class="page-title">Dhiran Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Gold loan analytics and reports</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('dhiran.dashboard') }}" class="btn btn-secondary btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Back to Dashboard
            </a>
        </div>
    </x-page-header>

    <div class="content-inner dhiran-reports-root">
        <x-app-alerts class="mb-6" />

        {{-- Date Range Filter --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6">
            <form method="GET" action="{{ route('dhiran.reports.index') }}" class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">From Date</label>
                    <input type="date" name="from_date" value="{{ request('from_date', now()->startOfMonth()->toDateString()) }}"
                           class="rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">To Date</label>
                    <input type="date" name="to_date" value="{{ request('to_date', now()->toDateString()) }}"
                           class="rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Report Type</label>
                    <select name="type" class="rounded-xl border-2 border-slate-300 bg-white py-2.5 px-3 text-sm text-slate-700 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500/15 focus:outline-none" style="appearance:none;-webkit-appearance:none;background-image:url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E&quot;);background-repeat:no-repeat;background-position:right 12px center;padding-right:36px;">
                        <option value="active" {{ request('type', 'active') === 'active' ? 'selected' : '' }}>Active Loans</option>
                        <option value="overdue" {{ request('type') === 'overdue' ? 'selected' : '' }}>Overdue Loans</option>
                        <option value="interest" {{ request('type') === 'interest' ? 'selected' : '' }}>Interest Collection</option>
                        <option value="forfeiture" {{ request('type') === 'forfeiture' ? 'selected' : '' }}>Forfeiture</option>
                        <option value="cashbook" {{ request('type') === 'cashbook' ? 'selected' : '' }}>Cashbook</option>
                        <option value="profitability" {{ request('type') === 'profitability' ? 'selected' : '' }}>Profitability</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Generate Report</button>
            </form>
        </div>

        {{-- Report Cards Grid --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mb-6">
            <a href="{{ route('dhiran.reports.index', ['type' => 'active']) }}" class="dr-report-card">
                <div class="dr-report-icon bg-emerald-100 text-emerald-700">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="dr-report-title">Active Loans Report</div>
                <div class="dr-report-desc">All currently active gold loans with principal, outstanding, and maturity details.</div>
            </a>
            <a href="{{ route('dhiran.reports.index', ['type' => 'overdue']) }}" class="dr-report-card">
                <div class="dr-report-icon bg-rose-100 text-rose-700">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="dr-report-title">Overdue Loans Report</div>
                <div class="dr-report-desc">Loans past maturity date with days overdue and penalty accrued.</div>
            </a>
            <a href="{{ route('dhiran.reports.index', ['type' => 'interest']) }}" class="dr-report-card">
                <div class="dr-report-icon bg-amber-100 text-amber-700">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                </div>
                <div class="dr-report-title">Interest Collection Report</div>
                <div class="dr-report-desc">Interest received within the selected date range, broken down by loan.</div>
            </a>
            <a href="{{ route('dhiran.reports.index', ['type' => 'forfeiture']) }}" class="dr-report-card">
                <div class="dr-report-icon bg-red-100 text-red-700">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="dr-report-title">Forfeiture Report</div>
                <div class="dr-report-desc">Loans approaching or past forfeiture threshold with collateral details.</div>
            </a>
            <a href="{{ route('dhiran.reports.index', ['type' => 'cashbook']) }}" class="dr-report-card">
                <div class="dr-report-icon bg-sky-100 text-sky-700">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                </div>
                <div class="dr-report-title">Cashbook Report</div>
                <div class="dr-report-desc">All loan disbursements and collections within the period.</div>
            </a>
            <a href="{{ route('dhiran.reports.index', ['type' => 'profitability']) }}" class="dr-report-card">
                <div class="dr-report-icon bg-violet-100 text-violet-700">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
                <div class="dr-report-title">Profitability Report</div>
                <div class="dr-report-desc">Interest income, processing fees, and net profit from gold loan operations.</div>
            </a>
        </div>

        {{-- Report Data Table (rendered when a report type is selected) --}}
        @if(isset($reportData) && count($reportData) > 0)
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
            <div class="p-4 border-b border-slate-200 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900">{{ $reportTitle ?? 'Report Results' }}</h2>
                @if(isset($reportSummary))
                <div class="flex gap-6">
                    @foreach($reportSummary as $label => $value)
                        <div class="text-right">
                            <div class="text-[10px] uppercase tracking-wide text-slate-500">{{ $label }}</div>
                            <div class="text-sm font-bold text-slate-900">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            @foreach($reportColumns ?? [] as $col)
                                <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($reportData as $row)
                            <tr class="hover:bg-slate-50/70">
                                @foreach($row as $cell)
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if(isset($reportTotals))
            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50">
                <div class="flex flex-wrap gap-6">
                    @foreach($reportTotals as $label => $value)
                        <div>
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}:</span>
                            <span class="text-sm font-bold text-slate-900 ml-1">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
        @elseif(request()->has('type'))
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-12 text-center">
            <svg class="w-12 h-12 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-lg font-semibold mb-1 text-slate-700">No data found</p>
            <p class="text-sm text-slate-500">Try adjusting the date range or report type</p>
        </div>
        @endif
    </div>
</x-app-layout>
