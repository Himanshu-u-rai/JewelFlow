<x-app-layout>
    <x-page-header
        class="export-page-header"
        title="Data Exports"
        subtitle="Download your shop data as Excel, PDF or CSV — pick filters and columns on the next screen."
        badge="CSV/PDF"
    />

    <div class="content-inner xp-page">
        @php
            // Each card → a registered report's export panel (format / filters /
            // columns chosen there). Grouped by what the owner is exporting.
            $panel = fn (string $key) => route('reporting.export.panel', ['report' => $key]);
            $groups = [
                'Customers & balances' => [
                    ['customers', 'Customers', 'Directory with spend, loyalty, EMI, scheme & old-gold balances. Contact details are hidden unless you have permission.'],
                ],
                'Inventory & purchases' => [
                    ['products', 'Products (catalog)', 'Your design/catalog master — codes, default purity, weight, making & stone.'],
                    ['inventory-items', 'Inventory items', 'Every individual piece — barcode, weight, purity, charges, status.'],
                    ['stock-purchases', 'Stock purchases', 'Supplier purchase invoices — what came in, from whom, cost & GST.'],
                ],
                'Sales & money' => [
                    ['sales-register', 'Sales / invoices', 'Full sales register with totals, GST and payments.'],
                    ['cash-flow', 'Cash book', 'Cash in / out with running balance.'],
                    ['metal-ledger', 'Metal ledger', 'Audit-grade gold movement trail.'],
                ],
                'Karigar & workers' => [
                    ['karigars', 'Karigars', 'Worker list with money owed (opening + invoiced − paid).'],
                    ['karigar-invoices', 'Karigar invoices', 'Labour/work invoices — pieces, making, tax, paid vs outstanding.'],
                    ['job-orders', 'Job orders', 'Gold issued to karigars — issued vs returned fine weight, wastage, status.'],
                ],
                'Returns & service' => [
                    ['returns', 'Returns', 'Customer returns — type, status, original invoice, refund total.'],
                    ['credit-notes', 'Credit notes', 'Issued credit notes with amounts and the original invoice.'],
                    ['repairs', 'Repairs', 'Repair jobs taken in — item, weight, estimated vs final cost, status.'],
                ],
                'Schemes & EMI' => [
                    ['installment-plans', 'EMI plans', 'Customer instalment plans — paid, remaining, next due date.'],
                    ['scheme-enrollments', 'Scheme enrolments', 'Savings-scheme participation — paid in, redeemed, maturity.'],
                ],
                'Customer balances' => [
                    ['store-credit', 'Store credit', 'Store-credit ledger — credit issued and consumed per customer.'],
                    ['loyalty', 'Loyalty points', 'Points ledger — earn and redeem with running balance.'],
                    ['old-gold', 'Old gold', 'Customers\' own gold received / applied (fine-weight ledger).'],
                ],
            ];
        @endphp

        {{-- Export everything --}}
        <section class="xp-backup">
            <div class="xp-backup-text">
                <h2 class="xp-backup-title">Export everything</h2>
                <p class="xp-backup-desc">One Excel file with a separate sheet for each of your data sets — a complete backup.</p>
            </div>
            <form action="{{ route('export.all') }}" method="POST" data-turbo="false" class="xp-backup-form">
                @csrf
                <button type="submit" class="xp-backup-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download Excel backup
                </button>
            </form>
        </section>

        @foreach($groups as $groupTitle => $cards)
            <h3 class="xp-group-title">{{ $groupTitle }}</h3>
            <div class="xp-grid {{ count($cards) === 1 ? 'xp-grid--single' : '' }}">
                @foreach($cards as [$key, $title, $desc])
                    <a href="{{ $panel($key) }}" class="xp-card" data-turbo-frame="_top">
                        <div class="xp-card-top">
                            <h4 class="xp-card-title">{{ $title }}</h4>
                            <span class="xp-card-arrow">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                            </span>
                        </div>
                        <p class="xp-card-desc">{{ $desc }}</p>
                        <span class="xp-card-formats">Excel · PDF · CSV</span>
                    </a>
                @endforeach
            </div>
        @endforeach

        <div class="xp-note">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
            <p>Every export contains only your shop's data. On the next screen you choose the format (Excel, PDF or CSV), the date range, and which columns to include. Personal contact details stay hidden unless you have permission to include them.</p>
        </div>
    </div>

    <style>
        .xp-page {
            --xp-gold:        #b45309;
            --xp-gold-soft:   #fef3e2;
            --xp-border:      #e7e2d6;
            --xp-border-soft: #efeadd;
            --xp-ink:         #1c1917;
            --xp-ink-2:       #44403c;
            --xp-muted:       #78716c;
            --xp-surface:     #fffdf8;
            --xp-ease:        cubic-bezier(0.23,1,0.32,1);
            width: 100%;
            max-width: none;
            margin-inline: 0;
        }

        /* Backup banner */
        .xp-backup {
            display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap;
            border: 1px solid var(--xp-border); border-radius: 16px;
            background: linear-gradient(180deg, var(--xp-surface), #fff);
            padding: 16px 20px; margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(28,25,23,.04), 0 14px 30px -20px rgba(28,25,23,.18);
        }
        .xp-backup-title { margin: 0; font-size: 15.5px; font-weight: 700; color: var(--xp-ink); letter-spacing: -.01em; }
        .xp-backup-desc { margin: 4px 0 0; font-size: 13px; color: var(--xp-muted); line-height: 1.5; max-width: 52ch; }
        .xp-backup-form { margin: 0; }
        .xp-backup-btn {
            display: inline-flex; align-items: center; gap: 8px; white-space: nowrap;
            min-height: 42px; padding: 0 18px;
            border: 1px solid var(--xp-gold); border-radius: 11px;
            background: var(--xp-gold); color: #fff; font-size: 13.5px; font-weight: 650; cursor: pointer;
            transition: background-color .15s var(--xp-ease), transform .15s var(--xp-ease);
        }
        .xp-backup-btn:hover { background: #92400e; }
        .xp-backup-btn:active { transform: scale(.98); }

        .xp-group-title {
            margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--xp-muted);
        }
        .xp-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; margin-bottom: 22px;
        }
        .xp-grid--single {
            grid-template-columns: 1fr;
        }
        .xp-grid--single .xp-card-desc {
            max-width: 76ch;
        }

        .xp-card {
            display: flex; flex-direction: column; gap: 8px;
            border: 1px solid var(--xp-border); border-radius: 14px; background: #fff;
            padding: 14px 15px; text-decoration: none;
            transition: border-color .16s var(--xp-ease), box-shadow .16s var(--xp-ease), transform .16s var(--xp-ease);
        }
        .xp-card:hover {
            border-color: #e3c9a6; transform: translateY(-2px);
            box-shadow: 0 1px 2px rgba(28,25,23,.04), 0 16px 30px -22px rgba(180,83,9,.45);
        }
        .xp-card:active { transform: translateY(0); }
        .xp-card-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .xp-card-title { margin: 0; font-size: 14.5px; font-weight: 650; color: var(--xp-ink); letter-spacing: -.01em; }
        .xp-card-arrow { color: #c9a06a; display: inline-flex; transition: transform .16s var(--xp-ease), color .16s var(--xp-ease); }
        .xp-card:hover .xp-card-arrow { color: var(--xp-gold); transform: translateX(3px); }
        .xp-card-desc { margin: 0; font-size: 12.5px; line-height: 1.55; color: var(--xp-muted); flex: 1; }
        .xp-card-formats {
            margin-top: 2px; font-size: 11px; font-weight: 600; letter-spacing: .02em;
            color: var(--xp-gold); background: var(--xp-gold-soft); border-radius: 7px;
            padding: 3px 9px; align-self: flex-start;
        }

        .xp-note {
            display: flex; gap: 11px; align-items: flex-start;
            border: 1px solid var(--xp-border-soft); border-radius: 12px; background: var(--xp-surface);
            padding: 12px 14px; margin-top: 2px;
        }
        .xp-note svg { flex-shrink: 0; margin-top: 1px; color: #c9a06a; }
        .xp-note p { margin: 0; font-size: 12.5px; line-height: 1.55; color: var(--xp-ink-2); }

        @media (prefers-reduced-motion: no-preference) {
            .xp-card, .xp-card-arrow, .xp-backup-btn { transition-duration: .16s; }
        }
        @media (max-width: 600px) {
            .export-page-header {
                display: grid;
                grid-template-columns: 34px minmax(0, 1fr) 78px;
                align-items: center;
                gap: 10px;
                min-height: 62px;
                padding: 12px 14px !important;
            }
            .export-page-header .content-header-nav {
                grid-column: 1;
                margin: 0;
                padding: 0;
            }
            .export-page-header > :nth-child(2) {
                grid-column: 2;
                min-width: 0;
                text-align: center;
            }
            .export-page-header .page-title {
                font-size: 16px;
                font-weight: 650;
                line-height: 1.2;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .export-page-header .page-subtitle {
                display: none;
            }
            .export-page-header .page-actions {
                grid-column: 3;
                min-width: 0;
                width: 78px;
                justify-content: flex-end;
            }
            .export-page-header .header-badge {
                width: 78px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                padding: 6px 8px;
                font-size: 11px;
                border-color: #fde68a;
                background: #fef9ee;
                color: #b45309;
            }
            .xp-page { gap: 12px; }
            .xp-backup { flex-direction: column; align-items: stretch; padding: 14px; margin-bottom: 18px; }
            .xp-backup-btn { width: 100%; justify-content: center; }
            .xp-grid { grid-template-columns: 1fr; gap: 10px; margin-bottom: 20px; }
            .xp-card { padding: 13px 14px; }
        }
    </style>
</x-app-layout>
