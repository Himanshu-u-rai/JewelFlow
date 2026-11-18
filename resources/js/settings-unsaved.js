const initialized = new WeakSet();

/**
 * One phase for the whole guard (audit M7, 2026-11-13), instead of loose leaving / submitting / turboSubmitting /
 * pending flags that could be set in any combination:
 *
 *   editing ──(navigate with edits)──▶ asking ──keep──▶ (back to what it was)
 *      │                                  └──discard──▶ leaving ──(new page)──▶ editing
 *      └──(save)──▶ submitting ──(save failed / cancelled)──▶ editing
 *                       └──(new page)──▶ editing
 *
 * `form` is the form being saved (kept while asking or leaving, as the save may still be running); `started`
 * records that Turbo really began that save.
 */
const PHASES = ['editing', 'asking', 'submitting', 'leaving'];

export function initializeSettingsUnsavedChanges(doc = globalThis.document, win = globalThis.window) {
    if (!doc || !win || initialized.has(doc)) return;
    initialized.add(doc);

    const originals = new WeakMap();
    const wiredDialogs = new WeakSet();
    const state = { phase: 'editing', form: null, started: false, rewind: null };
    const enter = (phase, changes = {}) => {
        if (!PHASES.includes(phase)) throw new Error(`Unknown settings guard phase: ${phase}`);
        Object.assign(state, { phase }, changes);
    };
    // Read-only view of the phase, for tests and debugging.
    Object.defineProperty(doc, 'settingsUnsavedPhase', { configurable: true, get: () => state.phase });

    let attempted = null;
    let rejected = null;
    let historyIndex = win.history.state?.turbo?.restorationIndex;

    const forms = () => [...doc.querySelectorAll('.settings-shell .settings-content form')].filter(form =>
        form.method.toLowerCase() === 'post' && form.querySelector(
            'input[name]:not([type="hidden"]):not([type="submit"]):not([type="button"]), select[name], textarea[name]'
        )
    );
    const snapshot = form => JSON.stringify([...new win.FormData(form)]
        .filter(([name, value]) => name !== '_token' && name !== '_method' &&
            (typeof value === 'string' || value.name || value.size))
        .map(([name, value]) => [name, typeof value === 'string' ? value :
            [value.name, value.size, value.type, value.lastModified]]));
    const identity = form => form.id || `${form.action}|${forms().filter(item => item.action === form.action).indexOf(form)}`;
    const remember = () => forms().forEach(form => {
        if (!originals.has(form)) originals.set(form,
            rejected?.key === identity(form) ? rejected.original : snapshot(form));
    });
    const changed = except => forms().some(form => form !== except && originals.has(form) &&
        originals.get(form) !== snapshot(form));
    // Leaving never warns; a form being saved does not count as unsaved unless something else changed.
    const needsWarning = () => state.phase !== 'leaving' && changed() && !(state.form && !changed(state.form));

    const ask = async proceed => {
        if (state.phase === 'asking') return;
        const dialog = doc.querySelector('[data-settings-unsaved-dialog]');
        if (!dialog) return; // Never leave automatically if the warning cannot be displayed.
        const before = state.phase;
        enter('asking');
        if (!wiredDialogs.has(dialog)) {
            wiredDialogs.add(dialog);
            dialog.querySelector('[data-unsaved-keep]').addEventListener('click', () => dialog.close('keep'));
            dialog.querySelector('[data-unsaved-discard]').addEventListener('click', () => dialog.close('discard'));
            dialog.addEventListener('cancel', event => { event.preventDefault(); dialog.close('keep'); });
            dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close('keep'); });
        }
        const accepted = await new Promise(resolve => {
            dialog.addEventListener('close', () => resolve(dialog.returnValue === 'discard'), { once: true });
            dialog.returnValue = '';
            dialog.showModal();
        });
        if (!accepted) {
            enter(before);
            return;
        }
        enter('leaving');
        win.Turbo.cache?.exemptPageFromCache();
        proceed();
    };

    const mount = () => {
        enter('editing', { form: null, started: false });
        historyIndex = win.history.state?.turbo?.restorationIndex;
        remember();
        attempted = rejected = null;
    };
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
    doc.addEventListener('focusin', remember);
    doc.addEventListener('turbo:before-render', event => {
        if (attempted && event.detail.newBody.querySelector('.settings-shell [data-settings-validation]')) rejected = attempted;
    });
    doc.addEventListener('turbo:render', mount);
    doc.addEventListener('turbo:load', mount);
    doc.addEventListener('turbo:frame-load', event => {
        if (event.target?.id === 'settings-content') mount();
    });

    doc.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !needsWarning()) return;
        const link = event.target.closest?.('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && !['_self', '_top', '_parent'].includes(link.target))) return;
        const url = new URL(link.href, win.location.href);
        const current = new URL(win.location.href);
        if (!['http:', 'https:'].includes(url.protocol) ||
            (url.hash && url.origin === current.origin && url.pathname === current.pathname && url.search === current.search)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        ask(() => link.click());
    }, true);

    doc.addEventListener('turbo:before-visit', event => {
        if (event.defaultPrevented || !needsWarning()) return;
        event.preventDefault();
        ask(() => win.Turbo.visit(event.detail.url));
    });

    doc.addEventListener('submit', event => {
        if (event.defaultPrevented || !changed()) return;
        const form = event.target;
        const writes = form.method.toLowerCase() === 'post';
        if (state.phase !== 'leaving' && changed(writes ? form : null)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            ask(() => form.requestSubmit(event.submitter || undefined));
            return;
        }
        if (writes) {
            // A save resumed after "Discard" stays in the leaving phase: the other edits were let go.
            enter(state.phase === 'leaving' ? 'leaving' : 'submitting', { form, started: false });
            win.Turbo.cache?.exemptPageFromCache();
            attempted = originals.has(form) ? { key: identity(form), original: originals.get(form) } : null;
            // Another confirmation handler may cancel this submit before Turbo starts.
            win.queueMicrotask(() => {
                if (event.defaultPrevented && !(state.form === form && state.started)) {
                    enter('editing', { form: null, started: false });
                }
            });
        }
    }, true);
    doc.addEventListener('turbo:submit-start', event => {
        if (!event.target.closest?.('.settings-shell .settings-content')) return;
        const phase = state.phase === 'leaving' || state.phase === 'asking' ? state.phase : 'submitting';
        enter(phase, { form: event.target, started: true });
    });
    const failed = () => enter('editing', { form: null, started: false });
    doc.addEventListener('turbo:submit-end', event => { if (!event.detail.success) failed(); });
    doc.addEventListener('turbo:fetch-request-error', failed);

    win.addEventListener('beforeunload', event => {
        if (!needsWarning()) return;
        event.preventDefault();
        event.returnValue = '';
    });

    // Turbo 8 restoration visits bypass before-visit. Rewind its indexed history
    // before asking, keeping both the live edits and URL intact on cancellation.
    win.addEventListener('popstate', event => {
        const index = event.state?.turbo?.restorationIndex;
        if (state.rewind) {
            event.stopImmediatePropagation();
            const delta = state.rewind.delta;
            state.rewind = null;
            ask(() => win.history.go(delta));
        } else if (needsWarning() && Number.isInteger(index) && Number.isInteger(historyIndex) && index !== historyIndex) {
            event.stopImmediatePropagation();
            state.rewind = { delta: index - historyIndex };
            win.history.go(-state.rewind.delta);
        } else {
            historyIndex = index;
        }
    }, true);
}
