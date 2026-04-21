@php
    $emiQuickMenuEnabled = ($emiMeta['is_retailer'] ?? false) && (($emiMeta['eligible'] ?? false) || ($emiMeta['has_plan'] ?? false));
@endphp

<x-app-layout>
    <x-page-header class="invoice-show-header {{ $emiQuickMenuEnabled ? 'invoice-show-header-emi-fab' : '' }}">
        <div>
            <h1 class="page-title">Invoice {{ $invoice->invoice_number }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $invoice->created_at->format('d M Y, h:i A') }}</p>
        </div>
        <div class="page-actions flex flex-wrap gap-2">
            @if(($emiMeta['is_retailer'] ?? false) && ($emiMeta['eligible'] ?? false))
                <a href="{{ route('installments.create', ['invoice_id' => $invoice->id]) }}" class="btn btn-dark btn-sm invoice-emi-primary-action">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Convert to EMI
                </a>
            @elseif(($emiMeta['is_retailer'] ?? false) && ($emiMeta['has_plan'] ?? false))
                <a href="{{ route('installments.show', $emiMeta['plan_id']) }}" class="btn btn-secondary btn-sm invoice-emi-primary-action">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                    View EMI Plan
                </a>
            @endif
            <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-secondary btn-sm invoice-edit-action">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-1.5"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                Edit Invoice
            </a>
            <a href="{{ route('invoices.print', $invoice) }}" target="_blank"
               class="btn btn-secondary btn-sm invoice-print-action">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print
            </a>
            <a href="{{ route('invoices.index') }}" 
               class="btn btn-secondary btn-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                All Invoices
            </a>
        </div>
    </x-page-header>

    @if($emiQuickMenuEnabled)
        <div x-data="{ invoiceEmiFabOpen: false }" class="invoice-emi-mobile-fab">
            <div class="invoice-emi-mobile-fab-shell" x-bind:class="{ 'is-open': invoiceEmiFabOpen }" @click.outside="invoiceEmiFabOpen = false">
                <nav class="invoice-emi-mobile-fab-nav" aria-label="EMI invoice quick actions">
                    @if(($emiMeta['is_retailer'] ?? false) && ($emiMeta['eligible'] ?? false))
                        <a href="{{ route('installments.create', ['invoice_id' => $invoice->id]) }}" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            <span>Convert to EMI</span>
                        </a>
                    @elseif(($emiMeta['is_retailer'] ?? false) && ($emiMeta['has_plan'] ?? false))
                        <a href="{{ route('installments.show', $emiMeta['plan_id']) }}" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                            <span>View EMI Plan</span>
                        </a>
                    @endif
                    <a href="{{ route('invoices.edit', $invoice) }}" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                        <span>Edit Invoice</span>
                    </a>
                    <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="invoice-emi-mobile-fab-link" @click="invoiceEmiFabOpen = false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 17h2a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2h2m2 4h6a2 2 0 0 0 2-2v-4a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v4a2 2 0 0 0 2 2zm8-12V5a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v4h10z"/></svg>
                        <span>Print</span>
                    </a>
                </nav>
                <button type="button" class="invoice-emi-mobile-fab-toggle" x-on:click="invoiceEmiFabOpen = !invoiceEmiFabOpen" x-bind:aria-expanded="invoiceEmiFabOpen.toString()" aria-label="Toggle invoice actions">
                    <span class="invoice-emi-mobile-fab-bars" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>
        </div>
    @endif

    <div class="content-inner invoice-show-page">
        @php
            $isRetailer = auth()->user()->shop?->isRetailer();
            $isRepairInvoice = str_starts_with($invoice->invoice_number, 'REP-') || $invoice->items->isEmpty();
            $repair = null;
            $offerApplied = $invoice->offerApplication;
            $offerDiscount = (float) ($offerApplied->discount_amount ?? 0);
            $manualDiscount = max(0, (float) $invoice->discount - $offerDiscount);
            $schemeRedemptionTotal = (float) $invoice->schemeRedemptions->sum('amount');

            if ($isRepairInvoice) {
                $repairLog = \App\Models\AuditLog::where('shop_id', auth()->user()->shop_id)
                    ->where('action', 'repair_deliver')
                    ->where('model_type', 'repair')
                    ->whereRaw("(data->>'invoice_id')::bigint = ?", [(int) $invoice->id])
                    ->latest()
                    ->first();

                if ($repairLog) {
                    $repair = \App\Models\Repair::where('shop_id', auth()->user()->shop_id)->find($repairLog->model_id);
                }
            }
        @endphp

        <div class="grid grid-cols-12 gap-4">
            <!-- Invoice Header Card -->
            <div class="col-span-12 bg-white shadow-sm border border-gray-200 p-4 invoice-show-card invoice-show-card--header">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 bg-green-100 flex items-center justify-center">
                            <svg class="w-7 h-7 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div>
                            <div class="text-xl font-bold text-gray-900">{{ $invoice->invoice_number }}</div>
                            <div class="text-sm text-gray-500">{{ $invoice->created_at->format('d M Y, h:i A') }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        @if(!$isRetailer)
                        <div class="text-right">
                            <div class="text-[11px] text-gray-500 uppercase">{{ $isRepairInvoice ? 'Invoice Type' : 'Gold Rate' }}</div>
                            <div class="text-lg font-semibold text-amber-600">
                                {{ $isRepairInvoice ? 'Repair Service' : '₹' . number_format($invoice->gold_rate, 2) . '/g' }}
                            </div>
                        </div>
                        @elseif($isRepairInvoice)
                        <div class="text-right">
                            <div class="text-[11px] text-gray-500 uppercase">Invoice Type</div>
                            <div class="text-lg font-semibold text-amber-600">Repair Service</div>
                        </div>
                        @endif
                        <span class="inline-flex items-center px-3 py-1.5 text-sm font-medium {{ $invoice->status == 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ ucfirst($invoice->status) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Customer Card -->
            <div class="col-span-12 lg:col-span-4 bg-white shadow-sm border border-gray-200 p-4 invoice-show-card invoice-show-card--customer">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Customer</h2>
                @if($invoice->customer)
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 bg-amber-100 flex items-center justify-center flex-shrink-0">
                            <span class="text-lg font-bold text-amber-700">
                                {{ strtoupper(substr($invoice->customer->name, 0, 2)) }}
                            </span>
                        </div>
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 truncate">{{ $invoice->customer->name }}</p>
                            <p class="text-sm text-gray-500">{{ $invoice->customer->mobile }}</p>
                        </div>
                    </div>
                    @if($invoice->customer->address)
                        <p class="text-xs text-gray-500 mt-2 truncate">{{ $invoice->customer->address }}</p>
                    @endif
                @else
                    <p class="text-gray-500">Walk-in Customer</p>
                @endif
            </div>

            <!-- Payment Summary Card -->
            <div class="col-span-12 lg:col-span-4 bg-white shadow-sm border border-gray-200 p-4 invoice-show-card invoice-show-card--summary">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Payment Summary</h2>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Subtotal</span>
                        <span class="font-medium text-gray-900">₹{{ number_format($invoice->subtotal, 2) }}</span>
                    </div>
                    @if($invoice->wastage_charge > 0)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Wastage Charge</span>
                            <span class="font-medium text-gray-900">₹{{ number_format($invoice->wastage_charge, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <span class="text-gray-600">GST ({{ $invoice->gst_rate ?? 3 }}%)</span>
                        <span class="font-medium text-gray-900">₹{{ number_format($invoice->gst, 2) }}</span>
                    </div>
                    @if($manualDiscount > 0)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Manual Discount</span>
                            <span class="font-medium text-red-600">−₹{{ number_format($manualDiscount, 2) }}</span>
                        </div>
                    @endif
                    @if($offerDiscount > 0)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Offer Discount @if($offerApplied)<span class="text-xs text-gray-400">({{ $offerApplied->scheme_name_snapshot }})</span>@endif</span>
                            <span class="font-medium text-red-600">−₹{{ number_format($offerDiscount, 2) }}</span>
                        </div>
                    @endif
                    @if($invoice->discount > 0 && $offerDiscount <= 0 && $manualDiscount <= 0)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Discount</span>
                            <span class="font-medium text-red-600">−₹{{ number_format($invoice->discount, 2) }}</span>
                        </div>
                    @endif
                    @if($invoice->round_off != 0)
                        <div class="flex justify-between">
                            <span class="text-gray-600">Round Off</span>
                            <span class="font-medium text-gray-700">{{ $invoice->round_off > 0 ? '+' : '' }}₹{{ number_format($invoice->round_off, 2) }}</span>
                        </div>
                    @endif
                    <div class="border-t border-gray-100 pt-2 mt-2">
                        <div class="flex justify-between items-center">
                            <span class="font-semibold text-gray-900">Total</span>
                            <span class="text-xl font-bold text-emerald-600">₹{{ number_format($invoice->total, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Info Card -->
            <div class="col-span-12 lg:col-span-4 bg-white rounded-xl shadow-sm border border-gray-200 p-4 invoice-show-card invoice-show-card--details">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Details</h2>
                <div class="grid grid-cols-2 gap-3 invoice-details-kpi-grid">
                    <div class="bg-gray-50 border border-gray-100 p-2 invoice-details-kpi-card">
                        <div class="text-[11px] text-gray-500 invoice-details-kpi-label">Items</div>
                        <div class="text-lg font-semibold text-gray-900 invoice-details-kpi-value">{{ $invoice->items->count() }}</div>
                    </div>
                    <div class="bg-gray-50 border border-gray-100 p-2 invoice-details-kpi-card">
                        <div class="text-[11px] text-gray-500 invoice-details-kpi-label">Payment</div>
                        @if($invoice->payments->count())
                            <div class="text-sm font-semibold text-gray-900 invoice-details-kpi-value">
                                {{ $invoice->payments->pluck('mode')->unique()->map(fn($m) => ucfirst(str_replace('_', ' ', $m)))->implode(', ') }}
                            </div>
                        @else
                            <div class="text-sm font-semibold text-gray-900 invoice-details-kpi-value">Cash</div>
                        @endif
                    </div>
                    <div class="bg-gray-50 border border-gray-100 p-2 invoice-details-kpi-card">
                        <div class="text-[11px] text-gray-500 invoice-details-kpi-label">Type</div>
                        <div class="text-sm font-semibold text-gray-900 invoice-details-kpi-value">{{ $isRepairInvoice ? 'Repair' : 'Sale' }}</div>
                    </div>
                    <div class="bg-gray-50 border border-gray-100 p-2 invoice-details-kpi-card">
                        <div class="text-[11px] text-gray-500 invoice-details-kpi-label">Invoice Number</div>
                        <div class="text-sm font-semibold text-gray-900 invoice-details-kpi-value">{{ $invoice->invoice_number }}</div>
                    </div>
                </div>

                {{-- Payment Breakdown --}}
                    @if($invoice->payments->count())
                        <div class="mt-4 pt-3 border-t border-gray-100">
                            <div class="text-[11px] text-gray-500 uppercase mb-2">Payment Breakdown</div>
                        <div class="space-y-2">
                            @foreach($invoice->payments as $payment)
                                <div class="flex items-center justify-between text-sm">
                                    <div class="flex items-center gap-2">
                                        <span class="text-gray-700">{{ ucfirst(str_replace('_', ' ', $payment->mode)) }}</span>
                                        @if($payment->reference)
                                            <span class="text-xs text-gray-400">({{ $payment->reference }})</span>
                                        @endif
                                    </div>
                                    <span class="font-medium text-gray-900">₹{{ number_format($payment->amount, 2) }}</span>
                                </div>
                                @if(in_array($payment->mode, ['old_gold', 'old_silver']) && $payment->metal_fine_weight)
                                    <div class="ml-7 text-xs text-gray-500">
                                        {{ number_format($payment->metal_gross_weight, 3) }}g gross
                                        · {{ $payment->mode === 'old_gold' ? $payment->metal_purity . 'K' : $payment->metal_purity . '‰' }}
                                        · {{ number_format($payment->metal_fine_weight, 3) }}g fine
                                        @ ₹{{ number_format($payment->metal_rate_per_gram, 2) }}/g
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- Items Table -->
            <div class="col-span-12 bg-white shadow-sm border border-gray-200 invoice-show-table-card">
                <div class="px-4 py-3 border-b border-gray-200">
                    <h2 class="text-sm font-semibold text-gray-900">{{ $isRepairInvoice ? 'Repair Details' : 'Sold Items' }}</h2>
                </div>
                <div class="overflow-x-auto invoice-show-table-shell">
                    @if($isRepairInvoice)
                        <table class="w-full invoice-show-data-table invoice-show-data-table--repair">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Weight</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Purity</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Service Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-900">
                                        {{ $repair?->item_description ?? 'Repair service' }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm text-gray-700">
                                        {{ $repair ? number_format($repair->gross_weight, 3) . ' g' : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm text-gray-700">
                                        {{ $repair ? number_format($repair->purity, 2) . 'K' : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900">
                                        ₹{{ number_format($invoice->total, 2) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-gray-50 border-t border-gray-200">
                                <tr>
                                    <td colspan="3" class="px-4 py-3 text-right text-sm font-semibold text-gray-900">Total</td>
                                    <td class="px-4 py-3 text-right text-lg font-bold text-emerald-600">₹{{ number_format($invoice->total, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    @else
                    <table class="w-full invoice-show-data-table invoice-show-data-table--sales">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase">Item</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Weight</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase">Purity</th>
                                @if(!$isRetailer)
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Rate</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Making</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Stone</th>
                                @endif
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($invoice->items as $invoiceItem)
                                @php
                                    $linkedItem = $invoiceItem->item;
                                @endphp
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            @if($linkedItem && $linkedItem->image)
                                                <img src="{{ asset('storage/' . $linkedItem->image) }}" alt="{{ $linkedItem->design ?: ($linkedItem->category ?: 'Item') }} image" class="object-cover bg-gray-100" style="width: 40px; height: 40px;">
                                            @else
                                                <div class="bg-amber-50 text-amber-700 flex items-center justify-center" style="width: 40px; height: 40px;">
                                                    <span class="text-lg"></span>
                                                </div>
                                            @endif
                                            <div>
                                                @if($linkedItem)
                                                    <div class="text-sm font-medium text-gray-900">{{ $linkedItem->design ?? 'N/A' }}</div>
                                                    <div class="text-xs text-gray-500 font-mono">{{ $linkedItem->barcode }}</div>
                                                    <div class="text-xs text-gray-400">{{ $linkedItem->category }}</div>
                                                @else
                                                    <div class="text-sm font-medium text-gray-900">Item (unlinked)</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm text-gray-700">
                                        {{ number_format($invoiceItem->weight, 3) }} g
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                            {{ $linkedItem->purity ?? 22 }}K
                                        </span>
                                    </td>
                                    @if(!$isRetailer)
                                    <td class="px-4 py-3 text-right text-sm text-gray-700">
                                        ₹{{ number_format($invoiceItem->rate, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-700">
                                        ₹{{ number_format($invoiceItem->making_charges, 2) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm text-gray-700">
                                        ₹{{ number_format($invoiceItem->stone_amount, 2) }}
                                    </td>
                                    @endif
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900">
                                        ₹{{ number_format($invoiceItem->line_total, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 border-t border-gray-200">
                            <tr>
                                <td colspan="{{ $isRetailer ? 3 : 6 }}" class="px-4 py-3 text-right text-sm font-semibold text-gray-900">Total</td>
                                <td class="px-4 py-3 text-right text-lg font-bold text-emerald-600">₹{{ number_format($invoice->total, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-span-12 bg-white rounded-xl shadow-sm border border-gray-200 mt-2">
            <div class="px-4 py-3 border-b border-gray-200">
                <h2 class="text-sm font-semibold text-gray-900">Invoice Preview</h2>
            </div>
            <div class="p-4">
                <div class="mx-auto max-w-3xl rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-center border-b border-gray-200 pb-3 mb-3">
                        <div class="text-lg font-semibold text-gray-900">{{ auth()->user()->shop->name }}</div>
                        <div class="text-sm text-gray-500">{{ auth()->user()->shop->phone }}</div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm mb-3">
                        <div><span class="text-gray-500">Invoice:</span> <span class="font-medium">{{ $invoice->invoice_number }}</span></div>
                        <div><span class="text-gray-500">Date:</span> <span class="font-medium">{{ $invoice->created_at->format('d M Y, h:i A') }}</span></div>
                        <div><span class="text-gray-500">Customer:</span> <span class="font-medium">{{ $invoice->customer?->name ?? 'Walk-in' }}</span></div>
                        <div><span class="text-gray-500">Type:</span> <span class="font-medium">{{ $isRepairInvoice ? 'Repair Service' : 'Sale' }}</span></div>
                    </div>

                    <div class="overflow-x-auto">
                        @if($isRepairInvoice)
                            <table class="w-full text-sm border border-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 border-b border-gray-200 text-left">Description</th>
                                        <th class="px-3 py-2 border-b border-gray-200 text-center">Weight</th>
                                        <th class="px-3 py-2 border-b border-gray-200 text-center">Purity</th>
                                        <th class="px-3 py-2 border-b border-gray-200 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="px-3 py-2 border-b border-gray-200">{{ $repair?->item_description ?? 'Repair service' }}</td>
                                        <td class="px-3 py-2 border-b border-gray-200 text-center">{{ $repair ? number_format($repair->gross_weight, 3) . ' g' : '—' }}</td>
                                        <td class="px-3 py-2 border-b border-gray-200 text-center">{{ $repair ? number_format($repair->purity, 2) . 'K' : '—' }}</td>
                                        <td class="px-3 py-2 border-b border-gray-200 text-right">₹{{ number_format($invoice->total, 2) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        @else
                            <table class="w-full text-sm border border-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-3 py-2 border-b border-gray-200 text-left">Item</th>
                                        <th class="px-3 py-2 border-b border-gray-200 text-center">Weight</th>
                                        @if(!$isRetailer)
                                            <th class="px-3 py-2 border-b border-gray-200 text-right">Rate</th>
                                            <th class="px-3 py-2 border-b border-gray-200 text-right">Making</th>
                                            <th class="px-3 py-2 border-b border-gray-200 text-right">Stone</th>
                                        @endif
                                        <th class="px-3 py-2 border-b border-gray-200 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoice->items as $line)
                                        <tr>
                                            <td class="px-3 py-2 border-b border-gray-200">{{ optional($line->item)->design ?? 'Item #' . $line->item_id }}</td>
                                            <td class="px-3 py-2 border-b border-gray-200 text-center">{{ number_format($line->weight, 3) }}</td>
                                            @if(!$isRetailer)
                                                <td class="px-3 py-2 border-b border-gray-200 text-right">₹{{ number_format($line->rate, 2) }}</td>
                                                <td class="px-3 py-2 border-b border-gray-200 text-right">₹{{ number_format($line->making_charges, 2) }}</td>
                                                <td class="px-3 py-2 border-b border-gray-200 text-right">₹{{ number_format($line->stone_amount, 2) }}</td>
                                            @endif
                                            <td class="px-3 py-2 border-b border-gray-200 text-right">₹{{ number_format($line->line_total, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>

                    <div class="mt-3 text-sm space-y-1">
                        <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span class="font-medium">₹{{ number_format($invoice->subtotal, 2) }}</span></div>
                        @if($invoice->wastage_charge > 0)
                            <div class="flex justify-between"><span class="text-gray-500">Wastage Charge</span><span class="font-medium">₹{{ number_format($invoice->wastage_charge, 2) }}</span></div>
                        @endif
                        <div class="flex justify-between"><span class="text-gray-500">GST ({{ number_format($invoice->gst_rate ?? 0, 2) }}%)</span><span class="font-medium">₹{{ number_format($invoice->gst, 2) }}</span></div>
                        @if($invoice->discount > 0)
                            <div class="flex justify-between"><span class="text-gray-500">Discount</span><span class="font-medium text-red-600">−₹{{ number_format($invoice->discount, 2) }}</span></div>
                        @endif
                        @if($invoice->round_off != 0)
                            <div class="flex justify-between"><span class="text-gray-500">Round Off</span><span class="font-medium">{{ $invoice->round_off > 0 ? '+' : '' }}₹{{ number_format($invoice->round_off, 2) }}</span></div>
                        @endif
                        <div class="flex justify-between border-t border-gray-200 pt-2"><span class="font-semibold text-gray-900">Grand Total</span><span class="font-semibold text-gray-900">₹{{ number_format($invoice->total, 2) }}</span></div>
                    </div>

                    @if($invoice->payments->count())
                        <div class="mt-3 pt-2 border-t border-gray-200 text-sm">
                            <div class="font-semibold text-gray-900 mb-1">Payment Received</div>
                            @foreach($invoice->payments as $payment)
                                <div class="flex justify-between text-gray-700">
                                    <span>{{ ucfirst(str_replace('_', ' ', $payment->mode)) }}@if($payment->reference) <span class="text-gray-400">({{ $payment->reference }})</span>@endif</span>
                                    <span>₹{{ number_format($payment->amount, 2) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($schemeRedemptionTotal > 0)
                        <div class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
                            Scheme redemption applied: ₹{{ number_format($schemeRedemptionTotal, 2) }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
