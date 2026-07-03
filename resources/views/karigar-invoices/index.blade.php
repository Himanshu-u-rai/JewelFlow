<x-app-layout>
    <style>
        [x-cloak] {
            display: none !important;
        }

        .ki-shell {
            max-width: 1500px;
        }

        .ki-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 38px;
            border-radius: 12px;
            border: 1px solid #b45309;
            background: #b45309;
            padding: 9px 14px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            box-shadow: none;
        }

        .ki-card {
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: none;
        }

        .ki-top-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .ki-stat {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 14px;
            box-shadow: none;
        }

        .ki-stat span {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0;
            text-transform: none;
        }

        .ki-stat strong {
            display: block;
            margin-top: 5px;
            color: #0f172a;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }

        .ki-filter {
            display: grid;
            grid-template-columns: minmax(180px, 220px) minmax(220px, 1fr) auto;
            gap: 12px;
            align-items: end;
            padding: 0;
            position: relative;
            z-index: 20;
            isolation: auto;
            overflow: visible;
            border: 0;
            background: transparent;
            box-shadow: none;
        }

        .ki-label {
            display: block;
            margin-bottom: 6px;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
        }

        .ki-control {
            width: 100%;
            min-height: 42px;
            border-radius: 13px;
            border-color: #cbd5e1;
            background: #f8fafc;
            color: #0f172a;
            font-size: 14px;
        }

        .ki-control:focus {
            border-color: #b45309;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, .16);
        }

        .ki-filter-select {
            position: relative;
            min-width: 0;
        }

        .ki-filter-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            min-height: 42px;
            border: 1px solid #cbd5e1;
            border-radius: 13px;
            background: #f8fafc;
            padding: 8px 12px;
            color: #0f172a;
            font-size: 14px;
            text-align: left;
        }

        .ki-filter-trigger:focus {
            outline: none;
            border-color: #b45309;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, .16);
        }

        .ki-filter-placeholder {
            color: #64748b;
        }

        .ki-filter-menu {
            position: absolute;
            z-index: 2400;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            overflow: hidden;
            border: 1px solid #dbe3ee;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: none;
        }

        .ki-filter-menu[data-open-up="true"] {
            top: auto;
            bottom: calc(100% + 8px);
        }

        .ki-filter-list {
            max-height: var(--ki-filter-list-max-height, 240px);
            overflow-y: auto;
            padding: 6px;
        }

        .ki-filter-option {
            display: block;
            width: 100%;
            border-radius: 10px;
            padding: 9px 10px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 600;
            text-align: left;
        }

        .ki-filter-option:hover,
        .ki-filter-option-selected {
            background: #fff7ed;
            color: #b45309;
        }

        .ki-filter-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border-radius: 13px;
            border: 1px solid #b45309;
            background: #b45309;
            padding: 10px 16px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 700;
            box-shadow: none;
        }

        .ki-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .ki-title {
            margin: 0;
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
        }

        .ki-copy {
            margin-top: 2px;
            color: #64748b;
            font-size: 12px;
        }

        .ki-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            border-radius: 999px;
            border: 1px solid #dbe3ee;
            background: #f8fafc;
            padding: 6px 11px;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .ki-table {
            min-width: 1060px;
        }

        .ki-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0;
            text-transform: none;
        }

        .ki-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .ki-mobile {
            display: none;
        }

        .ki-mobile-card {
            border: 1px solid #dbe3ee;
            border-radius: 16px;
            background: #ffffff;
            padding: 14px;
            box-shadow: none;
        }

        .ki-mobile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .ki-mobile-label {
            margin-bottom: 4px;
            color: #64748b;
            font-size: 11px;
            font-weight: 500;
        }

        .ki-register {
            position: relative;
            z-index: 1;
            overflow: visible;
        }

        .ki-mobile-filter {
            display: none;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px;
        }

        .ki-mobile-filter summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            list-style: none;
            cursor: pointer;
            border: 1px solid #dbe3ee;
            border-radius: 12px;
            background: #ffffff;
            padding: 12px 14px;
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
        }

        .ki-mobile-filter summary::-webkit-details-marker {
            display: none;
        }

        .ki-mobile-filter-panel {
            display: grid;
            gap: 12px;
            margin-top: 12px;
        }

        @media (max-width: 980px) {
            .ki-top-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ki-filter {
                grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
            }
        }

        @media (max-width: 1023px) {
            .ki-filter--desktop {
                display: none;
            }

            .ki-mobile-filter {
                display: block;
            }
        }

        @media (max-width: 680px) {
            .ki-shell {
                padding-inline: 10px;
            }

            .ki-top-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .ki-stat {
                border-radius: 14px;
                padding: 11px;
            }

            .ki-stat strong {
                font-size: 17px;
            }

            .ki-head {
                flex-direction: column;
                gap: 10px;
                padding: 16px;
            }

            .ki-desktop {
                display: none;
            }

            .ki-mobile {
                display: block;
                padding: 14px;
            }

            .ki-mobile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @php
        $pageInvoices = $invoices->getCollection();
        $paidCount = $pageInvoices->where('payment_status', 'paid')->count();
        $partialCount = $pageInvoices->where('payment_status', 'partial')->count();
        $unpaidCount = $pageInvoices->where('payment_status', 'unpaid')->count();
        $pageTotal = (float) $pageInvoices->sum('total_after_tax');
        $statusLabels = [
            'unpaid' => 'Unpaid',
            'partial' => 'Partial',
            'paid' => 'Paid',
        ];
        $selectedKarigar = $karigars->firstWhere('id', (int) $filterKarigar);
    @endphp

    <x-page-header title="Karigar Invoices" subtitle="Tax invoices received from karigars">
        <x-slot:actions>
            @can('karigar_invoice.manage')
            <a href="{{ route('karigar-invoices.create') }}" class="ki-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                Add Invoice
            </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ki-shell karigar-invoices-index-page space-y-5">

        @unless(auth()->user()->can('karigar_invoice.manage'))
            @include('partials.view-only-banner', ['permission' => 'karigar_invoice.manage', 'message' => 'karigar invoices'])
        @endunless

        <section class="jobwork-kpi-grid">
            <div class="jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--gold">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>
                </span>
                <div>
                    <p>Total invoices</p>
                    <strong>{{ $invoices->total() }}</strong>
                </div>
            </div>
            <div class="jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--slate">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
                </span>
                <div>
                    <p>This page value</p>
                    <strong>₹{{ number_format($pageTotal, 0) }}</strong>
                </div>
            </div>
            <div class="jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--green">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <div>
                    <p>Paid / partial</p>
                    <strong>{{ $paidCount }} / {{ $partialCount }}</strong>
                </div>
            </div>
            <div class="jobwork-kpi-card">
                <span class="jobwork-kpi-icon jobwork-kpi-icon--rose">
                    <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </span>
                <div>
                    <p>Unpaid</p>
                    <strong>{{ $unpaidCount }}</strong>
                </div>
            </div>
        </section>

        <form method="GET" class="ki-filter ki-filter--desktop"
              x-data="{
                  paymentOpen: false,
                  paymentMenuStyle: '',
                  paymentStatus: @js($filterStatus ?? ''),
                  paymentStatusName: @js($filterStatus ? ($statusLabels[$filterStatus] ?? ucfirst($filterStatus)) : ''),
                  karigarOpen: false,
                  karigarMenuStyle: '',
                  karigarId: @js((string) ($filterKarigar ?? '')),
                  karigarName: @js($selectedKarigar?->name ?? ''),
                  setPayment(value, label) {
                      this.paymentStatus = value;
                      this.paymentStatusName = value ? label : '';
                      this.paymentOpen = false;
                      this.paymentMenuStyle = '';
                  },
                  setKarigar(value, label) {
                      this.karigarId = value;
                      this.karigarName = value ? label : '';
                      this.karigarOpen = false;
                      this.karigarMenuStyle = '';
                  },
                  toggleDropdown(which, trigger) {
                      const openKey = which + 'Open';
                      const nextState = !this[openKey];
                      this.closeDropdowns();
                      if (!nextState) {
                          return;
                      }

                      this[openKey] = true;
                      this.$nextTick(() => this.positionDropdown(which, trigger));
                  },
                  positionDropdown(which, trigger) {
                      const styleKey = which + 'MenuStyle';
                      const menu = trigger?.parentElement?.querySelector('.ki-filter-menu');
                      if (!menu || window.innerWidth <= 680) {
                          this[styleKey] = '';
                          if (menu) {
                              menu.dataset.openUp = 'false';
                          }
                          return;
                      }

                      const gutter = 12;
                      const triggerRect = trigger.getBoundingClientRect();
                      const preferredHeight = Math.min(menu.scrollHeight || 240, 280);
                      const spaceBelow = window.innerHeight - triggerRect.bottom - gutter;
                      const spaceAbove = triggerRect.top - gutter;
                      const openUp = spaceBelow < Math.min(preferredHeight, 220) && spaceAbove > spaceBelow;
                      const maxHeight = Math.max(140, Math.min(preferredHeight, openUp ? spaceAbove : spaceBelow));
                      const width = Math.max(triggerRect.width, 180);
                      const left = Math.min(
                          Math.max(gutter, triggerRect.left),
                          Math.max(gutter, window.innerWidth - gutter - width)
                      );
                      const top = openUp
                          ? Math.max(gutter, triggerRect.top - maxHeight - 8)
                          : Math.min(window.innerHeight - gutter - maxHeight, triggerRect.bottom + 8);

                      menu.dataset.openUp = openUp ? 'true' : 'false';
                      this[styleKey] = `position: fixed; left: ${left}px; top: ${top}px; width: ${width}px; --ki-filter-list-max-height: ${maxHeight}px;`;
                  },
                  closeDropdowns() {
                      this.paymentOpen = false;
                      this.karigarOpen = false;
                      this.paymentMenuStyle = '';
                      this.karigarMenuStyle = '';
                  }
              }"
              @keydown.escape.window="closeDropdowns()"
              @resize.window="closeDropdowns()">
            <div>
                <span class="ki-label">Payment Status</span>
                <div class="ki-filter-select" @click.outside="paymentOpen = false; paymentMenuStyle = ''">
                    <input type="hidden" name="payment_status" x-model="paymentStatus">
                    <button type="button" class="ki-filter-trigger" @click="toggleDropdown('payment', $el)" :aria-expanded="paymentOpen.toString()">
                        <span :class="paymentStatusName ? '' : 'ki-filter-placeholder'" x-text="paymentStatusName || 'All statuses'">All statuses</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="ki-filter-menu" x-show="paymentOpen" :style="paymentMenuStyle" x-transition.origin.top x-cloak>
                        <div class="ki-filter-list">
                            <button type="button" class="ki-filter-option" @click="setPayment('', 'All statuses')">All statuses</button>
                            @foreach($statusLabels as $value => $label)
                                <button type="button"
                                        class="ki-filter-option"
                                        :class="paymentStatus === '{{ $value }}' ? 'ki-filter-option-selected' : ''"
                                        @click="setPayment('{{ $value }}', @js($label))">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <span class="ki-label">Karigar</span>
                <div class="ki-filter-select" @click.outside="karigarOpen = false; karigarMenuStyle = ''">
                    <input type="hidden" name="karigar_id" x-model="karigarId">
                    <button type="button" class="ki-filter-trigger" @click="toggleDropdown('karigar', $el)" :aria-expanded="karigarOpen.toString()">
                        <span :class="karigarName ? '' : 'ki-filter-placeholder'" x-text="karigarName || 'All karigars'">All karigars</span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="ki-filter-menu" x-show="karigarOpen" :style="karigarMenuStyle" x-transition.origin.top x-cloak>
                        <div class="ki-filter-list">
                            <button type="button" class="ki-filter-option" @click="setKarigar('', 'All karigars')">All karigars</button>
                            @foreach($karigars as $k)
                                <button type="button"
                                        class="ki-filter-option"
                                        :class="karigarId === '{{ $k->id }}' ? 'ki-filter-option-selected' : ''"
                                        @click="setKarigar('{{ $k->id }}', @js($k->name))">{{ $k->name }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="ki-filter-button">Filter Invoices</button>
        </form>

        <section class="ki-card ki-register">
            <div class="ki-head">
                <div>
                    <h2 class="ki-title">Invoice Register</h2>
                    <p class="ki-copy">Karigar billing, job-order references, totals, payment status, and review flags.</p>
                </div>
                <span class="ki-count">{{ $invoices->total() }} {{ Str::plural('invoice', $invoices->total()) }}</span>
            </div>

            <details class="ki-mobile-filter">
                <summary>
                    <span>Filter invoices</span>
                    <span class="text-xs font-semibold text-slate-500">{{ $filterStatus ? ($statusLabels[$filterStatus] ?? ucfirst($filterStatus)) : 'All statuses' }}</span>
                </summary>
                <form method="GET" class="ki-mobile-filter-panel">
                    <div>
                        <label class="ki-label" for="mobile-payment-status">Payment Status</label>
                        <select id="mobile-payment-status" name="payment_status" class="ki-control">
                            <option value="">All statuses</option>
                            @foreach($statusLabels as $value => $label)
                                <option value="{{ $value }}" @selected(($filterStatus ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="ki-label" for="mobile-karigar-id">Karigar</label>
                        <select id="mobile-karigar-id" name="karigar_id" class="ki-control">
                            <option value="">All karigars</option>
                            @foreach($karigars as $k)
                                <option value="{{ $k->id }}" @selected((string) ($filterKarigar ?? '') === (string) $k->id)>{{ $k->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="ki-filter-button">Apply Filter</button>
                </form>
            </details>

            @if($invoices->isEmpty())
                <div class="py-16 text-center text-slate-400">
                    <p class="text-sm">No karigar invoices match.</p>
                </div>
            @else
                <div class="ki-desktop overflow-x-auto">
                    <table class="ki-table w-full text-sm">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left">Invoice #</th>
                                <th class="px-4 py-3 text-left">Karigar</th>
                                <th class="px-4 py-3 text-left">Mode</th>
                                <th class="px-4 py-3 text-left">Date</th>
                                <th class="px-4 py-3 text-left">Job Order</th>
                                <th class="px-4 py-3 text-right">Net Wt</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-center">Payment</th>
                                <th class="px-4 py-3 text-left">Flags</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($invoices as $inv)
                                <tr class="cursor-pointer hover:bg-slate-50" onclick="window.location='{{ route('karigar-invoices.show', $inv) }}'">
                                    <td class="px-4 py-3"><a href="{{ route('karigar-invoices.show', $inv) }}" class="font-semibold text-amber-700 hover:underline">{{ $inv->karigar_invoice_number }}</a></td>
                                    <td class="px-4 py-3 text-slate-700">{{ $inv->karigar?->name }}</td>
                                    <td class="px-4 py-3 text-sm font-medium capitalize text-slate-600">{{ str_replace('_', ' ', $inv->mode) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                    <td class="px-4 py-3">
                                        @if($inv->jobOrder)
                                            <a href="{{ route('job-orders.show', $inv->jobOrder) }}" class="text-xs font-semibold text-amber-700 hover:underline">{{ $inv->jobOrder->job_order_number }}</a>
                                        @else
                                            <span class="text-xs text-slate-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono">{{ number_format($inv->total_net_weight, 3) }}g</td>
                                    <td class="px-4 py-3 text-right font-mono font-bold">₹{{ number_format($inv->total_after_tax, 2) }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="ki-status {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        @forelse($inv->discrepancy_flags ?? [] as $flag)
                                            <span class="mr-1 inline-flex rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">{{ str_replace('_', ' ', $flag) }}</span>
                                        @empty
                                            <span class="text-xs text-slate-400">—</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="ki-mobile space-y-3">
                    @foreach($invoices as $inv)
                        <article class="ki-mobile-card cursor-pointer"
                                 role="link"
                                 tabindex="0"
                                 onclick="window.location='{{ route('karigar-invoices.show', $inv) }}'"
                                 onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); window.location='{{ route('karigar-invoices.show', $inv) }}'; }">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('karigar-invoices.show', $inv) }}" class="text-sm font-semibold text-amber-700">{{ $inv->karigar_invoice_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $inv->karigar?->name }} · {{ $inv->karigar_invoice_date->format('d M Y') }}</p>
                                </div>
                                <span class="ki-status {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span>
                            </div>

                            <div class="ki-mobile-grid text-sm">
                                <div>
                                    <p class="ki-mobile-label">Mode</p>
                                    <p class="font-medium capitalize text-slate-700">{{ str_replace('_', ' ', $inv->mode) }}</p>
                                </div>
                                <div>
                                    <p class="ki-mobile-label">Net Weight</p>
                                    <p class="font-semibold text-slate-800">{{ number_format($inv->total_net_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="ki-mobile-label">Total</p>
                                    <p class="font-semibold text-amber-700">₹{{ number_format($inv->total_after_tax, 2) }}</p>
                                </div>
                                <div>
                                    <p class="ki-mobile-label">Job Order</p>
                                    @if($inv->jobOrder)
                                        <a href="{{ route('job-orders.show', $inv->jobOrder) }}" onclick="event.stopPropagation()" class="text-xs font-semibold text-amber-700">{{ $inv->jobOrder->job_order_number }}</a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </div>
                            </div>

                            @if(! empty($inv->discrepancy_flags))
                                <div class="mt-3 border-t border-slate-100 pt-3">
                                    @foreach($inv->discrepancy_flags as $flag)
                                        <span class="mr-1 inline-flex rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">{{ str_replace('_', ' ', $flag) }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="border-t border-slate-200 p-4">{{ $invoices->links() }}</div>
            @endif
        </section>
    </div>
</x-app-layout>
