@php
    $visibleReturns = $returnOrders->getCollection();
    $visibleRefundTotal = $visibleReturns->sum(fn ($order) => (float) ($order->creditNote?->total ?? 0));
    $visibleSettled = $visibleReturns->where('status', \App\Models\ReturnOrder::STATUS_SETTLED)->count();
    $visiblePending = $visibleReturns->where('status', \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)->count();
    $statusLabel = fn ($status) => match ($status) {
        \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'Pending Approval',
        \App\Models\ReturnOrder::STATUS_SETTLED => 'Settled',
        \App\Models\ReturnOrder::STATUS_DRAFT => 'Draft',
        \App\Models\ReturnOrder::STATUS_SUBMITTED => 'Submitted',
        \App\Models\ReturnOrder::STATUS_CANCELLED => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', (string) $status)),
    };
    $statusTone = fn ($status) => match ($status) {
        \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL => 'warning',
        \App\Models\ReturnOrder::STATUS_SETTLED => 'success',
        \App\Models\ReturnOrder::STATUS_CANCELLED => 'danger',
        default => 'neutral',
    };
@endphp

<x-app-layout>
<style>
    /* ── Returns Inbox ─────────────────────────────────────────────
       All .ri-* classes are scoped here. Consistent with the teal
       design system used across vault, karigar, and subscription pages.
       ─────────────────────────────────────────────────────────── */
    .ri-page {
        --ri-border:        #e7ebf1;
        --ri-border-soft:   #eef1f6;
        --ri-border-strong: #d9dfe8;
        --ri-ink:           #0f172a;
        --ri-ink-2:         #3d4861;
        --ri-muted:         #6a7588;
        --ri-accent:        #0d9488;
        --ri-accent-deep:   #0f766e;
        --ri-accent-soft:   rgba(13,148,136,.08);
        --ri-shadow:        0 1px 2px rgba(16,24,40,.04), 0 12px 28px -16px rgba(16,24,40,.16);
        --ri-ease:          cubic-bezier(0.23,1,0.32,1);
        max-width: 1360px;
    }

    @media (prefers-reduced-motion: no-preference) {
        .ri-page .ri-card { animation: riRise .5s var(--ri-ease) both; }
        .ri-page .ri-card:nth-child(2) { animation-delay: .04s; }
        .ri-page .ri-card:nth-child(3) { animation-delay: .08s; }
        @keyframes riRise {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    }

    .ri-card {
        border: 1px solid var(--ri-border);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: var(--ri-shadow);
        overflow: hidden;
    }

    .ri-flow {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    /* ── Back button ── */
    .ri-back-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 40px;
        padding: 0 15px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 12px;
        background: #ffffff;
        color: var(--ri-ink-2);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .16s var(--ri-ease);
    }
    .ri-back-btn:hover { background: #f7f9fc; }
    .ri-back-btn:active { transform: scale(.98); }

    /* ── KPI strip — one bordered panel, hairline-divided columns ── */
    .ri-kpi-strip {
        display: grid;
        grid-template-columns: repeat(5, minmax(0,1fr));
        border: 1px solid var(--ri-border);
        border-radius: 16px;
        background: #ffffff;
        box-shadow: var(--ri-shadow);
        overflow: hidden;
    }

    .ri-kpi {
        padding: 16px 18px;
        border-right: 1px solid var(--ri-border);
    }
    .ri-kpi:last-child { border-right: 0; }

    .ri-kpi-label {
        color: var(--ri-muted);
        font-size: 12px;
        font-weight: 500;
        margin: 0 0 6px;
    }

    .ri-kpi-value {
        color: var(--ri-ink);
        font-size: 20px;
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -.01em;
        font-variant-numeric: tabular-nums;
        margin: 0;
    }

    .ri-kpi-value--accent { color: var(--ri-accent-deep); }
    .ri-kpi-value--warn   { color: #92400e; }

    /* ── Filter bar ── */
    .ri-filter {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--ri-border-soft);
        background: #fafbfd;
    }

    .ri-filter-field { display: flex; flex-direction: column; gap: 6px; }

    .ri-filter-label {
        color: var(--ri-muted);
        font-size: 11.5px;
        font-weight: 600;
    }

    .ri-filter-control {
        height: 38px;
        padding: 0 11px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 10px;
        background: #ffffff;
        color: var(--ri-ink);
        font-size: 13px;
        min-width: 140px;
        transition: border-color .15s var(--ri-ease), box-shadow .15s var(--ri-ease);
    }
    .ri-filter-control:focus {
        border-color: var(--ri-accent-deep);
        box-shadow: 0 0 0 3px rgba(15,118,110,.12);
        outline: none;
    }

    .ri-filter-apply {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 38px;
        padding: 0 16px;
        border: 1px solid var(--ri-accent-deep);
        border-radius: 10px;
        background: var(--ri-accent-deep);
        color: #ffffff;
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
        transition: background-color .15s var(--ri-ease), transform .15s var(--ri-ease);
    }
    .ri-filter-apply:hover { background: #115e56; }
    .ri-filter-apply:active { transform: scale(.98); }

    .ri-filter-clear {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 38px;
        padding: 0 14px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 10px;
        background: #ffffff;
        color: var(--ri-ink-2);
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .15s var(--ri-ease);
    }
    .ri-filter-clear:hover { background: #f7f9fc; }

    /* ── Table section header ── */
    .ri-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--ri-border-soft);
    }

    .ri-section-title {
        margin: 0;
        color: var(--ri-ink);
        font-size: 14px;
        font-weight: 650;
    }

    .ri-section-count {
        color: var(--ri-muted);
        font-size: 13px;
        margin: 0;
    }

    /* ── Table ── */
    .ri-table-wrap { overflow-x: auto; }

    .ri-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .ri-table thead th {
        padding: 11px 16px;
        text-align: left;
        font-size: 11.5px;
        font-weight: 600;
        color: var(--ri-muted);
        background: #fafbfd;
        border-bottom: 1px solid var(--ri-border);
        white-space: nowrap;
    }
    .ri-table thead th.text-right { text-align: right; }
    .ri-table thead th.text-center { text-align: center; }

    .ri-table tbody td {
        padding: 13px 16px;
        vertical-align: middle;
        border-bottom: 1px solid var(--ri-border-soft);
        color: var(--ri-ink-2);
    }
    .ri-table tbody tr:last-child td { border-bottom: 0; }
    .ri-table tbody tr:hover { background: #fafbfd; }
    .ri-table td.text-right { text-align: right; }
    .ri-table td.text-center { text-align: center; }

    /* Cell content helpers */
    .ri-cn-number {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        font-weight: 700;
        color: var(--ri-ink);
        display: block;
    }

    .ri-cell-sub {
        display: block;
        font-size: 11.5px;
        color: var(--ri-muted);
        margin-top: 2px;
    }

    .ri-invoice-link {
        font-weight: 650;
        color: var(--ri-accent-deep);
        text-decoration: none;
    }
    .ri-invoice-link:hover { text-decoration: underline; }

    .ri-customer-name { font-weight: 600; color: var(--ri-ink); }

    .ri-refund-amount {
        font-weight: 700;
        color: var(--ri-ink);
        font-variant-numeric: tabular-nums;
    }

    .ri-muted { color: var(--ri-muted); }

    /* Status pills */
    .ri-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 650;
        white-space: nowrap;
    }
    .ri-status::before {
        content: '';
        width: 5px;
        height: 5px;
        border-radius: 999px;
        background: currentColor;
        flex-shrink: 0;
    }
    .ri-status--success  { background: #ecfdf5; color: #065f46; }
    .ri-status--warning  { background: #fffbeb; color: #92400e; }
    .ri-status--danger   { background: #fef2f2; color: #991b1b; }
    .ri-status--neutral  { background: #f1f5f9; color: #475569; }

    /* Action buttons */
    .ri-btn-view {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border: 1px solid var(--ri-border-strong);
        border-radius: 8px;
        background: #ffffff;
        color: var(--ri-ink-2);
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        transition: background-color .14s var(--ri-ease);
    }
    .ri-btn-view:hover { background: #f7f9fc; }

    .ri-btn-approve {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border: 1px solid #16a34a;
        border-radius: 8px;
        background: #16a34a;
        color: #ffffff;
        font-size: 12px;
        font-weight: 650;
        cursor: pointer;
        transition: background-color .14s var(--ri-ease);
    }
    .ri-btn-approve:hover { background: #15803d; }

    .ri-btn-reject {
        display: inline-flex;
        align-items: center;
        height: 30px;
        padding: 0 12px;
        border: 1px solid #fecaca;
        border-radius: 8px;
        background: #fef2f2;
        color: #991b1b;
        font-size: 12px;
        font-weight: 650;
        text-decoration: none;
        transition: background-color .14s var(--ri-ease);
    }
    .ri-btn-reject:hover { background: #fee2e2; }

    /* ── Empty state ── */
    .ri-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
        padding: 60px 24px;
        text-align: center;
    }

    .ri-empty-icon {
        width: 44px;
        height: 44px;
        color: #c8d0db;
    }

    .ri-empty-title {
        margin: 0;
        color: var(--ri-ink);
        font-size: 15px;
        font-weight: 650;
    }

    .ri-empty-copy {
        margin: 0;
        max-width: 38ch;
        color: var(--ri-muted);
        font-size: 13px;
        line-height: 1.6;
    }

    /* ── Pagination ── */
    .ri-pagination {
        padding: 14px 18px;
        border-top: 1px solid var(--ri-border-soft);
        background: #fafbfd;
    }

    /* ── Mobile card list (< 768px) ── */
    .ri-table-wrap,
    .ri-section-head { display: block; }
    .ri-mobile-list { display: none; }

    @media (max-width: 767px) {
        .ri-table-wrap { display: none; }
        .ri-mobile-list { display: block; }

        .ri-kpi-strip {
            grid-template-columns: repeat(2, minmax(0,1fr));
        }
        .ri-kpi { border-bottom: 1px solid var(--ri-border); }
        .ri-kpi:nth-child(2n) { border-right: 0; }
        .ri-kpi:nth-last-child(-n+2):nth-child(odd),
        .ri-kpi:last-child { border-bottom: 0; }

        .ri-filter { gap: 10px; }
        .ri-filter-control { min-width: 0; width: 100%; }
        .ri-filter-field { width: 100%; }
        .ri-filter-apply,
        .ri-filter-clear { height: 42px; flex: 1; }
    }

    .ri-mobile-card {
        padding: 16px;
        border-bottom: 1px solid var(--ri-border-soft);
    }
    .ri-mobile-card:last-child { border-bottom: 0; }

    .ri-mobile-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
    }

    .ri-mobile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0,1fr));
        gap: 8px;
        margin-bottom: 12px;
    }

    .ri-mobile-grid dt {
        font-size: 11px;
        font-weight: 600;
        color: var(--ri-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 2px;
    }

    .ri-mobile-grid dd {
        font-size: 13px;
        font-weight: 600;
        color: var(--ri-ink);
        margin: 0;
    }
</style>

    <x-page-header title="Returns" subtitle="Credit notes issued against customer returns">
        <x-slot:actions>
            <a href="{{ route('invoices.index') }}" class="ri-back-btn">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
                Back to Invoices
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ri-page">
        <div class="ri-flow">

            {{-- Policy warning --}}
            <x-return-policy-banner />

            {{-- KPI strip: one bordered panel, hairline-divided --}}
            <div class="ri-kpi-strip">
                <div class="ri-kpi">
                    <p class="ri-kpi-label">Total returns</p>
                    <p class="ri-kpi-value">{{ number_format($returnOrders->total()) }}</p>
                </div>
                <div class="ri-kpi">
                    <p class="ri-kpi-label">Visible refunds</p>
                    <p class="ri-kpi-value ri-kpi-value--accent">₹{{ number_format($visibleRefundTotal, 2) }}</p>
                </div>
                <div class="ri-kpi">
                    <p class="ri-kpi-label">Today's refunds</p>
                    <p class="ri-kpi-value">₹{{ number_format($todayRefunds, 2) }}</p>
                </div>
                <div class="ri-kpi">
                    <p class="ri-kpi-label">Pending approval</p>
                    <p class="ri-kpi-value {{ $pendingApprovalCount > 0 ? 'ri-kpi-value--warn' : '' }}">
                        {{ number_format($pendingApprovalCount) }}
                        @if($pendingApprovalCount > 0)
                            <a href="{{ route('returns.control-center') }}" style="font-size:12px;font-weight:600;color:var(--ri-accent-deep);text-decoration:none;margin-left:6px;">Review</a>
                        @endif
                    </p>
                </div>
                <div class="ri-kpi">
                    <p class="ri-kpi-label">Awaiting inspection</p>
                    <p class="ri-kpi-value {{ $pendingRestockCount > 0 ? 'ri-kpi-value--warn' : '' }}">{{ number_format($pendingRestockCount) }}</p>
                </div>
            </div>

            {{-- Filter + table card --}}
            <div class="ri-card">
                <form method="GET" action="{{ route('returns.index') }}" class="ri-filter">
                    <div class="ri-filter-field">
                        <span class="ri-filter-label">Status</span>
                        <select name="status" class="ri-filter-control">
                            <option value="">All</option>
                            <option value="settled"          {{ request('status') === 'settled'          ? 'selected' : '' }}>Settled</option>
                            <option value="pending_approval" {{ request('status') === 'pending_approval' ? 'selected' : '' }}>Pending Approval</option>
                            <option value="cancelled"        {{ request('status') === 'cancelled'        ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>
                    <div class="ri-filter-field">
                        <span class="ri-filter-label">From</span>
                        <input type="date" name="from" value="{{ request('from') }}" class="ri-filter-control">
                    </div>
                    <div class="ri-filter-field">
                        <span class="ri-filter-label">To</span>
                        <input type="date" name="to" value="{{ request('to') }}" class="ri-filter-control">
                    </div>
                    <div class="ri-filter-field">
                        <span class="ri-filter-label">Customer</span>
                        <input type="text" name="customer" value="{{ request('customer') }}" placeholder="Name or mobile" class="ri-filter-control">
                    </div>
                    <div style="display:flex;gap:8px;align-items:flex-end;">
                        <button type="submit" class="ri-filter-apply">Apply</button>
                        <a href="{{ route('returns.index') }}" class="ri-filter-clear">Clear</a>
                    </div>
                </form>

                @if($returnOrders->isEmpty())
                    <div class="ri-empty">
                        <svg class="ri-empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M9 14H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v6a2 2 0 01-2 2h-4m-6 4l3 3m0 0l3-3m-3 3V14"/>
                        </svg>
                        <h2 class="ri-empty-title">No returns yet</h2>
                        <p class="ri-empty-copy">When a customer return is processed, the credit note appears here. Open the original invoice and use Cancel via Reversal to start a return.</p>
                    </div>
                @else
                    <div class="ri-section-head">
                        <h2 class="ri-section-title">Return Orders</h2>
                        <p class="ri-section-count">{{ number_format($returnOrders->total()) }} {{ $returnOrders->total() === 1 ? 'return' : 'returns' }}</p>
                    </div>

                    {{-- Desktop table --}}
                    <div class="ri-table-wrap">
                        <table class="ri-table">
                            <thead>
                                <tr>
                                    <th>Credit Note</th>
                                    <th>Original Invoice</th>
                                    <th>Customer</th>
                                    <th class="text-right">Refund</th>
                                    <th class="text-center">Lines</th>
                                    <th>Status</th>
                                    <th>Settled</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($returnOrders as $ro)
                                    @php
                                        $cn = $ro->creditNote;
                                        $customer = $ro->customer;
                                        $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
                                        $tone = $statusTone($ro->status);
                                    @endphp
                                    <tr>
                                        <td>
                                            @if($cn)
                                                <span class="ri-cn-number">{{ $cn->credit_note_number }}</span>
                                                <span class="ri-cell-sub">{{ optional($cn->issued_at)->format('d M Y') }}</span>
                                            @else
                                                <span class="ri-muted">No credit note yet</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($ro->invoice)
                                                <a class="ri-invoice-link" href="{{ route('invoices.show', $ro->invoice) }}">{{ $ro->invoice->invoice_number }}</a>
                                                <span class="ri-cell-sub">₹{{ number_format((float) $ro->invoice->total, 2) }}</span>
                                            @else
                                                <span class="ri-muted">-</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($customerName)
                                                <span class="ri-customer-name">{{ $customerName }}</span>
                                                <span class="ri-cell-sub">{{ $customer->mobile ?: '' }}</span>
                                            @else
                                                <span class="ri-muted">Walk-in</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if($cn)
                                                <span class="ri-refund-amount">₹{{ number_format((float) $cn->total, 2) }}</span>
                                            @else
                                                <span class="ri-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="text-center">{{ $ro->lineItems->count() }}</td>
                                        <td>
                                            <span class="ri-status ri-status--{{ $tone }}">{{ $statusLabel($ro->status) }}</span>
                                        </td>
                                        <td>
                                            <span>{{ optional($ro->settled_at)->format('d M Y') ?? '-' }}</span>
                                            <span class="ri-cell-sub">{{ $ro->settledBy?->name ?? $ro->createdBy?->name ?? '' }}</span>
                                        </td>
                                        <td class="text-right">
                                            <div style="display:inline-flex;align-items:center;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
                                                <a href="{{ route('returns.show', $ro) }}" class="ri-btn-view">View</a>
                                                @if($ro->status === \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)
                                                    @can('returns.approve')
                                                        <form method="POST" action="{{ route('returns.approve', $ro) }}" style="display:inline;"
                                                              onsubmit="return confirm('Approve this return and issue the credit note?')">
                                                            @csrf
                                                            <button type="submit" class="ri-btn-approve">Approve</button>
                                                        </form>
                                                        <a href="{{ route('returns.show', $ro) }}" class="ri-btn-reject">Reject</a>
                                                    @endcan
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Mobile card list --}}
                    <div class="ri-mobile-list">
                        @foreach($returnOrders as $ro)
                            @php
                                $cn = $ro->creditNote;
                                $customer = $ro->customer;
                                $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Walk-in';
                                $tone = $statusTone($ro->status);
                            @endphp
                            <article class="ri-mobile-card">
                                <div class="ri-mobile-head">
                                    <div>
                                        <span class="ri-cn-number">{{ $cn?->credit_note_number ?? 'Pending' }}</span>
                                        <span class="ri-cell-sub">{{ $cn?->issued_at?->format('d M Y') ?? 'Not issued yet' }}</span>
                                    </div>
                                    <span class="ri-status ri-status--{{ $tone }}">{{ $statusLabel($ro->status) }}</span>
                                </div>

                                <dl class="ri-mobile-grid">
                                    <div>
                                        <dt>Refund</dt>
                                        <dd>{{ $cn ? '₹' . number_format((float) $cn->total, 2) : '-' }}</dd>
                                    </div>
                                    <div>
                                        <dt>Lines</dt>
                                        <dd>{{ $ro->lineItems->count() }}</dd>
                                    </div>
                                    <div>
                                        <dt>Invoice</dt>
                                        <dd>
                                            @if($ro->invoice)
                                                <a href="{{ route('invoices.show', $ro->invoice) }}" style="color:var(--ri-accent-deep);text-decoration:none;">{{ $ro->invoice->invoice_number }}</a>
                                            @else
                                                -
                                            @endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Customer</dt>
                                        <dd>{{ $customerName }}</dd>
                                    </div>
                                </dl>

                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <a href="{{ route('returns.show', $ro) }}" class="ri-btn-view">View Return</a>
                                    @if($ro->status === \App\Models\ReturnOrder::STATUS_PENDING_APPROVAL)
                                        @can('returns.approve')
                                            <form method="POST" action="{{ route('returns.approve', $ro) }}" style="display:inline;"
                                                  onsubmit="return confirm('Approve this return and issue the credit note?')">
                                                @csrf
                                                <button type="submit" class="ri-btn-approve">Approve</button>
                                            </form>
                                            <a href="{{ route('returns.show', $ro) }}" class="ri-btn-reject">Reject</a>
                                        @endcan
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if($returnOrders->hasPages())
                        <div class="ri-pagination">
                            {{ $returnOrders->links() }}
                        </div>
                    @endif
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
