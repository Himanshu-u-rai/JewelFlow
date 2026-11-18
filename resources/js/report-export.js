// Keep the server's export/download pipeline; only its setup moves over the current page.
export async function requestReportExport(url, data, panelUrl, fetcher = fetch) {
    const response = await fetcher(url, {
        method: 'POST', body: data, credentials: 'same-origin', referrer: panelUrl,
        headers: { Accept: 'application/json', 'Turbo-Frame': 'report-export-frame', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (response.status === 422) {
        const payload = await response.json();
        return { kind: 'errors', errors: Object.values(payload.errors || {}).flat() };
    }
    if ([401, 419].includes(response.status)) throw new Error('Your session expired. Sign in again, then reopen Export.');
    if (!response.ok) throw new Error(response.status === 403 ? 'You do not have permission to export this report.' : 'Export could not finish. Your settings are still here; please try again.');
    const disposition = response.headers.get('Content-Disposition') || '';
    if (/attachment/i.test(disposition)) {
        const filename = disposition.match(/filename="([^"]+)"/i)?.[1] || 'report';
        return { kind: 'download', filename, blob: await response.blob() };
    }
    return { kind: 'panel', html: await response.text() };
}

export function initializeReportExports(doc = document) {
    let dialog, frame, opener, busy = false, panelUrl = '';
    const message = (text, error = false) => {
        const status = dialog.querySelector('[data-export-message]');
        status.hidden = false;
        status.textContent = text;
        status.setAttribute('role', error ? 'alert' : 'status');
        status.tabIndex = -1;
        if (error) status.focus();
    };
    const clear = () => {
        frame?.removeAttribute('src');
        frame?.querySelectorAll('[data-jf-date-picker="true"]').forEach(input => input._flatpickr?.destroy());
        frame?.replaceChildren();
    };
    const close = () => { if (!busy) dialog?.close(); };
    const ensureDialog = () => {
        if (dialog?.isConnected) return;
        dialog = doc.createElement('dialog');
        dialog.id = 'report-export-dialog';
        dialog.className = 'ir-export-dialog jf-scrollbar';
        dialog.setAttribute('aria-labelledby', 'report-export-title');
        dialog.innerHTML = '<header class="ir-export-dialog-head"><h2 id="report-export-title">Export report</h2><button type="button" class="btn btn-secondary" data-export-close aria-label="Close export setup"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><p data-export-message role="status" hidden></p><turbo-frame id="report-export-frame"></turbo-frame>';
        frame = dialog.querySelector('turbo-frame');
        doc.body.append(dialog);
        dialog.addEventListener('click', event => {
            if (event.target.closest('[data-export-close]') || event.target === dialog) close();
        });
        dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
        dialog.addEventListener('close', () => { clear(); opener?.isConnected && opener.focus(); });
        frame.addEventListener('turbo:frame-load', () => {
            dialog.querySelector('[data-export-message]').hidden = true;
            frame.querySelector('[role="alert"], select, input:not([type="hidden"])')?.focus();
        });
        for (const name of ['turbo:frame-missing', 'turbo:fetch-request-error']) frame.addEventListener(name, event => {
            event.preventDefault();
            message('Export setup could not load. Close and retry, or sign in again if your session expired.', true);
        });
        dialog.addEventListener('submit', async event => {
            const form = event.target;
            if (event.defaultPrevented || !form.matches('form[data-report-download], form[data-export-preset]')) return;
            event.preventDefault();
            if (busy) return;
            busy = true;
            const activeDialog = dialog;
            const buttons = [...dialog.querySelectorAll('button')];
            const disabled = buttons.map(button => button.disabled);
            buttons.forEach(button => button.disabled = true);
            dialog.setAttribute('aria-busy', 'true');
            message(form.hasAttribute('data-report-download') ? 'Preparing export…' : 'Saving changes…');
            try {
                const result = await requestReportExport(form.action, new FormData(form), frame.src || panelUrl);
                if (dialog !== activeDialog || !activeDialog.isConnected) return;
                if (result.kind === 'errors') message(result.errors.join(' ') || 'Please check the export settings.', true);
                else if (result.kind === 'download') {
                    const url = URL.createObjectURL(result.blob);
                    const link = doc.createElement('a');
                    link.href = url;
                    link.download = result.filename;
                    doc.body.append(link);
                    link.click();
                    link.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 60000);
                    message('Your export is ready. Download started.');
                } else {
                    const next = new DOMParser().parseFromString(result.html, 'text/html').querySelector('#report-export-frame');
                    if (!next) throw new Error('Your session may have expired. Close this panel, sign in, then reopen Export.');
                    clear();
                    frame.replaceChildren(...next.childNodes);
                    dialog.querySelector('[data-export-message]').hidden = true;
                    frame.dispatchEvent(new Event('turbo:frame-load', { bubbles: true }));
                }
            } catch (error) {
                if (dialog === activeDialog && activeDialog.isConnected) message(error.message || 'Export could not finish. Please try again.', true);
            }
            finally {
                buttons.forEach((button, index) => button.disabled = disabled[index]);
                if (dialog === activeDialog) {
                    busy = false;
                    dialog.removeAttribute('aria-busy');
                }
            }
        });
    };
    doc.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.download) return;
        if (!doc.body.matches('.app-shell') || link.closest('dialog') || typeof HTMLDialogElement === 'undefined') return;
        const url = new URL(link.href, doc.baseURI);
        if (url.origin !== location.origin || !/^\/reports\/[^/]+\/export$/.test(url.pathname)) return;
        event.preventDefault();
        if (busy) return;
        ensureDialog();
        clear();
        opener = link;
        panelUrl = url.href;
        dialog.querySelector('#report-export-title').textContent = link.getAttribute('aria-label') || 'Export setup';
        message('Loading export setup…');
        dialog.showModal();
        frame.src = panelUrl;
    }, true);
    for (const name of ['turbo:before-cache', 'turbo:before-render']) doc.addEventListener(name, () => {
        if (!dialog) return;
        dialog.close();
        clear();
        dialog.remove();
        dialog = null;
        busy = false;
    });
}
