import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

const source = new URL('../../resources/js/list-workspace.js', import.meta.url);
const available = existsSync(source);
const module = available ? await import(source) : {};

// Real controller; only DOM measurement, observers and events are boundaries.
function fixture({ width = 1440, height = 1000, top = 120, overflow = false, shell = false, supported = true, optedIn = true } = {}) {
    assert.ok(available, 'Opt-in list workspace controller must exist.');
    const events = new Map(), frames = new Map(), observers = [], mutations = [];
    let serial = 0;
    function element(markers = []) {
        const attrs = new Map(markers.map(m => [m, '']));
        const values = new Map();
        return {
            children: [], parentElement: null, isConnected: true, visible: true,
            attrs, values, scrollTop: 0,
            style: { setProperty: (k, v) => values.set(k, v), removeProperty: k => values.delete(k) },
            setAttribute: (k, v) => attrs.set(k, v), removeAttribute: k => attrs.delete(k),
            hasAttribute: k => attrs.has(k),
            matches: selector => selector.split(',').some(s => attrs.has(s.trim().slice(1, -1))),
            getClientRects() { return this.visible ? [{}] : []; },
            contains(node) { return node === this || this.children.some(c => c.contains(node)); },
            getBoundingClientRect: () => ({ top: 0, bottom: 1000, height: 48 }),
        };
    }
    const root = element(['data-list-workspace']);
    const panel = element(['data-list-panel']), card = element(['data-list-frame']);
    const tabs = element(), kpis = element(), header = element(), footer = element();
    const scroll = element(['data-list-scroll']), second = element(['data-list-scroll']);
    second.visible = false;
    scroll.querySelector = () => ({ getBoundingClientRect: () => ({ height: 48 }) });
    second.querySelector = () => ({ getBoundingClientRect: () => ({ height: 72 }) });
    const parent = element();
    const outerHeader = element();
    parent.children = [outerHeader, root];
    parent.paddingBottom = '24px'; parent.overflowY = shell ? 'auto' : 'visible';
    const body = element(); body.parentElement = null;
    parent.parentElement = body; root.parentElement = parent;
    root.children = [tabs, panel]; panel.children = [kpis, card]; card.children = [header, scroll, second, footer];
    for (const p of [root, panel, card]) for (const c of p.children) c.parentElement = p;
    root.boxTop = top;
    root.closest = () => parent;
    root.getBoundingClientRect = () => ({ top: root.boxTop, bottom: 1500 });
    Object.defineProperties(root, {
        clientHeight: { get: () => Number.parseFloat(root.values.get('--jf-list-available')) || 1200 },
        scrollHeight: { get: () => root.clientHeight + (overflow ? 50 : 0) },
    });
    root.querySelectorAll = selector => selector === '[data-list-scroll]' ? [scroll, second] : [panel, card];
    const doc = {
        readyState: 'complete', body,
        querySelectorAll: () => optedIn ? [root] : [],
        addEventListener(type, fn) { const group = events.get(type) ?? []; group.push(fn); events.set(type, group); },
    };
    const win = {
        innerWidth: width, innerHeight: height, scrollY: 0,
        getComputedStyle: e => ({ overflowY: e.overflowY ?? 'visible', paddingBottom: e.paddingBottom ?? '0px' }),
        requestAnimationFrame(fn) { frames.set(++serial, fn); return serial; },
        cancelAnimationFrame: id => frames.delete(id),
        addEventListener: (...args) => doc.addEventListener(...args),
        ResizeObserver: class { constructor(fn) { this.fn = fn; this.nodes = []; observers.push(this); } observe(n) { this.nodes.push(n); } disconnect() { this.closed = true; } },
        MutationObserver: class { constructor(fn) { this.fn = fn; mutations.push(this); } observe() {} disconnect() { this.closed = true; } },
    };
    if (!supported) win.ResizeObserver = undefined;
    const flush = () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(fn => fn()); };
    const send = type => { for (const fn of events.get(type) ?? []) fn(); flush(); };
    module.initializeListWorkspaces(doc, win); flush();
    return { root, scroll, second, panel, parent, outerHeader, win, doc, events, frames, observers, mutations, flush, send };
}

