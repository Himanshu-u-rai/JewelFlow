import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// One bell for everything (owner request, 2026-11-14). The header bell carries every notice and redraws itself in
// place; the stock list lost its own bell and opens its price-review drawer only when the bell sends the user there.

const bellView = readFileSync(new URL('../../resources/views/components/notice-bell.blade.php', import.meta.url), 'utf8');
const bellScript = bellView.slice(bellView.indexOf('window.noticeBell'), bellView.lastIndexOf('</script>'));
const stockView = readFileSync(new URL('../../resources/views/inventory/items/index.blade.php', import.meta.url), 'utf8');
const drawerSource = stockView.slice(stockView.indexOf('function pricingAlertDrawer'), stockView.indexOf('</script>', stockView.indexOf('function pricingAlertDrawer')));

const tick = () => new Promise((r) => setTimeout(r, 0));

function makeBell(fetchImpl) {
    const timers = [];
    const calls = [];
    const pill = { textContent: '0', hidden: true };
    const doc = { hidden: false, querySelector: (sel) => (sel === '[data-reorder-pill]' ? pill : sel.includes('csrf') ? { content: 'tok' } : null) };
    const win = {};
    const fakeSetTimeout = (fn, ms) => { timers.push({ fn, ms, cleared: false }); return timers.length - 1; };
    const fakeClear = (id) => { if (timers[id]) timers[id].cleared = true; };
    new Function('window', 'document', 'fetch', 'setTimeout', 'clearTimeout', bellScript)(
        win, doc, (...a) => { calls.push(a); return fetchImpl(calls.length, a); }, fakeSetTimeout, fakeClear,
    );
    const bell = win.noticeBell('/notices', '/notices/downloads/clear', 2);
    bell.$el = { isConnected: true, dataset: {} };
    bell.$refs = { body: { innerHTML: 'page rows' } };
    bell.init();
    return { bell, calls, timers, pill, doc };
}

const answer = (data) => Promise.resolve({ ok: true, json: () => Promise.resolve(data) });
const fresh = { count: 3, reorder_count: 1, html: 'fresh rows' };

test('opening the bell redraws it from the server, once however often it is clicked', async () => {
    let resolve;
    const { bell, calls } = makeBell(() => new Promise((r) => { resolve = r; }));
    bell.toggle();
    bell.toggle();
    bell.toggle();
    assert.equal(calls.length, 1, 'one request in flight');
    resolve({ ok: true, json: () => Promise.resolve(fresh) });
    await tick();
    assert.equal(bell.count, 3);
    assert.equal(bell.$refs.body.innerHTML, 'fresh rows');
});

test('a redraw updates the badge, its data and the sidebar reorder pill', async () => {
    const { bell, pill } = makeBell(() => answer(fresh));
    await bell.refresh();
    assert.equal(bell.$el.dataset.noticeCount, '3');
    assert.equal(bell.$el.dataset.reorderCount, '1');
    assert.equal(pill.textContent, '1');
    assert.equal(pill.hidden, false);
});

test('a failed redraw keeps what the page showed', async () => {
    const { bell } = makeBell(() => Promise.reject(new Error('offline')));
    await bell.refresh();
    assert.equal(bell.count, 2);
    assert.equal(bell.$refs.body.innerHTML, 'page rows');
});

test('Clear posts with the CSRF token and redraws', async () => {
    const { bell, calls } = makeBell(() => answer({ count: 0, reorder_count: 0, html: 'All clear' }));
    bell.onBodyClick({ target: { closest: (sel) => sel === '[data-notice-clear]' }, preventDefault() {} });
    await tick();
    const [url, opts] = calls[0];
    assert.equal(url, '/notices/downloads/clear');
    assert.equal(opts.method, 'POST');
    assert.equal(opts.headers['X-CSRF-TOKEN'], 'tok');
    assert.equal(bell.count, 0);
});

test('starting a download redraws the bell shortly after', async () => {
    const { bell, timers, calls } = makeBell(() => answer(fresh));
    bell.onBodyClick({ target: { closest: (sel) => sel === '[data-notice-download]' } });
    const later = timers.find((t) => t.ms === 2000);
    assert.ok(later, 'a redraw is scheduled');
    later.fn();
    await tick();
    assert.equal(calls.length, 1);
});

test('the slow refresh skips a hidden tab, keeps going, and stops for good once the bell is gone', async () => {
    const { bell, timers, calls, doc } = makeBell(() => answer(fresh));
    const first = timers.find((t) => t.ms === 90000);
    doc.hidden = true;
    await first.fn();
    assert.equal(calls.length, 0, 'no request from a hidden tab');
    assert.equal(timers.filter((t) => t.ms === 90000).length, 2, 'still scheduled');

    bell.destroy();
    const second = timers.filter((t) => t.ms === 90000)[1];
    assert.equal(second.cleared, true, 'Turbo removing the page ends the timer');
    doc.hidden = false;
    await second.fn();
    assert.equal(calls.length, 0, 'an old schedule never polls');
});

function makeDrawer(search) {
    const replaced = [];
    const env = {
        document: { querySelector: () => null, addEventListener() {} },
        localStorage: { getItem: () => null, setItem() {} },
        window: { location: { search, pathname: '/inventory/items', hash: '' }, history: { replaceState: (...a) => replaced.push(a) } },
        fetch: () => answer({ count: 0, items: [] }),
    };
    const drawer = new Function('document', 'localStorage', 'window', 'fetch', `${drawerSource}; return pricingAlertDrawer;`)(
        env.document, env.localStorage, env.window, env.fetch,
    )(3, '/alerts');
    return { drawer, replaced };
}

test('the stock list no longer has a bell of its own', () => {
    assert.doesNotMatch(stockView, /id="pricing-alert-bell"/);
});

test('the price-review drawer opens when the bell sends the user there, and never by itself', () => {
    const sent = makeDrawer('?alerts=pricing');
    sent.drawer.init();
    assert.equal(sent.drawer.isOpen, true);

    const plain = makeDrawer('');
    plain.drawer.init();
    assert.equal(plain.drawer.isOpen, false, 'no once-a-day pop-up: the bell carries the count now');
});

test('closing the drawer drops the bell\'s link from the address, so a reload does not reopen it', () => {
    const { drawer, replaced } = makeDrawer('?alerts=pricing&page=2');
    drawer.init();
    drawer.close();
    assert.equal(replaced.length, 1);
    assert.equal(replaced[0][2], '/inventory/items?page=2');
});
