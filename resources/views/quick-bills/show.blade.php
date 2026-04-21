<x-app-layout>
    <x-page-header class="quick-bills-show-header ops-treatment-header">
        <div>
            <h1 class="page-title">{{ $quickBill->bill_number }}</h1>
            <p class="text-sm text-gray-600 mt-1">Quick Bill register entry using the shop’s own print identity.</p>
        </div>
        <div class="page-actions flex gap-2">
            <a href="{{ route('quick-bills.index') }}" class="inline-flex items-center px-4 py-2 rounded-full transition-colors text-sm font-semibold shadow-sm quick-bills-back-action" style="background:#fff; border:1px solid #dbe2ea; color:#0f172a;">
                Back
            </a>
            <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="inline-flex items-center px-4 py-2 rounded-full transition-colors text-sm font-semibold shadow-sm quick-bills-print-action" style="background:#0f172a; color:white;">
                Print
            </a>
            @if($quickBill->status !== \App\Models\QuickBill::STATUS_VOID)
                <a href="{{ route('quick-bills.edit', $quickBill) }}" class="inline-flex items-center px-4 py-2 rounded-full transition-colors text-sm font-semibold shadow-sm quick-bills-edit-action" style="background:#0f766e; color:white;">
                    Edit
                </a>
            @endif
        </div>
    </x-page-header>

    <div x-data="{ quickBillFabOpen: false }" class="invoice-emi-mobile-fab quick-bills-mobile-fab">
        <div class="invoice-emi-mobile-fab-shell" x-bind:class="{ 'is-open': quickBillFabOpen }" @click.outside="quickBillFabOpen = false">
            <nav class="invoice-emi-mobile-fab-nav" aria-label="Quick bill actions">
                <a href="{{ route('quick-bills.print', $quickBill) }}" target="_blank" class="invoice-emi-mobile-fab-link" @click="quickBillFabOpen = false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 17h2a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h2m2 4h6a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2zm8-12V5a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v4h10z"/></svg>
                    <span>Print</span>
                </a>
                @if($quickBill->status !== \App\Models\QuickBill::STATUS_VOID)
                    <a href="{{ route('quick-bills.edit', $quickBill) }}" class="invoice-emi-mobile-fab-link" @click="quickBillFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                        <span>Edit</span>
                    </a>
                @endif
            </nav>
            <button type="button" class="invoice-emi-mobile-fab-toggle" x-on:click="quickBillFabOpen = !quickBillFabOpen" x-bind:aria-expanded="quickBillFabOpen.toString()" aria-label="Toggle quick bill actions">
                <span class="invoice-emi-mobile-fab-bars" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
    </div>

    <div class="content-inner quick-bills-show-page max-w-[1320px] mx-auto ops-treatment-page">
        @if(session('success'))
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_320px] gap-6 quick-bills-show-grid">
            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm quick-bills-panel">
                    <div class="flex flex-wrap items-start justify-between gap-4 quick-bills-customer-head">
                        <div>
                            <div class="flex items-center gap-3">
                                <h2 class="text-2xl font-semibold text-slate-900">{{ $quickBill->customer_name ?: ($quickBill->customer?->name ?: 'Walk-in Customer') }}</h2>
                                @php
                                    $statusClass = match($quickBill->status) {
                                        'issued' => 'bg-emerald-100 text-emerald-800',
                                        'void' => 'bg-rose-100 text-rose-800',
                                        default => 'bg-amber-100 text-amber-800',
                                    };
                                @endphp
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ ucfirst($quickBill->status) }}</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ $quickBill->customer_mobile ?: ($quickBill->customer?->mobile ?: 'No mobile') }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ $quickBill->customer_address ?: ($quickBill->customer?->address ?: 'No address') }}</p>
                        </div>
                        <div class="text-right quick-bills-customer-date">
                            <div class="text-xs uppercase tracking-[0.18em] text-slate-500">Bill Date</div>
                            <div class="mt-1 text-lg font-semibold text-slate-900">{{ $quickBill->bill_date?->format('d M Y') }}</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-slate-900">Bill Items</h2>
                    </div>
                    <div class="overflow-x-auto quick-bills-items-wrap">
                        <table class="quick-bills-items-table">
                            <thead class="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Description</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Metal</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Purity</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Pcs</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Gross</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Net</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Rate</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($quickBill->items as $item)
                                    <tr>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-slate-800">{{ $item->description }}</div>
                                            <div class="mt-1 text-xs text-slate-500">HSN {{ $item->hsn_code ?: '—' }}</div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-700">{{ $item->metal_type ?: '—' }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-700">{{ $item->purity ?: '—' }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-700">{{ $item->pcs }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-700">{{ number_format((float) $item->gross_weight, 3) }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-700">{{ number_format((float) $item->net_weight, 3) }}</td>
                                        <td class="px-6 py-4 text-sm text-slate-700">₹{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="px-6 py-4 text-right text-sm font-semibold text-slate-800">₹{{ number_format((float) $item->line_total, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm quick-bills-panel">
                    <h2 class="text-lg font-semibold text-slate-900">Notes</h2>
                    <div class="mt-3 text-sm text-slate-600 whitespace-pre-line">{{ $quickBill->notes ?: 'No notes added.' }}</div>
                    <div class="mt-5 border-t border-slate-200 pt-5">
                        <h3 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Terms</h3>
                        <div class="mt-3 text-sm text-slate-600 whitespace-pre-line">{{ $quickBill->terms ?: 'No terms added.' }}</div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm quick-bills-panel">
                    <h2 class="text-lg font-semibold text-slate-900">Bill Summary</h2>
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between text-slate-600"><span>Pricing Mode</span><span class="font-medium text-slate-900">{{ ucwords(str_replace('_', ' ', $quickBill->pricing_mode)) }}</span></div>
                        <div class="flex justify-between text-slate-600"><span>GST Rate</span><span class="font-medium text-slate-900">{{ number_format((float) $quickBill->gst_rate, 2) }}%</span></div>
                        <div class="flex justify-between text-slate-600"><span>Subtotal</span><span class="font-medium text-slate-900">₹{{ number_format((float) $quickBill->subtotal, 2) }}</span></div>
                        <div class="flex justify-between text-slate-600"><span>Discount</span><span class="font-medium text-slate-900">- ₹{{ number_format((float) $quickBill->discount_amount, 2) }}</span></div>
                        <div class="flex justify-between text-slate-600"><span>Taxable</span><span class="font-medium text-slate-900">₹{{ number_format((float) $quickBill->taxable_amount, 2) }}</span></div>
                        <div class="flex justify-between text-slate-600"><span>CGST</span><span class="font-medium text-slate-900">₹{{ number_format((float) $quickBill->cgst_amount, 2) }}</span></div>
                        <div class="flex justify-between text-slate-600"><span>SGST</span><span class="font-medium text-slate-900">₹{{ number_format((float) $quickBill->sgst_amount, 2) }}</span></div>
                        <div class="flex justify-between text-slate-600"><span>Round Off</span><span class="font-medium text-slate-900">{{ $quickBill->round_off >= 0 ? '+' : '' }}₹{{ number_format((float) $quickBill->round_off, 2) }}</span></div>
                    </div>
                    <div class="mt-5 border-t border-slate-200 pt-5">
                        <div class="flex justify-between text-slate-600"><span>Paid</span><span class="font-medium text-slate-900">₹{{ number_format((float) $quickBill->paid_amount, 2) }}</span></div>
                        <div class="mt-2 flex justify-between text-slate-600"><span>Due</span><span class="font-medium {{ (float) $quickBill->due_amount > 0 ? 'text-amber-700' : 'text-emerald-700' }}">₹{{ number_format((float) $quickBill->due_amount, 2) }}</span></div>
                        <div class="mt-4 flex justify-between text-xl font-semibold text-slate-900"><span>Total</span><span>₹{{ number_format((float) $quickBill->total_amount, 2) }}</span></div>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm quick-bills-panel">
                    <h2 class="text-lg font-semibold text-slate-900">Payment Register</h2>
                    <div class="mt-4 space-y-3 quick-bills-payments-list">
                        @forelse($quickBill->payments as $payment)
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 quick-bills-payment-item">
                                <div class="flex items-center justify-between quick-bills-payment-head">
                                    <span class="text-sm font-semibold text-slate-800">{{ $payment->payment_mode }}</span>
                                    <span class="text-sm font-semibold text-slate-900">₹{{ number_format((float) $payment->amount, 2) }}</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-500">{{ $payment->reference_no ?: 'No reference' }}</div>
                            </div>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                                No payment rows recorded for this quick bill.
                            </div>
                        @endforelse
                    </div>
                </div>

                @if($quickBill->status !== \App\Models\QuickBill::STATUS_VOID)
                    <div class="rounded-2xl border border-rose-200 bg-rose-50 p-5 shadow-sm quick-bills-panel">
                        <h2 class="text-lg font-semibold text-rose-900">Void This Bill</h2>
                        <p class="mt-1 text-sm text-rose-700">Voiding keeps the record but marks it unusable.</p>
                        <form method="POST" action="{{ route('quick-bills.void', $quickBill) }}" class="mt-4 space-y-3" data-confirm-message="Void this quick bill?">
                            @csrf
                            <textarea name="void_reason" rows="3" class="w-full rounded-xl border-rose-200 bg-white px-3 py-2.5 text-sm text-slate-700 shadow-sm focus:border-rose-400 focus:ring-rose-400" placeholder="Reason for voiding"></textarea>
                            <button type="submit" class="inline-flex items-center rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-rose-700">
                                Void Quick Bill
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