test('desktop uses remaining viewport space, not a fixed table height, and measures its header', () => {
    const f = fixture();
    assert.equal(f.root.attrs.has('data-list-fit'), true);
    assert.equal(f.root.values.get('--jf-list-available'), '856px');
    assert.equal(f.scroll.values.get('--jf-list-head-size'), '48px');
    f.win.innerHeight = 1100; f.send('resize');
    assert.equal(f.root.values.get('--jf-list-available'), '956px');
});

test('short and narrow windows retain normal page scrolling, including after resizing', () => {
    const f = fixture();
    f.win.innerHeight = 650; f.send('resize');
    assert.equal(f.root.attrs.has('data-list-fit'), false);
    assert.equal(f.root.values.has('--jf-list-available'), false);
    f.win.innerHeight = 1000; f.win.innerWidth = 800; f.send('resize');
    assert.equal(f.root.attrs.has('data-list-fit'), false);
});

test('oversized controls or text zoom fall back instead of clipping the workspace', () => {
    const f = fixture({ overflow: true });
    assert.equal(f.root.attrs.has('data-list-fit'), false);
    assert.equal(f.root.values.has('--jf-list-available'), false);
});

test('internal shell scrolling does not change the natural workspace origin', () => {
    const f = fixture({ shell: true });
    f.parent.scrollTop = 40; f.send('resize');
    assert.equal(f.root.values.get('--jf-list-available'), '816px');
});

test('Alpine tab changes measure the newly visible table, never multiple lists together', () => {
    const f = fixture();
    f.scroll.visible = false; f.second.visible = true;
    f.mutations[0].fn([{ target: f.panel }]); f.flush();
    assert.equal(f.second.values.get('--jf-list-head-size'), '72px');
    f.scroll.visible = true;
    f.mutations[0].fn([{ target: f.panel }]); f.flush();
    assert.equal(f.root.attrs.has('data-list-fit'), false);
});

test('table-internal style changes do not cause fitting feedback loops', () => {
    const f = fixture();
    f.mutations[0].fn([{ target: f.scroll }]);
    assert.equal(f.frames.size, 0);
});

test('a wrapping page header outside the workspace triggers a new available-space measurement', () => {
    const f = fixture();
    assert.ok(f.observers[0].nodes.includes(f.outerHeader));
    f.root.boxTop = 200;
    f.observers[0].fn(); f.flush();
    assert.equal(f.root.values.get('--jf-list-available'), '776px');
});

test('missing observer support leaves the ordinary page intact without breaking application startup', () => {
    const f = fixture({ supported: false });
    assert.equal(f.root.attrs.has('data-list-fit'), false);
    assert.equal(f.observers.length, 0);
});

test('unmarked forms, dashboards and editable grids are never sized or observed', () => {
    const f = fixture({ optedIn: false });
    assert.equal(f.observers.length, 0);
    assert.equal(f.root.attrs.has('data-list-fit'), false);
    assert.equal(f.frames.size, 0);
});

test('Turbo cleans sizing and observers, remounts once and leaves unrelated pages alone', () => {
    const f = fixture();
    f.send('turbo:before-cache');
    assert.equal(f.root.attrs.has('data-list-fit'), false);
    assert.equal(f.root.values.has('--jf-list-available'), false);
    assert.equal(f.scroll.values.has('--jf-list-head-size'), false);
    assert.ok(f.observers.every(o => o.closed));
    assert.ok(f.mutations.every(o => o.closed));
    f.send('turbo:load');
    assert.equal(f.root.attrs.has('data-list-fit'), true);
    module.initializeListWorkspaces(f.doc, f.win);
    assert.equal(f.events.get('turbo:load').length, 1);
});
