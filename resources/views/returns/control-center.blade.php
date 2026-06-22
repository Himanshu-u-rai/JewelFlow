<x-app-layout>
    <style>
        .returns-control-page {
            --rc-gold: #b45309;
            --rc-gold-hover: #92400e;
            --rc-line-soft: #e2e8f0;
            --rc-line: #cbd5e1;
            --rc-ink: #1f2430;
            --rc-text: #4a4334;
            --rc-muted: #64748b;
            --rc-soft: #faf6ee;
            --rc-focus: rgba(245, 158, 11, .2);
            width: 100%;
            max-width: none;
            background: #f6f7f9;
            color: var(--rc-ink);
        }

        .content-header.returns-control-header {
            border-bottom: 1px solid #cbd5e1;
            background: #ffffff;
            box-shadow: none;
        }

        .content-header.returns-control-header .page-title {
            color: #111827;
            font-size: 22px;
            font-weight: 620;
            letter-spacing: 0;
        }

        .content-header.returns-control-header .page-subtitle {
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }

        .rc-back-btn,
        .returns-control-page .rc-action,
        .returns-control-page .rc-action button,
        .returns-control-page .rc-action-primary,
        .returns-control-page .rc-action-secondary,
        .returns-control-page .rc-action-success,
        .returns-control-page .rc-action-danger,
        .returns-control-page .rc-action-muted {
            box-shadow: none !important;
        }

        .rc-back-btn {
            display: inline-flex;
            min-height: 40px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid var(--rc-line);
            border-radius: 10px;
            background: #ffffff;
            padding: 0 14px;
            color: var(--rc-text);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .rc-back-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
            color: var(--rc-ink);
        }

        .returns-control-page .rc-kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .returns-control-page .rc-kpi-card {
            border: 1px solid var(--rc-line-soft) !important;
            border-radius: 14px !important;
            background: #ffffff !important;
            padding: 14px 15px !important;
            box-shadow: none !important;
        }

        .returns-control-page .rc-kpi-card__inner {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .returns-control-page .rc-kpi-icon {
            display: inline-flex;
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            align-items: center;
            justify-content: center;
            border: 1px solid #f3dcb6;
            border-radius: 10px;
            background: #fdf6ec;
            color: var(--rc-gold);
        }

        .returns-control-page .rc-kpi-card.is-calm .rc-kpi-icon {
            border-color: var(--rc-line-soft);
            background: #f8fafc;
            color: #475569;
        }

        .returns-control-page .rc-kpi-value {
            color: var(--rc-ink);
            font-size: 22px;
            font-weight: 650;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }

        .returns-control-page .rc-kpi-label {
            margin-top: 4px;
            color: var(--rc-muted);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .returns-control-page .rc-section {
            border: 1px solid var(--rc-line-soft);
            border-radius: 14px;
            background: #ffffff;
            box-shadow: none;
            overflow: hidden;
        }

        .returns-control-page .rc-section + .rc-section {
            margin-top: 16px;
        }

        .returns-control-page .rc-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 1px solid var(--rc-line-soft);
            padding: 16px 18px;
            background: #ffffff;
        }

        .returns-control-page .rc-section-title {
            margin: 0;
            color: var(--rc-ink);
            font-size: 17px;
            font-weight: 650;
            letter-spacing: -0.1px;
            line-height: 1.2;
        }

        .returns-control-page .rc-section-copy {
            margin: 4px 0 0;
            color: var(--rc-muted);
            font-size: 13px;
            font-weight: 500;
            line-height: 1.4;
        }

        .returns-control-page .rc-section-body {
            display: grid;
            gap: 14px;
            padding: 14px;
        }

        .returns-control-page .rc-queue {
            border: 1px solid var(--rc-line-soft);
            border-radius: 12px;
            background: #ffffff;
            overflow: hidden;
        }

        .returns-control-page .rc-queue-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid var(--rc-line-soft);
            padding: 13px 14px;
            background: #fbfcfd;
        }

        .returns-control-page .rc-queue-title {
            display: block;
            min-width: 0;
            color: var(--rc-ink);
            font-size: 14px;
            font-weight: 650;
            word-spacing: 0.08em;
            line-height: 1.2;
        }

        .returns-control-page .rc-queue-copy {
            margin: 0;
            padding: 0 14px 12px;
            color: var(--rc-muted);
            font-size: 13px;
            line-height: 1.4;
        }

        .returns-control-page .rc-count {
            display: inline-flex;
            min-width: 24px;
            height: 22px;
            align-items: center;
            justify-content: center;
            border: 1px solid #f8d28b;
            border-radius: 999px;
            background: #fff7ed;
            padding: 0 7px;
            color: var(--rc-gold);
            font-size: 12px;
            font-weight: 650;
            line-height: 1;
        }

        .returns-control-page .rc-table-card {
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .returns-control-page .rc-table-card table {
            width: 100%;
            border-collapse: collapse;
        }

        .returns-control-page .rc-table-card thead {
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc !important;
        }

        .returns-control-page .rc-table-card thead th {
            padding: 12px 16px;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
        }

        .returns-control-page .rc-table-card tbody td {
            border-bottom: 1px solid #edf2f7;
            padding: 14px 16px;
            color: var(--rc-text);
            font-size: 14px;
            font-weight: 400;
            vertical-align: middle;
        }

        .returns-control-page .rc-table-card tbody tr:nth-child(even) {
            background: #f8fbff;
        }

        .returns-control-page .rc-table-card tbody tr:hover {
            background: #edf5ff;
        }

        .returns-control-page .rc-action-primary {
            display: inline-flex;
            min-height: 36px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--rc-gold) !important;
            border-radius: 9px !important;
            background: var(--rc-gold) !important;
            padding: 8px 11px !important;
            color: #ffffff !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            text-decoration: none;
        }

        .returns-control-page .rc-action-primary:hover {
            border-color: var(--rc-gold-hover) !important;
            background: var(--rc-gold-hover) !important;
            color: #ffffff !important;
        }

        .returns-control-page .rc-action-secondary,
        .returns-control-page .rc-action-muted {
            display: inline-flex;
            min-height: 36px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--rc-line) !important;
            border-radius: 9px !important;
            background: #ffffff !important;
            padding: 8px 11px !important;
            color: var(--rc-text) !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            text-decoration: none;
        }

        .returns-control-page .rc-action-success {
            border-color: #bbf7d0 !important;
            background: #ecfdf5 !important;
            color: #047857 !important;
        }

        .returns-control-page .rc-action-danger {
            border-color: #fecaca !important;
            background: #fff1f2 !important;
            color: #be123c !important;
        }

        .returns-control-page .rc-action-warn {
            border-color: #fed7aa !important;
            background: #fff7ed !important;
            color: #c2410c !important;
        }

        .returns-control-page .rc-queue .ui-state {
            border: 0;
            border-top: 1px solid var(--rc-line-soft);
            border-radius: 0;
            background: #fbfcfd;
            padding: 24px 16px;
            box-shadow: none;
        }

        .returns-control-page .rc-queue .ui-state-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
        }

        .returns-control-page .rc-queue .ui-state-title {
            margin-top: 10px;
            font-size: 15px;
            font-weight: 650;
        }

        .returns-control-page .rc-queue .ui-state-description {
            margin-top: 5px;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .content-header.returns-control-header {
                display: grid;
                grid-template-columns: 40px minmax(0, 1fr) 40px;
                align-items: center;
                gap: 8px;
                min-height: 64px;
                padding: 12px 14px !important;
                overflow: hidden;
            }

            .content-header.returns-control-header .content-header-nav {
                grid-column: 1;
                grid-row: 1;
                margin-right: 0;
                padding-top: 0;
            }

            .content-header.returns-control-header > :nth-child(2) {
                grid-column: 2;
                grid-row: 1;
                min-width: 0;
                text-align: center;
            }

            .content-header.returns-control-header .page-title {
                margin: 0;
                font-size: 17px;
                white-space: nowrap;
            }

            .content-header.returns-control-header .page-subtitle {
                display: none;
            }

            .content-header.returns-control-header .page-actions {
                grid-column: 3;
                grid-row: 1;
                justify-self: end;
                width: 40px !important;
                height: 40px !important;
                overflow: hidden;
            }

            .content-header.returns-control-header .page-actions > .rc-back-btn {
                width: 36px !important;
                min-width: 36px !important;
                height: 36px !important;
                min-height: 36px !important;
                padding: 0 !important;
                font-size: 0 !important;
                line-height: 0 !important;
            }

            .content-header.returns-control-header .page-actions > .rc-back-btn span {
                display: none !important;
            }

            .returns-control-page {
                padding: 14px !important;
            }

            .returns-control-page .rc-kpi-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 12px;
            }

            .returns-control-page .rc-kpi-card {
                min-height: 72px;
                padding: 9px 8px !important;
            }

            .returns-control-page .rc-kpi-card__inner {
                align-items: flex-start;
                gap: 7px;
            }

            .returns-control-page .rc-kpi-icon {
                width: 30px;
                height: 30px;
                flex-basis: 30px;
                border-radius: 9px;
            }

            .returns-control-page .rc-kpi-value {
                font-size: 18px;
                line-height: 1;
            }

            .returns-control-page .rc-kpi-label {
                display: -webkit-box;
                margin-top: 3px;
                font-size: 10.5px;
                line-height: 1.05;
                overflow: hidden;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 2;
            }

            .returns-control-page .rc-section-head {
                padding: 13px 12px;
            }

            .returns-control-page .rc-section-title {
                font-size: 16px;
            }

            .returns-control-page .rc-section-body {
                gap: 12px;
                padding: 10px;
            }

            .returns-control-page .rc-queue-head {
                padding: 12px;
            }

            .returns-control-page .rc-queue-copy {
                padding: 0 12px 10px;
                font-size: 12px;
            }

            .returns-control-page .rc-table-card table,
            .returns-control-page .rc-table-card thead,
            .returns-control-page .rc-table-card tbody,
            .returns-control-page .rc-table-card tr,
            .returns-control-page .rc-table-card td {
                display: block;
                width: 100%;
            }

            .returns-control-page .rc-table-card thead {
                display: none;
            }

            .returns-control-page .rc-table-card tbody {
                display: grid;
                gap: 10px;
                padding: 10px;
                background: #fbfcfd;
            }

            .returns-control-page .rc-table-card tbody tr {
                border: 1px solid var(--rc-line-soft);
                border-radius: 12px;
                background: #ffffff !important;
                overflow: hidden;
            }

            .returns-control-page .rc-table-card tbody td {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 12px;
                border-bottom: 1px solid #edf2f7;
                padding: 10px 12px !important;
                text-align: right !important;
                font-size: 13px;
            }

            .returns-control-page .rc-table-card tbody td::before {
                content: attr(data-label);
                flex: 0 0 38%;
                color: var(--rc-muted);
                font-size: 11px;
                font-weight: 500;
                line-height: 1.2;
                text-align: left;
            }

            .returns-control-page .rc-table-card tbody td:last-child {
                border-bottom: 0;
            }

            .returns-control-page .rc-table-card tbody td[data-label="Actions"],
            .returns-control-page .rc-table-card tbody td[data-label="Action"] {
                display: block;
                text-align: left !important;
            }

            .returns-control-page .rc-table-card tbody td[data-label="Actions"]::before,
            .returns-control-page .rc-table-card tbody td[data-label="Action"]::before {
                display: block;
                margin-bottom: 8px;
            }

            .returns-control-page .rc-table-card tbody td[data-label="Actions"] form,
            .returns-control-page .rc-table-card tbody td[data-label="Action"] form {
                width: 100%;
            }

            .returns-control-page .rc-table-card tbody td[data-label="Actions"] :is(a, button),
            .returns-control-page .rc-table-card tbody td[data-label="Action"] :is(a, button, span) {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <x-page-header
        class="returns-control-header"
        title="Operations"
        subtitle="What needs your attention right now">
        <x-slot:actions>
            <a href="{{ route('returns.index') }}"
               class="rc-back-btn">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                <span>All Returns</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner customers-index-page returns-control-page jf-skeleton-host is-loading">

        {{-- KPI strip --}}
        <div class="rc-kpi-grid">
            @php
                $needsDecisionCount = $pendingApprovals->count() + $returnedAwaitingDecision->count();
                $incompleteStepsCount = $goldPendingRecovery->count() + $withKarigar->count() + $orphanedWithKarigar->count();

                $overdueDecision = $returnedAwaitingDecision->filter(
                    fn($item) => \Carbon\Carbon::parse($item->updated_at)->diffInDays(now()) > 14
                )->count();
                $overdueGold = $goldPendingRecovery->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 7
                )->count();
                $overdueKarigar = $withKarigar->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 7
                )->count();
                $overdueCount = $overdueDecision + $overdueGold + $overdueKarigar;

                // "Incomplete Steps" is only urgent if items have been waiting more than 3 days
                $incompleteOldCount = $goldPendingRecovery->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 3
                )->count() + $withKarigar->filter(
                    fn($disp) => \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now()) > 3
                )->count();

                // Age label for "Incomplete Steps": oldest item waiting
                $incompleteOldestDays = 0;
                foreach ($goldPendingRecovery as $_disp) {
                    $d = (int) \Carbon\Carbon::parse($_disp->dispositioned_at)->diffInDays(now());
                    if ($d > $incompleteOldestDays) $incompleteOldestDays = $d;
                }
                foreach ($withKarigar as $_disp) {
                    $d = (int) \Carbon\Carbon::parse($_disp->dispositioned_at)->diffInDays(now());
                    if ($d > $incompleteOldestDays) $incompleteOldestDays = $d;
                }

                $incompleteLabel = 'Incomplete Steps';
                if ($incompleteStepsCount > 0 && $incompleteOldestDays > 0) {
                    $incompleteLabel = 'Incomplete Steps · oldest ' . $incompleteOldestDays . 'd';
                }

                $chips = [
                    ['label' => 'Needs Decision',   'count' => $needsDecisionCount,   'urgent' => $needsDecisionCount > 0],
                    ['label' => $incompleteLabel,    'count' => $incompleteStepsCount, 'urgent' => $incompleteOldCount > 0],
                    ['label' => 'Overdue Items',     'count' => $overdueCount,         'urgent' => $overdueCount > 0],
                ];
            @endphp
            @foreach($chips as $chip)
                @php $isUrgent = $chip['urgent'] && $chip['count'] > 0; @endphp
                <div class="rc-kpi-card {{ $isUrgent ? 'is-urgent' : 'is-calm' }}">
                    <div class="rc-kpi-card__inner">
                        <div class="rc-kpi-icon">
                            @if($loop->first)
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 11l3 3L22 4M2 12l5 5m0 0l5-5m-5 5V3"/>
                                </svg>
                            @elseif($loop->iteration === 2)
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            @else
                                <svg width="17" height="17" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4m0 4h.01"/>
                                </svg>
                            @endif
                        </div>
                        <div>
                            <div class="rc-kpi-value {{ $isUrgent ? 'text-amber-700' : '' }}">{{ $chip['count'] }}</div>
                            <div class="rc-kpi-label">{{ $chip['label'] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Group 1: Blocked — needs your decision --}}
        <section id="blocked" class="rc-section">
            <div class="rc-section-head">
                <div>
                    <h2 class="rc-section-title">Blocked — needs your decision</h2>
                    <p class="rc-section-copy">The refund or next step is on hold until you act. These are your bottleneck.</p>
                </div>
            </div>
            <div class="rc-section-body">

            {{-- Approval queue --}}
            <div class="rc-queue">
                <div class="rc-queue-head">
                    <h3 class="rc-queue-title">Needs My Approval</h3>
                    @if($pendingApprovals->count() > 0)
                        <span class="rc-count">
                            {{ $pendingApprovals->count() }}
                        </span>
                    @endif
                </div>

                @if($pendingApprovals->isEmpty())
                    <x-empty-state
                        title="Nothing waiting"
                        description="Nothing waiting — all returns are up to date."
                        :compact="true"
                    />
                @else
                    <div class="rc-table-card rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Invoice</th>
                                    <th class="px-5 py-3 text-left">Customer</th>
                                    <th class="px-5 py-3 text-right">Est. Refund</th>
                                    <th class="px-5 py-3 text-left">Submitted by</th>
                                    <th class="px-5 py-3 text-left">Waiting since</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($pendingApprovals as $returnOrder)
                                    @php
                                        $customer = $returnOrder->customer;
                                        $customerName = $customer
                                            ? trim($customer->first_name . ' ' . $customer->last_name)
                                            : null;
                                    @endphp
                                    <tr class="align-top">
                                        <td data-label="Invoice" class="px-5 py-4">
                                            @if($returnOrder->invoice)
                                                <a href="{{ route('returns.show', $returnOrder) }}"
                                                   class="text-sm font-semibold text-amber-700 hover:underline">
                                                    {{ $returnOrder->invoice->invoice_number }}
                                                </a>
                                            @else
                                                <span class="text-sm text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Customer" class="px-5 py-4 text-sm text-slate-700">
                                            {{ $customerName ?? '—' }}
                                        </td>
                                        <td data-label="Est. Refund" class="px-5 py-4 text-right text-sm text-slate-700">
                                            @if($returnOrder->creditNote)
                                                ₹{{ number_format((float) $returnOrder->creditNote->total, 2) }}
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Submitted by" class="px-5 py-4 text-sm text-slate-600">
                                            {{ $returnOrder->createdBy?->name ?? '—' }}
                                        </td>
                                        <td data-label="Waiting since" class="px-5 py-4 text-sm text-slate-500">
                                            {{ \Carbon\Carbon::parse($returnOrder->created_at)->diffForHumans() }}
                                        </td>
                                        <td data-label="Action" class="px-5 py-4 text-right">
                                            <a href="{{ route('returns.approve-review', $returnOrder) }}"
                                               class="rc-action-primary inline-flex items-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition">
                                                Review &amp; Decide
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Returned items awaiting decision --}}
            <div class="rc-queue">
                <div class="rc-queue-head">
                    <h3 class="rc-queue-title">Returned Items — Decide What To Do</h3>
                    @if($returnedAwaitingDecision->count() > 0)
                        <span class="rc-count">
                            {{ $returnedAwaitingDecision->count() }}
                        </span>
                    @endif
                </div>
                <p class="rc-queue-copy">These pieces were set aside for inspection before restocking. Confirm they're ready, or redirect to melt/rework/write-off.</p>

                @if($returnedAwaitingDecision->isEmpty())
                    <x-empty-state
                        title="No items waiting"
                        description="No returned items waiting for a decision."
                        :compact="true"
                    />
                @else
                    @php
                        $goodConditionItems = $returnedAwaitingDecision->filter(function ($item) {
                            $condition = $item->latestReturnDisposition?->returnLineItem?->condition;
                            return $condition === 'good_condition';
                        })->values();
                    @endphp

                    @if($goodConditionItems->count() >= 2)
                        <div
                            x-data="{ showPreview: false }"
                            class="mb-4"
                        >
                            <button
                                type="button"
                                x-show="!showPreview"
                                @click="showPreview = true"
                                class="rc-action-secondary rc-action-success inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 transition"
                            >
                                Send all good-condition items back to stock
                                <span class="ml-1 inline-flex items-center justify-center min-w-[20px] h-[18px] px-1.5 rounded-full bg-emerald-600 text-white text-[10px] font-bold leading-none">
                                    {{ $goodConditionItems->count() }}
                                </span>
                            </button>

                            <div x-show="showPreview" x-transition class="rc-table-card rounded-2xl border border-emerald-200 bg-emerald-50 overflow-hidden">
                                <div class="px-5 py-4 border-b border-emerald-200 flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-emerald-900">
                                            These {{ $goodConditionItems->count() }} items will go back in stock:
                                        </p>
                                        <p class="text-xs text-emerald-700 mt-0.5">Check the list below, then confirm.</p>
                                    </div>
                                    <a href="#" @click.prevent="showPreview = false" class="text-xs text-slate-500 hover:text-slate-700 underline">Cancel</a>
                                </div>

                                <table class="w-full">
                                    <thead class="bg-emerald-100 text-xs font-semibold uppercase tracking-[0.15em] text-emerald-700">
                                        <tr>
                                            <th class="px-5 py-2.5 text-left">Item</th>
                                            <th class="px-5 py-2.5 text-left">Condition</th>
                                            <th class="px-5 py-2.5 text-left">Waiting since</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-emerald-100">
                                        @foreach($goodConditionItems as $batchItem)
                                            @php
                                                $batchDisp = $batchItem->latestReturnDisposition;
                                                $batchReturnedAt = $batchDisp?->dispositioned_at ?? $batchItem->updated_at;
                                                $batchDays = (int) \Carbon\Carbon::parse($batchReturnedAt)->diffInDays(now());
                                            @endphp
                                            <tr>
                                                <td data-label="Item" class="px-5 py-3">
                                                    <div class="text-sm font-semibold text-slate-900">{{ $batchItem->barcode ?? '—' }}</div>
                                                    <div class="text-xs text-slate-500">{{ $batchItem->design ?? '—' }}</div>
                                                </td>
                                                <td data-label="Condition" class="px-5 py-3 text-sm text-emerald-800">Good condition</td>
                                                <td data-label="Waiting since" class="px-5 py-3 text-sm text-slate-600">
                                                    @if($batchDays === 0)
                                                        Today
                                                    @elseif($batchDays === 1)
                                                        1 day
                                                    @else
                                                        {{ $batchDays }} days
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <div class="px-5 py-4 border-t border-emerald-200 flex items-center justify-between bg-emerald-50">
                                    <a href="#" @click.prevent="showPreview = false" class="text-sm text-slate-500 hover:text-slate-700 underline">Cancel</a>
                                    <form method="POST" action="{{ route('returns.batch-restock') }}">
                                        @csrf
                                        @foreach($goodConditionItems as $batchItem)
                                            <input type="hidden" name="item_ids[]" value="{{ $batchItem->id }}">
                                        @endforeach
                                        <button type="submit"
                                                class="rc-action-secondary rc-action-success inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 transition">
                                            Confirm send back to stock
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="rc-table-card rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Item</th>
                                    <th class="px-5 py-3 text-left">Condition</th>
                                    <th class="px-5 py-3 text-left">Returned</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($returnedAwaitingDecision as $item)
                                    @php
                                        $disp = $item->latestReturnDisposition;
                                        $returnedAt = $disp?->dispositioned_at ?? $item->updated_at;
                                        $condition = $disp?->returnLineItem?->condition ?? null;
                                        $suggestedDisp = match($condition) {
                                            'good_condition' => 'restocked',
                                            'minor_wear'     => 'restocked',
                                            'damaged'        => 'sent_to_melt',
                                            'non_sellable'   => 'written_off',
                                            default          => null,
                                        };
                                        $conditionLabel = match($condition) {
                                            'good_condition' => 'Good condition',
                                            'minor_wear'     => 'Minor wear',
                                            'damaged'        => 'Damaged',
                                            'non_sellable'   => 'Non-sellable',
                                            default          => $condition ? ucfirst(str_replace('_', ' ', $condition)) : null,
                                        };
                                        $suggestionReason = match($condition) {
                                            'good_condition' => 'Good condition — safe to put back on display.',
                                            'minor_wear'     => 'Minor wear — usually still sellable; inspect before restocking.',
                                            'damaged'        => 'Damaged — not suitable for resale; recovering the gold is typical.',
                                            'non_sellable'   => 'Non-sellable — no resale value; write off or melt.',
                                            default          => null,
                                        };
                                    @endphp
                                    <tr class="align-top">
                                        <td data-label="Item" class="px-5 py-4">
                                            <div class="text-sm font-semibold text-slate-900">{{ $item->barcode ?? '—' }}</div>
                                            <div class="text-xs text-slate-500">{{ $item->design ?? '—' }}</div>
                                        </td>
                                        <td data-label="Condition" class="px-5 py-4 text-sm text-slate-600">
                                            {{ $conditionLabel ?? '—' }}
                                        </td>
                                        <td data-label="Returned" class="px-5 py-4 text-sm text-slate-600">
                                            {{ \Carbon\Carbon::parse($returnedAt)->diffForHumans() }}
                                        </td>
                                        <td data-label="Actions" class="px-5 py-4 text-right">
                                            <form method="POST"
                                                  action="{{ route('returns.items.redispose', $item) }}"
                                                  class="inline-flex items-center gap-2 justify-end flex-wrap">
                                                @csrf
                                                <button type="submit" name="disposition" value="restocked"
                                                        class="rc-action-secondary rc-action-success inline-flex items-center rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 transition {{ $suggestedDisp === 'restocked' ? 'ring-2 ring-emerald-400 ring-offset-1' : '' }}">
                                                    Back to Stock{{ $suggestedDisp === 'restocked' ? ' ✓' : '' }}
                                                </button>
                                                <button type="submit" name="disposition" value="sent_to_melt"
                                                        class="rc-action-secondary rc-action-warn inline-flex items-center rounded-lg border border-orange-300 bg-orange-50 px-3 py-1.5 text-xs font-semibold text-orange-800 hover:bg-orange-100 transition {{ $suggestedDisp === 'sent_to_melt' ? 'ring-2 ring-orange-400 ring-offset-1' : '' }}">
                                                    Send to Melt{{ $suggestedDisp === 'sent_to_melt' ? ' ✓' : '' }}
                                                </button>
                                                {{-- "Send to Karigar" retired (M11): the rework job-work backend was
                                                     never built, so this only trapped items in an unclearable queue.
                                                     To rework a returned piece: Send to Melt → record recovery into the
                                                     vault → create a karigar job from that lot. --}}
                                                <button type="submit" name="disposition" value="written_off"
                                                        class="rc-action-secondary inline-flex items-center rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition {{ $suggestedDisp === 'written_off' ? 'ring-2 ring-slate-400 ring-offset-1' : '' }}">
                                                    Write Off{{ $suggestedDisp === 'written_off' ? ' ✓' : '' }}
                                                </button>
                                            </form>
                                            @if($suggestionReason)
                                                <p class="mt-1.5 text-xs text-slate-400 text-right">{{ $suggestionReason }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            </div>
        </section>

        {{-- Group 2: Started but not finished --}}
        <section id="incomplete" class="rc-section">
            <div class="rc-section-head">
                <div>
                    <h2 class="rc-section-title">Started But Not Finished</h2>
                    <p class="rc-section-copy">A step was started but not finished. These need to be done before everything is properly closed.</p>
                </div>
            </div>
            <div class="rc-section-body">

            {{-- Gold melt unrecorded --}}
            <div class="rc-queue">
                <div class="rc-queue-head">
                    <h3 class="rc-queue-title">Gold melt unrecorded</h3>
                    @if($goldPendingRecovery->count() > 0)
                        <span class="rc-count">
                            {{ $goldPendingRecovery->count() }}
                        </span>
                    @endif
                </div>
                <p class="rc-queue-copy">These pieces have been set aside for melting. Record the gold recovery to add the weight back to your stock.</p>

                @if($goldPendingRecovery->isNotEmpty())
                    @if($goldRatePerGram > 0)
                        <div class="mb-4 inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800">
                            Estimated recoverable value: ₹{{ number_format($goldPendingValue, 0) }}
                            <span class="text-xs font-normal text-amber-700">(based on today's rate ₹{{ number_format($goldRatePerGram, 0) }}/g)</span>
                        </div>
                    @else
                        <div class="mb-4 inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-500">
                            Set today's gold rate to see estimated value.
                        </div>
                    @endif
                @endif

                @if($goldPendingRecovery->isEmpty())
                    <x-empty-state
                        title="No gold waiting"
                        description="No gold waiting for recovery — all melts have been recorded."
                        :compact="true"
                    />
                @else
                    <div class="rc-table-card rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Item</th>
                                    <th class="px-5 py-3 text-right">Est. Fine Weight</th>
                                    <th class="px-5 py-3 text-right">Est. Value</th>
                                    <th class="px-5 py-3 text-left">Sitting since</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($goldPendingRecovery as $disp)
                                    @php
                                        $ro = $disp->returnLineItem?->returnOrder;
                                        $item = $disp->item;
                                        $fineWeight = null;
                                        $estValue = null;
                                        if ($item && $item->net_metal_weight && $item->purity) {
                                            $fineWeight = (float) $item->net_metal_weight * ((float) $item->purity / 24);
                                            $estValue = $goldRatePerGram > 0 ? round($fineWeight * $goldRatePerGram, 2) : null;
                                        }
                                    @endphp
                                    <tr class="align-top">
                                        <td data-label="Item" class="px-5 py-4">
                                            <div class="text-sm font-semibold text-slate-900">{{ $item?->barcode ?? '—' }}</div>
                                            <div class="text-xs text-slate-500">{{ $item?->design ?? '—' }}</div>
                                            @if($ro)
                                                <a href="{{ route('returns.show', $ro->id) }}"
                                                   class="text-xs font-semibold text-amber-700 hover:underline">
                                                    RO#{{ $ro->id }}
                                                </a>
                                            @endif
                                        </td>
                                        <td data-label="Est. Fine Weight" class="px-5 py-4 text-right text-sm text-slate-700">
                                            @if($fineWeight !== null)
                                                {{ number_format($fineWeight, 2) }} g
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Est. Value" class="px-5 py-4 text-right text-sm text-slate-700">
                                            @if($estValue !== null)
                                                ₹{{ number_format($estValue, 0) }}
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Sitting since" class="px-5 py-4 text-sm text-slate-500">
                                            {{ \Carbon\Carbon::parse($disp->dispositioned_at)->diffForHumans() }}
                                        </td>
                                        <td data-label="Action" class="px-5 py-4 text-right">
                                            <a href="{{ route('returns.items.recover', $disp) }}"
                                               class="rc-action-primary inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                                                Record Recovery
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Items orphaned by cancelled job orders --}}
            @if($orphanedWithKarigar->isNotEmpty())
            <div class="rc-queue">
                <div class="rc-queue-head">
                    <h3 class="rc-queue-title text-rose-700">Items stuck after cancelled job</h3>
                    <span class="rc-count">
                        {{ $orphanedWithKarigar->count() }}
                    </span>
                </div>
                <p class="rc-queue-copy">These items are still marked "with karigar" but the job order was cancelled. Their status needs to be corrected manually.</p>
                <div class="rc-table-card rounded-2xl border border-rose-200 bg-white overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-rose-50 border-b border-rose-100 text-xs font-semibold uppercase tracking-[0.18em] text-rose-600">
                            <tr>
                                <th class="px-5 py-3 text-left">Item</th>
                                <th class="px-5 py-3 text-left">Cancelled Job</th>
                                <th class="px-5 py-3 text-left">Karigar</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($orphanedWithKarigar as $job)
                                <tr class="align-top">
                                    <td data-label="Item" class="px-5 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-semibold text-slate-900">{{ $job->sourceItem?->barcode ?? '—' }}</span>
                                            @if($job->job_type === \App\Models\JobOrder::JOB_TYPE_REPAIR)
                                                <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-700">Repair</span>
                                            @endif
                                        </div>
                                        <div class="text-xs text-slate-500">{{ $job->sourceItem?->design ?? '—' }}</div>
                                    </td>
                                    <td data-label="Cancelled Job" class="px-5 py-4">
                                        <a href="{{ route('job-orders.show', $job->id) }}"
                                           class="text-sm font-semibold text-rose-700 hover:underline">
                                            {{ $job->job_order_number ?? 'JO#'.$job->id }}
                                        </a>
                                        <div class="text-xs text-slate-500 capitalize">{{ $job->job_type }} · cancelled</div>
                                    </td>
                                    <td data-label="Karigar" class="px-5 py-4 text-sm text-slate-600">
                                        {{ $job->karigar?->name ?? '—' }}
                                    </td>
                                    <td data-label="Actions" class="px-5 py-4 text-right">
                                        @php $fixLabel = $job->job_type === \App\Models\JobOrder::JOB_TYPE_REPAIR ? 'in stock' : 'returned'; @endphp
                                        <div class="flex items-center justify-end gap-2">
                                            <form method="POST"
                                                  action="{{ route('returns.items.fix-orphan-status', $job->source_item_id) }}"
                                                  onsubmit="return confirm('Mark {{ $job->sourceItem?->barcode ?? $job->source_item_id }} as {{ $fixLabel }}?')">
                                                @csrf
                                                <button type="submit"
                                                        class="rc-action-secondary rc-action-success inline-flex items-center rounded-lg border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700 hover:bg-green-100 transition">
                                                    Fix Status
                                                </button>
                                            </form>
                                            <a href="{{ route('inventory.items.show', $job->source_item_id) }}"
                                               class="rc-action-secondary rc-action-danger inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 transition">
                                                View
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Legacy rework items (sent_to_rework retired in M11) --}}
            <div class="rc-queue">
                <div class="rc-queue-head">
                    <h3 class="rc-queue-title">Pieces marked for rework (legacy)</h3>
                    @if($withKarigar->count() > 0)
                        <span class="rc-count">
                            {{ $withKarigar->count() }}
                        </span>
                    @endif
                </div>
                <p class="rc-queue-copy">These pieces were marked for rework under the old flow. To rework a returned piece now: choose <span class="font-semibold">Send to Melt</span> above, record the recovery into the vault, then create a karigar job from that gold lot.</p>

                @if($withKarigar->isEmpty())
                    <x-empty-state
                        title="None out"
                        description="No pieces currently out with karigar."
                        :compact="true"
                    />
                @else
                    <div class="rc-table-card rounded-2xl border border-slate-200 bg-white overflow-hidden">
                        <table class="w-full">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 text-left">Item</th>
                                    <th class="px-5 py-3 text-left">From Return</th>
                                    <th class="px-5 py-3 text-left">Sent out</th>
                                    <th class="px-5 py-3 text-center">Days out</th>
                                    <th class="px-5 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($withKarigar as $disp)
                                    @php
                                        $ro = $disp->returnLineItem?->returnOrder;
                                        $daysOut = (int) \Carbon\Carbon::parse($disp->dispositioned_at)->diffInDays(now());
                                        $daysColor = $daysOut > 14
                                            ? 'text-rose-700 font-bold'
                                            : ($daysOut > 7 ? 'text-amber-700 font-semibold' : 'text-slate-600');
                                    @endphp
                                    <tr class="align-top">
                                        <td data-label="Item" class="px-5 py-4">
                                            <div class="text-sm font-semibold text-slate-900">
                                                {{ $disp->item?->barcode ?? '—' }}
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                {{ $disp->item?->design ?? '—' }}
                                            </div>
                                        </td>
                                        <td data-label="From Return" class="px-5 py-4">
                                            @if($ro)
                                                <a href="{{ route('returns.show', $ro->id) }}"
                                                   class="text-sm font-semibold text-amber-700 hover:underline">
                                                    RO#{{ $ro->id }}
                                                </a>
                                            @else
                                                <span class="text-sm text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td data-label="Sent out" class="px-5 py-4 text-sm text-slate-600">
                                            {{ \Carbon\Carbon::parse($disp->dispositioned_at)->diffForHumans() }}
                                        </td>
                                        <td data-label="Days out" class="px-5 py-4 text-center text-sm {{ $daysColor }}">
                                            {{ $daysOut }}d
                                        </td>
                                        <td data-label="Action" class="px-5 py-4 text-right">
                                            <span class="rc-action-muted inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-400 cursor-not-allowed"
                                                  title="In-app rework job creation is not available yet — track the karigar rework manually.">
                                                Rework (manual)
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            </div>
        </section>

        {{-- Group 3: Time-sensitive --}}
        <section id="time-sensitive" class="rc-section">
            <div class="rc-section-head">
                <div>
                    <h2 class="rc-section-title">Time-sensitive</h2>
                    <p class="rc-section-copy">Items that have been waiting a long time. These will become problems if not resolved soon.</p>
                </div>
            </div>
            <div class="rc-section-body">

            @php
                // $overdueDecision, $overdueGold, $overdueKarigar already computed above
            @endphp

            @if($overdueCount === 0)
                <x-empty-state
                    title="All good"
                    description="Nothing has been waiting too long."
                    :compact="true"
                />
            @else
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 shadow-none">
                    <ul class="space-y-2">
                        @if($overdueDecision > 0)
                            <li class="text-sm text-amber-800">
                                <span class="font-semibold">{{ $overdueDecision }} {{ Str::plural('item', $overdueDecision) }}</span>
                                awaiting a decision for more than 14 days —
                                <a href="#blocked" class="font-semibold underline hover:text-amber-900">Go to Blocked</a>
                            </li>
                        @endif
                        @if($overdueGold > 0)
                            <li class="text-sm text-amber-800">
                                <span class="font-semibold">{{ $overdueGold }} {{ Str::plural('melt', $overdueGold) }}</span>
                                unrecorded for more than 7 days —
                                <a href="#incomplete" class="font-semibold underline hover:text-amber-900">Go to Incomplete</a>
                            </li>
                        @endif
                        @if($overdueKarigar > 0)
                            <li class="text-sm text-amber-800">
                                <span class="font-semibold">{{ $overdueKarigar }} rework {{ Str::plural('job', $overdueKarigar) }}</span>
                                not created for more than 7 days —
                                <a href="#incomplete" class="font-semibold underline hover:text-amber-900">Go to Incomplete</a>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
            </div>
        </section>

    </div>
</x-app-layout>
