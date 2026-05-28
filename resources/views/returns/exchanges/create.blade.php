<x-app-layout>
    @php $cn = $returnOrder->creditNote; @endphp

    <x-page-header
        title="Link as Exchange"
        :subtitle="'Return ' . ($cn?->credit_note_number ?? '#' . $returnOrder->id)">
        <x-slot:actions>
            <a href="{{ route('returns.show', $returnOrder) }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Return
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

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>
                Pick the new sale invoice this return is being exchanged against. The system will compute the net settlement and emit a single cash entry for the difference. The customer paid net (new sale − refund credit). For Phase 3A only cash settlement is supported — store credit lands in Phase 4.
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Return (this credit note)</p>
                <p class="text-xl font-semibold text-emerald-700 mt-2">₹{{ number_format((float) ($cn?->total ?? 0), 2) }}</p>
                <p class="text-xs text-slate-500 mt-2">{{ $cn?->credit_note_number }} · {{ $returnOrder->lineItems->count() }} line(s)</p>
                <p class="text-xs text-slate-500 mt-1">Customer: {{ $returnOrder->customer ? trim($returnOrder->customer->first_name . ' ' . $returnOrder->customer->last_name) : '—' }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 flex items-center justify-center text-slate-400">
                <div class="text-center">
                    <svg class="w-8 h-8 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m4 6H8m0 0l4 4m-4-4l4-4"/></svg>
                    <p class="text-sm">↔ pick the new sale below ↔</p>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('exchanges.store', $returnOrder) }}" class="space-y-6">
            @csrf

            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200">
                    <h2 class="text-lg font-semibold text-slate-900">Eligible new-sale invoices</h2>
                    <p class="text-sm text-slate-500 mt-1">Showing finalized invoices from the last 30 days for this customer (or any customer if the return wasn't linked to one).</p>
                </div>
                @if($candidateInvoices->isEmpty())
                    <div class="p-12 text-center">
                        <p class="text-sm text-slate-500">No eligible invoices found. Create the new sale through the POS first, then come back here.</p>
                    </div>
                @else
                    <table class="w-full">
                        <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                            <tr>
                                <th class="px-5 py-3 text-left w-12"></th>
                                <th class="px-5 py-3 text-left">Invoice</th>
                                <th class="px-5 py-3 text-left">Finalized</th>
                                <th class="px-5 py-3 text-right">Total</th>
                                <th class="px-5 py-3 text-right">Computed Net</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($candidateInvoices as $inv)
                                @php
                                    $net = round(((float) $inv->total) - ((float) ($cn?->total ?? 0)), 2);
                                    $direction = $net > 0.005 ? 'Customer pays' : ($net < -0.005 ? 'Shop refunds' : 'Even swap');
                                    $tone = $net > 0.005 ? 'text-emerald-700' : ($net < -0.005 ? 'text-rose-700' : 'text-slate-700');
                                @endphp
                                <tr>
                                    <td class="px-5 py-4">
                                        <input type="radio" name="new_invoice_id" value="{{ $inv->id }}" required
                                               class="rounded border-slate-300"
                                               {{ old('new_invoice_id') == $inv->id ? 'checked' : '' }}>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="text-sm font-semibold text-slate-900">{{ $inv->invoice_number }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-slate-700">{{ optional($inv->finalized_at)->format('d M Y, h:i A') }}</td>
                                    <td class="px-5 py-4 text-right text-sm font-semibold text-slate-900">₹{{ number_format((float) $inv->total, 2) }}</td>
                                    <td class="px-5 py-4 text-right text-sm font-semibold {{ $tone }}">
                                        {{ $direction }} ₹{{ number_format(abs($net), 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Valuation Basis</label>
                    <select name="valuation_basis_source" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="sale_day_rate" @selected(old('valuation_basis_source','sale_day_rate') === 'sale_day_rate')>Sale-day rate (refund credit = what was charged)</option>
                        <option value="today_rate" @selected(old('valuation_basis_source') === 'today_rate')>Today's rate</option>
                    </select>
                    <p class="text-xs text-slate-500 mt-2">Phase 3A records the basis but the credit-note total was locked at return time. Manual override and per-line revaluation come in Phase 3B.</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Settlement (no input needed)</p>
                    <p class="text-sm text-slate-700">
                        The money already moved when you processed the two halves — the refund was paid via the credit note, and the new sale was paid via POS payment methods. Linking the exchange is metadata only; nothing extra is debited or credited.
                    </p>
                    <p class="text-xs text-slate-500 mt-2">Store credit / mixed-method settlement (where money is held back instead of refunded) lands in Phase 4.</p>
                    <input type="hidden" name="payment_method" value="cash">
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Reason (optional)</label>
                <textarea name="reason" rows="2" maxlength="500"
                          placeholder="e.g. Customer upgraded to a bigger chain"
                          class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">{{ old('reason') }}</textarea>
            </div>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('returns.show', $returnOrder) }}"
                   class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Cancel
                </a>
                <button type="submit" {{ $candidateInvoices->isEmpty() ? 'disabled' : '' }}
                        class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed">
                    Link Exchange
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
