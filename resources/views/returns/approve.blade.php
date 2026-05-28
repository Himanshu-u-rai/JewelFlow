<x-app-layout>
    @php
        $invoice      = $returnOrder->invoice;
        $customer     = $returnOrder->customer ?? $invoice?->customer;
        $customerName = $customer ? trim($customer->first_name . ' ' . $customer->last_name) : null;
        $selections   = $returnOrder->pending_data['selections'] ?? [];
        $refundTotal  = collect($selections)->sum(fn ($s) => (float) ($s['refund_total'] ?? 0));
        $settlement   = $returnOrder->refund_settlement ?? $returnOrder->pending_data['refund_settlement'] ?? null;
        $settlementLabel = match ($settlement) {
            'cash'         => 'Cash',
            'store_credit' => 'Store Credit',
            default        => ucfirst(str_replace('_', ' ', (string) ($settlement ?? 'Not specified'))),
        };
    @endphp

    <x-page-header
        class="returns-approve-header"
        title="Review Pending Return"
        :subtitle="'Return against ' . ($invoice?->invoice_number ?? '#' . $returnOrder->id)">
        <x-slot:actions>
            <a href="{{ route('returns.show', $returnOrder) }}" class="returns-show-back-btn" aria-label="Back to return">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="19" y1="12" x2="5" y2="12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    <polyline points="12 19 5 12 12 5" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                </svg>
                <span>Back to Return</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-6">

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if(isset($approvalReason) && $approvalReason)
        <div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
            <div class="flex items-start">
                <svg class="h-5 w-5 text-amber-500 mt-0.5 mr-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <div>
                    <p class="font-semibold text-amber-800">Approval required because:</p>
                    <p class="mt-1 text-amber-700">{{ $approvalReason }}</p>
                </div>
            </div>
        </div>
        @endif

        {{-- Status notice --}}
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 flex items-start gap-3 text-sm text-amber-800">
            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <div>
                <strong>This return is awaiting manager approval.</strong>
                A staff member submitted this return but it exceeds a policy threshold and requires your explicit approval before the credit note is issued.
            </div>
        </div>

        {{-- Return summary --}}
        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden" aria-label="Return summary">
            <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
                <h2 class="text-base font-semibold text-slate-900">Return Summary</h2>
                <p class="text-xs text-slate-500 mt-0.5">Details of what was submitted for approval.</p>
            </div>

            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-px bg-slate-100">
                <div class="bg-white px-5 py-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Invoice</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">
                        @if($invoice)
                            <a href="{{ route('invoices.show', $invoice) }}" class="text-indigo-600 hover:underline">{{ $invoice->invoice_number }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </div>

                <div class="bg-white px-5 py-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Customer</dt>
                    <dd class="mt-1 text-sm font-semibold text-slate-900">
                        {{ $customerName ?: 'Walk-in customer' }}
                        @if($customer?->mobile)
                            <span class="block text-xs font-normal text-slate-500">{{ $customer->mobile }}</span>
                        @endif
                    </dd>
                </div>

                <div class="bg-white px-5 py-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Estimated Refund</dt>
                    <dd class="mt-1 text-sm font-semibold text-rose-700">
                        @if($refundTotal > 0)
                            ₹{{ number_format($refundTotal, 2) }}
                        @else
                            <span class="text-slate-500">Computed at approval</span>
                        @endif
                    </dd>
                </div>

                <div class="bg-white px-5 py-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Reason</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $returnOrder->reason ?: ($returnOrder->pending_data['reason'] ?? '—') }}</dd>
                </div>

                <div class="bg-white px-5 py-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Settlement Mode</dt>
                    <dd class="mt-1 text-sm text-slate-900">{{ $settlementLabel }}</dd>
                </div>

                <div class="bg-white px-5 py-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Submitted By</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        {{ $returnOrder->createdBy?->name ?? 'Unknown' }}
                        <span class="block text-xs text-slate-500">{{ optional($returnOrder->created_at)->format('d M Y, h:i A') ?? '—' }}</span>
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Items being returned --}}
        @if(count($selections) > 0)
            <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden" aria-label="Items being returned">
                <div class="px-5 py-4 border-b border-slate-200 bg-slate-50">
                    <h2 class="text-base font-semibold text-slate-900">Items Being Returned</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Selections submitted by staff for this return.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left">Item / Barcode</th>
                                <th class="px-4 py-3 text-left">Condition</th>
                                <th class="px-4 py-3 text-left">Outcome</th>
                                <th class="px-4 py-3 text-right">Refund (est)</th>
                                <th class="px-4 py-3 text-left">Line Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($selections as $itemId => $sel)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-slate-900">
                                        {{ $sel['barcode'] ?? '#' . $itemId }}
                                        @if(!empty($sel['design']) || !empty($sel['category']))
                                            <span class="block text-xs font-normal text-slate-500">{{ $sel['design'] ?? $sel['category'] ?? '' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-700">{{ ucfirst(str_replace('_', ' ', (string) ($sel['condition'] ?? '—'))) }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ ucfirst(str_replace('_', ' ', (string) ($sel['disposition'] ?? '—'))) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-rose-700">
                                        @if(isset($sel['refund_total']))
                                            ₹{{ number_format((float) $sel['refund_total'], 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-500">{{ $sel['reason'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-500">
                No line-item selections found in pending data. The items will be determined at approval time from the original submission.
            </div>
        @endif

        @can('returns.approve')
        @if(!empty($returnOrder->pending_data['selections'] ?? []))
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-slate-200">
                <h3 class="text-base font-semibold text-slate-900">Refund Adjustments <span class="text-sm font-normal text-slate-500">(optional)</span></h3>
                <p class="text-sm text-slate-500 mt-1">You may adjust refund amounts before approving. All adjustments are logged.</p>
            </div>
            <div class="px-6 py-4 space-y-4" id="approve-adjustments-container">
                @foreach($selections as $selIdx => $sel)
                @php
                    $lineIdx = $loop->index;
                    $invoiceItemId = $sel['invoice_item_id'] ?? null;
                    $selLineTotal = (float)($sel['line_total'] ?? 0);
                    $selGst = (float)($sel['gst_amount'] ?? 0);
                    $selDiscount = (float)($sel['allocated_discount'] ?? 0);
                    $selRoundOff = (float)($sel['allocated_round_off'] ?? 0);
                    $maxRefundable = $selLineTotal + $selGst - $selDiscount + $selRoundOff;
                @endphp
                <div class="border border-slate-200 rounded-lg p-4">
                    <div class="text-sm font-medium text-slate-800 mb-2">
                        {{ $sel['barcode'] ?? '#' . $selIdx }}
                        @if(!empty($sel['design']) || !empty($sel['category']))
                            <span class="text-slate-500 font-normal"> — {{ $sel['design'] ?? $sel['category'] ?? '' }}</span>
                        @endif
                        @if(isset($sel['refund_total']))
                            <span class="text-slate-500 font-normal"> — Current refund: ₹{{ number_format((float)$sel['refund_total'], 2) }}</span>
                        @endif
                    </div>
                    <details>
                        <summary class="cursor-pointer text-xs font-semibold text-indigo-600 hover:text-indigo-800 select-none">
                            Adjust Refund for this line
                        </summary>
                        <div class="mt-2 rounded border border-indigo-200 bg-indigo-50 p-3 space-y-2 text-xs">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="lines[{{ $lineIdx }}][override_making_charges]" value="1"
                                       class="rounded text-indigo-600">
                                <span class="text-gray-700">Refund making charges</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="lines[{{ $lineIdx }}][override_stone_charges]" value="1"
                                       class="rounded text-indigo-600">
                                <span class="text-gray-700">Refund stone charges</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="lines[{{ $lineIdx }}][override_gst]" value="1"
                                       class="rounded text-indigo-600">
                                <span class="text-gray-700">Refund GST</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="lines[{{ $lineIdx }}][override_waive_restocking]" value="1"
                                       class="rounded text-indigo-600">
                                <span class="text-gray-700">Waive restocking fee</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <label class="text-gray-700 shrink-0">Custom wear loss %</label>
                                <input type="number" name="lines[{{ $lineIdx }}][override_wear_loss_pct]"
                                       min="0" max="25" step="0.1"
                                       class="w-20 rounded border-gray-300 text-xs shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div class="border-t border-indigo-200 pt-2">
                                <div class="flex items-center gap-2">
                                    <label class="text-gray-700 shrink-0">Manual refund (₹)</label>
                                    <input type="number" name="lines[{{ $lineIdx }}][override_manual_total]"
                                           min="0" max="{{ $maxRefundable }}" step="0.01"
                                           class="w-32 rounded border-gray-300 text-xs shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                                    <span class="text-gray-400">max ₹{{ number_format($maxRefundable, 2) }}</span>
                                </div>
                            </div>
                            <div class="border-t border-indigo-200 pt-2">
                                @if($invoiceItemId)
                                <input type="hidden" name="lines[{{ $lineIdx }}][invoice_item_id]" value="{{ $invoiceItemId }}">
                                @endif
                                <label class="block text-gray-700 font-medium mb-1">Override reason <span class="text-red-500">*</span></label>
                                <textarea name="lines[{{ $lineIdx }}][override_reason]" rows="2" minlength="5" maxlength="500"
                                          class="w-full rounded border-gray-300 text-xs shadow-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                            </div>
                        </div>
                    </details>
                </div>
                @endforeach
            </div>
        </div>
        @endif
        @endcan

        {{-- Approve / Reject actions --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">

            {{-- LEFT: Approve --}}
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6 flex flex-col gap-4">
                <div>
                    <h3 class="text-base font-semibold text-emerald-900">Approve Return</h3>
                    <p class="text-sm text-emerald-700 mt-1">
                        Approving will process the return, issue the credit note, and update inventory dispositions as submitted. This action cannot be undone.
                    </p>
                </div>
                <form method="POST" action="{{ route('returns.approve', $returnOrder) }}" id="approve-form">
                    @csrf
                    <div id="approve-overrides-target"></div>
                    <button type="submit"
                            class="w-full rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 transition"
                            onclick="return confirm('Approve this return and issue the credit note?')">
                        Approve &amp; Issue Credit Note
                    </button>
                </form>
            </div>

            {{-- RIGHT: Reject --}}
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-6 flex flex-col gap-4">
                <div>
                    <h3 class="text-base font-semibold text-rose-900">Reject Return</h3>
                    <p class="text-sm text-rose-700 mt-1">
                        Rejecting will cancel this return. The staff member will be notified with the reason provided below.
                    </p>
                </div>
                <form method="POST" action="{{ route('returns.reject', $returnOrder) }}" class="flex flex-col gap-3">
                    @csrf
                    <div>
                        <label for="rejection_reason" class="block text-xs font-semibold uppercase tracking-[0.15em] text-rose-800 mb-1">
                            Rejection Reason <span class="text-rose-600">*</span>
                        </label>
                        <textarea id="rejection_reason" name="rejection_reason" rows="3" required minlength="5" maxlength="500"
                                  placeholder="e.g. Return window has passed. Please speak to the customer directly."
                                  class="w-full rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm focus:border-rose-500 focus:ring-rose-500">{{ old('rejection_reason') }}</textarea>
                        @error('rejection_reason')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit"
                            class="w-full rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2 transition"
                            onclick="return confirm('Reject this return? This cannot be undone.')">
                        Reject Return
                    </button>
                </form>
            </div>

        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const approveForm = document.getElementById('approve-form');
            const overridesTarget = document.getElementById('approve-overrides-target');
            const adjustmentsContainer = document.getElementById('approve-adjustments-container');
            if (approveForm && overridesTarget && adjustmentsContainer) {
                approveForm.addEventListener('submit', () => {
                    adjustmentsContainer.querySelectorAll('input, textarea, select').forEach(el => {
                        const clone = el.cloneNode(true);
                        overridesTarget.appendChild(clone);
                    });
                });
            }
        });
    </script>
</x-app-layout>
