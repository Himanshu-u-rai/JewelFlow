<x-app-layout>
    <x-page-header
        class="quick-bills-index-header"
        title="Quick Bills"
        subtitle="A flexible mini bill register, separate from your main invoices."
    >
        <x-slot:actions>
            @can('sales.create')
            <a href="{{ route('quick-bills.create') }}" class="qb-new-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/></svg>
                New Quick Bill
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <style>
        .quick-bills-page {
            --qb-gold:#d97706; --qb-gold-deep:#b45309; --qb-gold-soft:#f59e0b;
            --qb-ink:#1e2530; --qb-ink-soft:#475467; --qb-muted:#667085;
            --qb-line:#e6e8ec; --qb-ease:cubic-bezier(0.23,1,0.32,1);
        }

        /* New-bill CTA (gold, matches the onboarding flow). */
        .qb-new-btn {
            display: inline-flex; align-items: center; gap: 7px;
            background: var(--qb-gold-deep); color: #fff; text-decoration: none;
            font-size: 14px; font-weight: 700; padding: 9px 16px; border-radius: 10px;
            box-shadow: 0 1px 2px rgba(16,24,40,0.08), 0 8px 18px -10px rgba(180,83,9,0.5);
            transition: background .16s ease, transform .12s var(--qb-ease), box-shadow .16s ease;
        }
        .qb-new-btn svg { width: 16px; height: 16px; }
        .qb-new-btn:hover { background: #92400e; box-shadow: 0 1px 2px rgba(16,24,40,0.08), 0 12px 22px -10px rgba(180,83,9,0.55); }
        .qb-new-btn:active { transform: scale(0.97); }

        /* ---------- Stat cards: separate cards, responsive grid ---------- */
        .qb-stats {
            display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px;
        }
        .qb-stat {
            display: flex; align-items: flex-start; gap: 11px; padding: 16px 18px; min-width: 0;
            background: #fff; border: 1px solid var(--qb-line); border-radius: 14px;
            box-shadow: 0 1px 2px rgba(16,24,40,0.04), 0 1px 3px rgba(16,24,40,0.04);
            transition: box-shadow .2s var(--qb-ease), border-color .2s ease;
        }
        @media (hover: hover) and (pointer: fine) {
            .qb-stat:hover { border-color: #dadde2; box-shadow: 0 1px 2px rgba(16,24,40,0.05), 0 10px 24px -16px rgba(16,24,40,0.2); }
        }
        .qb-stat__icon {
            width: 34px; height: 34px; flex: 0 0 auto; border-radius: 9px;
            display: grid; place-items: center;
        }
        .qb-stat__icon svg { width: 17px; height: 17px; }
        .qb-stat__icon.is-gold   { color: var(--qb-gold-deep); background: #fdf6ec; }
        .qb-stat__icon.is-green  { color: #047857; background: #ecfdf5; }
        .qb-stat__icon.is-slate  { color: #475467; background: #f1f3f7; }
        .qb-stat__icon.is-amber  { color: #b45309; background: #fff7ed; }
        .qb-stat__body { min-width: 0; }
        .qb-stat__label { font-size: 12px; font-weight: 600; color: var(--qb-muted); }
        .qb-stat__value { margin-top: 3px; font-size: 22px; font-weight: 800; letter-spacing: -0.5px; color: var(--qb-ink); line-height: 1.1; }
        .qb-stat__value.is-money { font-size: 19px; }

        /* ---------- Filter bar ---------- */
        .qb-filters {
            background: #fff; border: 1px solid var(--qb-line); border-radius: 16px;
            padding: 14px 16px; box-shadow: 0 1px 2px rgba(16,24,40,0.04);
        }
        .qb-filters form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .qb-field { display: flex; flex-direction: column; gap: 6px; }
        .qb-field label { font-size: 12px; font-weight: 600; color: var(--qb-ink-soft); }
        .qb-field.is-search { flex: 1 1 240px; min-width: 200px; }
        .qb-search-wrap { position: relative; }
        .qb-search-wrap > svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--qb-muted); pointer-events: none; }
        .qb-filters input[type="text"], .qb-filters input[type="date"], .qb-filters select {
            width: 100%; padding: 10px 12px; border: 1px solid #d3d8e0; border-radius: 10px;
            font: inherit; font-size: 14px; color: var(--qb-ink); background: #fff;
            transition: border-color .16s ease, box-shadow .18s var(--qb-ease);
        }
        .qb-filters input[type="text"] { padding-left: 36px; }
        @media (hover: hover) and (pointer: fine) {
            .qb-filters input:hover, .qb-filters select:hover { border-color: #b8c0cc; }
        }
        .qb-filters input:focus, .qb-filters select:focus {
            outline: none; border-color: var(--qb-gold-soft); box-shadow: 0 0 0 3px rgba(245,158,11,0.15);
        }
        .qb-filters select { appearance: none; -webkit-appearance: none; padding-right: 32px; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23667085' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 11px center; }
        .qb-btn {
            display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; border-radius: 10px;
            font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none;
            transition: background .16s ease, border-color .16s ease, transform .12s var(--qb-ease);
        }
        .qb-btn--primary { background: var(--qb-ink); color: #fff; border: 1px solid var(--qb-ink); }
        .qb-btn--primary:hover { background: #0f1722; }
        .qb-btn--ghost { background: #fff; color: var(--qb-ink-soft); border: 1px solid var(--qb-line); }
        .qb-btn--ghost:hover { background: #f7f8fa; border-color: #c4cad3; }
        .qb-btn:active { transform: scale(0.97); }

        /* ---------- Table (desktop) ---------- */
        .qb-table-card { background: #fff; border: 1px solid var(--qb-line); border-radius: 16px; overflow: hidden; box-shadow: 0 1px 2px rgba(16,24,40,0.04); }
        .qb-table { width: 100%; border-collapse: collapse; }
        .qb-table thead th {
            text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--qb-muted); padding: 13px 20px; background: #fafbfc; border-bottom: 1px solid var(--qb-line);
        }
        .qb-table thead th.ar { text-align: right; }
        .qb-table tbody td { padding: 15px 20px; border-bottom: 1px solid #f1f3f7; vertical-align: middle; }
        .qb-table tbody tr:last-child td { border-bottom: 0; }
        .qb-table tbody tr { transition: background .14s ease; }
        @media (hover: hover) and (pointer: fine) { .qb-table tbody tr:hover { background: #fcfcfd; } }
        .qb-billno { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13.5px; font-weight: 600; color: var(--qb-ink); }
        .qb-sub { font-size: 12px; color: var(--qb-muted); margin-top: 2px; }
        .qb-name { font-size: 14px; font-weight: 600; color: var(--qb-ink); }
        .qb-cell { font-size: 14px; color: var(--qb-ink-soft); }
        .qb-amount { font-size: 14px; font-weight: 700; color: var(--qb-ink); }
        .qb-due-0 { color: #047857; font-weight: 600; }
        .qb-due-pos { color: var(--qb-gold-deep); font-weight: 700; }

        .qb-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; }
        .qb-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; }
        .qb-badge.is-issued { background: #ecfdf5; color: #047857; } .qb-badge.is-issued::before { background: #10b981; }
        .qb-badge.is-draft  { background: #fff7ed; color: #b45309; } .qb-badge.is-draft::before  { background: #f59e0b; }
        .qb-badge.is-void   { background: #fef2f2; color: #b42318; } .qb-badge.is-void::before   { background: #f04438; }

        .qb-actions { display: inline-flex; gap: 8px; justify-content: flex-end; }
        .qb-action {
            display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 9px;
            border: 1px solid var(--qb-line); background: #fff; color: var(--qb-ink-soft);
            font-size: 13px; font-weight: 600; text-decoration: none;
            transition: background .14s ease, border-color .14s ease, color .14s ease, transform .12s var(--qb-ease);
        }
        .qb-action svg { width: 14px; height: 14px; }
        .qb-action:hover { background: #f7f8fa; border-color: #c4cad3; color: var(--qb-ink); }
        .qb-action:active { transform: scale(0.96); }

        /* ---------- Mobile card list (replaces the wide table) ---------- */
        .qb-cards { display: none; }
        .qb-card {
            background: #fff; border: 1px solid var(--qb-line); border-radius: 14px; padding: 15px 16px;
            box-shadow: 0 1px 2px rgba(16,24,40,0.04);
        }
        .qb-card + .qb-card { margin-top: 12px; }
        .qb-card__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .qb-card__amounts { display: flex; gap: 18px; margin-top: 14px; padding-top: 13px; border-top: 1px solid #f1f3f7; }
        .qb-card__amt-label { font-size: 11px; font-weight: 600; color: var(--qb-muted); }
        .qb-card__amt-value { font-size: 15px; font-weight: 700; color: var(--qb-ink); margin-top: 2px; }
        .qb-card__foot { display: flex; gap: 8px; margin-top: 14px; }
        .qb-card__foot .qb-action { flex: 1; justify-content: center; }

        /* entrance */
        @keyframes qb-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .qb-stats, .qb-filters, .qb-table-card, .qb-cards { opacity: 0; animation: qb-rise .45s var(--qb-ease) forwards; }
        .qb-stats { animation-delay: .02s; }
        .qb-filters { animation-delay: .08s; }
        .qb-table-card, .qb-cards { animation-delay: .14s; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1100px) {
            .qb-stats { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 760px) {
            /* swap table for cards */
            .qb-table-card { display: none; }
            .qb-cards { display: block; }
            .qb-filters form { gap: 10px; }
            .qb-field:not(.is-search) { flex: 1 1 calc(50% - 5px); }
        }
        @media (max-width: 560px) {
            .qb-stats { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .qb-stat { padding: 13px 13px; gap: 9px; }
            .qb-stat__value { font-size: 19px; }
            .qb-stat__value.is-money { font-size: 16px; }
            /* odd count: the last card stretches across both columns */
            .qb-stat:last-child:nth-child(odd) { grid-column: 1 / -1; }
        }

        @media (prefers-reduced-motion: reduce) {
            .qb-stats, .qb-filters, .qb-table-card, .qb-cards { opacity: 1; animation: none; }
            .qb-new-btn, .qb-action, .qb-btn, .qb-table tbody tr, .qb-filters input, .qb-filters select { transition: none; }
        }
    </style>

    @php
        $statusMeta = [
            'issued' => ['cls' => 'is-issued', 'label' => 'Issued'],
            'draft'  => ['cls' => 'is-draft',  'label' => 'Draft'],
            'void'   => ['cls' => 'is-void',   'label' => 'Void'],
        ];
    @endphp

    <div class="content-inner max-w-[1380px] mx-auto quick-bills-page">

        @unless(auth()->user()->can('sales.create'))
            @include('partials.view-only-banner', ['permission' => 'sales.create', 'message' => 'creating quick bills'])
        @endunless

        {{-- Stat strip --}}
        <div class="qb-stats mb-5">
            <div class="qb-stat">
                <span class="qb-stat__icon is-slate">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6M9 11h6M9 15h4M6 3h12a1 1 0 011 1v17l-3-2-2 2-2-2-2 2-2-2-3 2V4a1 1 0 011-1z"/></svg>
                </span>
                <div class="qb-stat__body">
                    <div class="qb-stat__label">Total bills</div>
                    <div class="qb-stat__value">{{ number_format($stats['total_count']) }}</div>
                </div>
            </div>
            <div class="qb-stat">
                <span class="qb-stat__icon is-green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 6L9 17l-5-5"/></svg>
                </span>
                <div class="qb-stat__body">
                    <div class="qb-stat__label">Issued</div>
                    <div class="qb-stat__value">{{ number_format($stats['issued_count']) }}</div>
                </div>
            </div>
            <div class="qb-stat">
                <span class="qb-stat__icon is-amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7M18.5 2.5a2.12 2.12 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </span>
                <div class="qb-stat__body">
                    <div class="qb-stat__label">Drafts</div>
                    <div class="qb-stat__value">{{ number_format($stats['draft_count']) }}</div>
                </div>
            </div>
            <div class="qb-stat">
                <span class="qb-stat__icon is-gold">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                </span>
                <div class="qb-stat__body">
                    <div class="qb-stat__label">Today's value</div>
                    <div class="qb-stat__value is-money">₹{{ number_format((float) $stats['today_total'], 2) }}</div>
                </div>
            </div>
            <div class="qb-stat">
                <span class="qb-stat__icon is-amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 17h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                </span>
                <div class="qb-stat__body">
                    <div class="qb-stat__label">Outstanding</div>
                    <div class="qb-stat__value is-money">₹{{ number_format((float) $stats['outstanding_total'], 2) }}</div>
                </div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="qb-filters mb-5 ui-filter-enhanced-wrap">
            <form method="GET" action="{{ route('quick-bills.index') }}" data-enhance-selects="true" data-enhance-selects-variant="standard">
                <div class="qb-field is-search">
                    <label>Search</label>
                    <div class="qb-search-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path stroke-linecap="round" d="M21 21l-4.35-4.35"/></svg>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Bill number, customer or mobile" data-suggest="quick-bills" autocomplete="off">
                    </div>
                </div>
                <div class="qb-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All statuses</option>
                        <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                        <option value="issued" @selected(request('status') === 'issued')>Issued</option>
                        <option value="void" @selected(request('status') === 'void')>Void</option>
                    </select>
                </div>
                <div class="qb-field">
                    <label>From</label>
                    <input type="date" name="from_date" value="{{ request('from_date') }}">
                </div>
                <div class="qb-field">
                    <label>To</label>
                    <input type="date" name="to_date" value="{{ request('to_date') }}">
                </div>
                <div style="display:flex; gap:8px;">
                    @if(request()->hasAny(['search', 'status', 'from_date', 'to_date']))
                        <a href="{{ route('quick-bills.index') }}" class="qb-btn qb-btn--ghost">Clear</a>
                    @endif
                    <button type="submit" class="qb-btn qb-btn--primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h18M6 12h12M10 19h4"/></svg>
                        Filter
                    </button>
                </div>
            </form>
        </div>

        {{-- Desktop table --}}
        <div class="qb-table-card">
            @if($quickBills->count())
                <table class="qb-table">
                    <thead>
                        <tr>
                            <th>Bill</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th class="ar">Total</th>
                            <th class="ar">Due</th>
                            <th>Status</th>
                            <th class="ar">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($quickBills as $quickBill)
                            @php $meta = $statusMeta[$quickBill->status] ?? $statusMeta['draft']; @endphp
                            <tr>
                                <td>
                                    <div class="qb-billno">{{ $quickBill->bill_number }}</div>
                                    <div class="qb-sub">Quick bill</div>
                                </td>
                                <td>
                                    <div class="qb-name">{{ $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in customer') }}</div>
                                    <div class="qb-sub">{{ $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: 'No mobile') }}</div>
                                </td>
                                <td class="qb-cell">{{ $quickBill->bill_date?->format('d M Y') }}</td>
                                <td class="qb-amount ar" style="text-align:right;">₹{{ number_format((float) $quickBill->total_amount, 2) }}</td>
                                <td class="ar" style="text-align:right;">
                                    <span class="{{ (float) $quickBill->due_amount > 0 ? 'qb-due-pos' : 'qb-due-0' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</span>
                                </td>
                                <td><span class="qb-badge {{ $meta['cls'] }}">{{ $meta['label'] }}</span></td>
                                <td style="text-align:right;">
                                    <div class="qb-actions">
                                        <a href="{{ route('quick-bills.show', $quickBill) }}" class="qb-action">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View
                                        </a>
                                        <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="qb-action">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/></svg>
                                            Print
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div style="padding: 56px 24px; text-align:center;">
                    <x-empty-state
                        title="No quick bills yet"
                        description="Create a small flexible jewellery bill without affecting the main invoice system."
                    />
                </div>
            @endif

            @if($quickBills->hasPages())
                <div style="border-top: 1px solid var(--qb-line); padding: 14px 20px;">
                    {{ $quickBills->links() }}
                </div>
            @endif
        </div>

        {{-- Mobile card list --}}
        <div class="qb-cards">
            @if($quickBills->count())
                @foreach($quickBills as $quickBill)
                    @php $meta = $statusMeta[$quickBill->status] ?? $statusMeta['draft']; @endphp
                    <div class="qb-card">
                        <div class="qb-card__top">
                            <div style="min-width:0;">
                                <div class="qb-billno">{{ $quickBill->bill_number }}</div>
                                <div class="qb-name" style="margin-top:4px;">{{ $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in customer') }}</div>
                                <div class="qb-sub">{{ $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: 'No mobile') }} · {{ $quickBill->bill_date?->format('d M Y') }}</div>
                            </div>
                            <span class="qb-badge {{ $meta['cls'] }}">{{ $meta['label'] }}</span>
                        </div>
                        <div class="qb-card__amounts">
                            <div>
                                <div class="qb-card__amt-label">Total</div>
                                <div class="qb-card__amt-value">₹{{ number_format((float) $quickBill->total_amount, 2) }}</div>
                            </div>
                            <div>
                                <div class="qb-card__amt-label">Due</div>
                                <div class="qb-card__amt-value {{ (float) $quickBill->due_amount > 0 ? 'qb-due-pos' : 'qb-due-0' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</div>
                            </div>
                        </div>
                        <div class="qb-card__foot">
                            <a href="{{ route('quick-bills.show', $quickBill) }}" class="qb-action">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                View
                            </a>
                            <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="qb-action">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/></svg>
                                Print
                            </a>
                        </div>
                    </div>
                @endforeach

                @if($quickBills->hasPages())
                    <div style="margin-top: 16px;">{{ $quickBills->links() }}</div>
                @endif
            @else
                <div class="qb-card" style="padding: 40px 20px; text-align:center;">
                    <x-empty-state
                        title="No quick bills yet"
                        description="Create a small flexible jewellery bill without affecting the main invoice system."
                    />
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
