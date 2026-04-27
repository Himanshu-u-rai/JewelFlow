<x-app-layout>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .jo-filter-select {
            position: relative;
            min-width: 180px;
        }

        .jo-filter-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            min-height: 34px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            padding: 6px 10px;
            color: #111827;
            font-size: 14px;
            text-align: left;
        }

        .jo-filter-trigger:focus {
            outline: none;
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .jo-filter-placeholder {
            color: #6b7280;
        }

        .jo-filter-menu {
            position: absolute;
            z-index: 40;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            overflow: hidden;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 18px 36px rgba(15, 23, 42, .16);
        }

        .jo-filter-list {
            max-height: 240px;
            overflow-y: auto;
            padding: 6px;
        }

        .jo-filter-option {
            display: block;
            width: 100%;
            border-radius: 10px;
            padding: 9px 10px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 800;
            text-align: left;
        }

        .jo-filter-option:hover,
        .jo-filter-option-selected {
            background: #f0fdfa;
            color: #0f766e;
        }

        .jo-filter-card {
            position: relative;
            z-index: 30;
            overflow: visible;
        }

        .jo-filter-field {
            display: flex;
            flex-direction: column;
            min-width: 150px;
        }

        .jo-date-control {
            width: 100%;
            height: 34px;
        }

        .jo-table-card {
            position: relative;
            z-index: 1;
        }

        .jo-table-wrap {
            overflow-x: auto;
            overscroll-behavior-x: contain;
        }

        .jo-table {
            min-width: 980px;
        }

        .jo-mobile-list {
            display: none;
        }

        .jo-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            background: #ffffff;
            padding: 14px;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
        }

        .jo-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .jo-mobile-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
        }

        @media (max-width: 680px) {
            .jo-filter-select {
                width: 100%;
            }

            .jo-filter-card {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
                padding: 20px;
            }

            .jo-filter-field,
            .jo-filter-card > div,
            .jo-filter-card button[type="submit"] {
                width: 100%;
            }

            .jo-filter-trigger,
            .jo-date-control,
            .jo-filter-card button[type="submit"] {
                min-height: 48px;
                height: 48px !important;
                border-radius: 12px;
                font-size: 15px;
            }

            .jo-filter-menu {
                position: fixed;
                top: auto;
                right: 14px;
                bottom: 16px;
                left: 14px;
                max-height: 55vh;
                border: 1.5px solid #0f766e;
                border-radius: 16px;
                box-shadow: 0 18px 36px rgba(15, 23, 42, .24), 0 0 0 4px rgba(15, 118, 110, .08);
            }

            .jo-filter-list {
                max-height: 55vh;
                padding: 8px;
            }

            .jo-filter-option {
                border: 1px solid #e2e8f0;
                margin-bottom: 7px;
                background: #ffffff;
            }

            .jo-filter-option:last-child {
                margin-bottom: 0;
            }

            .jo-filter-option:hover,
            .jo-filter-option-selected {
                border-color: #0f766e;
                background: #f0fdfa;
            }

            .jo-table-wrap {
                display: none;
            }

            .jo-mobile-list {
                display: block;
                padding: 14px;
            }

            .jo-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $statusLabels = [
            'issued' => 'Issued',
            'partial_return' => 'Partial return',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];
        $selectedKarigar = $karigars->firstWhere('id', (int) $filterKarigar);
    @endphp

    <x-page-header title="Job Orders" subtitle="Bullion issued to karigars">
        <x-slot:actions>
            <a href="{{ route('job-orders.create') }}" class="btn btn-success btn-sm">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Issue Bullion
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner">
        <x-app-alerts class="mb-4" />

        <form method="GET" class="jo-filter-card bg-white border border-gray-200 rounded-xl p-4 mb-4 flex flex-wrap items-end gap-3"
              x-data="{
                  statusOpen: false,
                  status: @js($filterStatus ?? ''),
                  statusName: @js($filterStatus ? ($statusLabels[$filterStatus] ?? str_replace('_', ' ', $filterStatus)) : ''),
                  karigarOpen: false,
                  karigarId: @js((string) ($filterKarigar ?? '')),
                  karigarName: @js($selectedKarigar?->name ?? ''),
                  setStatus(value, label) {
                      this.status = value;
                      this.statusName = value ? label : '';
                      this.statusOpen = false;
                  },
                  setKarigar(value, label) {
                      this.karigarId = value;
                      this.karigarName = value ? label : '';
                      this.karigarOpen = false;
                  },
                  closeDropdowns() {
                      this.statusOpen = false;
                      this.karigarOpen = false;
                  }
              }"
              @keydown.escape.window="closeDropdowns()">
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Status</label>
                <div class="jo-filter-select" @click.outside="statusOpen = false">
                    <input type="hidden" name="status" x-model="status">
                    <button type="button" class="jo-filter-trigger" @click="statusOpen = ! statusOpen" :aria-expanded="statusOpen.toString()">
                        <span :class="statusName ? '' : 'jo-filter-placeholder'" x-text="statusName || 'All statuses'">All statuses</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="jo-filter-menu" x-show="statusOpen" x-transition.origin.top x-cloak>
                        <div class="jo-filter-list">
                            <button type="button" class="jo-filter-option" @click="setStatus('', 'All statuses')">All statuses</button>
                            @foreach($statusLabels as $value => $label)
                                <button type="button"
                                        class="jo-filter-option"
                                        :class="status === '{{ $value }}' ? 'jo-filter-option-selected' : ''"
                                        @click="setStatus('{{ $value }}', @js($label))">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">Karigar</label>
                <div class="jo-filter-select" @click.outside="karigarOpen = false">
                    <input type="hidden" name="karigar_id" x-model="karigarId">
                    <button type="button" class="jo-filter-trigger" @click="karigarOpen = ! karigarOpen" :aria-expanded="karigarOpen.toString()">
                        <span :class="karigarName ? '' : 'jo-filter-placeholder'" x-text="karigarName || 'All karigars'">All karigars</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="jo-filter-menu" x-show="karigarOpen" x-transition.origin.top x-cloak>
                        <div class="jo-filter-list">
                            <button type="button" class="jo-filter-option" @click="setKarigar('', 'All karigars')">All karigars</button>
                            @foreach($karigars as $k)
                                <button type="button"
                                        class="jo-filter-option"
                                        :class="karigarId === '{{ $k->id }}' ? 'jo-filter-option-selected' : ''"
                                        @click="setKarigar('{{ $k->id }}', @js($k->name))">{{ $k->name }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">From</label>
                <input type="date" name="from" value="{{ $filterFrom }}" class="jo-date-control rounded-md border-gray-300 text-sm">
            </div>
            <div class="jo-filter-field">
                <label class="text-[11px] uppercase tracking-wide text-gray-500 font-semibold">To</label>
                <input type="date" name="to" value="{{ $filterTo }}" class="jo-date-control rounded-md border-gray-300 text-sm">
            </div>
            <button type="submit" class="btn btn-secondary btn-sm" style="height:34px;">Filter</button>
        </form>

        <div class="jo-table-card bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            @if($orders->isEmpty())
                <div class="py-16 text-center text-gray-400">
                    <p class="text-sm mb-3">No job orders match your filter.</p>
                    <a href="{{ route('job-orders.create') }}" class="text-teal-700 underline text-sm">Issue your first job order</a>
                </div>
            @else
                <div class="jo-table-wrap">
                    <table class="jo-table w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <th class="px-4 py-3 text-left font-semibold">Job #</th>
                                <th class="px-4 py-3 text-left font-semibold">Karigar</th>
                                <th class="px-4 py-3 text-left font-semibold">Issued</th>
                                <th class="px-4 py-3 text-right font-semibold">Gross / Fine</th>
                                <th class="px-4 py-3 text-right font-semibold">Returned (fine)</th>
                                <th class="px-4 py-3 text-right font-semibold">Wastage</th>
                                <th class="px-4 py-3 text-center font-semibold">Status</th>
                                <th class="px-4 py-3 text-left font-semibold">Flags</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($orders as $jo)
                                <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('job-orders.show', $jo) }}'"  >
                                    <td class="px-4 py-3">
                                        <a href="{{ route('job-orders.show', $jo) }}" class="text-teal-700 font-mono hover:underline">{{ $jo->job_order_number }}</a>
                                        <div class="text-[10px] text-gray-400">DC: {{ $jo->challan_number }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ $jo->karigar?->name }}</td>
                                    <td class="px-4 py-3 text-gray-500 whitespace-nowrap">{{ $jo->issue_date->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->returned_fine_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($jo->actual_wastage_fine, 3) }}g</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $jo->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jo->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @foreach($jo->discrepancy_flags ?? [] as $flag)
                                            <span class="inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800 mr-1">{{ str_replace('_', ' ', $flag) }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="jo-mobile-list space-y-3">
                    @foreach($orders as $jo)
                        <article class="jo-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('job-orders.show', $jo) }}" class="font-mono text-sm font-black text-teal-700">{{ $jo->job_order_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">DC: {{ $jo->challan_number }}</p>
                                </div>
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold {{ $jo->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : ($jo->status === 'cancelled' ? 'bg-gray-200 text-gray-600' : ($jo->status === 'partial_return' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')) }}">{{ str_replace('_', ' ', $jo->status) }}</span>
                            </div>

                            <div class="jo-mobile-grid text-sm">
                                <div>
                                    <p class="jo-mobile-label">Karigar</p>
                                    <p class="font-semibold text-slate-800">{{ $jo->karigar?->name ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Issued</p>
                                    <p class="text-slate-700">{{ $jo->issue_date->format('d M Y') }}</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Gross / Fine</p>
                                    <p class="font-mono font-bold text-slate-800">{{ number_format($jo->issued_gross_weight, 3) }} / {{ number_format($jo->issued_fine_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Returned Fine</p>
                                    <p class="font-mono text-slate-700">{{ number_format($jo->returned_fine_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="jo-mobile-label">Wastage</p>
                                    <p class="font-mono text-slate-700">{{ number_format($jo->actual_wastage_fine, 3) }}g</p>
                                </div>
                            </div>

                            @if(! empty($jo->discrepancy_flags))
                                <div class="mt-3 border-t border-slate-100 pt-3">
                                    @foreach($jo->discrepancy_flags as $flag)
                                        <span class="mr-1 inline-flex rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">{{ str_replace('_', ' ', $flag) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="p-4">{{ $orders->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
