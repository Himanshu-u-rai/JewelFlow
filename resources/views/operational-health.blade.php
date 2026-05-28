<x-app-layout>
    <x-page-header
        title="Operational Health"
        subtitle="Read-only system health — for diagnostics only. Use artisan commands for full reconciliation."
    >
        <x-slot:actions>
            @if($lastReconciliationRun)
                <span class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-500 shadow-sm">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Last reconciled {{ \Carbon\Carbon::parse($lastReconciliationRun)->diffForHumans() }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-600 shadow-sm">
                    No reconciliation runs found
                </span>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-8">

        {{-- ══════════════════════════════════════════════════════════════════
             SECTION 1: STUCK ITEMS
        ══════════════════════════════════════════════════════════════════ --}}
        <section>
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Stuck Items</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Items that have not progressed in longer than expected. Zero across all four is a healthy state.
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">

                @php
                    /**
                     * Chip colour logic:
                     *   0        → green
                     *   1–5      → amber
                     *   > 5      → red
                     */
                    function stuckChipClasses(int $count): string {
                        if ($count === 0)      return 'border-emerald-200 bg-emerald-50 text-emerald-700';
                        if ($count <= 5)       return 'border-amber-200 bg-amber-50 text-amber-700';
                        return 'border-red-200 bg-red-50 text-red-700';
                    }
                    function stuckCountClasses(int $count): string {
                        if ($count === 0)      return 'text-emerald-600';
                        if ($count <= 5)       return 'text-amber-600';
                        return 'text-red-600';
                    }
                    function stuckLabelClasses(int $count): string {
                        if ($count === 0)      return 'text-emerald-500';
                        if ($count <= 5)       return 'text-amber-500';
                        return 'text-red-500';
                    }

                    $stuckItems = [
                        [
                            'count'   => (int) $stuckPendingRestock,
                            'label'   => 'Pending Restock',
                            'detail'  => 'In pending_restock > 14 days',
                        ],
                        [
                            'count'   => (int) $stuckWithKarigar,
                            'label'   => 'With Karigar (no open order)',
                            'detail'  => 'In with_karigar, no open job order > 30 days',
                        ],
                        [
                            'count'   => (int) $stuckPartialReturn,
                            'label'   => 'Partial Return',
                            'detail'  => 'Job orders in partial_return > 21 days',
                        ],
                        [
                            'count'   => (int) $stuckDraftReturns,
                            'label'   => 'Draft Returns',
                            'detail'  => 'Return orders in draft > 7 days',
                        ],
                    ];
                @endphp

                @foreach($stuckItems as $chip)
                    <div class="rounded-2xl border {{ stuckChipClasses($chip['count']) }} px-5 py-4"
                         title="{{ $chip['detail'] }}">
                        <div class="text-3xl font-bold {{ stuckCountClasses($chip['count']) }}">
                            {{ $chip['count'] }}
                        </div>
                        <div class="mt-1 text-xs font-semibold uppercase tracking-[0.14em] {{ stuckLabelClasses($chip['count']) }}">
                            {{ $chip['label'] }}
                        </div>
                        <div class="mt-1.5 text-[11px] text-slate-400 leading-snug">
                            {{ $chip['detail'] }}
                        </div>
                    </div>
                @endforeach

            </div>
        </section>

        {{-- ══════════════════════════════════════════════════════════════════
             SECTION 2: RECONCILIATION HISTORY
        ══════════════════════════════════════════════════════════════════ --}}
        <section>
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Reconciliation History</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Last 10 vault reconciliation runs for this shop.
                    Run <code class="rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-700">php artisan vault:reconcile</code> or
                    <code class="rounded bg-slate-100 px-1 py-0.5 text-xs text-slate-700">php artisan karigar:reconcile</code>
                    to generate a new entry.
                </p>
            </div>

            @if($reconciliationRuns->isEmpty())
                <div class="rounded-2xl border border-slate-200 bg-white px-6 py-8 text-center text-sm text-slate-400">
                    No reconciliation runs found for this shop.
                </div>
            @else
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                            <tr>
                                <th class="px-5 py-3 text-left">Run At</th>
                                <th class="px-5 py-3 text-left">Type</th>
                                <th class="px-5 py-3 text-left">Status</th>
                                <th class="px-5 py-3 text-left">Discrepancy Lots</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($reconciliationRuns as $run)
                                @php
                                    $isClean = $run->status === \App\Models\VaultReconciliationRun::STATUS_CLEAN;
                                    $isCorrected = $run->status === \App\Models\VaultReconciliationRun::STATUS_CORRECTED;
                                    $statusLabel = match($run->status) {
                                        \App\Models\VaultReconciliationRun::STATUS_CLEAN             => 'Clean',
                                        \App\Models\VaultReconciliationRun::STATUS_DISCREPANCY_FOUND => 'Discrepancy Found',
                                        \App\Models\VaultReconciliationRun::STATUS_CORRECTED         => 'Corrected',
                                        default => ucfirst(str_replace('_', ' ', $run->status)),
                                    };
                                    $statusClasses = $isClean || $isCorrected
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : 'bg-amber-100 text-amber-700';

                                    $notes = $run->notes ?? '';
                                    $typeLabel = match(true) {
                                        str_contains($notes, 'karigar') => 'Karigar Reconcile',
                                        str_contains($notes, 'vault')   => 'Vault Reconcile',
                                        default                          => $notes ?: '—',
                                    };

                                    $discrepancyLots = $run->discrepancy_lots;
                                    $lotCount = is_array($discrepancyLots) ? count($discrepancyLots) : 0;
                                @endphp
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3 text-slate-700 whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($run->run_at)->format('d M Y, H:i') }}
                                    </td>
                                    <td class="px-5 py-3 text-slate-500">
                                        {{ $typeLabel }}
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                            {{ $statusLabel }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-slate-500">
                                        @if($lotCount > 0)
                                            <span class="text-amber-600 font-medium">{{ $lotCount }} lot(s)</span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ══════════════════════════════════════════════════════════════════
             SECTION 3: RETURNS VALIDATION GUIDANCE
        ══════════════════════════════════════════════════════════════════ --}}
        <section>
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Returns Validation</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Accounting-level consistency checks across all return orders.
                </p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white px-6 py-5">
                <div class="flex items-start gap-4">
                    <div class="mt-0.5 flex-shrink-0 w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center">
                        <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">
                            Run
                            <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-mono text-slate-700">php artisan returns:validate</code>
                            on the server to check 12 accounting invariants.
                        </p>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed">
                            This command verifies refund totals, ledger balances, item status consistency,
                            and other accounting invariants across all return orders.
                            It runs as a read-only audit — nothing is modified.
                        </p>
                        <p class="mt-3 text-xs text-slate-400">
                            This command is not run from the browser to prevent accidental load on production during business hours.
                            Schedule it in an off-peak maintenance window, or run it from an SSH session / CI pipeline.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ══════════════════════════════════════════════════════════════════
             SECTION 4: RECENT AUDIT ACTIVITY
        ══════════════════════════════════════════════════════════════════ --}}
        <section>
            <div class="mb-4">
                <h2 class="text-base font-semibold text-slate-900">Recent Audit Activity</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Last 10 audit log entries for this shop. For full filtering, use
                    <a href="{{ route('settings.edit', ['tab' => 'audit']) }}"
                       class="text-slate-700 underline underline-offset-2 hover:text-slate-900">Settings &rsaquo; Audit</a>.
                </p>
            </div>

            @if($recentAudit->isEmpty())
                <div class="rounded-2xl border border-slate-200 bg-white px-6 py-8 text-center text-sm text-slate-400">
                    No audit log entries found.
                </div>
            @else
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                    <ul class="divide-y divide-slate-100">
                        @foreach($recentAudit as $entry)
                            <li class="flex items-center gap-4 px-5 py-3 hover:bg-slate-50 transition-colors">
                                <span class="flex-shrink-0 w-2 h-2 rounded-full bg-slate-300"></span>
                                <span class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-slate-700">{{ $entry->action }}</span>
                                    @if($entry->model_type)
                                        <span class="ml-1.5 text-xs text-slate-400">
                                            on {{ class_basename($entry->model_type) }}
                                        </span>
                                    @endif
                                </span>
                                <span class="flex-shrink-0 text-xs text-slate-400 whitespace-nowrap">
                                    {{ \Carbon\Carbon::parse($entry->created_at)->diffForHumans() }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </section>

    </div>
</x-app-layout>
