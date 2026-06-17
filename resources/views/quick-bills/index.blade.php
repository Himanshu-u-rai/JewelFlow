<x-app-layout>
    <x-page-header
        class="quick-bills-index-header"
        title="Quick Bills"
        subtitle="A flexible mini bill register, separate from your main invoices."
    >
        <x-slot:actions>
            @can('sales.create')
                <a href="{{ route('quick-bills.create') }}" class="qb-new-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    <span>New Quick Bill</span>
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <style>
        .qb-page {
            --qb-bg: #f6f7f9;
            --qb-surface: #ffffff;
            --qb-soft: #faf6ee;
            --qb-line: #cbd5e1;
            --qb-line-soft: #e2e8f0;
            --qb-ink: #1f2430;
            --qb-text: #4a4334;
            --qb-muted: #64748b;
            /* Primary accent is the JewelFlow gold, not generic navy. */
            --qb-dark: #b45309;
            --qb-dark-hover: #92400e;
            --qb-blue: #b45309;
            --qb-green: #047857;
            --qb-amber: #b45309;
            --qb-red: #b42318;
            --qb-focus: rgba(245, 158, 11, .2);
            --qb-ease: cubic-bezier(.23, 1, .32, 1);
            width: 100%;
            max-width: none;
            color: var(--qb-ink);
        }

        .qb-page *,
        .qb-page *::before,
        .qb-page *::after {
            box-sizing: border-box;
        }

        .qb-new-btn,
        .qb-btn,
        .qb-action,
        .qb-filter-trigger,
        .qb-chip-clear {
            -webkit-tap-highlight-color: transparent;
        }

        .qb-new-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 9px 15px;
            border: 1px solid var(--qb-dark);
            border-radius: 10px;
            background: var(--qb-dark);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            box-shadow: none;
            transition: background .16s ease, border-color .16s ease, transform .12s var(--qb-ease);
        }

        .qb-new-btn svg {
            width: 16px;
            height: 16px;
        }

        .quick-bills-index-header .page-actions > .qb-new-btn,
        .quick-bills-index-header .page-actions > .qb-new-btn:hover {
            box-shadow: none !important;
        }

        @media (hover: hover) and (pointer: fine) {
            .qb-new-btn:hover {
                background: var(--qb-dark-hover);
                border-color: var(--qb-dark-hover);
            }
        }

        .qb-new-btn:active,
        .qb-btn:active,
        .qb-action:active,
        .qb-filter-trigger:active,
        .qb-chip-clear:active {
            transform: scale(.98);
        }

        .qb-new-btn:focus-visible,
        .qb-btn:focus-visible,
        .qb-action:focus-visible,
        .qb-filter-trigger:focus-visible,
        .qb-chip-clear:focus-visible {
            outline: 3px solid var(--qb-focus);
            outline-offset: 2px;
        }

        .qb-shell {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .qb-stats {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
        }

        .qb-stat {
            min-width: 0;
            display: grid;
            grid-template-columns: 36px minmax(0, 1fr);
            align-items: center;
            column-gap: 12px;
            padding: 14px 15px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 12px;
            background: var(--qb-surface);
        }

        .qb-stat__icon {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--qb-line-soft);
            border-radius: 10px;
            background: #f8fafc;
            color: var(--qb-text);
        }

        .qb-stat__icon svg {
            width: 17px;
            height: 17px;
        }

        .qb-stat__icon.is-success {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: var(--qb-green);
        }

        .qb-stat__icon.is-warning {
            border-color: #fed7aa;
            background: #fff7ed;
            color: var(--qb-amber);
        }

        .qb-stat__icon.is-money {
            border-color: #f3dcb6;
            background: #fdf6ec;
            color: var(--qb-amber);
        }

        .qb-stat__body {
            min-width: 0;
        }

        .qb-stat__label {
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .qb-stat__value {
            overflow-wrap: anywhere;
            color: var(--qb-ink);
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }

        .qb-stat__value.is-money {
            font-size: 18px;
        }

        .qb-register {
            overflow: hidden;
            border: 1px solid var(--qb-line-soft);
            border-radius: 14px;
            background: var(--qb-surface);
        }

        .qb-register__head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 18px;
            border-bottom: 1px solid var(--qb-line-soft);
            background: var(--qb-surface);
        }

        .qb-register__title {
            margin: 0;
            color: var(--qb-ink);
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.3px;
            line-height: 1.2;
        }

        .qb-register__meta {
            margin-top: 4px;
            color: var(--qb-muted);
            font-size: 13px;
            line-height: 1.35;
        }

        .qb-toolbar {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) auto auto auto;
            align-items: end;
            gap: 10px;
            width: min(100%, 960px);
        }

        .qb-field {
            display: grid;
            gap: 6px;
            min-width: 0;
        }

        .qb-field__label {
            color: var(--qb-text);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .qb-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            min-width: 0;
        }

        .qb-input-icon {
            position: absolute;
            left: 12px;
            width: 16px;
            height: 16px;
            color: var(--qb-muted);
            pointer-events: none;
        }

        .qb-page input.qb-control,
        .qb-page select.qb-control {
            width: 100%;
            height: 40px;
            min-height: 40px;
            border: 1px solid var(--qb-line);
            border-radius: 10px;
            background-color: #fff;
            color: var(--qb-ink);
            font: inherit;
            font-size: 14px;
            font-weight: 400;
            line-height: 1;
            box-shadow: none;
            transition: border-color .16s ease, background-color .16s ease, box-shadow .16s ease;
        }

        .qb-page input.qb-control {
            padding: 0 12px;
        }

        .qb-page input.qb-control.has-icon {
            padding-left: 38px;
        }

        .qb-page input.qb-control::placeholder {
            color: #64748b;
        }

        .qb-page select.qb-control {
            min-width: 148px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding: 0 34px 0 12px;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 14px;
        }

        .qb-page input.qb-control[type="date"] {
            min-width: 142px;
            cursor: pointer;
        }

        .qb-page input.qb-control:focus,
        .qb-page select.qb-control:focus {
            outline: none;
            border-color: var(--qb-dark);
            box-shadow: 0 0 0 3px var(--qb-focus);
        }

        .qb-date-row {
            display: grid;
            grid-template-columns: minmax(128px, 1fr) minmax(128px, 1fr);
            gap: 8px;
        }

        .qb-btn-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .qb-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            height: 40px;
            padding: 0 14px;
            border-radius: 10px;
            border: 1px solid var(--qb-dark);
            background: var(--qb-dark);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            cursor: pointer;
            transition: background .16s ease, border-color .16s ease, color .16s ease, transform .12s var(--qb-ease);
        }

        .qb-btn.is-secondary {
            border-color: var(--qb-line);
            background: #fff;
            color: var(--qb-text);
        }

        .qb-btn svg,
        .qb-filter-trigger svg,
        .qb-action svg {
            width: 15px;
            height: 15px;
            flex: 0 0 auto;
        }

        @media (hover: hover) and (pointer: fine) {
            .qb-btn:hover {
                background: var(--qb-dark-hover);
                border-color: var(--qb-dark-hover);
            }

            .qb-btn.is-secondary:hover {
                background: var(--qb-soft);
                border-color: var(--qb-line);
                color: var(--qb-ink);
            }
        }

        .qb-active-filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            border-bottom: 1px solid var(--qb-line-soft);
            background: #fbfcfd;
        }

        .qb-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 28px;
            max-width: 100%;
            padding: 5px 10px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 999px;
            background: #fff;
            color: var(--qb-text);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .qb-chip strong {
            color: var(--qb-ink);
            font-weight: 600;
        }

        .qb-chip-clear {
            color: var(--qb-dark);
            text-decoration: none;
        }

        .qb-mobile-tools {
            display: none;
            padding: 12px;
            border-bottom: 1px solid var(--qb-line-soft);
            background: var(--qb-surface);
        }

        .qb-mobile-search {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
        }

        .qb-mobile-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }

        .qb-filter-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 13px;
            border: 1px solid var(--qb-line);
            border-radius: 10px;
            background: #fff;
            color: var(--qb-ink);
            font-size: 14px;
            font-weight: 600;
            line-height: 1;
            cursor: pointer;
            transition: background .16s ease, border-color .16s ease, transform .12s var(--qb-ease);
        }

        .qb-filter-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--qb-dark);
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
        }

        .qb-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .qb-table {
            width: 100%;
            border-collapse: collapse;
        }

        .qb-table th {
            padding: 12px 18px;
            border-bottom: 1px solid var(--qb-line-soft);
            background: #f8fafc;
            color: var(--qb-text);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            text-align: left;
            white-space: nowrap;
        }

        .qb-table th.is-right,
        .qb-table td.is-right {
            text-align: right;
        }

        .qb-table td {
            padding: 15px 18px;
            border-bottom: 1px solid #edf2f7;
            color: var(--qb-text);
            font-size: 15px;
            font-weight: 400;
            line-height: 1.35;
            vertical-align: middle;
        }

        .qb-table tbody tr:last-child td {
            border-bottom: 0;
        }

        @media (hover: hover) and (pointer: fine) {
            .qb-table tbody tr:hover {
                background: #fbfcfd;
            }
        }

        .qb-billno {
            color: var(--qb-ink);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.2;
        }

        .qb-sub {
            margin-top: 3px;
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 400;
            line-height: 1.25;
        }

        .qb-name {
            color: var(--qb-ink);
            font-size: 15px;
            font-weight: 500;
            line-height: 1.25;
        }

        .qb-amount {
            color: var(--qb-ink);
            font-size: 15px;
            font-weight: 500;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .qb-due-0 {
            color: var(--qb-green);
            font-weight: 500;
        }

        .qb-due-pos {
            color: var(--qb-amber);
            font-weight: 600;
        }

        .qb-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 26px;
            padding: 5px 10px;
            border: 1px solid transparent;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
        }

        .qb-badge.is-issued {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #047857;
        }

        .qb-badge.is-draft {
            border-color: #fed7aa;
            background: #fff7ed;
            color: #b45309;
        }

        .qb-badge.is-void {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b42318;
        }

        .qb-actions {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .qb-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 36px;
            padding: 8px 11px;
            border: 1px solid var(--qb-line);
            border-radius: 9px;
            background: #fff;
            color: var(--qb-text);
            font-size: 13px;
            font-weight: 600;
            line-height: 1;
            text-decoration: none;
            transition: background .16s ease, border-color .16s ease, color .16s ease, transform .12s var(--qb-ease);
        }

        .qb-action.is-view {
            border-color: var(--qb-dark);
            background: var(--qb-dark);
            color: #fff;
        }

        .qb-action.is-print {
            border-color: #f3dcb6;
            background: #fdf6ec;
            color: var(--qb-amber);
        }

        @media (hover: hover) and (pointer: fine) {
            .qb-action:hover {
                background: var(--qb-soft);
                border-color: var(--qb-line);
                color: var(--qb-ink);
            }

            .qb-action.is-view:hover {
                background: var(--qb-dark-hover);
                border-color: var(--qb-dark-hover);
                color: #fff;
            }

            .qb-action.is-print:hover {
                background: #fbecd2;
                border-color: #e9c98c;
                color: #92400e;
            }
        }

        .qb-cards {
            display: none;
            padding: 12px;
            background: var(--qb-bg);
        }

        .qb-card {
            border: 1px solid var(--qb-line-soft);
            border-radius: 12px;
            background: #fff;
            overflow: hidden;
        }

        .qb-card + .qb-card {
            margin-top: 10px;
        }

        .qb-card__main {
            padding: 13px 14px;
        }

        .qb-card__top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .qb-card__identity {
            min-width: 0;
        }

        .qb-card__customer {
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--qb-line-soft);
        }

        .qb-card__amounts {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 12px;
        }

        .qb-card__metric {
            min-width: 0;
            padding: 10px 11px;
            border: 1px solid var(--qb-line-soft);
            border-radius: 10px;
            background: #f8fafc;
        }

        .qb-card__label {
            color: var(--qb-muted);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.2;
        }

        .qb-card__value {
            margin-top: 3px;
            color: var(--qb-ink);
            font-size: 15px;
            font-weight: 600;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
            overflow-wrap: anywhere;
        }

        .qb-card__actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            padding: 10px;
            border-top: 1px solid var(--qb-line-soft);
            background: #fbfcfd;
        }

        .qb-card__actions .qb-action {
            width: 100%;
            min-height: 40px;
        }

        .qb-pagination {
            padding: 14px 18px;
            border-top: 1px solid var(--qb-line-soft);
            background: #fff;
        }

        .qb-empty {
            padding: 46px 20px;
            text-align: center;
        }

        .qb-filter-backdrop {
            position: fixed;
            inset: 0;
            z-index: 80;
            background: rgba(15, 23, 42, .42);
        }

        .qb-filter-sheet {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 90;
            max-height: min(82vh, 680px);
            min-height: min(560px, 72vh);
            display: flex;
            flex-direction: column;
            border: 1px solid var(--qb-line-soft);
            border-bottom: 0;
            border-radius: 16px 16px 0 0;
            background: #fff;
            overflow: hidden;
        }

        .qb-filter-sheet__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--qb-line-soft);
        }

        .qb-filter-sheet__title {
            margin: 0;
            color: var(--qb-ink);
            font-size: 16px;
            font-weight: 600;
            line-height: 1.2;
        }

        .qb-filter-sheet__body {
            display: grid;
            gap: 14px;
            padding: 16px;
            overflow-y: auto;
        }

        .qb-filter-sheet__foot {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 10px;
            padding: 12px 16px 16px;
            border-top: 1px solid var(--qb-line-soft);
            background: #fff;
        }

        .qb-filter-close {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--qb-line);
            border-radius: 10px;
            background: #fff;
            color: var(--qb-ink);
            cursor: pointer;
        }

        .qb-filter-close svg {
            width: 17px;
            height: 17px;
        }

        [x-cloak] {
            display: none !important;
        }

        @media (max-width: 1240px) {
            .qb-stats {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .qb-toolbar {
                grid-template-columns: minmax(260px, 1fr) auto auto;
            }

            .qb-toolbar .qb-btn-row {
                grid-column: 1 / -1;
                justify-content: flex-end;
            }
        }

        @media (max-width: 860px) {
            .quick-bills-index-header {
                flex-wrap: nowrap;
                align-items: center;
                gap: 8px;
            }

            .quick-bills-index-header > :nth-child(n+3) {
                flex: 0 0 auto;
            }

            .quick-bills-index-header .content-header-nav {
                margin-right: 0;
                padding-top: 0;
            }

            .quick-bills-index-header .page-title {
                margin-left: 2px;
                font-size: 17px;
            }

            .quick-bills-index-header .page-subtitle {
                display: none;
            }

            .quick-bills-index-header .page-actions {
                width: auto;
                margin-left: auto;
                justify-content: flex-end;
            }

            .quick-bills-index-header .qb-new-btn {
                width: 40px;
                min-height: 38px;
                padding: 0;
            }

            .quick-bills-index-header .qb-new-btn span {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            .qb-page {
                margin-left: -4px;
                margin-right: -4px;
            }

            .qb-shell {
                gap: 12px;
            }

            .qb-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .qb-stat {
                min-height: 82px;
                grid-template-columns: 30px minmax(0, 1fr);
                column-gap: 10px;
                padding: 11px;
                justify-content: center;
            }

            .qb-stat__icon {
                width: 30px;
                height: 30px;
                border-radius: 9px;
            }

            .qb-stat__icon svg {
                width: 15px;
                height: 15px;
            }

            .qb-stat__label {
                font-size: 11px;
            }

            .qb-stat__value {
                font-size: 19px;
            }

            .qb-stat__value.is-money {
                font-size: 16px;
            }

            .qb-stat:last-child:nth-child(odd) {
                grid-column: 1 / -1;
            }

            .qb-register {
                border-radius: 12px;
            }

            .qb-register__head {
                display: none;
            }

            .qb-mobile-tools {
                display: block;
            }

            .qb-active-filters {
                padding: 10px 12px;
                gap: 6px;
            }

            .qb-chip {
                font-size: 11px;
            }

            .qb-table-wrap {
                display: none;
            }

            .qb-cards {
                display: block;
            }

            .qb-pagination {
                padding: 12px;
            }
        }

        @media (max-width: 460px) {
            .qb-mobile-search {
                grid-template-columns: minmax(0, 1fr) 44px;
            }

            .qb-mobile-search .qb-btn span {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            .qb-mobile-search .qb-btn {
                padding: 0;
            }

            .qb-mobile-actions {
                align-items: stretch;
            }

            .qb-filter-trigger,
            .qb-mobile-actions .qb-btn {
                flex: 1 1 0;
            }

            .qb-card__top {
                gap: 8px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .qb-new-btn,
            .qb-btn,
            .qb-action,
            .qb-filter-trigger,
            .qb-chip-clear,
            .qb-page input.qb-control,
            .qb-page select.qb-control {
                transition: none;
            }
        }
    </style>

    @php
        $statusMeta = [
            'issued' => ['cls' => 'is-issued', 'label' => 'Issued'],
            'draft'  => ['cls' => 'is-draft',  'label' => 'Draft'],
            'void'   => ['cls' => 'is-void',   'label' => 'Void'],
        ];
        $hasFilters = request()->hasAny(['search', 'status', 'from_date', 'to_date']);
        $activeFilterCount = collect(['search', 'status', 'from_date', 'to_date'])->filter(fn ($key) => filled(request($key)))->count();
        $activeStatus = request('status') ? ($statusMeta[request('status')]['label'] ?? ucfirst((string) request('status'))) : null;
        $fromDate = request('from_date');
        $toDate = request('to_date');
        $dateSummary = $fromDate && $toDate ? "{$fromDate} to {$toDate}" : ($fromDate ? "From {$fromDate}" : ($toDate ? "Until {$toDate}" : null));
        $resultStart = $quickBills->firstItem() ?? 0;
        $resultEnd = $quickBills->lastItem() ?? 0;
        $resultTotal = $quickBills->total();
    @endphp

    <div
        class="content-inner qb-page"
        x-data="{ filterOpen: false }"
        x-effect="document.body.style.overflow = filterOpen ? 'hidden' : ''"
        @keydown.escape.window="filterOpen = false"
    >
        @unless(auth()->user()->can('sales.create'))
            @include('partials.view-only-banner', ['permission' => 'sales.create', 'message' => 'creating quick bills'])
        @endunless

        <div class="qb-shell">
            <div class="qb-stats" aria-label="Quick bill summary">
                <div class="qb-stat">
                    <span class="qb-stat__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h10M8 10h10M8 14h7M5 4h.01M5 10h.01M5 16h.01M4 20h16"/>
                        </svg>
                    </span>
                    <div class="qb-stat__body">
                        <div class="qb-stat__label">Total bills</div>
                        <div class="qb-stat__value">{{ number_format($stats['total_count']) }}</div>
                    </div>
                </div>
                <div class="qb-stat">
                    <span class="qb-stat__icon is-success" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 6 9 17l-5-5"/>
                        </svg>
                    </span>
                    <div class="qb-stat__body">
                        <div class="qb-stat__label">Issued</div>
                        <div class="qb-stat__value">{{ number_format($stats['issued_count']) }}</div>
                    </div>
                </div>
                <div class="qb-stat">
                    <span class="qb-stat__icon is-warning" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>
                        </svg>
                    </span>
                    <div class="qb-stat__body">
                        <div class="qb-stat__label">Drafts</div>
                        <div class="qb-stat__value">{{ number_format($stats['draft_count']) }}</div>
                    </div>
                </div>
                <div class="qb-stat">
                    <span class="qb-stat__icon is-money" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 4h12M6 8h12M7 4c5 0 7 2 7 5s-2 5-7 5l8 6"/>
                        </svg>
                    </span>
                    <div class="qb-stat__body">
                        <div class="qb-stat__label">Today's value</div>
                        <div class="qb-stat__value is-money">₹{{ number_format((float) $stats['today_total'], 2) }}</div>
                    </div>
                </div>
                <div class="qb-stat">
                    <span class="qb-stat__icon is-warning" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 17h.01"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
                        </svg>
                    </span>
                    <div class="qb-stat__body">
                        <div class="qb-stat__label">Outstanding</div>
                        <div class="qb-stat__value is-money">₹{{ number_format((float) $stats['outstanding_total'], 2) }}</div>
                    </div>
                </div>
            </div>

            <section class="qb-register" aria-label="Quick bill register">
                <div class="qb-register__head">
                    <div>
                        <h2 class="qb-register__title">Bill register</h2>
                        <div class="qb-register__meta">
                            @if($resultTotal)
                                Showing {{ number_format($resultStart) }}-{{ number_format($resultEnd) }} of {{ number_format($resultTotal) }}
                            @else
                                No records found
                            @endif
                        </div>
                    </div>

                    <form method="GET" action="{{ route('quick-bills.index') }}" class="qb-toolbar" data-qb-filter-form>
                        <label class="qb-field">
                            <span class="qb-field__label">Search</span>
                            <span class="qb-input-wrap">
                                <svg class="qb-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
                                </svg>
                                <input class="qb-control has-icon" type="text" name="search" value="{{ request('search') }}" placeholder="Bill number, customer or mobile" data-suggest="quick-bills" autocomplete="off">
                            </span>
                        </label>

                        <label class="qb-field">
                            <span class="qb-field__label">Status</span>
                            <select name="status" class="qb-control" data-qb-autosubmit>
                                <option value="">All statuses</option>
                                <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                                <option value="issued" @selected(request('status') === 'issued')>Issued</option>
                                <option value="void" @selected(request('status') === 'void')>Void</option>
                            </select>
                        </label>

                        <label class="qb-field">
                            <span class="qb-field__label">Bill date</span>
                            <span class="qb-date-row">
                                <input class="qb-control" type="date" name="from_date" value="{{ request('from_date') }}" aria-label="From date" data-qb-autosubmit>
                                <input class="qb-control" type="date" name="to_date" value="{{ request('to_date') }}" aria-label="To date" data-qb-autosubmit>
                            </span>
                        </label>

                        <span class="qb-btn-row">
                            <button type="submit" class="qb-btn">Apply</button>
                            @if($hasFilters)
                                <a href="{{ route('quick-bills.index') }}" class="qb-btn is-secondary">Clear</a>
                            @endif
                        </span>
                    </form>
                </div>

                <div class="qb-mobile-tools">
                    <form method="GET" action="{{ route('quick-bills.index') }}" class="qb-mobile-search" data-qb-filter-form>
                        <input type="hidden" name="status" value="{{ request('status') }}">
                        <input type="hidden" name="from_date" value="{{ request('from_date') }}">
                        <input type="hidden" name="to_date" value="{{ request('to_date') }}">
                        <label class="qb-input-wrap">
                            <svg class="qb-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"/>
                                <path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
                            </svg>
                            <input class="qb-control has-icon" type="text" name="search" value="{{ request('search') }}" placeholder="Search quick bills" data-suggest="quick-bills" autocomplete="off" aria-label="Search quick bills">
                        </label>
                        <button type="submit" class="qb-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"/>
                                <path stroke-linecap="round" d="M21 21l-4.35-4.35"/>
                            </svg>
                            <span>Search</span>
                        </button>
                    </form>

                    <div class="qb-mobile-actions">
                        <button type="button" class="qb-filter-trigger" @click="filterOpen = true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M7 12h10M10 18h4"/>
                            </svg>
                            <span>Filters</span>
                            @if($activeFilterCount)
                                <span class="qb-filter-count">{{ $activeFilterCount }}</span>
                            @endif
                        </button>
                        @if($hasFilters)
                            <a href="{{ route('quick-bills.index') }}" class="qb-btn is-secondary">Clear</a>
                        @endif
                    </div>
                </div>

                @if($hasFilters)
                    <div class="qb-active-filters" aria-label="Active filters">
                        @if(request('search'))
                            <span class="qb-chip">Search <strong>{{ request('search') }}</strong></span>
                        @endif
                        @if($activeStatus)
                            <span class="qb-chip">Status <strong>{{ $activeStatus }}</strong></span>
                        @endif
                        @if($dateSummary)
                            <span class="qb-chip">Date <strong>{{ $dateSummary }}</strong></span>
                        @endif
                        <a href="{{ route('quick-bills.index') }}" class="qb-chip qb-chip-clear">Clear all</a>
                    </div>
                @endif

                @if($quickBills->count())
                    <div class="qb-table-wrap">
                        <table class="qb-table">
                            <thead>
                                <tr>
                                    <th>Bill</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th class="is-right">Total</th>
                                    <th class="is-right">Due</th>
                                    <th>Status</th>
                                    <th class="is-right">Actions</th>
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
                                        <td>{{ $quickBill->bill_date?->format('d M Y') }}</td>
                                        <td class="is-right">
                                            <span class="qb-amount">₹{{ number_format((float) $quickBill->total_amount, 2) }}</span>
                                        </td>
                                        <td class="is-right">
                                            <span class="{{ (float) $quickBill->due_amount > 0 ? 'qb-due-pos' : 'qb-due-0' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</span>
                                        </td>
                                        <td>
                                            <span class="qb-badge {{ $meta['cls'] }}">{{ $meta['label'] }}</span>
                                        </td>
                                        <td class="is-right">
                                            <div class="qb-actions">
                                                <a href="{{ route('quick-bills.show', $quickBill) }}" class="qb-action is-view">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>
                                                        <circle cx="12" cy="12" r="3"/>
                                                    </svg>
                                                    View
                                                </a>
                                                <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="qb-action is-print">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>
                                                    </svg>
                                                    Print
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="qb-cards">
                        @foreach($quickBills as $quickBill)
                            @php $meta = $statusMeta[$quickBill->status] ?? $statusMeta['draft']; @endphp
                            <article class="qb-card">
                                <div class="qb-card__main">
                                    <div class="qb-card__top">
                                        <div class="qb-card__identity">
                                            <div class="qb-billno">{{ $quickBill->bill_number }}</div>
                                            <div class="qb-sub">{{ $quickBill->bill_date?->format('d M Y') }}</div>
                                        </div>
                                        <span class="qb-badge {{ $meta['cls'] }}">{{ $meta['label'] }}</span>
                                    </div>

                                    <div class="qb-card__customer">
                                        <div class="qb-name">{{ $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in customer') }}</div>
                                        <div class="qb-sub">{{ $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: 'No mobile') }}</div>
                                    </div>

                                    <div class="qb-card__amounts">
                                        <div class="qb-card__metric">
                                            <div class="qb-card__label">Total</div>
                                            <div class="qb-card__value">₹{{ number_format((float) $quickBill->total_amount, 2) }}</div>
                                        </div>
                                        <div class="qb-card__metric">
                                            <div class="qb-card__label">Due</div>
                                            <div class="qb-card__value {{ (float) $quickBill->due_amount > 0 ? 'qb-due-pos' : 'qb-due-0' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="qb-card__actions">
                                    <a href="{{ route('quick-bills.show', $quickBill) }}" class="qb-action is-view">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>
                                            <circle cx="12" cy="12" r="3"/>
                                        </svg>
                                        View
                                    </a>
                                    <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="qb-action is-print">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>
                                        </svg>
                                        Print
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    @if($quickBills->hasPages())
                        <div class="qb-pagination">{{ $quickBills->links() }}</div>
                    @endif
                @else
                    <div class="qb-empty">
                        <x-empty-state
                            title="{{ $hasFilters ? 'No quick bills match your filters' : 'No quick bills yet' }}"
                            description="{{ $hasFilters ? 'Try clearing the filters to see all bills.' : 'Create a small flexible jewellery bill without affecting the main invoice system.' }}"
                        />
                    </div>
                @endif

                <div x-show="filterOpen" x-transition.opacity x-cloak class="qb-filter-backdrop" @click="filterOpen = false"></div>
                <aside x-show="filterOpen" x-transition x-cloak class="qb-filter-sheet" role="dialog" aria-modal="true" aria-label="Quick bill filters">
                    <div class="qb-filter-sheet__head">
                        <h3 class="qb-filter-sheet__title">Filters</h3>
                        <button type="button" class="qb-filter-close" @click="filterOpen = false" aria-label="Close filters">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 6L6 18M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <form method="GET" action="{{ route('quick-bills.index') }}">
                        <input type="hidden" name="search" value="{{ request('search') }}">
                        <div class="qb-filter-sheet__body">
                            <label class="qb-field">
                                <span class="qb-field__label">Status</span>
                                <select name="status" class="qb-control">
                                    <option value="">All statuses</option>
                                    <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                                    <option value="issued" @selected(request('status') === 'issued')>Issued</option>
                                    <option value="void" @selected(request('status') === 'void')>Void</option>
                                </select>
                            </label>

                            <label class="qb-field">
                                <span class="qb-field__label">From date</span>
                                <input class="qb-control" type="date" name="from_date" value="{{ request('from_date') }}">
                            </label>

                            <label class="qb-field">
                                <span class="qb-field__label">To date</span>
                                <input class="qb-control" type="date" name="to_date" value="{{ request('to_date') }}">
                            </label>
                        </div>

                        <div class="qb-filter-sheet__foot">
                            <a href="{{ route('quick-bills.index') }}" class="qb-btn is-secondary">Clear</a>
                            <button type="submit" class="qb-btn">Apply filters</button>
                        </div>
                    </form>
                </aside>
            </section>
        </div>
    </div>

    <script>
        (function () {
            const bind = () => {
                document.querySelectorAll('[data-qb-filter-form]').forEach((form) => {
                    if (form.dataset.qbBound === '1') return;
                    form.dataset.qbBound = '1';

                    form.querySelectorAll('[data-qb-autosubmit]').forEach((el) => {
                        el.addEventListener('change', () => form.requestSubmit());
                    });

                    const search = form.querySelector('input[name="search"]');
                    if (search) {
                        search.addEventListener('blur', () => {
                            if (search.value !== (search.defaultValue || '')) {
                                form.requestSubmit();
                            }
                        });
                    }
                });
            };

            const unlockBody = () => {
                if (document.querySelector('.qb-page')) {
                    document.body.style.overflow = '';
                }
            };

            document.addEventListener('turbo:load', bind);
            document.addEventListener('turbo:before-render', unlockBody);
            document.addEventListener('turbo:before-cache', unlockBody);
            window.addEventListener('beforeunload', unlockBody);
            document.addEventListener('DOMContentLoaded', bind);
            bind();
        })();
    </script>
</x-app-layout>
