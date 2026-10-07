/**
 * Real toast functions, deterministic clock and geometry stubs. Checks lifecycle,
 * not browser layout. Run: node tests/js/toast-lifecycle.check.mjs
 * ponytail: reuse the repo's node + assert check pattern; no new test dependency.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const start = app.indexOf('let toastTimer =');
const end = app.indexOf('const originalFetch =');
assert(start >= 0 && end > start, 'Toast source boundaries changed: update this check.');

function screen(hasPage = true) {
    let now = 0;
    let next = 0;
    const timers = new Map();
    const handlers = new Map();
    const classes = new Set();
    const attrs = new Map();
    const style = { setProperty() {}, removeProperty() {} };
    const state = { blocked: false, phone: true };
    const page = { parentElement: null, position: 'static', getBoundingClientRect: () => ({ top: 0 }) };
    const overlay = { parentElement: null, position: 'fixed' };
    const control = {
        parentElement: overlay,
        getBoundingClientRect: () => ({ top: 0, bottom: 568, left: 12, right: 308, width: 296, height: 568 }),
        contains: el => el === control,
    };
    const toast = {
        textContent: '', offsetHeight: 50, style: { ...style },
        classList: { add: name => classes.add(name), remove: name => classes.delete(name) },
        setAttribute: (name, value) => attrs.set(name, value),
        contains: el => el === toast,
        getBoundingClientRect: () => ({ left: 12, right: 308, height: 50 }),
    };
    const on = (type, fn) => {
        if (!handlers.has(type)) handlers.set(type, []);
        handlers.get(type).push(fn);
    };
    const document = {
        body: {}, documentElement: { style },
        getElementById: id => id === 'global-toast' ? toast : hasPage ? page : null,
        querySelectorAll: () => state.blocked ? [control] : [],
        elementFromPoint: () => state.blocked ? control : page,
        addEventListener: on,
    };
    const window = {
        innerWidth: 320, innerHeight: 568,
        matchMedia: () => ({ get matches() { return state.phone; }, addEventListener: on }),
        setTimeout: (fn, delay) => { timers.set(++next, { fn, at: now + delay }); return next; },
        clearTimeout: id => timers.delete(id),
        addEventListener: on,
    };
    vm.runInNewContext(app.slice(start, end), {
        window, document, console, getComputedStyle: el => ({ position: el.position || 'static' }),
    });
    return {
        state, toast, show: window.showToast,
        visible: () => classes.has('is-visible'),
        event: type => (handlers.get(type) || []).forEach(fn => fn()),
        tick(ms) {
            const until = now + ms;
            for (;;) {
                const due = [...timers].filter(([, t]) => t.at <= until).sort((a, b) => a[1].at - b[1].at)[0];
                if (!due) break;
                const [id, timer] = due;
                now = timer.at;
                timers.delete(id);
                timer.fn();
            }
            now = until;
        },
    };
}

const cases = {
    'ordinary toast expires; Turbo cache contains no message'() {
        const s = screen();
        s.show('Saved', 1000);
        assert(s.visible());
        s.tick(999);
        assert(s.visible());
        s.tick(1);
        assert(!s.visible());
        assert.equal(s.toast.textContent, '');
        s.show('Another');
        s.event('turbo:before-cache');
        s.tick(600);
        assert.equal(s.toast.textContent, '');
    },
    'toast blocked from the start waits, then gets its display time'() {
        const s = screen();
        s.state.blocked = true;
        s.show('Refused', 1000);
        s.tick(2000);
        assert(!s.visible());
        assert.equal(s.toast.textContent, 'Refused');
        s.state.blocked = false;
        s.event('keyup');
        s.tick(120);
        assert(s.visible());
        s.tick(999);
        assert(s.visible());
        s.tick(1);
        assert.equal(s.toast.textContent, '');
    },
    'already-visible toast does not expire while waiting behind a panel'() {
        const s = screen();
        s.show('Save refused', 1000);
        s.tick(200);
        s.state.blocked = true;
        s.event('click');
        s.tick(2000);
        assert(!s.visible());
        assert.equal(s.toast.textContent, 'Save refused', 'Message lost while waiting for space');
        s.state.blocked = false;
        s.event('keyup');
        s.tick(120);
        assert(s.visible());
        s.tick(999);
        assert(s.visible());
        s.tick(1);
        assert.equal(s.toast.textContent, '');
    },
    'layout without main-content also refuses an occupied placement'() {
        const s = screen(false);
        s.state.blocked = true;
        s.show('No room');
        assert(!s.visible(), 'Admin layout displayed toast over controls');
    },
    'resize can release a waiting toast without a click'() {
        const s = screen();
        s.state.blocked = true;
        s.show('Waiting');
        s.tick(600);
        assert(!s.visible());
        s.state.phone = false;
        s.event('resize');
        s.tick(600);
        assert(s.visible(), 'Toast stayed hidden after viewport gained space');
    },
    'scroll can release a waiting toast without a click'() {
        const s = screen();
        s.state.blocked = true;
        s.show('Waiting');
        s.tick(600);
        s.state.blocked = false;
        s.event('scroll');
        s.tick(600);
        assert(s.visible(), 'Toast stayed hidden after panel scrolled');
    },
};
let failures = 0;
for (const [name, run] of Object.entries(cases)) {
    try { run(); console.log('PASS', name); }
    catch (error) { failures++; console.error('FAIL', name, '-', error.message); }
}
console.log(`toast-lifecycle.check: ${Object.keys(cases).length - failures} passed, ${failures} failed`);
process.exitCode = failures ? 1 : 0;
