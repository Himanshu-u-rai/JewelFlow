import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

const source = new URL('../../resources/js/repair-intake.js', import.meta.url);
const controller = existsSync(source) ? await import(source) : {};

function fixture({ room = 400, height = 925, width = 0, cardHeight = 460, errors = false, animated = false, reducedMotion = false } = {}) {
    assert.equal(typeof controller.createRepairIntake, 'function', 'The repair intake scroll controller must exist');
    const listeners = new Map(), frames = new Map(), attrs = new Map(), styles = new Map();
    let serial = 0;
    const target = () => ({
        addEventListener(type, fn) { listeners.set([this.key, type].join(':'), fn); },
        removeEventListener(type) { listeners.delete([this.key, type].join(':')); },
    });
    const shell = { ...target(), key: 'shell', scrollTop: 0,
        getBoundingClientRect: () => ({ top: 0, bottom: height }),
        scrollTo({ top }) { this.scrollTop = top; },
    };
    const summary = { ...target(), key: 'summary' };
    const form = { ...target(), key: 'form', draft: 'Unsaved repair description', contains: node => node === form };
    const body = {};
    const details = { open: true, querySelector: s => s === 'summary' ? summary : s === '[data-repair-intake-body]' ? body : form };
    const motions = [];
    details.getBoundingClientRect = () => ({ height: details.open ? cardHeight : 80 });
    summary.getBoundingClientRect = () => ({ height: 80 });
    details.setAttribute = (k, v) => attrs.set(k, v);
    details.removeAttribute = k => attrs.delete(k);
    body.getBoundingClientRect = () => ({ height: !details.open ? 0 :
        motions.at(-1) && !motions.at(-1).cancelled ? motions.at(-1).renderedHeight :
            attrs.has('data-repair-steady') ? 380 - Number.parseFloat(styles.get('--repair-scroll-offset') || '0') : 380 });
    if (animated) for (const element of [details, body]) element.animate = (keyframes, options) => {
        const motion = { target: element, keyframes, options, renderedHeight: Number.parseFloat(keyframes[0].height),
            cancel() { this.cancelled = true; }, finish() { this.renderedHeight = Number.parseFloat(keyframes.at(-1).height); this.onfinish?.(); } };
        motions.push(motion);
        return motion;
    };
    const pane = { clientHeight: room, scrollTop: 0, getClientRects: () => [{}] };
    const layout = {};
    const root = {
        parentElement: shell, dataset: { intakeErrors: String(errors) }, isConnected: true,
        querySelector: s => s === '.repairs-layout' ? layout : details,
        querySelectorAll: s => s === '[data-repair-rows]' ? [pane] : [],
        getBoundingClientRect: () => ({ top: 90 - shell.scrollTop }),
        contains: node => !node?.outsideWorkspace,
        setAttribute: (k, v) => attrs.set(k, v), removeAttribute: k => attrs.delete(k),
        style: { setProperty: (k, v) => styles.set(k, v), removeProperty: k => styles.delete(k) },
    };
    const doc = { body: {}, activeElement: null };
    const observers = [];
    const win = {
        ...target(), key: 'win', innerHeight: height, innerWidth: width, scrollY: 0,
        matchMedia: () => ({ matches: reducedMotion }),
        getComputedStyle: e => ({ overflowY: e.overflowY ?? 'auto', paddingTop: e === root ? '32px' : '0px',
            paddingBottom: e === root ? '32px' : '0px', rowGap: e === layout ? '20px' : '0px' }),
        requestAnimationFrame(fn) { frames.set(++serial, fn); return serial; },
        cancelAnimationFrame: id => frames.delete(id),
        ResizeObserver: class { constructor(fn) { this.fn = fn; observers.push(this); } observe() {} disconnect() { this.closed = true; } },
    };
    const flush = () => { const pending = [...frames.values()]; frames.clear(); pending.forEach(fn => fn()); };
    const send = (key, type, extras = {}) => { listeners.get(`${key}:${type}`)?.({ preventDefault() {}, ...extras }); flush(); };
    const dispose = controller.createRepairIntake(root, doc, win);
    flush();
    return { root, shell, summary, form, details, body, pane, attrs, styles, doc, win, frames, observers, listeners, motions, dispose, send, flush };
}

