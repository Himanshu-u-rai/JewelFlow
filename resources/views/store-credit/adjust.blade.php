<x-app-layout>
    <x-page-header
        :title="'Adjust Store Credit'"
        :subtitle="'Customer: ' . trim($customer->first_name . ' ' . $customer->last_name)" />

    <style>
        .sca-page { max-width: 40rem; }
        .sca-note { display: flex; gap: .75rem; align-items: flex-start; border: 1px solid #fcd34d; border-left-width: 4px; background: #fffbeb; color: #78350f; border-radius: .75rem; padding: .75rem 1rem; font-size: .875rem; line-height: 1.45; }
        .sca-note svg { flex-shrink: 0; margin-top: .125rem; }
        .sca-note strong { display: block; color: #78350f; }
        .sca-fact { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; border-bottom: 1px solid #f1f5f9; padding-bottom: .75rem; }
        .sca-fact-label { font-size: .75rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #64748b; }
        .sca-fact-value { font-size: 1rem; font-weight: 700; color: #0f172a; }
        .sca-label { display: block; font-size: .75rem; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #64748b; margin-bottom: .375rem; }
        .sca-dir { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
        .sca-dir-opt { display: flex; align-items: flex-start; gap: .5rem; border: 1px solid #e2e8f0; border-radius: .75rem; background: #fff; padding: .5rem .75rem; cursor: pointer; }
        .sca-dir-opt:has(input:checked) { border-color: #f97316; background: #fff7ed; }
        .sca-dir-opt input { margin-top: .25rem; accent-color: #f97316; }
        .sca-dir-title { display: block; font-size: .875rem; font-weight: 600; color: #0f172a; }
        .sca-dir-hint { display: block; font-size: .75rem; color: #64748b; margin-top: .125rem; }
        .sca-input { width: 100%; max-width: 14rem; border: 1px solid #cbd5e1; border-radius: .5rem; background: #fff; padding: .5rem .75rem; font-size: .875rem; }
        .sca-textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: .5rem; background: #fff; padding: .5rem .75rem; font-size: .875rem; }
        .sca-footer { display: flex; align-items: center; justify-content: flex-end; gap: .75rem; padding-top: .75rem; border-top: 1px solid #f1f5f9; }
        @media (max-width: 640px) {
            .sca-dir { grid-template-columns: 1fr; }
            .sca-input { max-width: none; }
            .sca-footer { flex-direction: column-reverse; align-items: stretch; }
            .sca-footer .btn { width: 100%; justify-content: center; }
        }
    </style>

    <div class="content-inner space-y-4 sca-page">

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="sca-note" role="note">
            <x-icon name="circle-alert" class="w-5 h-5" />
            <div>
                <strong>Owner-only action — audited.</strong>
                Manual adjustments bypass the normal return flow. Use only for goodwill credits, corrections to past mistakes, or recording credit issued outside the system. A note is required and the adjustment is permanently logged. Debits cannot push the balance below zero.
            </div>
        </div>

        <form method="POST" action="{{ route('store-credit.adjust.store', $customer) }}"
              class="rounded-2xl border border-slate-200 bg-white p-5 space-y-4"
              onsubmit="return confirm('Record this manual adjustment? This action is permanent and audited.');">
            @csrf

            <div class="sca-fact">
                <span class="sca-fact-label">Current balance</span>
                <span class="sca-fact-value">₹{{ number_format($balance, 2) }}</span>
            </div>

            <div role="radiogroup" aria-labelledby="store-credit-direction-label">
                <span id="store-credit-direction-label" class="sca-label">Direction <span class="text-rose-600">*</span></span>
                <div class="sca-dir">
                    <label class="sca-dir-opt">
                        <input type="radio" name="direction" value="credit"
                               @checked(old('direction', 'credit') === 'credit')>
                        <span>
                            <span class="sca-dir-title">Credit (+)</span>
                            <span class="sca-dir-hint">Add to customer's wallet</span>
                        </span>
                    </label>
                    <label class="sca-dir-opt">
                        <input type="radio" name="direction" value="debit"
                               @checked(old('direction') === 'debit')>
                        <span>
                            <span class="sca-dir-title">Debit (−)</span>
                            <span class="sca-dir-hint">Remove from customer's wallet</span>
                        </span>
                    </label>
                </div>
            </div>

            <div>
                <label for="store-credit-amount" class="sca-label">Amount (₹) <span class="text-rose-600">*</span></label>
                <input type="number" name="amount" id="store-credit-amount" step="0.01" min="0.01" max="1000000" required
                       value="{{ old('amount') }}"
                       placeholder="e.g. 500.00"
                       class="sca-input">
            </div>

            <div>
                <label for="store-credit-notes" class="sca-label">Reason / Notes <span class="text-rose-600">*</span></label>
                <textarea name="notes" id="store-credit-notes" required minlength="5" maxlength="500" rows="3"
                          placeholder="e.g. Goodwill credit — customer reported delayed delivery on order INV/00123"
                          class="sca-textarea">{{ old('notes') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">Required. Will appear in the customer's audit history alongside this adjustment.</p>
            </div>

            <div class="sca-footer">
                <a href="{{ route('customers.show', $customer) }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Record Adjustment</button>
            </div>
        </form>

    </div>
</x-app-layout>
