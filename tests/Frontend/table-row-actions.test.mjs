import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const source = new URL('../../resources/js/table-row-actions.js', import.meta.url);
const available = existsSync(source);
const module = available ? await import(source) : {};
const css = readFileSync(new URL('../../resources/css/tables.css', import.meta.url), 'utf8');

// Only the DOM/event boundaries are stubbed; these exercise the real label controller.
function fixture({ left = 150, top = 100, fine = true, popover = true, header = false } = {}) {
    assert.ok(available, 'Shared row labels must escape table overflow and support dismissal.');
    const listeners = new Map();
    const frames = new Map();
    const timers = new Map();
    const nodes = [];
    let serial = 0;
    const doc = {
        activeElement: null,
        addEventListener(type, fn) { const group = listeners.get(type) ?? []; group.push(fn); listeners.set(type, group); },
        body: { appendChild(node) { nodes.push(node); } },
        createElement() {
            const node = {
                attrs: {}, style: {}, className: '', textContent: '', removed: false,
                setAttribute(name, value) { this.attrs[name] = value; },
                removeAttribute(name) { delete this.attrs[name]; },
                getBoundingClientRect: () => ({ width: 90, height: 24 }),
                contains: other => other === node,
                remove() { this.removed = true; },
            };
            if (popover) node.showPopover = () => { node.shown = true; };
            return node;
        },
    };
    const win = {
        innerWidth: 320, innerHeight: 240,
        matchMedia: () => ({ matches: fine }),
        requestAnimationFrame(fn) { frames.set(++serial, fn); return serial; },
        cancelAnimationFrame(id) { frames.delete(id); },
        setTimeout(fn) { timers.set(++serial, fn); return serial; },
        clearTimeout(id) { timers.delete(id); },
        addEventListener: (...args) => doc.addEventListener(...args),
    };
    const action = {
        dataset: { tip: 'View Profile' }, disabled: false, isConnected: true,
        box: { left, top, width: 36, height: 36, right: left + 36, bottom: top + 36 },
        closest: selector => selector.split(',').map(s => s.trim()).includes(header ? '.repairs-show-header-actions [data-tip]' : '.jf-row-action[data-tip]') ? action : null,
        matches: () => doc.activeElement === action,
        contains: other => other === action,
        getBoundingClientRect() { return this.box; },
    };
    const send = (type, extra = {}) => (listeners.get(type) ?? []).forEach(fn => fn({ target: action, ...extra }));
    const flush = () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(fn => fn()); };
    module.initializeRowActionLabels(doc, win);
    const focus = () => { doc.activeElement = action; send('focusin'); flush(); };
    return { action, doc, win, nodes, send, flush, focus, timers, listeners };
}

test('keyboard labels mount outside the table, use native popovers and keep the control named', () => {
    const f = fixture();
    f.focus();
    assert.equal(f.nodes.length, 1);
    const label = f.nodes[0];
    assert.equal(label.className, 'jf-row-action-label');
    assert.equal(label.textContent, 'View Profile');
    assert.equal(label.attrs['aria-hidden'], 'true', 'the existing accessible name is not announced twice');
    assert.equal(label.shown, true);
    assert.equal(f.doc.activeElement, f.action, 'labels never steal keyboard focus');
    assert.match(css, /\.jf-row-action-label\s*\{[^}]*position:\s*fixed/s);
    assert.doesNotMatch(css, /\.jf-row-action\[data-tip\]::after\s*\{/);
});

test('compact repair header actions reuse the same focus label and Escape dismissal', () => {
    const f = fixture({ header: true, top: 5 });
    f.action.dataset.tip = 'Back to Repairs';
    f.focus();
    assert.equal(f.nodes.length, 1);
    assert.equal(f.nodes[0].textContent, 'Back to Repairs');
    assert.equal(f.nodes[0].style.top, '47px');
    f.send('keydown', { key: 'Escape' });
    assert.equal(f.nodes[0].removed, true);
    assert.equal(f.doc.activeElement, f.action);
});

test('labels clamp at both viewport edges and flip below a top-edge control', () => {
    for (const [left, expected] of [[0, '8px'], [300, '222px']]) {
        const f = fixture({ left, top: 5 });
        f.focus();
        assert.equal(f.nodes[0].style.left, expected);
        assert.equal(f.nodes[0].style.top, '47px');
    }
});

test('Escape dismisses without losing focus or immediately reopening on the same control', () => {
    const f = fixture();
    f.focus();
    f.send('keydown', { key: 'Escape' });
    assert.equal(f.nodes[0].removed, true);
    assert.equal(f.doc.activeElement, f.action);
    f.send('pointerover', { pointerType: 'mouse' }); f.flush();
    assert.equal(f.nodes.length, 1);
    f.doc.activeElement = null;
    f.send('focusout');
    f.focus();
    assert.equal(f.nodes.length, 2, 'returning focus may show the label again');
});

test('scroll and resize reposition a focused label; Turbo removes it without duplicate listeners', () => {
    const f = fixture();
    f.focus();
    f.action.box.top = 160; f.action.box.bottom = 196;
    f.send('scroll'); f.flush();
    assert.equal(f.nodes[0].style.top, '130px');
    f.win.innerWidth = 180;
    f.send('resize'); f.flush();
    assert.equal(f.nodes[0].style.left, '82px');
    f.send('turbo:before-cache');
    assert.equal(f.nodes[0].removed, true);
    module.initializeRowActionLabels(f.doc, f.win);
    assert.equal(f.listeners.get('focusin').length, 1);
    f.focus();
    assert.equal(f.nodes.length, 2);
    f.action.isConnected = false;
    f.send('scroll'); f.flush();
    assert.equal(f.nodes[1].removed, true);
});

test('mouse labels are hoverable and fall back to a body layer when popovers are unavailable', () => {
    const f = fixture({ popover: false });
    f.action.dataset.tip = '<b>literal label</b>';
    f.send('pointerover', { pointerType: 'mouse' }); f.flush();
    assert.equal(f.nodes[0].textContent, '<b>literal label</b>');
    f.send('pointerout', { relatedTarget: null });
    f.send('pointerover', { target: f.nodes[0], pointerType: 'mouse' });
    assert.equal(f.timers.size, 0, 'moving over the label cancels the gap timer');
    f.send('pointerout', { target: f.nodes[0], relatedTarget: null });
    [...f.timers.values()].forEach(fn => fn());
    assert.equal(f.nodes[0].removed, true);
});

test('touch does not show hover-only labels, but keyboard focus still does', () => {
    const f = fixture({ fine: false });
    f.send('pointerover', { pointerType: 'touch' }); f.flush();
    assert.equal(f.nodes.length, 0);
    f.focus();
    assert.equal(f.nodes.length, 1);
});

test('moving from a control to the rest of the page does not leave a stuck label', () => {
    const f = fixture();
    f.send('pointerover', { pointerType: 'mouse' }); f.flush();
    f.send('pointerout', { relatedTarget: null });
    f.send('pointerover', { target: { closest: () => null }, pointerType: 'mouse' });
    assert.equal(f.timers.size, 1);
    [...f.timers.values()].forEach(fn => fn());
    assert.equal(f.nodes[0].removed, true);
});
