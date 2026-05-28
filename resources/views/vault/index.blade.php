<x-app-layout>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .vault-shell {
            max-width: 1500px;
        }

        .vault-flow {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .vault-tablet-mobile-only,
        .vault-mobile-only {
            display: none;
        }

        .vault-header-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }

        .vault-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 13px;
            font-weight: 800;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease, color .18s ease;
            white-space: nowrap;
        }

        .vault-action-secondary {
            border: 1px solid #dbe3ee;
            background: #ffffff;
            color: #1f2a44;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
        }

        .vault-action-secondary:hover {
            background: #f8fafc;
            transform: translateY(-1px);
        }

        .vault-action-primary {
            border: 1px solid transparent;
            background: linear-gradient(135deg, var(--jf-gold) 0%, var(--jf-gold-deep) 100%);
            color: #ffffff;
            box-shadow: var(--jf-shadow-gold);
        }

        .vault-action-primary:hover {
            background: linear-gradient(135deg, var(--jf-gold-glow) 0%, var(--jf-gold) 100%);
            transform: translateY(-1px);
            box-shadow: var(--jf-shadow-gold-hover);
        }

        .vault-card {
            border: 1px solid #dbe3ee;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
            overflow: hidden;
        }

        .vault-summary-card {
            position: relative;
            border: 1px solid var(--jf-border);
            border-radius: var(--jf-radius-2xl);
            background: linear-gradient(180deg, var(--jf-surface-accent) 0%, #ffffff 36%);
            padding: 22px 22px 20px;
            box-shadow: var(--jf-shadow-md);
            overflow: hidden;
        }

        .vault-summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent 0%, var(--jf-gold) 20%, var(--jf-gold-glow) 50%, var(--jf-gold) 80%, transparent 100%);
        }

        .vault-summary-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .vault-summary-kicker {
            color: #b45309;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .vault-summary-title {
            margin-top: 4px;
            color: #0f172a;
            font-size: 22px;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .vault-summary-note {
            max-width: 720px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }

        .vault-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }

        .vault-summary-stat {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 13px 14px;
            min-width: 0;
        }

        .vault-summary-stat span {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .vault-summary-stat strong {
            display: block;
            margin-top: 6px;
            color: #0f172a;
            font-size: 22px;
            line-height: 1.1;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .vault-section-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .vault-section-title {
            margin: 0;
            color: #0f172a;
            font-size: 14px;
            font-weight: 900;
        }

        .vault-section-copy {
            margin-top: 2px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .vault-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            border: 1px solid #dbe3ee;
            border-radius: 999px;
            background: #f8fafc;
            padding: 7px 12px;
            color: #0f172a;
            font-size: 12px;
            font-weight: 900;
            box-shadow: 0 8px 16px rgba(15, 23, 42, .05);
            white-space: nowrap;
        }

        .vault-link:hover {
            border-color: var(--jf-gold);
            color: var(--jf-gold-deep);
            background: var(--jf-surface-accent);
        }

        .vault-count-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            min-height: 34px;
            padding: 0 10px;
            border-radius: 9999px;
            border: 1px solid var(--jf-warn-border);
            background: var(--jf-warn-bg);
            color: var(--jf-warn-ink);
            font-size: 12px;
            font-weight: 900;
        }

        .vault-empty-state {
            padding: 30px 24px;
            text-align: center;
        }

        .vault-empty-title {
            color: #0f172a;
            font-size: 16px;
            font-weight: 900;
        }

        .vault-empty-copy {
            margin-top: 6px;
            color: #64748b;
            font-size: 13px;
        }

        .vault-empty-action {
            margin-top: 18px;
        }

        .vault-responsive-actions-shell {
            padding: 14px;
        }

        .vault-responsive-actions-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .vault-responsive-actions-row .vault-action {
            width: 100%;
        }

        .vault-mobile-fab {
            display: none;
        }

        .vault-mobile-fab-shell {
            position: fixed;
            right: 16px;
            bottom: calc(16px + env(safe-area-inset-bottom, 0px));
            z-index: 70;
        }

        .vault-mobile-fab-nav {
            position: absolute;
            right: 0;
            bottom: calc(100% + 12px);
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .vault-mobile-fab-link {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-width: 176px;
            padding: 11px 14px;
            border-radius: 999px;
            border: 1px solid rgba(15, 118, 110, 0.16);
            background: rgba(255, 255, 255, 0.98);
            color: #0f172a;
            text-decoration: none;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .01em;
            box-shadow: 0 14px 24px rgba(15, 23, 42, .14);
            transform: translateY(18px) scale(.92);
            opacity: 0;
            transition: transform 180ms ease, opacity 180ms ease, box-shadow 180ms ease;
        }

        .vault-mobile-fab-link:hover {
            box-shadow: 0 18px 28px rgba(15, 23, 42, .2);
        }

        .vault-mobile-fab-link svg {
            flex-shrink: 0;
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-nav {
            pointer-events: auto;
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-link {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-link:nth-child(1) {
            transition-delay: 0ms;
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-link:nth-child(2) {
            transition-delay: 36ms;
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-link:nth-child(3) {
            transition-delay: 72ms;
        }

        .vault-mobile-fab-toggle {
            position: relative;
            width: 56px;
            height: 56px;
            border: none;
            border-radius: 9999px;
            background: linear-gradient(135deg, var(--jf-navy) 0%, var(--jf-gold-deep) 100%);
            box-shadow: 0 18px 30px rgba(15, 23, 42, .28);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .vault-mobile-fab-toggle::after {
            content: '';
            position: absolute;
            inset: 4px;
            border-radius: inherit;
            border: 1px solid rgba(245, 158, 11, .32);
        }

        .vault-mobile-fab-bars {
            position: relative;
            width: 22px;
            height: 18px;
        }

        .vault-mobile-fab-bars span {
            position: absolute;
            left: 0;
            width: 22px;
            height: 2.5px;
            border-radius: 999px;
            background: #ffffff;
            transition: transform 220ms cubic-bezier(0.4, 0, 0.2, 1), opacity 180ms ease, top 220ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        .vault-mobile-fab-bars span:nth-child(1) {
            top: 1px;
        }

        .vault-mobile-fab-bars span:nth-child(2) {
            top: 8px;
        }

        .vault-mobile-fab-bars span:nth-child(3) {
            top: 15px;
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-bars span:nth-child(1) {
            top: 8px;
            transform: rotate(45deg);
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-bars span:nth-child(2) {
            opacity: 0;
        }

        .vault-mobile-fab-shell.is-open .vault-mobile-fab-bars span:nth-child(3) {
            top: 8px;
            transform: rotate(-45deg);
        }

        .vault-purity-section {
            overflow: hidden;
        }

        .vault-purity-scroll {
            max-height: 396px;
            overflow: auto;
            padding: 18px 20px 20px;
        }

        .vault-purity-wrap {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .vault-purity-card {
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            background: #ffffff;
            padding: 16px;
            box-shadow: 0 10px 22px rgba(15, 23, 42, .05);
            min-width: 0;
        }

        .vault-purity-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
        }

        .vault-purity-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 52px;
            height: 52px;
            border-radius: 999px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #92400e;
            box-shadow: inset 0 0 0 4px #fef3c7, 0 10px 18px rgba(245, 158, 11, .16);
            font-size: 15px;
            font-weight: 950;
            flex-shrink: 0;
        }

        .vault-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .vault-value {
            color: #0f172a;
            font-weight: 900;
            line-height: 1.1;
        }

        .vault-purity-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .vault-mini-stat {
            border: 1px solid #fde68a;
            border-radius: 14px;
            background: #fffdf5;
            padding: 10px 11px;
            min-width: 0;
        }

        .vault-panel-shell {
            overflow: hidden;
        }

        .vault-panel-top {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .vault-panel-title {
            margin: 0;
            color: #0f172a;
            font-size: 14px;
            font-weight: 900;
        }

        .vault-panel-copy {
            margin-top: 3px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .vault-segmented {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .vault-segment {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            color: #334155;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 900;
            transition: all .16s ease;
        }

        .vault-segment:hover {
            border-color: #cbd5e1;
            background: #ffffff;
        }

        .vault-segment.is-active {
            border-color: #f59e0b;
            background: #fff7ed;
            color: #92400e;
            box-shadow: inset 0 0 0 1px rgba(245, 158, 11, .16);
        }

        .vault-segment-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            border-radius: 999px;
            padding: 0 6px;
            background: rgba(148, 163, 184, .14);
            color: inherit;
            font-size: 11px;
            font-weight: 900;
        }

        .vault-section-panel {
            min-width: 0;
        }

        .vault-scroll-region {
            max-height: 430px;
            overflow: auto;
        }

        .vault-scroll-region--cards {
            max-height: 440px;
            overflow: auto;
            padding: 14px;
        }

        .vault-scroll-region--cards .vault-mobile-list {
            display: grid;
            gap: 12px;
        }

        .vault-table-view--tablet,
        .vault-table-view--mobile {
            display: none;
        }

        .vault-data-table {
            width: 100%;
            min-width: 1020px;
        }

        .vault-data-table th,
        .vault-compact-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .vault-compact-table {
            width: 100%;
            min-width: 0;
        }

        .vault-table-link {
            font-weight: 800;
        }

        .vault-table-meta {
            margin-top: 3px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.45;
        }

        .vault-table-strong {
            font-weight: 900;
        }

        .vault-table-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 2px 9px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
        }

        .vault-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 18px;
            background: #ffffff;
            padding: 14px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .vault-mobile-card.is-clickable {
            cursor: pointer;
        }

        .vault-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .vault-mobile-title {
            color: #0f172a;
            font-size: 15px;
            font-weight: 900;
            line-height: 1.2;
        }

        .vault-mobile-kicker {
            margin-top: 3px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.45;
        }

        .vault-mobile-value {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
        }

        .vault-panel-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 280px;
            padding: 24px;
            text-align: center;
        }

        .vault-panel-empty-copy {
            color: #64748b;
            font-size: 13px;
            line-height: 1.6;
        }

        .vault-mobile-overview-shell {
            display: none;
        }

        .vault-overview-toggle {
            display: flex;
            width: 100%;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            border: 0;
            background: transparent;
            padding: 16px 18px;
            text-align: left;
        }

        .vault-overview-toggle:focus-visible {
            outline: 2px solid rgba(15, 118, 110, 0.28);
            outline-offset: -2px;
        }

        .vault-overview-chevron {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            color: #334155;
            flex-shrink: 0;
            transition: transform .18s ease, border-color .18s ease, background .18s ease;
        }

        .vault-overview-chevron.is-open {
            transform: rotate(180deg);
            border-color: #f59e0b;
            background: #fff7ed;
            color: #92400e;
        }

        .vault-overview-body {
            display: grid;
            gap: 14px;
            padding: 0 16px 16px;
        }

        .vault-overview-shell-summary .vault-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .vault-overview-shell-summary .vault-summary-stat strong {
            font-size: 17px;
        }

        .vault-overview-shell-summary .vault-summary-stat {
            padding: 10px;
        }

        .vault-overview-purity-rail {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(248px, 84%);
            gap: 12px;
            overflow-x: auto;
            scroll-snap-type: x proximity;
            padding-bottom: 4px;
        }

        .vault-overview-purity-rail .vault-purity-card {
            scroll-snap-align: start;
        }

        @media (max-width: 1024px) {
            .vault-shell {
                max-width: 100%;
            }

            .vault-page-header .page-actions {
                display: none;
            }

            .vault-header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .vault-header-actions .vault-action {
                flex: 1 1 calc(50% - 5px);
            }

            .vault-summary-card,
            .vault-card {
                border-radius: 18px;
            }

            .vault-summary-card {
                padding: 16px;
            }

            .vault-summary-head,
            .vault-panel-top,
            .vault-section-head {
                flex-direction: column;
                align-items: stretch;
            }

            .vault-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .vault-purity-wrap {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .vault-purity-scroll {
                max-height: 430px;
                padding: 16px;
            }

            .vault-purity-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .vault-panel-top {
                gap: 12px;
            }

            .vault-segmented {
                justify-content: flex-start;
            }

            .vault-scroll-region {
                max-height: 380px;
            }

            .vault-scroll-region--cards {
                max-height: 400px;
                padding: 12px;
            }

            .vault-table-view--desktop {
                display: none;
            }

            .vault-table-view--tablet {
                display: block;
            }

            .vault-compact-table th {
                font-size: 10px;
            }

            .vault-tablet-mobile-only {
                display: block;
            }

            .vault-responsive-actions-shell {
                order: 1;
            }

            .vault-summary-shell {
                order: 2;
            }

            .vault-workspace-shell {
                order: 3;
            }

            .vault-purity-shell,
            .vault-overview-empty-shell {
                order: 4;
            }
        }

        @media (max-width: 640px) {
            .vault-flow {
                gap: 16px;
            }

            .vault-shell {
                padding-inline: 10px;
            }

            .vault-responsive-actions-row {
                grid-template-columns: 1fr;
            }

            .vault-responsive-actions-shell {
                display: none;
            }

            .vault-summary-card {
                padding: 14px;
            }

            .vault-summary-title {
                font-size: 18px;
            }

            .vault-summary-note {
                font-size: 12px;
            }

            .vault-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .vault-summary-stat {
                padding: 10px;
            }

            .vault-summary-stat strong {
                font-size: 17px;
            }

            .vault-section-head,
            .vault-panel-top {
                padding: 16px;
            }

            .vault-link {
                width: 100%;
            }

            .vault-purity-wrap {
                display: grid;
                grid-auto-flow: column;
                grid-auto-columns: minmax(260px, 84%);
                gap: 12px;
                overflow-x: auto;
                scroll-snap-type: x proximity;
            }

            .vault-purity-scroll {
                max-height: none;
                overflow: visible;
                padding: 14px;
            }

            .vault-purity-card {
                scroll-snap-align: start;
                padding: 14px;
            }

            .vault-purity-chip {
                width: 46px;
                height: 46px;
                font-size: 13px;
            }

            .vault-purity-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .vault-segmented {
                display: grid;
                grid-template-columns: 1fr;
            }

            .vault-segment {
                justify-content: space-between;
            }

            .vault-scroll-region {
                max-height: 360px;
            }

            .vault-scroll-region--cards {
                max-height: 380px;
            }

            .vault-table-view--tablet {
                display: none;
            }

            .vault-table-view--mobile {
                display: block;
            }

            .vault-mobile-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .vault-panel-empty {
                min-height: 200px;
                padding: 18px;
            }

            .vault-desktop-tablet-only {
                display: none;
            }

            .vault-mobile-only {
                display: block;
            }

            .vault-workspace-shell {
                order: 2;
            }

            .vault-mobile-overview-shell {
                display: block;
                order: 3;
            }

            .vault-mobile-fab {
                display: block;
            }
        }
    </style>

    @php
        $vaultFine = (float) $balances->sum('in_vault_fine');
        $karigarFine = (float) $balances->sum('with_karigar_fine');
        $totalFine = (float) $balances->sum('total_fine');
        $activeLots = $lots->filter(fn ($lot) => (float) $lot->fine_weight_remaining > 0)->count();
        $depletedLots = $lots->count() - $activeLots;
        $lotsCount = $lots->count();
        $openJobsCount = $openJobs->count();
        $movementsCount = $recentMovements->count();
        $initialSection = $lots->isNotEmpty() ? 'lots' : ($openJobs->isNotEmpty() ? 'jobs' : 'movements');
    @endphp

    <x-page-header class="vault-page-header" title="Bullion Vault" subtitle="Real-time fine-weight balances per purity">
        <x-slot:actions>
            <div class="vault-header-actions">
                <a href="{{ route('vault.ledger') }}" class="vault-action vault-action-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                    Full Ledger
                </a>
                <a href="{{ route('vault.lots.create') }}" class="vault-action vault-action-secondary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Bullion
                </a>
                <a href="{{ route('job-orders.create') }}" class="vault-action vault-action-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Issue to Karigar
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner vault-shell">

        @unless(auth()->user()->can('vault.manage'))
            @include('partials.view-only-banner', ['permission' => 'vault.manage', 'message' => 'vault management'])
        @endunless

        <div class="vault-flow" x-data="{ vaultSection: @js($initialSection), mobileOverviewOpen: false, vaultFabOpen: false }" @keydown.escape.window="vaultFabOpen = false">
            <section class="vault-card vault-responsive-actions-shell vault-tablet-mobile-only">
                <div class="vault-responsive-actions-row">
                    <a href="{{ route('vault.ledger') }}" class="vault-action vault-action-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                        Full Ledger
                    </a>
                    <a href="{{ route('vault.lots.create') }}" class="vault-action vault-action-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Bullion
                    </a>
                    <a href="{{ route('job-orders.create') }}" class="vault-action vault-action-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        Issue to Karigar
                    </a>
                </div>
            </section>

            <section class="vault-summary-card vault-summary-shell vault-desktop-tablet-only">
                <div class="vault-summary-head">
                    <div>
                        <div class="vault-summary-kicker">Vault Snapshot</div>
                        <h2 class="vault-summary-title">Current position</h2>
                        <p class="vault-summary-note">Compact view of bullion available in vault, issued to karigars, and active tracking counts. All calculations and navigation remain unchanged; only the page structure is condensed.</p>
                    </div>
                </div>
                <div class="vault-summary-grid">
                    <div class="vault-summary-stat">
                        <span>In Vault</span>
                        <strong>{{ number_format($vaultFine, 3) }}g</strong>
                    </div>
                    <div class="vault-summary-stat">
                        <span>With Karigar</span>
                        <strong>{{ number_format($karigarFine, 3) }}g</strong>
                    </div>
                    <div class="vault-summary-stat">
                        <span>Total Fine</span>
                        <strong>{{ number_format($totalFine, 3) }}g</strong>
                    </div>
                    <div class="vault-summary-stat">
                        <span>Active Lots</span>
                        <strong>{{ $activeLots }}</strong>
                    </div>
                    <div class="vault-summary-stat">
                        <span>Open Jobs</span>
                        <strong>{{ $openJobsCount }}</strong>
                    </div>
                </div>
            </section>

            @if($balances->isEmpty())
                <section class="vault-card vault-empty-state vault-overview-empty-shell vault-desktop-tablet-only">
                    <p class="vault-empty-title">No bullion lots in this shop yet.</p>
                    <p class="vault-empty-copy">Add the first lot to begin tracking fine-weight balances and purity-level vault positions.</p>
                    <a href="{{ route('vault.lots.create') }}" class="vault-action vault-action-primary vault-empty-action">Add your first lot</a>
                </section>
            @else
                <section class="vault-card vault-purity-section vault-purity-shell vault-desktop-tablet-only">
                    <div class="vault-section-head">
                        <div>
                            <h2 class="vault-section-title">Purity Balances</h2>
                            <p class="vault-section-copy">Live fine-weight distribution across vault stock and bullion currently issued to karigars.</p>
                        </div>
                        <span class="vault-count-chip">{{ $balances->count() }}</span>
                    </div>
                    <div class="vault-purity-scroll">
                        <div class="vault-purity-wrap">
                            @foreach($primaryBalances as $row)
                                @php $purityLabel = rtrim(rtrim(number_format($row['purity'], 2), '0'), '.'); @endphp
                                <article class="vault-purity-card">
                                    <div class="vault-purity-top">
                                        <div>
                                            <p class="vault-label capitalize">{{ $row['metal_type'] ?? 'Metal' }}</p>
                                            <h3 class="text-2xl font-black text-slate-950">{{ $purityLabel }}<span class="ml-1 text-sm font-bold text-amber-700">fine</span></h3>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">{{ $row['lots_count'] }} {{ Str::plural('lot', $row['lots_count']) }} linked</p>
                                        </div>
                                        <div class="vault-purity-chip">{{ $purityLabel }}</div>
                                    </div>
                                    <div class="vault-purity-stats">
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">In Vault</p>
                                            <p class="vault-value text-amber-800">{{ number_format($row['in_vault_fine'], 3) }}g</p>
                                        </div>
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">Karigar</p>
                                            <p class="vault-value text-blue-800">{{ number_format($row['with_karigar_fine'], 3) }}g</p>
                                        </div>
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">Total</p>
                                            <p class="vault-value">{{ number_format($row['total_fine'], 3) }}g</p>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </div>

                    @if($otherBalances->isNotEmpty())
                        <details class="mt-5 rounded-xl border border-slate-200 bg-slate-50/60">
                            <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-700">
                                Other materials ({{ $otherBalances->count() }})
                            </summary>
                            <div class="vault-purity-wrap px-4 pb-4">
                                @foreach($otherBalances as $row)
                                    @php $purityLabel = rtrim(rtrim(number_format($row['purity'], 2), '0'), '.'); @endphp
                                    <article class="vault-purity-card">
                                        <div class="vault-purity-top">
                                            <div>
                                                <p class="vault-label capitalize">{{ $row['metal_type'] ?? 'Other' }}</p>
                                                <h3 class="text-2xl font-black text-slate-950">{{ $purityLabel }}<span class="ml-1 text-sm font-bold text-slate-500">fine</span></h3>
                                                <p class="mt-1 text-xs font-semibold text-slate-500">{{ $row['lots_count'] }} {{ Str::plural('lot', $row['lots_count']) }} linked</p>
                                            </div>
                                            <div class="vault-purity-chip">{{ $purityLabel }}</div>
                                        </div>
                                        <div class="vault-purity-stats">
                                            <div class="vault-mini-stat">
                                                <p class="vault-label">In Vault</p>
                                                <p class="vault-value text-slate-700">{{ number_format($row['in_vault_fine'], 3) }}g</p>
                                            </div>
                                            <div class="vault-mini-stat">
                                                <p class="vault-label">Karigar</p>
                                                <p class="vault-value text-slate-700">{{ number_format($row['with_karigar_fine'], 3) }}g</p>
                                            </div>
                                            <div class="vault-mini-stat">
                                                <p class="vault-label">Total</p>
                                                <p class="vault-value">{{ number_format($row['total_fine'], 3) }}g</p>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </section>
            @endif

            <section class="vault-card vault-panel-shell vault-workspace-shell">
                <div class="vault-panel-top">
                    <div>
                        <h2 class="vault-panel-title">Vault Workspace</h2>
                        <p class="vault-panel-copy">Switch between inventory lots, open job exposure, and recent movement history without stretching the page into one long report.</p>
                    </div>
                    <div class="vault-segmented" role="tablist" aria-label="Vault sections">
                        <button type="button" class="vault-segment" :class="{ 'is-active': vaultSection === 'lots' }" @click="vaultSection = 'lots'">
                            <span>All Lots</span>
                            <span class="vault-segment-count">{{ $lotsCount }}</span>
                        </button>
                        <button type="button" class="vault-segment" :class="{ 'is-active': vaultSection === 'jobs' }" @click="vaultSection = 'jobs'">
                            <span>Open Job Orders</span>
                            <span class="vault-segment-count">{{ $openJobsCount }}</span>
                        </button>
                        <button type="button" class="vault-segment" :class="{ 'is-active': vaultSection === 'movements' }" @click="vaultSection = 'movements'">
                            <span>Recent Movements</span>
                            <span class="vault-segment-count">{{ $movementsCount }}</span>
                        </button>
                    </div>
                </div>

                <div class="vault-section-panel" x-show="vaultSection === 'lots'" x-cloak>
                    <div class="vault-section-head">
                        <div>
                            <h2 class="vault-section-title">All Lots</h2>
                            <p class="vault-section-copy">Source, vendor, remaining fine weight, and current availability.</p>
                        </div>
                        <a href="{{ route('vault.lots.create') }}" class="vault-link">+ Add bullion</a>
                    </div>

                    @if($lots->isEmpty())
                        <div class="vault-panel-empty">
                            <div>
                                <p class="vault-empty-title">No lots available</p>
                                <p class="vault-panel-empty-copy">Create the first bullion lot to start tracking remaining fine weight, vendor source, issue exposure, and purity-wise availability.</p>
                            </div>
                        </div>
                    @else
                        <div class="vault-table-view vault-table-view--desktop">
                        <div class="vault-scroll-region overflow-x-auto">
                            <table class="vault-data-table w-full text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left">Lot #</th>
                                        <th class="px-4 py-3 text-left">Source</th>
                                        <th class="px-4 py-3 text-left">Vendor</th>
                                        <th class="px-4 py-3 text-center">Metal</th>
                                        <th class="px-4 py-3 text-center">Purity</th>
                                        <th class="px-4 py-3 text-right">Total Fine</th>
                                        <th class="px-4 py-3 text-right">Remaining Fine</th>
                                        <th class="px-4 py-3 text-right">Issued</th>
                                        <th class="px-4 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($lots as $lot)
                                        @php
                                            $issued = (float) $lot->fine_weight_total - (float) $lot->fine_weight_remaining;
                                            $pct = $lot->fine_weight_total > 0 ? round((float) $lot->fine_weight_remaining / (float) $lot->fine_weight_total * 100) : 0;
                                            $isEmpty = (float) $lot->fine_weight_remaining <= 0;
                                        @endphp
                                        <tr class="cursor-pointer hover:bg-slate-50 {{ $isEmpty ? 'opacity-55' : '' }}" onclick="window.location='{{ route('vault.lots.show', $lot) }}'">
                                            <td class="px-4 py-3 font-mono font-bold text-amber-700">
                                                <a href="{{ route('vault.lots.show', $lot) }}" class="hover:underline">#{{ $lot->lot_number }}</a>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600 capitalize">{{ str_replace('_', ' ', $lot->source) }}</td>
                                            <td class="px-4 py-3 text-xs text-slate-500">{{ $lot->vendor?->name ?? '—' }}</td>
                                            <td class="px-4 py-3 text-center text-xs capitalize text-slate-600">{{ $lot->metal_type ?? '—' }}</td>
                                            <td class="px-4 py-3 text-center font-bold text-amber-700">{{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}K</td>
                                            <td class="px-4 py-3 text-right font-mono text-slate-500">{{ number_format($lot->fine_weight_total, 3) }}g</td>
                                            <td class="px-4 py-3 text-right font-mono font-bold {{ $isEmpty ? 'text-slate-400' : 'text-emerald-700' }}">
                                                {{ number_format($lot->fine_weight_remaining, 3) }}g
                                                <span class="ml-0.5 text-[10px] font-normal text-slate-400">({{ $pct }}%)</span>
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono text-blue-600">{{ $issued > 0 ? number_format($issued, 3).'g' : '—' }}</td>
                                            <td class="px-4 py-3 text-center">
                                                @if($isEmpty)
                                                    <span class="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">Depleted</span>
                                                @elseif($issued > 0)
                                                    <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700">Partial</span>
                                                @else
                                                    <span class="inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">Available</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="vault-table-view vault-table-view--tablet">
                        <div class="vault-scroll-region overflow-x-auto">
                            <table class="vault-compact-table text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left">Lot</th>
                                        <th class="px-4 py-3 text-left">Profile</th>
                                        <th class="px-4 py-3 text-right">Remaining</th>
                                        <th class="px-4 py-3 text-right">Issued</th>
                                        <th class="px-4 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($lots as $lot)
                                        @php
                                            $issued = (float) $lot->fine_weight_total - (float) $lot->fine_weight_remaining;
                                            $pct = $lot->fine_weight_total > 0 ? round((float) $lot->fine_weight_remaining / (float) $lot->fine_weight_total * 100) : 0;
                                            $isEmpty = (float) $lot->fine_weight_remaining <= 0;
                                        @endphp
                                        <tr class="cursor-pointer hover:bg-slate-50 {{ $isEmpty ? 'opacity-60' : '' }}" onclick="window.location='{{ route('vault.lots.show', $lot) }}'">
                                            <td class="px-4 py-3 align-top">
                                                <a href="{{ route('vault.lots.show', $lot) }}" class="vault-table-link font-mono text-amber-700 hover:underline">#{{ $lot->lot_number }}</a>
                                                <div class="vault-table-meta capitalize">{{ str_replace('_', ' ', $lot->source) }}{{ $lot->vendor ? ' · ' . $lot->vendor->name : '' }}</div>
                                            </td>
                                            <td class="px-4 py-3 align-top">
                                                <div class="vault-table-strong capitalize text-slate-800">{{ $lot->metal_type ?? '—' }} {{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}K</div>
                                                <div class="vault-table-meta">Total fine {{ number_format($lot->fine_weight_total, 3) }}g</div>
                                            </td>
                                            <td class="px-4 py-3 text-right align-top font-mono font-bold {{ $isEmpty ? 'text-slate-400' : 'text-emerald-700' }}">
                                                {{ number_format($lot->fine_weight_remaining, 3) }}g
                                                <div class="vault-table-meta">{{ $pct }}% left</div>
                                            </td>
                                            <td class="px-4 py-3 text-right align-top font-mono text-blue-700">{{ $issued > 0 ? number_format($issued, 3).'g' : '—' }}</td>
                                            <td class="px-4 py-3 text-center align-top">
                                                @if($isEmpty)
                                                    <span class="vault-table-status bg-slate-100 text-slate-500">Depleted</span>
                                                @elseif($issued > 0)
                                                    <span class="vault-table-status bg-blue-100 text-blue-700">Partial</span>
                                                @else
                                                    <span class="vault-table-status bg-emerald-100 text-emerald-700">Available</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="vault-table-view vault-table-view--mobile">
                        <div class="vault-scroll-region--cards">
                            <div class="vault-mobile-list">
                                @foreach($lots as $lot)
                                    @php
                                        $issued = (float) $lot->fine_weight_total - (float) $lot->fine_weight_remaining;
                                        $pct = $lot->fine_weight_total > 0 ? round((float) $lot->fine_weight_remaining / (float) $lot->fine_weight_total * 100) : 0;
                                        $isEmpty = (float) $lot->fine_weight_remaining <= 0;
                                    @endphp
                                    <article class="vault-mobile-card is-clickable {{ $isEmpty ? 'opacity-60' : '' }}" onclick="window.location='{{ route('vault.lots.show', $lot) }}'">
                                        <div class="mb-3 flex items-start justify-between gap-3">
                                            <div>
                                                <a href="{{ route('vault.lots.show', $lot) }}" class="font-mono text-sm font-black text-amber-700">#{{ $lot->lot_number }}</a>
                                                <p class="vault-mobile-kicker capitalize">{{ str_replace('_', ' ', $lot->source) }}{{ $lot->vendor ? ' · ' . $lot->vendor->name : '' }}</p>
                                            </div>
                                            @if($isEmpty)
                                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">Depleted</span>
                                            @elseif($issued > 0)
                                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700">Partial</span>
                                            @else
                                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">Available</span>
                                            @endif
                                        </div>
                                        <div class="vault-mobile-grid">
                                            <div>
                                                <p class="vault-label">Metal / Purity</p>
                                                <p class="vault-mobile-value capitalize">{{ $lot->metal_type ?? '—' }} {{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}K</p>
                                            </div>
                                            <div>
                                                <p class="vault-label">Total Fine</p>
                                                <p class="vault-mobile-value">{{ number_format($lot->fine_weight_total, 3) }}g</p>
                                            </div>
                                            <div>
                                                <p class="vault-label">Remaining</p>
                                                <p class="vault-mobile-value text-emerald-700">{{ number_format($lot->fine_weight_remaining, 3) }}g <span class="text-[11px] font-semibold text-slate-400">({{ $pct }}%)</span></p>
                                            </div>
                                            <div>
                                                <p class="vault-label">Issued</p>
                                                <p class="vault-mobile-value text-blue-700">{{ $issued > 0 ? number_format($issued, 3).'g' : '—' }}</p>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="vault-section-panel" x-show="vaultSection === 'jobs'" x-cloak>
                <div class="vault-section-head">
                    <div>
                        <h2 class="vault-section-title">Open Job Orders</h2>
                        <p class="vault-section-copy">Bullion currently issued to karigars and not fully returned.</p>
                    </div>
                    <a href="{{ route('job-orders.index') }}" class="vault-link">View all</a>
                </div>

                @if($openJobs->isEmpty())
                    <div class="vault-panel-empty">
                        <div>
                            <p class="vault-empty-title">No open job orders</p>
                            <p class="vault-panel-empty-copy">Issued bullion will appear here until it is returned or fully settled back into the vault workflow.</p>
                        </div>
                    </div>
                @else
                    <div class="vault-table-view vault-table-view--desktop">
                        <div class="vault-scroll-region overflow-x-auto">
                            <table class="vault-data-table w-full text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left">Job #</th>
                                        <th class="px-4 py-3 text-left">Karigar</th>
                                        <th class="px-4 py-3 text-left">Purity</th>
                                        <th class="px-4 py-3 text-right">Issued Fine</th>
                                        <th class="px-4 py-3 text-right">Outstanding Fine</th>
                                        <th class="px-4 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($openJobs as $jo)
                                        <tr class="cursor-pointer hover:bg-slate-50" onclick="window.location='{{ route('job-orders.show', $jo) }}'">
                                            <td class="px-4 py-3 font-mono font-bold text-teal-700"><a href="{{ route('job-orders.show', $jo) }}" class="hover:underline">{{ $jo->job_order_number }}</a></td>
                                            <td class="px-4 py-3 text-slate-700">{{ $jo->karigar?->name ?? '—' }}</td>
                                            <td class="px-4 py-3 text-slate-600">{{ rtrim(rtrim(number_format($jo->purity, 2), '0'), '.') }}K</td>
                                            <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                            <td class="px-4 py-3 text-right font-mono font-bold">{{ number_format($jo->outstanding_fine, 3) }}g</td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold {{ $jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="vault-table-view vault-table-view--tablet">
                        <div class="vault-scroll-region overflow-x-auto">
                            <table class="vault-compact-table text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left">Job</th>
                                        <th class="px-4 py-3 text-left">Purity</th>
                                        <th class="px-4 py-3 text-right">Issued</th>
                                        <th class="px-4 py-3 text-right">Outstanding</th>
                                        <th class="px-4 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($openJobs as $jo)
                                        <tr class="cursor-pointer hover:bg-slate-50" onclick="window.location='{{ route('job-orders.show', $jo) }}'">
                                            <td class="px-4 py-3 align-top">
                                                <a href="{{ route('job-orders.show', $jo) }}" class="vault-table-link font-mono text-teal-700 hover:underline">{{ $jo->job_order_number }}</a>
                                                <div class="vault-table-meta">{{ $jo->karigar?->name ?? 'No karigar' }}</div>
                                            </td>
                                            <td class="px-4 py-3 align-top font-semibold text-slate-700">{{ rtrim(rtrim(number_format($jo->purity, 2), '0'), '.') }}K</td>
                                            <td class="px-4 py-3 text-right align-top font-mono text-slate-700">{{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                            <td class="px-4 py-3 text-right align-top font-mono font-bold text-amber-700">{{ number_format($jo->outstanding_fine, 3) }}g</td>
                                            <td class="px-4 py-3 text-center align-top">
                                                <span class="vault-table-status {{ $jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="vault-table-view vault-table-view--mobile">
                        <div class="vault-scroll-region--cards">
                            <div class="vault-mobile-list">
                                @foreach($openJobs as $jo)
                                    <article class="vault-mobile-card is-clickable" onclick="window.location='{{ route('job-orders.show', $jo) }}'">
                                        <div class="mb-3 flex items-start justify-between gap-3">
                                            <div>
                                                <a href="{{ route('job-orders.show', $jo) }}" class="font-mono text-sm font-black text-teal-700">{{ $jo->job_order_number }}</a>
                                                <p class="vault-mobile-kicker">{{ $jo->karigar?->name ?? 'No karigar' }}</p>
                                            </div>
                                            <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                        </div>
                                        <div class="vault-mobile-grid">
                                            <div>
                                                <p class="vault-label">Purity</p>
                                                <p class="vault-mobile-value">{{ rtrim(rtrim(number_format($jo->purity, 2), '0'), '.') }}K</p>
                                            </div>
                                            <div>
                                                <p class="vault-label">Issued Fine</p>
                                                <p class="vault-mobile-value">{{ number_format($jo->issued_fine_weight, 3) }}g</p>
                                            </div>
                                            <div>
                                                <p class="vault-label">Outstanding</p>
                                                <p class="vault-mobile-value text-amber-700">{{ number_format($jo->outstanding_fine, 3) }}g</p>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="vault-section-panel" x-show="vaultSection === 'movements'" x-cloak>
                <div class="vault-section-head">
                    <div>
                        <h2 class="vault-section-title">Recent Vault Movements</h2>
                        <p class="vault-section-copy">Latest credits, debits, issues, returns, and lot transfers.</p>
                    </div>
                    <a href="{{ route('vault.ledger') }}" class="vault-link">Full ledger</a>
                </div>

                @if($recentMovements->isEmpty())
                    <div class="vault-panel-empty">
                        <div>
                            <p class="vault-empty-title">No movements yet</p>
                            <p class="vault-panel-empty-copy">New credits, debits, issues, returns, and transfer entries will appear here as soon as vault activity starts.</p>
                        </div>
                    </div>
                @else
                    <div class="vault-table-view vault-table-view--desktop">
                        <div class="vault-scroll-region overflow-x-auto">
                            <table class="vault-data-table w-full text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left">When</th>
                                        <th class="px-4 py-3 text-left">Type</th>
                                        <th class="px-4 py-3 text-left">From Lot</th>
                                        <th class="px-4 py-3 text-left">To Lot</th>
                                        <th class="px-4 py-3 text-right">Fine Wt</th>
                                        <th class="px-4 py-3 text-left">Reference</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($recentMovements as $mv)
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $mv->created_at->format('d M, H:i') }}</td>
                                            <td class="px-4 py-3"><span class="text-[11px] font-black uppercase text-slate-700">{{ str_replace('_', ' ', $mv->type) }}</span></td>
                                            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $mv->from_lot_id ?? '—' }}</td>
                                            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $mv->to_lot_id ?? '—' }}</td>
                                            <td class="px-4 py-3 text-right font-mono font-bold">{{ number_format($mv->fine_weight, 3) }}g</td>
                                            <td class="px-4 py-3 text-xs text-slate-500">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="vault-table-view vault-table-view--tablet">
                        <div class="vault-scroll-region overflow-x-auto">
                            <table class="vault-compact-table text-sm">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-3 text-left">When / Type</th>
                                        <th class="px-4 py-3 text-left">Lots</th>
                                        <th class="px-4 py-3 text-right">Fine Wt</th>
                                        <th class="px-4 py-3 text-left">Reference</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($recentMovements as $mv)
                                        <tr class="hover:bg-slate-50">
                                            <td class="px-4 py-3 align-top">
                                                <div class="vault-table-strong text-slate-800">{{ $mv->created_at->format('d M, H:i') }}</div>
                                                <div class="vault-table-meta uppercase">{{ str_replace('_', ' ', $mv->type) }}</div>
                                            </td>
                                            <td class="px-4 py-3 align-top">
                                                <div class="vault-table-strong font-mono text-slate-700">{{ $mv->from_lot_id ?? '—' }} → {{ $mv->to_lot_id ?? '—' }}</div>
                                                <div class="vault-table-meta">From / To lot</div>
                                            </td>
                                            <td class="px-4 py-3 text-right align-top font-mono font-bold text-slate-800">{{ number_format($mv->fine_weight, 3) }}g</td>
                                            <td class="px-4 py-3 align-top text-xs text-slate-500">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="vault-table-view vault-table-view--mobile">
                        <div class="vault-scroll-region--cards">
                            <div class="vault-mobile-list">
                                @foreach($recentMovements as $mv)
                                    <article class="vault-mobile-card">
                                        <div class="mb-3 flex items-start justify-between gap-3">
                                            <div>
                                                <p class="text-[11px] font-black uppercase text-slate-700">{{ str_replace('_', ' ', $mv->type) }}</p>
                                                <p class="vault-mobile-kicker">{{ $mv->created_at->format('d M, H:i') }}</p>
                                            </div>
                                            <p class="font-mono text-sm font-black text-amber-700">{{ number_format($mv->fine_weight, 3) }}g</p>
                                        </div>
                                        <div class="vault-mobile-grid">
                                            <div>
                                                <p class="vault-label">From Lot</p>
                                                <p class="vault-mobile-value font-mono">{{ $mv->from_lot_id ?? '—' }}</p>
                                            </div>
                                            <div>
                                                <p class="vault-label">To Lot</p>
                                                <p class="vault-mobile-value font-mono">{{ $mv->to_lot_id ?? '—' }}</p>
                                            </div>
                                            <div>
                                                <p class="vault-label">Reference</p>
                                                <p class="vault-mobile-value">{{ $mv->reference_type }}#{{ $mv->reference_id }}</p>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endif
            </div>
            </section>

            <section class="vault-card vault-mobile-overview-shell vault-mobile-only">
                <button
                    type="button"
                    class="vault-overview-toggle"
                    @click="mobileOverviewOpen = !mobileOverviewOpen"
                    :aria-expanded="mobileOverviewOpen ? 'true' : 'false'"
                >
                    <div>
                        <div class="vault-summary-kicker">Vault Overview</div>
                        <h2 class="vault-section-title">Snapshot & Purity Balances</h2>
                        <p class="vault-section-copy">Quick totals and purity-level distribution for phone-sized screens.</p>
                    </div>
                    <span class="vault-overview-chevron" :class="{ 'is-open': mobileOverviewOpen }" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </span>
                </button>

                <div class="vault-overview-body" x-show="mobileOverviewOpen" x-cloak>
                    <div class="vault-overview-shell-summary">
                        <div class="vault-summary-grid">
                            <div class="vault-summary-stat">
                                <span>In Vault</span>
                                <strong>{{ number_format($vaultFine, 3) }}g</strong>
                            </div>
                            <div class="vault-summary-stat">
                                <span>With Karigar</span>
                                <strong>{{ number_format($karigarFine, 3) }}g</strong>
                            </div>
                            <div class="vault-summary-stat">
                                <span>Total Fine</span>
                                <strong>{{ number_format($totalFine, 3) }}g</strong>
                            </div>
                            <div class="vault-summary-stat">
                                <span>Active Lots</span>
                                <strong>{{ $activeLots }}</strong>
                            </div>
                            <div class="vault-summary-stat">
                                <span>Open Jobs</span>
                                <strong>{{ $openJobsCount }}</strong>
                            </div>
                        </div>
                    </div>

                    @if($balances->isEmpty())
                        <div class="vault-empty-state">
                            <p class="vault-empty-title">No purity balances yet</p>
                            <p class="vault-empty-copy">Add the first bullion lot to begin tracking purity-level positions in the vault.</p>
                            <a href="{{ route('vault.lots.create') }}" class="vault-action vault-action-primary vault-empty-action">Add your first lot</a>
                        </div>
                    @else
                        <div class="vault-overview-purity-rail">
                            @foreach($primaryBalances as $row)
                                @php $purityLabel = rtrim(rtrim(number_format($row['purity'], 2), '0'), '.'); @endphp
                                <article class="vault-purity-card">
                                    <div class="vault-purity-top">
                                        <div>
                                            <p class="vault-label capitalize">{{ $row['metal_type'] ?? 'Metal' }}</p>
                                            <h3 class="text-xl font-black text-slate-950">{{ $purityLabel }}<span class="ml-1 text-xs font-bold text-amber-700">fine</span></h3>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">{{ $row['lots_count'] }} {{ Str::plural('lot', $row['lots_count']) }} linked</p>
                                        </div>
                                        <div class="vault-purity-chip">{{ $purityLabel }}</div>
                                    </div>
                                    <div class="vault-purity-stats">
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">In Vault</p>
                                            <p class="vault-value text-amber-800">{{ number_format($row['in_vault_fine'], 3) }}g</p>
                                        </div>
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">Karigar</p>
                                            <p class="vault-value text-blue-800">{{ number_format($row['with_karigar_fine'], 3) }}g</p>
                                        </div>
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">Total</p>
                                            <p class="vault-value">{{ number_format($row['total_fine'], 3) }}g</p>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                            @foreach($otherBalances as $row)
                                @php $purityLabel = rtrim(rtrim(number_format($row['purity'], 2), '0'), '.'); @endphp
                                <article class="vault-purity-card">
                                    <div class="vault-purity-top">
                                        <div>
                                            <p class="vault-label capitalize">{{ $row['metal_type'] ?? 'Other' }}</p>
                                            <h3 class="text-xl font-black text-slate-950">{{ $purityLabel }}<span class="ml-1 text-xs font-bold text-slate-500">fine</span></h3>
                                            <p class="mt-1 text-xs font-semibold text-slate-500">{{ $row['lots_count'] }} {{ Str::plural('lot', $row['lots_count']) }} linked</p>
                                        </div>
                                        <div class="vault-purity-chip">{{ $purityLabel }}</div>
                                    </div>
                                    <div class="vault-purity-stats">
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">In Vault</p>
                                            <p class="vault-value text-slate-700">{{ number_format($row['in_vault_fine'], 3) }}g</p>
                                        </div>
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">Karigar</p>
                                            <p class="vault-value text-slate-700">{{ number_format($row['with_karigar_fine'], 3) }}g</p>
                                        </div>
                                        <div class="vault-mini-stat">
                                            <p class="vault-label">Total</p>
                                            <p class="vault-value">{{ number_format($row['total_fine'], 3) }}g</p>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <div class="vault-mobile-fab vault-mobile-only">
                <div class="vault-mobile-fab-shell" :class="{ 'is-open': vaultFabOpen }" @click.outside="vaultFabOpen = false">
                    <nav class="vault-mobile-fab-nav" aria-label="Vault quick actions">
                        <a href="{{ route('vault.ledger') }}" class="vault-mobile-fab-link" @click="vaultFabOpen = false">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                            <span>Full Ledger</span>
                        </a>
                        <a href="{{ route('vault.lots.create') }}" class="vault-mobile-fab-link" @click="vaultFabOpen = false">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <span>Add Bullion</span>
                        </a>
                        <a href="{{ route('job-orders.create') }}" class="vault-mobile-fab-link" @click="vaultFabOpen = false">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            <span>Issue to Karigar</span>
                        </a>
                    </nav>

                    <button type="button" class="vault-mobile-fab-toggle" @click="vaultFabOpen = !vaultFabOpen" :aria-expanded="vaultFabOpen.toString()" aria-label="Toggle vault quick actions">
                        <span class="vault-mobile-fab-bars" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
