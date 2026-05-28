<x-app-layout>
    @php
        $ro = $exchange->returnOrder;
        $cn = $ro?->creditNote;
        $newInv = $exchange->newInvoice;
        $netAbs = number_format(abs((float) $exchange->net_amount), 2);
        if ((float) $exchange->net_amount > 0.005) {
            $netLabel = 'Customer paid ₹' . $netAbs;
            $netTone = 'text-emerald-700';
        } elseif ((float) $exchange->net_amount < -0.005) {
            $netLabel = 'Shop refunded ₹' . $netAbs;
            $netTone = 'text-rose-700';
        } else {
            $netLabel = 'Even swap';
            $netTone = 'text-slate-700';
        }
    @endphp

    <x-page-header
        :title="'Exchange #' . $exchange->id"
        :subtitle="$cn?->credit_note_number . ' ↔ ' . $newInv?->invoice_number">
        <x-slot:actions>
            <a href="{{ route('exchanges.receipt', $exchange) }}" target="_blank"
               class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/></svg>
                Print Receipt
            </a>
            <a href="{{ route('returns.index') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back to Returns
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-6">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Return Credit</p>
                <p class="text-2xl font-semibold text-emerald-700 mt-2">₹{{ number_format((float) ($cn?->total ?? 0), 2) }}</p>
                <p class="text-xs text-slate-500 mt-2">{{ $cn?->credit_note_number ?? '—' }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">New Sale</p>
                <p class="text-2xl font-semibold text-slate-900 mt-2">₹{{ number_format((float) ($newInv?->total ?? 0), 2) }}</p>
                <p class="text-xs text-slate-500 mt-2">{{ $newInv?->invoice_number ?? '—' }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-500">Net Settlement</p>
                <p class="text-2xl font-semibold mt-2 {{ $netTone }}">{{ $netLabel }}</p>
                <p class="text-xs text-slate-500 mt-2">Basis: {{ str_replace('_', ' ', $exchange->valuation_basis_source) }}</p>
            </div>
        </div>

        {{-- Valuation Detail --}}
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-200">
                <h3 class="text-base font-semibold text-slate-900">Valuation Detail</h3>
            </div>
            <dl class="divide-y divide-slate-100 px-5 py-2">
                <div class="flex justify-between py-2">
                    <dt class="text-sm text-slate-500">Gold rate basis</dt>
                    <dd class="text-sm font-medium text-slate-900">
                        @if($exchange->valuation_basis_source === 'sale_day_rate') Sale-day Rate
                        @elseif($exchange->valuation_basis_source === 'today_rate') Today's Rate
                        @elseif($exchange->valuation_basis_source === 'manual_override') Manual Override
                        @else {{ $exchange->valuation_basis_source ?? '—' }}
                        @endif
                    </dd>
                </div>
                @if($exchange->valuation_rate_override)
                <div class="flex justify-between py-2">
                    <dt class="text-sm text-slate-500">Override rate</dt>
                    <dd class="text-sm font-medium text-slate-900">₹{{ number_format($exchange->valuation_rate_override, 2) }}/g</dd>
                </div>
                @endif
                @if($exchange->approvedBy ?? null)
                <div class="flex justify-between py-2">
                    <dt class="text-sm text-slate-500">Rate authorized by</dt>
                    <dd class="text-sm text-slate-900">{{ $exchange->approvedBy->name }} · {{ \Carbon\Carbon::parse($exchange->approved_at)->format('d M Y, H:i') }}</dd>
                </div>
                @endif
                @if(auth()->user()->shop?->preferences?->exchange_rate_basis_locked ?? false)
                <div class="py-2">
                    <dt class="text-sm text-slate-500">Policy note</dt>
                    <dd class="text-sm text-slate-500 italic">Shop policy locks rate basis to default.</dd>
                </div>
                @endif
            </dl>
        </div>

        {{-- Actual money flow — pulled from the two halves, not from the
             exchange row's payment_method (which is metadata for Phase 4). --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-base font-semibold text-slate-900">How the money actually moved</h2>
            <p class="text-xs text-slate-500 mt-1">The exchange link is metadata only — no new cash entry was written when you linked them. These are the real entries from each half:</p>
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4 text-sm">
                <div>
                    <dt class="text-slate-500 text-xs uppercase tracking-wide">Refund out (return half)</dt>
                    <dd class="mt-1">
                        @if($refundCashOut)
                            <span class="font-semibold text-rose-700">−₹{{ number_format((float) $refundCashOut->amount, 2) }}</span>
                            <span class="text-slate-500 text-xs ml-2">cash, on {{ optional($refundCashOut->created_at)->format('d M Y, h:i A') }}</span>
                        @else
                            <span class="text-slate-400">No cash entry found for the CN</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500 text-xs uppercase tracking-wide">New sale payments (new invoice)</dt>
                    <dd class="mt-1">
                        @if($newSalePaymentMethods->isEmpty())
                            <span class="text-slate-400">No payment rows on the new invoice yet</span>
                        @else
                            <span class="font-semibold text-emerald-700">+₹{{ number_format((float) ($exchange->newInvoice?->total ?? 0), 2) }}</span>
                            <span class="text-slate-500 text-xs ml-2">via {{ $newSalePaymentMethods->map(fn($m) => str_replace('_', ' ', $m))->implode(', ') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Return side --}}
        @if($ro)
            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Items Returned</h2>
                        <p class="text-xs text-slate-500 mt-1">Credit note {{ $cn?->credit_note_number }}, return order #{{ $ro->id }}</p>
                    </div>
                    <a href="{{ route('returns.show', $ro) }}" class="text-sm text-amber-700 hover:underline">View full return →</a>
                </div>
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <tr>
                            <th class="px-5 py-3 text-left">Item</th>
                            <th class="px-5 py-3 text-left">Condition</th>
                            <th class="px-5 py-3 text-right">Refund</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($ro->lineItems as $rl)
                            <tr>
                                <td class="px-5 py-4">
                                    <div class="text-sm font-semibold text-slate-900">{{ $rl->item?->barcode ?? '—' }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $rl->item?->design ?? $rl->item?->category }}</div>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700 capitalize">{{ str_replace('_', ' ', $rl->condition) }}</td>
                                <td class="px-5 py-4 text-right text-sm font-semibold text-emerald-700">
                                    ₹{{ number_format((float) $rl->refund_total, 2) }}
                                    @include('returns.partials.policy-breakdown', [
                                        'breakdown' => $rl->policy_breakdown ?: null,
                                        'lineId'    => 'exc-' . $rl->id,
                                    ])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- New sale side --}}
        @if($newInv)
            <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Items Bought</h2>
                        <p class="text-xs text-slate-500 mt-1">{{ $newInv->invoice_number }} · finalized {{ optional($newInv->finalized_at)->format('d M Y, h:i A') }}</p>
                    </div>
                    <a href="{{ route('invoices.show', $newInv) }}" class="text-sm text-amber-700 hover:underline">View full invoice →</a>
                </div>
                <table class="w-full">
                    <thead class="bg-slate-50 border-b border-slate-200 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <tr>
                            <th class="px-5 py-3 text-left">Item</th>
                            <th class="px-5 py-3 text-right">Line Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($newInv->items as $il)
                            <tr>
                                <td class="px-5 py-4">
                                    <div class="text-sm font-semibold text-slate-900">{{ $il->item?->barcode ?? '—' }}</div>
                                    <div class="text-xs text-slate-500 mt-1">{{ $il->item?->design ?? $il->item?->category }}</div>
                                </td>
                                <td class="px-5 py-4 text-right text-sm font-semibold text-slate-900">₹{{ number_format((float) $il->line_total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Audit --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-xs text-slate-500">
            <p>Linked by {{ $exchange->createdBy?->name }} on {{ optional($exchange->created_at)->format('d M Y, h:i A') }}.</p>
            @if($exchange->settled_at)
                <p>Settled {{ $exchange->settled_at->format('d M Y, h:i A') }}{{ $exchange->settledBy ? ' by ' . $exchange->settledBy->name : '' }}.</p>
            @endif
            @if($exchange->reason)
                <p class="mt-2">Reason: {{ $exchange->reason }}</p>
            @endif
        </div>
    </div>
</x-app-layout>
