import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

const source = new URL('../../resources/js/repair-intake.js', import.meta.url);
const controller = existsSync(source) ? await import(source) : {};

function fixture({ room = 400, height = 925, errors = false, animated = false, reducedMotion = false } = {}) {
    assert.equal(typeof controller.createRepairIntake, 'function', 'The repair intake scroll controller must exist');
    const listeners = new Map(), frames = new Map(), attrs = new Map(), styles = new Map();
    let serial = 0;
    const target = () => ({
        addEventListener(type, fn) { listeners.set([this.key, type].join(':'), fn); },
        removeEventListener(type) { listeners.delete([this.key, type].join(':')); },
    });
    const shell = { ...target(), key: 'shell', scrollTop: 0,
        getBoundingClientRect: () => ({ bottom: height }),
        scrollTo({ top }) { this.scrollTop = top; },
    };
    const summary = { ...target(), key: 'summary' };
    const form = { ...target(), key: 'form', draft: 'Unsaved repair description', contains: node => node === form };
    const details = { open: true, querySelector: s => s === 'summary' ? summary : form };
    const motions = [];
    details.getBoundingClientRect = () => ({ height: details.open ? 460 : 80 });
    summary.getBoundingClientRect = () => ({ height: 80 });
    details.setAttribute = (k, v) => attrs.set(k, v);
    details.removeAttribute = k => attrs.delete(k);
    if (animated) details.animate = (keyframes, options) => {
        const motion = { keyframes, options, cancel() { this.cancelled = true; }, finish() { this.onfinish?.(); } };
        motions.push(motion);
        return motion;
    };
    const pane = { clientHeight: room, scrollTop: 0, getClientRects: () => [{}] };
    const root = {
        parentElement: shell, dataset: { intakeErrors: String(errors) }, isConnected: true,
        querySelector: () => details,
        querySelectorAll: s => s === '[data-repair-rows]' ? [pane] : [],
        getBoundingClientRect: () => ({ top: 90 - shell.scrollTop }),
        setAttribute: (k, v) => attrs.set(k, v), removeAttribute: k => attrs.delete(k),
        style: { setProperty: (k, v) => styles.set(k, v), removeProperty: k => styles.delete(k) },
    };
    const doc = { body: {}, activeElement: null };
    const observers = [];
    const win = {
        ...target(), key: 'win', innerHeight: height, scrollY: 0,
        matchMedia: () => ({ matches: reducedMotion }),
        getComputedStyle: () => ({ overflowY: 'auto' }),
        requestAnimationFrame(fn) { frames.set(++serial, fn); return serial; },
        cancelAnimationFrame: id => frames.delete(id),
        ResizeObserver: class { constructor(fn) { this.fn = fn; observers.push(this); } observe() {} disconnect() { this.closed = true; } },
    };
    const flush = () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(fn => fn()); };
    const send = (key, type, extras = {}) => { listeners.get(`${key}:${type}`)?.({ preventDefault() {}, ...extras }); flush(); };
    const dispose = controller.createRepairIntake(root, doc, win);
    flush();
    return { root, shell, summary, form, details, pane, attrs, styles, doc, win, frames, observers, listeners, motions, dispose, send, flush };
}

test('closing slides to the summary before hiding content and fitting the register', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click');
    assert.equal(f.motions.length, 1);
    assert.equal(f.details.open, true, 'Native details must remain open until its closing animation finishes');
    assert.equal(f.form.inert, true, 'Fields being hidden cannot receive focus');
    assert.equal(f.attrs.has('data-repair-fit'), false);
    assert.deepEqual(f.motions[0].keyframes, [{ height: '460px' }, { height: '80px' }]);
    f.motions[0].finish();
    assert.equal(f.details.open, false);
    assert.equal(f.form.inert, false);
    assert.equal(f.attrs.has('data-repair-fit'), true);
    f.send('summary', 'click');
    assert.equal(f.motions.length, 2);
    assert.equal(f.details.open, true);
    f.motions[1].finish();
    assert.equal(f.form.draft, 'Unsaved repair description');
});

test('reduced motion applies the final state immediately without spatial animation', () => {
    const f = fixture({ animated: true, reducedMotion: true });
    f.send('summary', 'click');
    assert.equal(f.motions.length, 0);
    assert.equal(f.details.open, false);
    assert.equal(f.attrs.has('data-repair-fit'), true);
});

test('rapid reversal cancels the old animation and invalid fields are revealed immediately', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click');
    f.send('summary', 'click');
    assert.equal(f.motions[0].cancelled, true);
    assert.equal(f.motions.length, 2);
    f.motions[0].finish();
    assert.equal(f.details.open, true, 'A superseded close must not hide a newer open');
    f.send('form', 'invalid');
    assert.equal(f.motions[1].cancelled, true);
    assert.equal(f.details.open, true);
    assert.equal(f.form.inert, false);
});

test('navigation cancels in-flight animation and removes temporary clipping and inert state', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click');
    f.dispose();
    assert.equal(f.motions[0].cancelled, true);
    assert.equal(f.form.inert, false);
    assert.equal(f.attrs.size, 0);
    f.motions[0].finish();
    assert.equal(f.details.open, true);
});

test('the summary border resize cannot cancel reopening, while a real viewport resize settles it safely', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click'); f.motions[0].finish();
    f.send('summary', 'click');
    f.observers[0].fn();
    assert.notEqual(f.motions[1].cancelled, true, 'The native open summary gains a border during its own animation');
    f.send('win', 'resize');
    assert.equal(f.motions[1].cancelled, true);
    assert.equal(f.details.open, true);
});

