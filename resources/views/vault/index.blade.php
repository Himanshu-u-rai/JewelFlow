<x-app-layout>
    <style>
        .vault-shell {
            max-width: 1500px;
        }

        .vault-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            border-radius: 12px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 800;
            transition: all .18s ease;
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
            border: 1px solid #0f766e;
            background: #0f766e;
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(15, 118, 110, .22);
        }

        .vault-summary {
            border: 1px solid #dbe3ee;
            border-radius: 18px;
            background: #ffffff;
            padding: 16px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, .06);
        }

        .vault-top-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            align-items: stretch;
        }

        .vault-summary-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 12px;
        }

        .vault-summary-kicker {
            color: #b45309;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .vault-summary-title {
            margin-top: 2px;
            color: #0f172a;
            font-size: 18px;
            font-weight: 900;
            letter-spacing: -.02em;
        }

        .vault-summary-note {
            max-width: 560px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        .vault-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(136px, 1fr));
            gap: 10px;
        }

        .vault-summary-stat {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #f8fafc;
            padding: 11px 12px;
            min-width: 0;
        }

        .vault-summary-stat span {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
        }

        .vault-summary-stat strong {
            display: block;
            margin-top: 4px;
            color: #0f172a;
            font-size: 20px;
            line-height: 1.1;
            font-weight: 900;
        }

        .vault-card {
            border: 1px solid #dbe3ee;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
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
            border-color: #0f766e;
            color: #0f766e;
            background: #f0fdfa;
        }

        .vault-balance-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .vault-balance-card {
            position: relative;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            background: #ffffff;
            padding: 16px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, .06);
            min-height: 100%;
        }

        .vault-purity-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 999px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
            color: #92400e;
            box-shadow: inset 0 0 0 5px #fef3c7, 0 10px 18px rgba(245, 158, 11, .16);
            font-size: 16px;
            font-weight: 950;
        }

        .vault-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
        }

        .vault-value {
            color: #0f172a;
            font-weight: 900;
            line-height: 1.1;
        }

        .vault-mini-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-top: 14px;
        }

        .vault-mini-stat {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border: 1px solid #fde68a;
            border-radius: 12px;
            background: #fffdf5;
            padding: 9px 11px;
            min-width: 0;
        }

        .vault-data-table {
            min-width: 960px;
        }

        .vault-data-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .vault-mobile-list {
            display: none;
        }

        .vault-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            background: #ffffff;
            padding: 14px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .vault-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        @media (max-width: 680px) {
            .vault-shell {
                padding-inline: 10px;
            }

            .vault-top-grid {
                grid-template-columns: 1fr;
            }

            .vault-summary {
                border-radius: 16px;
                padding: 12px;
            }

            .vault-summary-head {
                margin-bottom: 10px;
            }

            .vault-summary-title {
                font-size: 15px;
            }

            .vault-summary-note {
                display: none;
            }

            .vault-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .vault-summary-stat {
                padding: 10px;
            }

            .vault-summary-stat strong {
                margin-top: 3px;
                font-size: 16px;
            }

            .vault-section-head {
                flex-direction: column;
                gap: 10px;
                padding: 16px;
            }

            .vault-link {
                width: 100%;
            }

            .vault-mini-grid,
            .vault-mobile-grid {
                grid-template-columns: 1fr;
            }

            .vault-desktop-table {
                display: none;
            }

            .vault-mobile-list {
                display: block;
                padding: 14px;
            }
        }

        @media (min-width: 390px) and (max-width: 680px) {
            .vault-top-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }

            .vault-summary-grid {
                grid-template-columns: 1fr;
            }

            .vault-summary-stat {
                padding: 8px;
            }

            .vault-summary-stat span {
                font-size: 10px;
            }

            .vault-summary-stat strong {
                font-size: 14px;
            }

            .vault-balance-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .vault-balance-card {
                border-radius: 16px;
                padding: 12px;
            }

            .vault-purity-chip {
                width: 44px;
                height: 44px;
                box-shadow: inset 0 0 0 4px #fef3c7, 0 8px 14px rgba(245, 158, 11, .14);
                font-size: 13px;
            }

            .vault-mini-grid {
                gap: 7px;
                margin-top: 12px;
            }

            .vault-mini-stat {
                align-items: flex-start;
                flex-direction: column;
                gap: 2px;
                padding: 8px;
            }
        }
    </style>

    @php
        $vaultFine = (float) $balances->sum('in_vault_fine');
        $karigarFine = (float) $balances->sum('with_karigar_fine');
        $totalFine = (float) $balances->sum('total_fine');
        $activeLots = $lots->filter(fn ($lot) => (float) $lot->fine_weight_remaining > 0)->count();
        $depletedLots = $lots->count() - $activeLots;
    @endphp

    <x-page-header title="Bullion Vault" subtitle="Real-time fine-weight balances per purity">
        <x-slot:actions>
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
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner vault-shell space-y-5 xl:space-y-6">
        <x-app-alerts class="mb-2" />

        <div class="vault-top-grid">
            <section class="vault-summary">
                <div class="vault-summary-head">
                    <div>
                        <div class="vault-summary-kicker">Vault Snapshot</div>
                        <h2 class="vault-summary-title">Current position</h2>
                        <p class="vault-summary-note">Compact view of bullion available in vault, issued to karigars, and active tracking counts.</p>
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
                        <strong>{{ $openJobs->count() }}</strong>
                    </div>
                </div>
            </section>

            @if($balances->isEmpty())
                <div class="vault-card p-8 text-center">
                    <p class="text-base font-bold text-slate-900">No bullion lots in this shop yet.</p>
                    <p class="mt-1 text-sm text-slate-500">Add the first lot to begin tracking fine-weight balances.</p>
                    <a href="{{ route('vault.lots.create') }}" class="vault-action vault-action-primary mt-5">Add your first lot</a>
                </div>
            @else
                <section class="vault-balance-grid">
                    @foreach($balances as $row)
                        @php $purityLabel = rtrim(rtrim(number_format($row['purity'], 2), '0'), '.'); @endphp
                        <article class="vault-balance-card">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="vault-label">Purity Profile</p>
                                    <h3 class="text-2xl font-black text-slate-950">{{ $purityLabel }}<span class="ml-1 text-sm font-bold text-amber-700">fine</span></h3>
                                    <p class="mt-1 text-xs font-semibold text-slate-500">{{ $row['lots_count'] }} {{ Str::plural('lot', $row['lots_count']) }} linked</p>
                                </div>
                                <div class="vault-purity-chip">{{ $purityLabel }}</div>
                            </div>
                            <div class="vault-mini-grid">
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
                </section>
            @endif
        </div>

        @if($lots->isNotEmpty())
            <section class="vault-card overflow-hidden">
                <div class="vault-section-head">
                    <div>
                        <h2 class="vault-section-title">All Lots</h2>
                        <p class="vault-section-copy">Source, vendor, remaining fine weight, and current availability.</p>
                    </div>
                    <a href="{{ route('vault.lots.create') }}" class="vault-link">+ Add bullion</a>
                </div>

                <div class="vault-desktop-table overflow-x-auto">
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

                <div class="vault-mobile-list space-y-3">
                    @foreach($lots as $lot)
                        @php
                            $issued = (float) $lot->fine_weight_total - (float) $lot->fine_weight_remaining;
                            $pct = $lot->fine_weight_total > 0 ? round((float) $lot->fine_weight_remaining / (float) $lot->fine_weight_total * 100) : 0;
                            $isEmpty = (float) $lot->fine_weight_remaining <= 0;
                        @endphp
                        <article class="vault-mobile-card {{ $isEmpty ? 'opacity-60' : '' }}">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('vault.lots.show', $lot) }}" class="font-mono text-sm font-black text-amber-700">#{{ $lot->lot_number }}</a>
                                    <p class="mt-1 text-xs capitalize text-slate-500">{{ str_replace('_', ' ', $lot->source) }}{{ $lot->vendor ? ' · ' . $lot->vendor->name : '' }}</p>
                                </div>
                                @if($isEmpty)
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-500">Depleted</span>
                                @elseif($issued > 0)
                                    <span class="rounded-full bg-blue-100 px-2 py-0.5 text-[11px] font-bold text-blue-700">Partial</span>
                                @else
                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-700">Available</span>
                                @endif
                            </div>
                            <div class="vault-mobile-grid text-sm">
                                <div>
                                    <p class="vault-label">Metal / Purity</p>
                                    <p class="font-bold capitalize text-slate-800">{{ $lot->metal_type ?? '—' }} {{ rtrim(rtrim(number_format($lot->purity, 2), '0'), '.') }}K</p>
                                </div>
                                <div>
                                    <p class="vault-label">Remaining</p>
                                    <p class="font-black text-emerald-700">{{ number_format($lot->fine_weight_remaining, 3) }}g <span class="text-xs font-semibold text-slate-400">({{ $pct }}%)</span></p>
                                </div>
                                <div>
                                    <p class="vault-label">Total Fine</p>
                                    <p class="font-semibold text-slate-700">{{ number_format($lot->fine_weight_total, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="vault-label">Issued</p>
                                    <p class="font-semibold text-blue-700">{{ $issued > 0 ? number_format($issued, 3).'g' : '—' }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if($openJobs->isNotEmpty())
            <section class="vault-card overflow-hidden">
                <div class="vault-section-head">
                    <div>
                        <h2 class="vault-section-title">Open Job Orders</h2>
                        <p class="vault-section-copy">Bullion currently issued to karigars and not fully returned.</p>
                    </div>
                    <a href="{{ route('job-orders.index') }}" class="vault-link">View all</a>
                </div>

                <div class="vault-desktop-table overflow-x-auto">
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

                <div class="vault-mobile-list space-y-3">
                    @foreach($openJobs as $jo)
                        <article class="vault-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('job-orders.show', $jo) }}" class="font-mono text-sm font-black text-teal-700">{{ $jo->job_order_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $jo->karigar?->name ?? 'No karigar' }}</p>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800' }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                            </div>
                            <div class="vault-mobile-grid text-sm">
                                <div>
                                    <p class="vault-label">Purity</p>
                                    <p class="font-bold text-slate-800">{{ rtrim(rtrim(number_format($jo->purity, 2), '0'), '.') }}K</p>
                                </div>
                                <div>
                                    <p class="vault-label">Issued Fine</p>
                                    <p class="font-semibold text-slate-700">{{ number_format($jo->issued_fine_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="vault-label">Outstanding</p>
                                    <p class="font-black text-amber-700">{{ number_format($jo->outstanding_fine, 3) }}g</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="vault-card overflow-hidden">
            <div class="vault-section-head">
                <div>
                    <h2 class="vault-section-title">Recent Vault Movements</h2>
                    <p class="vault-section-copy">Latest credits, debits, issues, returns, and lot transfers.</p>
                </div>
                <a href="{{ route('vault.ledger') }}" class="vault-link">Full ledger</a>
            </div>

            @if($recentMovements->isEmpty())
                <div class="py-10 text-center text-sm text-slate-400">No movements yet.</div>
            @else
                <div class="vault-desktop-table overflow-x-auto">
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

                <div class="vault-mobile-list space-y-3">
                    @foreach($recentMovements as $mv)
                        <article class="vault-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[11px] font-black uppercase text-slate-700">{{ str_replace('_', ' ', $mv->type) }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $mv->created_at->format('d M, H:i') }}</p>
                                </div>
                                <p class="font-mono text-sm font-black text-amber-700">{{ number_format($mv->fine_weight, 3) }}g</p>
                            </div>
                            <div class="vault-mobile-grid text-sm">
                                <div>
                                    <p class="vault-label">From Lot</p>
                                    <p class="font-mono text-slate-700">{{ $mv->from_lot_id ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="vault-label">To Lot</p>
                                    <p class="font-mono text-slate-700">{{ $mv->to_lot_id ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="vault-label">Reference</p>
                                    <p class="text-slate-600">{{ $mv->reference_type }}#{{ $mv->reference_id }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
