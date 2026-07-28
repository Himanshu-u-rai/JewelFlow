{{-- MASTERS PART 3: archived is a visible state, not a hidden one. Shown on both
     the retailer and manufacturer customer pages so the reason a customer cannot
     be picked in POS/repairs is explained where the user is looking. --}}
@unless($customer->is_active)
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        <strong>This customer is archived.</strong>
        Every invoice, balance, EMI, scheme and repair below stays on record and open ones can still be settled,
        but the customer cannot be selected for a new transaction until you reactivate them.
    </div>
@endunless
