// "Share challan": hand the repair challan PDF to the device's share menu, so
// staff pick WhatsApp (or anything else) and the recipient, and send it
// themselves. The PDF comes from the signed-in session, never a public link.
// Opening the share menu is not delivery: nothing here claims a message was sent.

export const MESSAGES = {
    preparing: 'Preparing the PDF…',
    shared: 'Share menu used. JewelFlows cannot see whether the message was sent: check the chat.',
    cancelled: 'Sharing cancelled. Nothing was sent.',
    ready: 'PDF ready. Tap “Share now” to open the share menu.',
    failed: 'The PDF could not be prepared. Use Download PDF instead.',
    unsupported: 'This browser cannot share files. Download the PDF, then in WhatsApp open the customer’s chat, tap Attach (📎) → Document and choose the downloaded file.',
};

/** One sharer per challan; it keeps the prepared file so a second tap does not refetch it. */
export function createChallanSharer({ url, filename, nav = globalThis.navigator, fetchImpl = (...args) => globalThis.fetch(...args) }) {
    let file = null;
    const supported = () => Boolean(nav) && typeof nav.share === 'function' && typeof nav.canShare === 'function';

    return async function share() {
        if (!supported()) return 'unsupported';
        if (!file) {
            try {
                const response = await fetchImpl(url, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } });
                if (!response.ok) return 'failed';
                file = new File([await response.blob()], filename, { type: 'application/pdf' });
            } catch {
                return 'failed';
            }
        }
        if (!nav.canShare({ files: [file] })) return 'unsupported';
        try {
            await nav.share({ files: [file], title: filename.replace(/\.pdf$/i, '') });
            return 'shared';
        } catch (error) {
            if (error?.name === 'AbortError') return 'cancelled';
            // Browsers allow the share menu only straight after a tap; fetching the
            // PDF can outlast that. The file is ready now: the next tap shares it.
            if (error?.name === 'NotAllowedError') return 'ready';
            return 'failed';
        }
    };
}

/** Delegated, so it keeps working across Turbo page loads; bound once. */
export function initializeChallanShare(doc = document) {
    const sharers = new WeakMap();
    doc.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-challan-share]');
        if (!button || button.disabled) return;
        const box = button.closest('[data-challan-share-box]') ?? doc;
        const status = box.querySelector('[data-challan-share-status]');
        const fallback = box.querySelector('[data-challan-share-fallback]');
        if (!sharers.has(button)) {
            sharers.set(button, createChallanSharer({ url: button.dataset.url, filename: button.dataset.filename }));
        }
        button.disabled = true;
        if (status) status.textContent = MESSAGES.preparing;
        const result = await sharers.get(button)(button);
        button.disabled = false;
        if (status) status.textContent = MESSAGES[result] ?? MESSAGES.failed;
        if (fallback) fallback.hidden = !(result === 'unsupported' || result === 'failed');
        const label = button.querySelector('[data-challan-share-label]');
        if (label) label.textContent = result === 'ready' ? 'Share now' : 'Share challan';
    });
}
