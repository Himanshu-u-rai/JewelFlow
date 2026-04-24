<x-app-layout>
    <x-page-header :title="$purchase->purchase_number" subtitle="Stock Purchase">
        <x-slot:actions>
            <a href="{{ route('inventory.purchases.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                ← All Purchases
            </a>
            @if($purchase->isDraft())
                <a href="{{ route('inventory.purchases.edit', $purchase) }}" class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 shadow-sm hover:bg-amber-100">
                    Edit
                </a>
                <form method="POST" action="{{ route('inventory.purchases.confirm', $purchase) }}" class="inline">
                    @csrf @method('PATCH')
                    <button type="submit" onclick="return confirm('Confirm this purchase and add items to stock?')"
                            class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Confirm Purchase
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-6">
        <x-app-alerts class="mb-2" />

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- ── Header Card ─────────────────────────────────────────── --}}
            <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Purchase Details</h3>
                    @if($purchase->isDraft())
                        <span class="inline-flex items-center rounded-full bg-orange-100 px-3 py-1 text-xs font-semibold text-orange-700">Draft</span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Confirmed</span>
                    @endif
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Purchase #</dt>
                        <dd class="font-mono font-semibold text-amber-600">{{ $purchase->purchase_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Purchase Date</dt>
                        <dd class="font-medium text-slate-700">{{ $purchase->purchase_date->format('d M Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Supplier</dt>
                        <dd class="font-medium text-slate-700">{{ $purchase->supplier_label }}</dd>
                    </div>
                    @if($purchase->supplier_gstin)
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Supplier GST Number (GSTIN)</dt>
                        <dd class="font-mono text-slate-600">{{ $purchase->supplier_gstin }}</dd>
                    </div>
                    @endif
                    @if($purchase->invoice_number)
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Invoice #</dt>
                        <dd class="font-medium text-slate-700">{{ $purchase->invoice_number }}</dd>
                    </div>
                    @endif
                    @if($purchase->invoice_date)
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Invoice Date</dt>
                        <dd class="font-medium text-slate-700">{{ $purchase->invoice_date->format('d M Y') }}</dd>
                    </div>
                    @endif
                    @if($purchase->irn_number)
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Invoice Reference Number (IRN)</dt>
                        <dd class="font-mono text-xs text-slate-600 break-all">{{ $purchase->irn_number }}</dd>
                    </div>
                    @endif
                    @if($purchase->ack_number)
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Acknowledgement Number (ACK)</dt>
                        <dd class="font-mono text-xs text-slate-600 break-all">{{ $purchase->ack_number }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Entered By</dt>
                        <dd class="text-slate-600">{{ $purchase->enteredBy?->name ?? '—' }}</dd>
                    </div>
                    @if($purchase->isConfirmed())
                    <div>
                        <dt class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-0.5">Confirmed By</dt>
                        <dd class="text-slate-600">{{ $purchase->confirmedBy?->name ?? '—' }} on {{ $purchase->confirmed_at?->format('d M Y') }}</dd>
                    </div>
                    @endif
                </dl>

                @if($purchase->notes)
                <div class="mt-4 pt-4 border-t border-slate-100">
                    <p class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-1">Notes</p>
                    <p class="text-sm text-slate-600">{{ $purchase->notes }}</p>
                </div>
                @endif
            </div>

            {{-- ── Right Sidebar: Totals + Invoice Image ─────────────── --}}
            <div class="space-y-4">

                {{-- Totals Card --}}
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500 mb-4">Totals</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between text-slate-600">
                            <dt>Lines Total</dt>
                            <dd class="font-medium">₹{{ number_format($purchase->lines->sum('purchase_line_amount'), 2) }}</dd>
                        </div>
                        @if($purchase->labour_discount > 0)
                        <div class="flex justify-between text-slate-500 text-xs">
                            <dt>− Labour Discount</dt>
                            <dd>₹{{ number_format($purchase->labour_discount, 2) }}</dd>
                        </div>
                        @endif
                        <div class="flex justify-between text-slate-600">
                            <dt>Subtotal</dt>
                            <dd class="font-medium">₹{{ number_format($purchase->subtotal_amount, 2) }}</dd>
                        </div>
                        @if($purchase->cgst_amount > 0)
                        <div class="flex justify-between text-slate-500 text-xs">
                            <dt>Central GST / CGST ({{ $purchase->cgst_rate }}%)</dt>
                            <dd>₹{{ number_format($purchase->cgst_amount, 2) }}</dd>
                        </div>
                        @endif
                        @if($purchase->sgst_amount > 0)
                        <div class="flex justify-between text-slate-500 text-xs">
                            <dt>State GST / SGST ({{ $purchase->sgst_rate }}%)</dt>
                            <dd>₹{{ number_format($purchase->sgst_amount, 2) }}</dd>
                        </div>
                        @endif
                        @if($purchase->igst_amount > 0)
                        <div class="flex justify-between text-slate-500 text-xs">
                            <dt>Integrated GST / IGST ({{ $purchase->igst_rate }}%)</dt>
                            <dd>₹{{ number_format($purchase->igst_amount, 2) }}</dd>
                        </div>
                        @endif
                        @if($purchase->tcs_amount > 0)
                        <div class="flex justify-between text-slate-500 text-xs">
                            <dt>Tax Collected at Source (TCS)</dt>
                            <dd>₹{{ number_format($purchase->tcs_amount, 2) }}</dd>
                        </div>
                        @endif
                        <div class="border-t border-slate-200 pt-2 flex justify-between text-base font-bold text-amber-700">
                            <dt>Grand Total</dt>
                            <dd>₹{{ number_format($purchase->total_amount, 2) }}</dd>
                        </div>
                    </dl>
                </div>

                {{-- Invoice Image --}}
                @if($purchase->invoice_image)
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-[11px] uppercase tracking-[0.15em] text-slate-400 mb-2">Invoice Document</p>
                    @php $ext = strtolower(pathinfo($purchase->invoice_image, PATHINFO_EXTENSION)); @endphp
                    @if(in_array($ext, ['jpg','jpeg','png','gif','webp']))
                        <a href="{{ Storage::url($purchase->invoice_image) }}" target="_blank">
                            <img src="{{ Storage::url($purchase->invoice_image) }}" alt="Invoice" class="w-full rounded-xl border border-slate-200 object-cover">
                        </a>
                    @else
                        <a href="{{ Storage::url($purchase->invoice_image) }}" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            View PDF Invoice
                        </a>
                    @endif
                </div>
                @endif

                {{-- Delete (draft only) --}}
                @if($purchase->isDraft())
                <form method="POST" action="{{ route('inventory.purchases.destroy', $purchase) }}" onsubmit="return confirm('Delete this draft? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="w-full rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-100 transition">
                        Delete Draft
                    </button>
                </form>
                @endif
            </div>
        </div>

        {{-- Line Items Table --}}
        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200">
                <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">
                    Line Items ({{ $purchase->lines->count() }})
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">#</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Type</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Design / Cat.</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Metal / Purity</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Gross Wt</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Net Wt</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Hallmark ID (HUID)</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Rate/g</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Making</th>
                            <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Line Total</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-[0.15em] text-slate-400">Item</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($purchase->lines as $idx => $line)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 text-slate-400 text-xs">{{ $idx + 1 }}</td>
                            <td class="px-4 py-2.5">
                                @php
                                    $typeLabels = ['ornament' => 'Ornament', 'bullion_for_sale' => 'Bullion (Sale)', 'bullion_reserve' => 'Bullion (Reserve)'];
                                    $typeColors = ['ornament' => 'bg-amber-100 text-amber-700', 'bullion_for_sale' => 'bg-blue-100 text-blue-700', 'bullion_reserve' => 'bg-slate-100 text-slate-600'];
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $typeColors[$line->line_type] ?? '' }}">
                                    {{ $typeLabels[$line->line_type] ?? $line->line_type }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">
                                <div class="font-medium">{{ $line->design ?: '—' }}</div>
                                @if($line->category)
                                    <div class="text-xs text-slate-400">{{ $line->category }}{{ $line->sub_category ? ' / ' . $line->sub_category : '' }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-slate-700">
                                <span class="capitalize">{{ $line->metal_type }}</span>
                                <span class="text-slate-400 ml-1">{{ $line->purity }}</span>
                                @if($line->hsn_code)
                                    <div class="text-xs text-slate-400">HSN Code: {{ $line->hsn_code }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right text-slate-700">{{ number_format($line->gross_weight, 3) }} g</td>
                            <td class="px-4 py-2.5 text-right text-slate-700">{{ number_format($line->net_metal_weight, 3) }} g</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-600">{{ $line->huid ?: '—' }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600">₹{{ number_format($line->purchase_rate_per_gram, 2) }}</td>
                            <td class="px-4 py-2.5 text-right text-slate-600">₹{{ number_format($line->making_charges, 2) }}</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-amber-700">₹{{ number_format($line->purchase_line_amount, 2) }}</td>
                            <td class="px-4 py-2.5">
                                @if($line->item)
                                    <a href="{{ route('inventory.items.show', $line->item) }}" class="font-mono text-xs text-amber-600 hover:underline">{{ $line->item->barcode }}</a>
                                @elseif($line->line_type === 'bullion_reserve')
                                    <span class="text-xs text-slate-400 italic">Reserve only</span>
                                @else
                                    <span class="text-xs text-slate-400 italic">Pending confirm</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="11" class="px-4 py-8 text-center text-slate-400 text-sm">No line items recorded.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Items Added to Stock (confirmed only) --}}
        @if($purchase->isConfirmed())
        @php $itemLines = $purchase->lines->filter(fn($l) => $l->item !== null); @endphp
        @if($itemLines->count() > 0)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-emerald-800 mb-3">Items Added to Stock</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($itemLines as $line)
                    <a href="{{ route('inventory.items.show', $line->item) }}"
                       class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        {{ $line->item->barcode }}
                    </a>
                @endforeach
            </div>
        </div>
        @endif
        @endif

    </div>
</x-app-layout>
