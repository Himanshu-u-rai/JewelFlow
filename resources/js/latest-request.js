/**
 * "Latest request wins" for type-ahead searches (audit M4, 2026-11-13).
 *
 * start() aborts the previous request and returns a ticket; an answer is used only while isLatest(ticket).
 * cancel() makes every request so far stale (the text was cleared). A ticket's own abort() (its timeout) touches
 * no other request.
 */
export function createLatestRequest() {
    let seq = 0;
    let controller = null;

    return {
        start() {
            controller?.abort();
            const own = typeof AbortController === 'function' ? new AbortController() : null;
            controller = own;
            seq += 1;

            return { seq, signal: own?.signal, abort: () => own?.abort() };
        },

        cancel() {
            controller?.abort();
            controller = null;
            seq += 1;
        },

        isLatest(ticket) {
            return Boolean(ticket) && ticket.seq === seq;
        },
    };
}
