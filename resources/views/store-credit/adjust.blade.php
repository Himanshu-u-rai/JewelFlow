<x-app-layout>
    <x-page-header
        :title="'Adjust Store Credit'"
        :subtitle="'Customer: ' . trim($customer->first_name . ' ' . $customer->last_name)">
        <x-slot:actions>
            <a href="{{ route('customers.show', $customer) }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Cancel
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="content-inner space-y-6 max-w-2xl">

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
                <div class="font-semibold mb-1">Owner-only action — audited.</div>
                Manual adjustments bypass the normal return flow. Use only for goodwill credits, corrections to past mistakes, or recording credit issued outside the system. A note is required and the adjustment is permanently logged. Debits cannot push the balance below zero.
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Current Balance</div>
            <div class="mt-1 text-2xl font-bold text-slate-900">₹{{ number_format($balance, 2) }}</div>
        </div>

        <form method="POST" action="{{ route('store-credit.adjust.store', $customer) }}"
              class="rounded-2xl border border-slate-200 bg-white p-6 space-y-5"
              onsubmit="return confirm('Record this manual adjustment? This action is permanent and audited.');">
            @csrf

            <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Direction <span class="text-rose-600">*</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex items-start gap-2 rounded-xl border border-slate-200 bg-white p-3 cursor-pointer hover:bg-emerald-50/50">
                        <input type="radio" name="direction" value="credit"
                               @checked(old('direction', 'credit') === 'credit')
                               class="mt-1 rounded border-slate-300 text-emerald-600">
                        <span>
                            <span class="block font-semibold text-emerald-700">Credit (+)</span>
                            <span class="block text-xs text-slate-500 mt-0.5">Add to customer's wallet</span>
                        </span>
                    </label>
                    <label class="flex items-start gap-2 rounded-xl border border-slate-200 bg-white p-3 cursor-pointer hover:bg-rose-50/50">
                        <input type="radio" name="direction" value="debit"
                               @checked(old('direction') === 'debit')
                               class="mt-1 rounded border-slate-300 text-rose-600">
                        <span>
                            <span class="block font-semibold text-rose-700">Debit (−)</span>
                            <span class="block text-xs text-slate-500 mt-0.5">Remove from customer's wallet</span>
                        </span>
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Amount (₹) <span class="text-rose-600">*</span></label>
                <input type="number" name="amount" step="0.01" min="0.01" max="1000000" required
                       value="{{ old('amount') }}"
                       placeholder="e.g. 500.00"
                       class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 mb-2">Reason / Notes <span class="text-rose-600">*</span></label>
                <textarea name="notes" required minlength="5" maxlength="500" rows="3"
                          placeholder="e.g. Goodwill credit — customer reported delayed delivery on order INV/00123"
                          class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">{{ old('notes') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">Required. Will appear in the customer's audit history alongside this adjustment.</p>
            </div>

            <div class="flex items-center justify-end gap-3 pt-2 border-t border-slate-100">
                <a href="{{ route('customers.show', $customer) }}"
                   class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Cancel
                </a>
                <button type="submit"
                        class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    Record Adjustment
                </button>
            </div>
        </form>

    </div>
</x-app-layout>