test('starts expanded, collapses on page scroll and gives the register remaining viewport space', () => {
    const f = fixture();
    assert.equal(f.details.open, true);
    f.shell.scrollTop = 40; f.send('shell', 'scroll');
    assert.equal(f.details.open, false);
    assert.equal(f.attrs.has('data-repair-fit'), true);
    assert.equal(f.styles.get('--repair-workspace-height'), '836px');
    assert.equal(f.shell.scrollTop, 1, 'A one-pixel return path prevents collapse/reopen scroll-clamping loops');
    f.send('shell', 'scroll');
    assert.equal(f.details.open, false, 'Layout-induced scroll must not reopen the intake');
});

test('scrolling back to the page top reopens the original form and preserves draft input', () => {
    const f = fixture();
    f.shell.scrollTop = 80; f.send('shell', 'scroll');
    f.send('shell', 'wheel', { deltaY: -100 });
    f.shell.scrollTop = 0; f.send('shell', 'scroll');
    assert.equal(f.details.open, true);
    assert.equal(f.attrs.has('data-repair-fit'), false);
    assert.equal(f.form.draft, 'Unsaved repair description');
});

test('focus or scroll-into-view adjustments do not reopen just before a manual click toggles the form', () => {
    const f = fixture();
    f.send('summary', 'click');
    f.shell.scrollTop = 0; f.send('shell', 'scroll');
    assert.equal(f.details.open, false, 'A programmatic one-pixel adjustment is not an upward browsing gesture');
    f.send('summary', 'click');
    assert.equal(f.details.open, true);
});

test('an upward gesture still reopens after focus has already adjusted the page to zero', () => {
    const f = fixture();
    f.send('summary', 'click');
    f.shell.scrollTop = 0; f.send('shell', 'scroll');
    f.send('shell', 'wheel', { deltaY: -100 });
    assert.equal(f.details.open, true, 'An already-zero page has no further scroll event to wait for');
});

test('upward gestures first scroll the repair rows, then reopen when those rows reach the top', () => {
    const f = fixture();
    f.send('summary', 'click');
    f.pane.scrollTop = 100;
    f.send('shell', 'wheel', { deltaY: -50, target: { closest: () => f.pane } });
    assert.equal(f.details.open, false);
    f.pane.scrollTop = 0;
    f.send('shell', 'wheel', { deltaY: -50, target: { closest: () => f.pane } });
    assert.equal(f.details.open, true);
});

test('upward touch and keyboard scrolling can reopen the intake, not just the mouse wheel', () => {
    for (const mode of ['touch', 'keyboard']) {
        const f = fixture();
        f.send('summary', 'click');
        if (mode === 'touch') {
            f.send('shell', 'touchstart', { touches: [{ clientY: 100 }] });
            f.send('shell', 'touchmove', { touches: [{ clientY: 150 }] });
        } else f.send('shell', 'keydown', { key: 'Home' });
        f.shell.scrollTop = 0; f.send('shell', 'scroll');
        assert.equal(f.details.open, true);
    }
});

test('manual summary activation opens the intake and resets the page and rows to the top', () => {
    const f = fixture();
    f.send('summary', 'click');
    assert.equal(f.details.open, false);
    f.pane.scrollTop = 120;
    f.send('summary', 'click');
    assert.equal(f.details.open, true);
    assert.equal(f.shell.scrollTop, 0);
    assert.equal(f.pane.scrollTop, 0);
});

test('scrolling never hides the field being edited or a server validation error', () => {
    const f = fixture();
    f.doc.activeElement = f.form;
    f.shell.scrollTop = 60; f.send('shell', 'scroll');
    assert.equal(f.details.open, true);
    const e = fixture({ errors: true });
    e.shell.scrollTop = 60; e.send('shell', 'scroll');
    assert.equal(e.details.open, true);
});

test('native invalid events reveal the form before the browser focuses a required field', () => {
    const f = fixture();
    f.send('summary', 'click');
    f.send('form', 'invalid');
    assert.equal(f.details.open, true);
    assert.equal(f.shell.scrollTop, 0);
});

test('short windows or wrapped controls fall back to normal page scrolling rather than clipping rows', () => {
    for (const options of [{ height: 500 }, { room: 100 }]) {
        const f = fixture(options);
        f.shell.scrollTop = 50; f.send('shell', 'scroll');
        assert.equal(f.details.open, false);
        assert.equal(f.attrs.has('data-repair-fit'), false);
        assert.equal(f.styles.has('--repair-workspace-height'), false);
    }
});

test('row scrolling does not collapse or reopen the form; only returning the page to top does', () => {
    const f = fixture();
    f.pane.scrollTop = 100;
    f.send('shell', 'scroll');
    assert.equal(f.details.open, true);
    f.shell.scrollTop = 40; f.send('shell', 'scroll');
    f.pane.scrollTop = 0; f.send('shell', 'scroll');
    assert.equal(f.details.open, false);
});

test('Turbo disposal removes listeners, observers and fitting and restores the native expanded fallback', () => {
    const f = fixture();
    f.send('summary', 'click');
    f.dispose();
    assert.equal(f.details.open, true);
    assert.equal(f.attrs.size, 0);
    assert.equal(f.styles.size, 0);
    assert.equal(f.listeners.size, 0);
    assert.ok(f.observers.every(o => o.closed));
    assert.equal(f.frames.size, 0);
});