test('desktop keeps the same meaningful page scroll range when intake closes and reopens', () => {
    const f = fixture({ width: 1364, animated: true });
    assert.equal(f.attrs.has('data-repair-steady'), true);
    assert.equal(f.styles.get('--repair-scroll-range'), '380px');
    const table = f.styles.get('--repair-table-height');
    f.send('summary', 'click'); f.motions[0].finish();
    assert.equal(f.details.open, false);
    assert.equal(f.shell.scrollTop, 380, 'Closed is the bottom of the same track, not a reset to zero');
    assert.equal(f.styles.get('--repair-scroll-range'), '380px');
    assert.equal(f.styles.get('--repair-table-height'), table);
    f.send('summary', 'click'); f.motions[1].finish();
    assert.equal(f.shell.scrollTop, 0);
    assert.equal(f.styles.get('--repair-scroll-range'), '380px');
});

test('desktop animation synchronizes scroll and reveal compensation instead of shrinking the scrollbar track', () => {
    const f = fixture({ width: 1364, animated: true });
    f.send('summary', 'click');
    f.motions[0].renderedHeight = 200; f.flush();
    assert.equal(f.styles.get('--repair-scroll-offset'), '180px');
    assert.equal(f.shell.scrollTop, 180);
    assert.equal(f.styles.get('--repair-scroll-range'), '380px');
    f.dispose();
    assert.equal(f.frames.size, 0);
    assert.equal(f.attrs.size, 0);
    assert.equal(f.styles.size, 0);
});

test('native page scrolling can close and reopen a desktop intake on the same track', () => {
    const f = fixture({ width: 1364, animated: true });
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    assert.equal(f.details.open, false);
    assert.equal(f.shell.scrollTop, 380);
    f.shell.scrollTop = 300; f.send('shell', 'scroll');
    assert.equal(f.details.open, true);
    assert.deepEqual(f.motions[0].keyframes, [{ height: '80px' }, { height: '380px' }]);
    assert.equal(f.shell.scrollTop, 300, 'Upward scrolling must not jump back to the closed endpoint');
    f.motions[0].finish();
    assert.equal(f.shell.scrollTop, 0);
});

test('desktop compensation uses the same whole pixels as the native scroll position', () => {
    const f = fixture({ width: 1364, animated: true });
    f.send('summary', 'click');
    f.motions[0].renderedHeight = 200.4; f.flush();
    assert.equal(f.styles.get('--repair-scroll-offset'), '180px');
    assert.equal(f.shell.scrollTop, 180);
});

test('desktop reversals, observer callbacks and resizing cannot let stale motion change the new state', () => {
    const f = fixture({ width: 1364, animated: true });
    f.send('summary', 'click');
    f.motions[0].renderedHeight = 200; f.flush();
    f.send('summary', 'click');
    assert.equal(f.motions[0].cancelled, true);
    assert.deepEqual(f.motions[1].keyframes, [{ height: '200px' }, { height: '380px' }]);
    f.observers[0].fn();
    assert.notEqual(f.motions[1].cancelled, true);
    f.motions[0].finish();
    assert.equal(f.details.open, true);
    f.win.innerHeight = 500; f.send('win', 'resize');
    assert.equal(f.motions[1].cancelled, true);
    assert.equal(f.attrs.has('data-repair-steady'), false);
    assert.equal(f.details.open, true);
    assert.equal(f.form.inert, false);
});

test('editing, server errors, phones and short windows retain ordinary page flow', () => {
    const f = fixture({ width: 1364 });
    f.doc.activeElement = f.form; f.send('form', 'focusin');
    assert.equal(f.attrs.has('data-repair-steady'), false);
    assert.equal(f.form.draft, 'Unsaved repair description');
    f.doc.activeElement = null; f.send('form', 'focusout');
    assert.equal(f.attrs.has('data-repair-steady'), true);
    for (const options of [{ width: 390 }, { width: 1364, height: 500 }, { width: 1364, errors: true }, { width: 1364, cardHeight: 1200 }]) {
        assert.equal(fixture(options).attrs.has('data-repair-steady'), false);
    }
});

test('reduced-motion desktop toggles retain the track without animating or losing drafts', () => {
    const f = fixture({ width: 1364, animated: true, reducedMotion: true });
    f.send('summary', 'click');
    assert.equal(f.motions.length, 0);
    assert.equal(f.shell.scrollTop, 380);
    f.shell.scrollTop = 0; f.send('shell', 'scroll');
    assert.equal(f.details.open, true);
    assert.equal(f.form.draft, 'Unsaved repair description');
});

test('only the form body animates, leaving the Receive New Repair heading outside the moving region', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click');
    assert.equal(f.motions[0].target, f.body);
    assert.deepEqual(f.motions[0].keyframes, [{ height: '380px' }, { height: '0px' }]);
    f.motions[0].finish();
    f.send('shell', 'wheel', { deltaY: -100 });
    assert.equal(f.motions[1].target, f.body);
    assert.deepEqual(f.motions[1].keyframes, [{ height: '0px' }, { height: '380px' }]);
    assert.equal(f.shell.scrollTop, 0);
});

