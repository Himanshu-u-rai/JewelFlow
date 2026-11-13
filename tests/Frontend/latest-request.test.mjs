import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createLatestRequest } from '../../resources/js/latest-request.js';
import { registerHistoricalManual } from '../../resources/js/historical-manual.js';

// Audit M4 (2026-11-13): type-ahead customer searches let an older answer land after a newer one, kept showing
// results for text that had been cleared, and a superseded request removed the spinner of the live one. The POS
// also aborted "whichever request is current" from an old request's timeout. One helper decides which answer counts.

test('only the latest request counts, and starting one aborts the previous', () => {
    const r = createLatestRequest();
    const a = r.start();
    const b = r.start();
    assert.equal(a.signal.aborted, true);
    assert.equal(r.isLatest(a), false);
    assert.equal(r.isLatest(b), true);
});

test('cancel makes every request so far stale', () => {
    const r = createLatestRequest();
    const a = r.start();
    r.cancel();
    assert.equal(a.signal.aborted, true);
    assert.equal(r.isLatest(a), false);
});

test("a request's own abort (its timeout) touches no other request", () => {
    const r = createLatestRequest();
    const a = r.start();
    const b = r.start();
    a.abort();
    assert.equal(b.signal.aborted, false);
});

/** The Historical Manual customer picker, with a network whose answers we release by hand. */
function picker() {
    const factories = {};
    registerHistoricalManual({ data: (name, fn) => { factories[name] = fn; } });
    const c = factories.historicalCustomerPicker({ searchUrl: '/customers/search' });
    c.$root = { querySelector: () => null };
    const pending = [];
    globalThis.fetch = (url, init) => new Promise((resolve, reject) => {
        const entry = { url, release: results => resolve({ ok: true, json: async () => ({ results }) }) };
        init?.signal?.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
        pending.push(entry);
    });

    return { c, pending };
}

const tick = () => new Promise(r => setTimeout(r, 0));

test('Historical picker: an older, slower answer never replaces the newer one', async () => {
    const { c, pending } = picker();
    c.search = 'ram'; const first = c.findCustomers();
    c.search = 'rame'; const second = c.findCustomers();
    pending[1].release([{ id: 2, label: 'Ramesh' }]);
    await second;
    pending[0].release([{ id: 1, label: 'Ram' }]);
    await first; await tick();
    assert.deepEqual(c.results.map(r => r.id), [2]);
    assert.equal(c.loading, false);
});

test('Historical picker: clearing the text drops the answer still on its way', async () => {
    const { c, pending } = picker();
    c.search = 'ram'; const first = c.findCustomers();
    c.search = 'r'; await c.findCustomers();
    pending[0].release([{ id: 1, label: 'Ram' }]);
    await first; await tick();
    assert.equal(c.open, false, 'no dropdown for text that is gone');
    assert.deepEqual(c.results, []);
});

test('Historical picker: the spinner stays while the latest search is still running', async () => {
    const { c, pending } = picker();
    c.search = 'ram'; const first = c.findCustomers();
    c.search = 'rame'; c.findCustomers();
    await first; await tick(); // the first one was aborted and has settled
    assert.equal(c.loading, true);
    pending[1].release([]);
});

test('the POS customer search uses the same rule', () => {
    const pos = readFileSync(new URL('../../resources/views/pos.blade.php', import.meta.url), 'utf8');
    // Found in the browser: the page's inline script runs before app.js (a deferred module) on a full load, so the
    // helper may only be created on first use, never while the script is first running.
    assert.match(pos, /\(custSearchState \?\?= window\.createLatestRequest\(\)\)/);
    assert.doesNotMatch(pos, /^\s*(const|let|var)\s+\w+\s*=\s*window\.create\w+\(\)/m, 'nothing from app.js is called at load');
    assert.match(pos, /custSearch\.isLatest\(ticket\)/);
    assert.doesNotMatch(pos, /custAbort/, 'no shared abort controller left');
    assert.match(readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8'), /window\.createLatestRequest = createLatestRequest/);
});
