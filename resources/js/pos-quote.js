/**
 * The POS price quote as one explicit state (audit H4, 2026-11-13).
 *
 *   empty ──wait()/request()──▶ pending ──settle(latest, ok)──▶ ready | failed
 *                                  ▲                                │
 *                                  └────── request() (inputs changed)┘
 *
 * A quote is "ready for" a set of inputs only when the latest request priced exactly those inputs and succeeded.
 * Answers to older requests are dropped (and their requests aborted), so a slow, older answer can never overwrite
 * a newer one. State lives in plain properties so Alpine can react to it.
 */
export function createQuoteState() {
    return {
        status: 'empty', // empty | pending | ready | failed
        appliedKey: null,
        seq: 0,
        controller: null,

        /** Inputs reduced to what changes the price: same items in another order are the same cart. */
        keyOf(payload) {
            const items = [...(payload.item_ids || [])].map(Number).sort((a, b) => a - b);
            return JSON.stringify({ ...payload, item_ids: items });
        },

        /** A change is coming (debounce): nothing may sell until it is priced. */
        wait() {
            this.status = 'pending';
        },

        /** Start pricing these inputs; returns the ticket the answer must present. */
        request(payload) {
            this.controller?.abort();
            this.controller = typeof AbortController === 'function' ? new AbortController() : null;
            this.seq += 1;
            this.status = 'pending';

            return { seq: this.seq, key: this.keyOf(payload), signal: this.controller?.signal };
        },

        /** Apply an answer only if it is for the latest request. Returns whether the caller may use the answer. */
        settle(ticket, ok) {
            if (!ticket || ticket.seq !== this.seq) return false;
            this.controller = null;
            this.status = ok ? 'ready' : 'failed';
            if (ok) this.appliedKey = ticket.key;

            return true;
        },

        /** The sale returned a fresh quote for the cart it was sent: that quote is now the current one. */
        adopt(payload) {
            this.controller?.abort();
            this.controller = null;
            this.seq += 1;
            this.status = 'ready';
            this.appliedKey = this.keyOf(payload);
        },

        clear() {
            this.controller?.abort();
            this.controller = null;
            this.seq += 1;
            this.status = 'empty';
            this.appliedKey = null;
        },

        readyFor(payload) {
            return this.status === 'ready' && this.appliedKey === this.keyOf(payload);
        },
    };
}
