<x-app-layout>
    <style>
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
            border: 1px solid #0f766e;
            background: #0f766e;
            padding: 9px 14px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15, 118, 110, .18);
        }

        .ki-card {
            border: 1px solid #dbe3ee;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .06);
        }

        .ki-top-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .ki-stat {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #ffffff;
            padding: 14px;
            box-shadow: 0 10px 20px rgba(15, 23, 42, .04);
        }

        .ki-stat span {
            display: block;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .ki-stat strong {
            display: block;
            margin-top: 5px;
            color: #0f172a;
            font-size: 22px;
            font-weight: 950;
            line-height: 1.1;
        }

        .ki-filter {
            display: grid;
            grid-template-columns: minmax(180px, 220px) minmax(220px, 1fr) auto;
            gap: 12px;
            align-items: end;
            padding: 16px;
        }

        .ki-label {
            display: block;
            margin-bottom: 6px;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
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
            border-color: #0f766e;
            box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
        }

        .ki-filter-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            border-radius: 13px;
            border: 1px solid #111827;
            background: #111827;
            padding: 10px 16px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 12px 24px rgba(15, 23, 42, .16);
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
            font-weight: 900;
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
            font-weight: 900;
            white-space: nowrap;
        }

        .ki-table {
            min-width: 1060px;
        }

        .ki-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .ki-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 11px;
            font-weight: 900;
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
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05);
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
            font-weight: 900;
        }

        @media (max-width: 980px) {
            .ki-top-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ki-filter {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .ki-filter-button {
                width: 100%;
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

            .ki-card {
                border-radius: 16px;
            }

            .ki-filter {
                grid-template-columns: 1fr;
                padding: 14px;
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
    @endphp

    <x-page-header title="Karigar Invoices" subtitle="Tax invoices received from karigars">
        <x-slot:actions>
            <a href="{{ route('karigar-invoices.create') }}" class="ki-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                Add Invoice
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner ki-shell space-y-5">
        <x-app-alerts class="mb-2" />

        <section class="ki-top-grid">
            <div class="ki-stat">
                <span>Total Results</span>
                <strong>{{ $invoices->total() }}</strong>
            </div>
            <div class="ki-stat">
                <span>This Page Value</span>
                <strong>₹{{ number_format($pageTotal, 2) }}</strong>
            </div>
            <div class="ki-stat">
                <span>Paid / Partial</span>
                <strong>{{ $paidCount }} / {{ $partialCount }}</strong>
            </div>
            <div class="ki-stat">
                <span>Unpaid</span>
                <strong>{{ $unpaidCount }}</strong>
            </div>
        </section>

        <form method="GET" class="ki-card ki-filter">
            <label>
                <span class="ki-label">Payment Status</span>
                <select name="payment_status" class="ki-control">
                    <option value="">All statuses</option>
                    @foreach(['unpaid','partial','paid'] as $s)
                        <option value="{{ $s }}" {{ $filterStatus === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span class="ki-label">Karigar</span>
                <select name="karigar_id" class="ki-control">
                    <option value="">All karigars</option>
                    @foreach($karigars as $k)
                        <option value="{{ $k->id }}" {{ (string) $filterKarigar === (string) $k->id ? 'selected' : '' }}>{{ $k->name }}</option>
                    @endforeach
                </select>
            </label>

            <button type="submit" class="ki-filter-button">Filter Invoices</button>
        </form>

        <section class="ki-card overflow-hidden">
            <div class="ki-head">
                <div>
                    <h2 class="ki-title">Invoice Register</h2>
                    <p class="ki-copy">Karigar billing, job-order references, totals, payment status, and discrepancy flags.</p>
                </div>
                <span class="ki-count">{{ $invoices->total() }} {{ Str::plural('invoice', $invoices->total()) }}</span>
            </div>

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
                                    <td class="px-4 py-3"><a href="{{ route('karigar-invoices.show', $inv) }}" class="font-mono font-bold text-teal-700 hover:underline">{{ $inv->karigar_invoice_number }}</a></td>
                                    <td class="px-4 py-3 text-slate-700">{{ $inv->karigar?->name }}</td>
                                    <td class="px-4 py-3 text-xs font-black uppercase text-slate-600">{{ str_replace('_', ' ', $inv->mode) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $inv->karigar_invoice_date->format('d M Y') }}</td>
                                    <td class="px-4 py-3">
                                        @if($inv->jobOrder)
                                            <a href="{{ route('job-orders.show', $inv->jobOrder) }}" class="font-mono text-xs font-bold text-teal-700 hover:underline">{{ $inv->jobOrder->job_order_number }}</a>
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
                        <article class="ki-mobile-card">
                            <div class="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <a href="{{ route('karigar-invoices.show', $inv) }}" class="font-mono text-sm font-black text-teal-700">{{ $inv->karigar_invoice_number }}</a>
                                    <p class="mt-1 text-xs text-slate-500">{{ $inv->karigar?->name }} · {{ $inv->karigar_invoice_date->format('d M Y') }}</p>
                                </div>
                                <span class="ki-status {{ $inv->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : ($inv->payment_status === 'partial' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800') }}">{{ $inv->payment_status }}</span>
                            </div>

                            <div class="ki-mobile-grid text-sm">
                                <div>
                                    <p class="ki-mobile-label">Mode</p>
                                    <p class="font-bold uppercase text-slate-700">{{ str_replace('_', ' ', $inv->mode) }}</p>
                                </div>
                                <div>
                                    <p class="ki-mobile-label">Net Weight</p>
                                    <p class="font-mono font-bold text-slate-800">{{ number_format($inv->total_net_weight, 3) }}g</p>
                                </div>
                                <div>
                                    <p class="ki-mobile-label">Total</p>
                                    <p class="font-mono font-black text-amber-700">₹{{ number_format($inv->total_after_tax, 2) }}</p>
                                </div>
                                <div>
                                    <p class="ki-mobile-label">Job Order</p>
                                    @if($inv->jobOrder)
                                        <a href="{{ route('job-orders.show', $inv->jobOrder) }}" class="font-mono text-xs font-bold text-teal-700">{{ $inv->jobOrder->job_order_number }}</a>
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
