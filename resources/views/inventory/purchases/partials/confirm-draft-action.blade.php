<button type="button" x-cloak class="jf-row-action jf-row-action--success" data-tip="Confirm draft"
        aria-label="Confirm draft {{ $purchase->purchase_number }}" aria-haspopup="dialog" aria-controls="purchase-confirm-dialog"
        data-confirm-url="{{ route('inventory.purchases.confirm', $purchase) }}"
        data-number="{{ $purchase->purchase_number }}" data-supplier="{{ $purchase->supplier_label }}"
        data-total="₹{{ number_format($purchase->total_amount, 2) }}"
        @click="draft = $el.dataset; confirming = false; $refs.confirmDraft.showModal()">
    <x-icon name="check" size="16" />
    <span class="jf-sr-only">Confirm draft</span>
</button>