test('manual closing does not nudge the page down before animating the form', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click');
    assert.equal(f.shell.scrollTop, 0);
    f.motions[0].finish();
    assert.equal(f.shell.scrollTop, 0);
});

test('a collapsed workspace fits without a phantom scrollbar and still reopens on upward intent at zero', () => {
    const f = fixture();
    f.send('summary', 'click');
    assert.equal(f.styles.get('--repair-workspace-height'), '835px', '925px viewport minus the 90px header');
    f.send('shell', 'wheel', { deltaY: -50 });
    assert.equal(f.details.open, true);
    assert.equal(f.shell.scrollTop, 0);
});

test('a fitting intake is anchored at its original position before the scroll-collapse threshold', () => {
    const f = fixture();
    assert.equal(f.attrs.has('data-repair-intake-pin'), true);
    assert.equal(f.styles.get('--repair-intake-top'), '122px');
    f.shell.scrollTop = 20; f.send('shell', 'scroll');
    assert.equal(f.details.open, true, 'The screenshot case is before the 24px threshold');
    f.observers[0].fn();
    assert.equal(f.styles.get('--repair-intake-top'), '122px', 'Scroll offset must not move the anchor');
    f.dispose();
    assert.equal(f.attrs.has('data-repair-intake-pin'), false);
    assert.equal(f.styles.has('--repair-intake-top'), false);
});

test('an intake taller than the viewport remains normally scrollable and resizing removes its anchor', () => {
    const tall = fixture({ cardHeight: 1200 });
    assert.equal(tall.attrs.has('data-repair-intake-pin'), false);
    const f = fixture();
    assert.equal(f.attrs.has('data-repair-intake-pin'), true);
    f.win.innerHeight = 500; f.send('win', 'resize');
    assert.equal(f.attrs.has('data-repair-intake-pin'), false);
    assert.equal(f.styles.has('--repair-intake-top'), false);
});

test('the table starts at its full responsive height and never grows on collapse or reopening', () => {
    const f = fixture();
    assert.equal(f.styles.get('--repair-table-height'), '671px');
    assert.equal(f.attrs.has('data-repair-table-fit'), true);
    f.shell.scrollTop = 40; f.send('shell', 'scroll');
    assert.equal(f.styles.get('--repair-table-height'), '671px');
    f.send('summary', 'click');
    assert.equal(f.styles.get('--repair-table-height'), '671px');
});

test('the table height stays unchanged throughout the form animation, not just at its endpoints', () => {
    const f = fixture({ animated: true });
    const height = f.styles.get('--repair-table-height');
    assert.ok(height);
    f.send('summary', 'click');
    assert.equal(f.styles.get('--repair-table-height'), height);
    f.motions[0].finish();
    assert.equal(f.styles.get('--repair-table-height'), height);
    f.send('summary', 'click');
    assert.equal(f.styles.get('--repair-table-height'), height);
    f.motions[1].finish();
    assert.equal(f.styles.get('--repair-table-height'), height);
});

