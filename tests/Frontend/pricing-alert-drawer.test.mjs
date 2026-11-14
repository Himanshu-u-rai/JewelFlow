import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(new URL('../../resources/views/inventory/items/index.blade.php', import.meta.url), 'utf8');

// Audit N9: the drawer kept only isOpen + loading, so a failed load looked like "no alerts" while the bell still
// showed a count, and opening it twice fetched twice. It now has one explicit load state, an error with retry, and
// one load in flight.
const source = page.slice(page.indexOf('function pricingAlertDrawer'), page.indexOf('</script>', page.indexOf('function pricingAlertDrawer')));

function makeDrawer(fetchImpl) {
    const store = new Map();
    const calls = [];
    const env = {
        document: { querySelector: () => null, addEventListener() {} },
        localStorage: { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) },
        window: {},
        fetch: (...a) => { calls.push(a); return fetchImpl(calls.length); },
    };
    const factory = new Function('document', 'localStorage', 'window', 'fetch', `${source}; return pricingAlertDrawer;`);
    const drawer = factory(env.document, env.localStorage, env.window, env.fetch)(3, '/alerts', 'k');
    return { drawer, calls, store };
}

const ok = (items) => Promise.resolve({ ok: true, json: () => Promise.resolve({ count: items.length, items }) });
const tick = () => new Promise((r) => setTimeout(r, 0));

test('a failed load is an error state, not an empty list', async () => {
    const { drawer } = makeDrawer(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.reject(new Error('x')) }));
    drawer.open();
    await tick();
    assert.equal(drawer.state, 'error');
    assert.equal(drawer.count, 3, 'the bell count is not zeroed by a failure');
});

test('opening twice while loading fetches once', async () => {
    let resolve;
    const { drawer, calls } = makeDrawer(() => new Promise((r) => { resolve = r; }));
    drawer.open();
    drawer.close();
    drawer.open();
    assert.equal(drawer.state, 'loading');
    assert.equal(calls.length, 1);
    resolve({ ok: true, json: () => Promise.resolve({ count: 1, items: [{ id: 1 }] }) });
    await tick();
    assert.equal(drawer.state, 'loaded');
    assert.equal(drawer.items.length, 1);
});

test('retry after a failure loads again; a loaded list is not fetched again', async () => {
    const { drawer, calls } = makeDrawer((n) => (n === 1 ? Promise.reject(new Error('offline')) : ok([{ id: 7 }])));
    drawer.open();
    await tick();
    assert.equal(drawer.state, 'error');
    drawer.retry();
    await tick();
    assert.equal(drawer.state, 'loaded');
    assert.equal(calls.length, 2);
    drawer.close();
    drawer.open();
    await tick();
    assert.equal(calls.length, 2, 'already loaded');
});

test('the markup shows the error with a retry button', () => {
    assert.match(page, /x-if="state === 'error'"/);
    assert.match(page, /@click="retry\(\)"/);
    assert.match(page, /x-if="state === 'loaded' && items\.length === 0"/);
    assert.doesNotMatch(page, /x-if="!loading && items\.length === 0"/);
});
