<x-app-layout>
    <style>
        .vault-ledger-shell {
            max-width: 1500px;
        }

        .vault-ledger-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            background: #ffffff;
            padding: 9px 14px;
            color: #1f2a44;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .06);
        }

        .vault-ledger-card {
            border: 1px solid #dbe3ee;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
        }

        .vault-ledger-filter {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) repeat(2, minmax(150px, 190px)) auto;
            gap: 12px;
            align-items: end;
            padding: 16px;
        }

        .vault-ledger-label {
            display: block;
            margin-bottom: 6px;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .vault-ledger-control {
            width: 100%;
            min-height: 42px;
            border-radius: 13px;
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
        }

        .vault-ledger-control:focus {
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .vault-ledger-filter-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border: 1px solid #0f766e;
            border-radius: 13px;
            background: #0f766e;
            padding: 10px 16px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15, 118, 110, .16);
        }

        .vault-ledger-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .vault-ledger-title {
            margin: 0;
            color: #0f172a;
            font-size: 14px;
            font-weight: 900;
        }

        .vault-ledger-copy {
            margin-top: 2px;
            color: #64748b;
            font-size: 12px;
        }

        .vault-ledger-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #dbe3ee;
            padding: 6px 11px;
            color: #475569;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .vault-ledger-table {
            min-width: 980px;
        }

        .vault-ledger-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .vault-ledger-type {
            display: inline-flex;
            border-radius: 999px;
            background: #f1f5f9;
            padding: 4px 8px;
            color: #334155;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .vault-ledger-mobile {
            display: none;
        }

        .vault-ledger-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            background: #ffffff;
            padding: 14px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .vault-ledger-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .vault-ledger-mobile-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
        }

        @media (max-width: 860px) {
            .vault-ledger-filter {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .vault-ledger-filter-button {
                width: 100%;
            }
        }

        @media (max-width: 680px) {
            .vault-ledger-shell {
                padding-inline: 10px;
            }

            .vault-ledger-card {
                border-radius: 16px;
            }

            .vault-ledger-filter {
                grid-template-columns: 1fr;
                padding: 14px;
            }

            .vault-ledger-head {
                flex-direction: column;
                gap: 10px;
                padding: 16px;
            }

            .vault-ledger-desktop {
                display: none;
            }

            .vault-ledger-mobile {
                display: block;
                padding: 14px;
            }

            .vault-ledger-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <x-page-header title="Vault Ledger" subtitle="All bullion movements across this shop">
        <x-slot:actions>
            <a href="{{ route('vault.index') }}" class="vault-ledger-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                Vault
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner vault-ledger-shell space-y-5">
        <form method="GET" class="vault-ledger-card vault-ledger-filter">
            <label>
                <span class="vault-ledger-label">Movement Type</span>
                <select name="type" class="vault-ledger-control">
                    <option value="">All movements</option>
                    @foreach($types as $t)
                        <option value="{{ $t }}" {{ $type === $t ? 'selected' : '' }}>{{ str_replace('_', ' ', $t) }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span class="vault-ledger-label">From</span>
                <input type="date" name="from" value="{{ $from }}" class="vault-ledger-control">
            </label>

            <label>
                <span class="vault-ledger-label">To</span>
                <input type="date" name="to" value="{{ $to }}" class="vault-ledger-control">
            </label>

            <button type="submit" class="vault-ledger-filter-button">Filter Ledger</button>
        </form>

        <section class="vault-ledger-card overflow-hidden">
            <div class="vault-ledger-head">
                <div>
                    <h2 class="vault-ledger-title">Movement History</h2>
                    <p class="vault-ledger-copy">Credits, debits, issues, returns, and lot transfers matching the selected filters.</p>
                </div>
                <span class="vault-ledger-count">{{ $movements->total() }} {{ Str::plural('movement', $movements->total()) }}</span>
            </div>

            <div class="vault-ledger-desktop overflow-x-auto">
                <table class="vault-ledger-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left">When</th>
                            <th class="px-4 py-3 text-left">Type</th>
                            <th class="px-4 py-3 text-left">From Lot</th>
                            <th class="px-4 py-3 text-left">To Lot</th>
                            <th class="px-4 py-3 text-right">Fine Wt</th>
                            <th class="px-4 py-3 text-left">Reference</th>
                            <th class="px-4 py-3 text-right">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($movements as $mv)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $mv->created_at->format('d M Y, H:i') }}</td>
                                <td class="px-4 py-3"><span class="vault-ledger-type">{{ str_replace('_', ' ', $mv->type) }}</span></td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $mv->from_lot_id ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $mv->to_lot_id ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-mono font-bold text-amber-700">{{ number_format($mv->fine_weight, 3) }}g</td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ $mv->reference_type }}#{{ $mv->reference_id }}</td>
                                <td class="px-4 py-3 text-right text-xs text-slate-500">{{ $mv->user_id }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-400">No movements match your filter.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="vault-ledger-mobile space-y-3">
                @forelse($movements as $mv)
                    <article class="vault-ledger-mobile-card">
                        <div class="mb-3 flex items-start justify-between gap-3">
                            <div>
                                <span class="vault-ledger-type">{{ str_replace('_', ' ', $mv->type) }}</span>
                                <p class="mt-1 text-xs text-slate-500">{{ $mv->created_at->format('d M Y, H:i') }}</p>
                            </div>
                            <p class="font-mono text-sm font-black text-amber-700">{{ number_format($mv->fine_weight, 3) }}g</p>
                        </div>

                        <div class="vault-ledger-mobile-grid text-sm">
                            <div>
                                <p class="vault-ledger-mobile-label">From Lot</p>
                                <p class="font-mono font-semibold text-slate-700">{{ $mv->from_lot_id ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="vault-ledger-mobile-label">To Lot</p>
                                <p class="font-mono font-semibold text-slate-700">{{ $mv->to_lot_id ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="vault-ledger-mobile-label">Reference</p>
                                <p class="text-slate-600">{{ $mv->reference_type }}#{{ $mv->reference_id }}</p>
                            </div>
                            <div>
                                <p class="vault-ledger-mobile-label">By</p>
                                <p class="text-slate-600">{{ $mv->user_id }}</p>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-400">No movements match your filter.</div>
                @endforelse
            </div>

            <div class="border-t border-slate-200 p-4">{{ $movements->links() }}</div>
        </section>
    </div>
</x-app-layout>