test('closing slides to the summary before hiding content and fitting the register', () => {
    const f = fixture({ animated: true });
    f.send('summary', 'click');
    assert.equal(f.motions.length, 1);
    assert.equal(f.details.open, true, 'Native details must remain open until its closing animation finishes');
    assert.equal(f.form.inert, true, 'Fields being hidden cannot receive focus');
    assert.equal(f.attrs.has('data-repair-fit'), false);
    assert.deepEqual(f.motions[0].keyframes, [{ height: '380px' }, { height: '0px' }]);
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
    f.motions[0].renderedHeight = 200;
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
    assert.equal(f.styles.get('--repair-workspace-height'), '835px');
    assert.equal(f.shell.scrollTop, 0, 'The heading uses the same page origin in both states');
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

// Live staging, 2026-11-19 (1920x870): with the intake open, the wheel over the rows scrolled the table hidden behind
// the form (two rows visible), and a later upward page scroll reopened the form and threw the rows back to row one.
test('rows stay still behind the open intake and scroll only once it has fully collapsed', () => {
    const f = fixture({ width: 1364, animated: true });
    assert.equal(f.attrs.has('data-repair-steady'), true);
    assert.equal(f.attrs.has('data-repair-collapsed'), false, 'open intake: the wheel collapses it first');
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    assert.equal(f.details.open, false);
    assert.equal(f.attrs.has('data-repair-collapsed'), true, 'fully collapsed: now the rows scroll');
    f.shell.scrollTop = 300; f.send('shell', 'scroll');
    assert.equal(f.details.open, true, 'scrolling back up at row one reopens it, as before');
    assert.equal(f.attrs.has('data-repair-collapsed'), false);
});

test('scrolling back up from the middle of the rows never reopens the intake or jumps to row one', () => {
    const f = fixture({ width: 1364, animated: true });
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    assert.equal(f.details.open, false);
    f.pane.scrollTop = 500;
    f.shell.scrollTop = 300; f.send('shell', 'scroll');
    assert.equal(f.details.open, false, 'rows are not at the top yet');
    assert.equal(f.shell.scrollTop, 380, 'the page stays at the collapsed position');
    assert.equal(f.pane.scrollTop, 500, 'the list keeps its place');
    f.pane.scrollTop = 0;
    f.shell.scrollTop = 300; f.send('shell', 'scroll');
    assert.equal(f.details.open, true, 'back at row one: scrolling up reopens it');
});

// Owner, 2026-11-20: "when Receive New Repair opens on scroll the whole panel shakes". The browser moved the collapsed
// page by the whole wheel step before the controller could react a frame later, so the heading dropped and snapped
// back on every frame of the scroll. Collapsed, the controller now takes the upward wheel itself.
const wheel = (f, deltaY, overRows) => {
    const event = { deltaY, deltaMode: 0, prevented: false, target: { closest: () => (overRows ? f.pane : null) } };
    event.preventDefault = () => { event.prevented = true; };
    f.listeners.get('shell:wheel')(event); f.flush();
    return event;
};

test('collapsed at row one, an upward wheel opens the intake itself instead of letting the page jump first', () => {
    const f = fixture({ width: 1364, animated: true });
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    assert.equal(f.details.open, false);
    const event = wheel(f, -100, true);
    assert.equal(event.prevented, true, 'the page must not move before the reveal starts');
    assert.equal(f.details.open, true);
    assert.equal(f.shell.scrollTop, 380, 'the reveal starts from the collapsed position, with no one-frame drop');
    f.motions[0].finish();
    assert.equal(f.shell.scrollTop, 0);
});

test('collapsed mid-list, an upward wheel anywhere scrolls the rows; over the rows the list scrolls natively', () => {
    const f = fixture({ width: 1364, animated: true });
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    f.pane.scrollTop = 500;
    const outside = wheel(f, -100, false);
    assert.equal(outside.prevented, true);
    assert.equal(f.pane.scrollTop, 400, 'the rows move instead of the page');
    assert.equal(f.shell.scrollTop, 380);
    assert.equal(f.details.open, false);
    const over = wheel(f, -100, true);
    assert.equal(over.prevented, false, 'the list scrolls itself');
    assert.equal(f.details.open, false);
});

test('while the intake opens, further wheel steps cannot fight the reveal', () => {
    const f = fixture({ width: 1364, animated: true });
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    wheel(f, -100, true);
    assert.equal(wheel(f, -100, true).prevented, true);
    assert.equal(wheel(f, 100, true).prevented, true);
    assert.equal(f.motions.length, 1, 'one reveal, not restarted');
});

test('ctrl+wheel zoom and wheels from outside the workspace or inside another scroller are left to the browser', () => {
    const f = fixture({ width: 1364, animated: true });
    f.shell.scrollTop = 380; f.send('shell', 'scroll');
    const send = extras => {
        const event = { deltaY: -100, deltaMode: 0, prevented: false, target: { closest: () => null }, ...extras };
        event.preventDefault = () => { event.prevented = true; };
        f.listeners.get('shell:wheel')(event); f.flush();
        return event;
    };
    assert.equal(send({ ctrlKey: true }).prevented, false, 'zoom');
    assert.equal(send({ target: { closest: () => null, outsideWorkspace: true } }).prevented, false, 'the notice panel in the page header');
    const menu = { closest: () => null, scrollHeight: 300, clientHeight: 100, scrollTop: 50, overflowY: 'auto' };
    assert.equal(send({ target: menu }).prevented, false, 'a dropdown that can still scroll');
    assert.equal(f.details.open, false, 'none of them opened the intake');
});

test('the rows keep their scrollbar space, so the columns never shift sideways when the lock changes', async () => {
    const { readFileSync } = await import('node:fs');
    const view = readFileSync(new URL('../../resources/views/repairs.blade.php', import.meta.url), 'utf8');
    assert.match(view, /\[data-repair-steady\] \[data-repair-rows\]\s*\{[^}]*scrollbar-gutter:\s*stable/);
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
