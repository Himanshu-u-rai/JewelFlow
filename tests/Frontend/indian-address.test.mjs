import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import postcss from 'postcss';

const path = new URL('../../resources/js/indian-address.js', import.meta.url);
const { createIndianAddress } = existsSync(path) ? await import(path) : {};

function fixture(values = {}) {
    assert.equal(typeof createIndianAddress, 'function', 'shared address behavior must exist');
    const state = createIndianAddress();
    state.$el = { dataset: { lookupUrl: '/shops/pincode/__PIN__' } };
    state.$refs = Object.fromEntries(['pin', 'city', 'state'].map(field => [field, {
        value: values[field] ?? '',
        dispatchEvent() { if (field !== 'pin') state.edited(field); },
    }]));
    return state;
}

const found = (city = 'Pune', state = 'Maharashtra') => ({ ok: true, json: async () => ({ found: true, city, state }) });
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };

test('only a complete PIN requests same-origin lookup and fills editable fields', async () => {
    const state = fixture({ pin: '41100' });
    let calls = 0;
    const fetcher = async (url, options) => {
        calls++;
        assert.equal(url, '/shops/pincode/411001');
        assert.equal(options.credentials, 'same-origin');
        return found();
    };
    await state.lookup(fetcher);
    assert.equal(calls, 0);
    state.$refs.pin.value = '411001';
    await state.lookup(fetcher);
    assert.equal(calls, 1);
    assert.equal(state.$refs.city.value, 'Pune');
    assert.equal(state.$refs.state.value, 'Maharashtra');
    assert.equal(state.loading, false);
    assert.match(state.message, /district/i);
});

test('existing values and manual edits are not replaced; previously autofilled fields follow a new PIN', async () => {
    const state = fixture({ pin: '411001', city: 'Existing locality', state: 'Existing state' });
    await state.lookup(async () => found());
    assert.equal(state.$refs.city.value, 'Existing locality');
    assert.equal(state.$refs.state.value, 'Existing state');
    state.$refs.city.value = '';
    state.$refs.state.value = '';
    await state.lookup(async () => found());
    state.$refs.city.value = 'My locality';
    state.edited('city');
    state.$refs.pin.value = '400001';
    await state.lookup(async () => found('Mumbai', 'Maharashtra'));
    assert.equal(state.$refs.city.value, 'My locality');
    assert.equal(state.$refs.state.value, 'Maharashtra');
    state.$refs.pin.value = '560001';
    await state.lookup(async () => found('Bengaluru', 'Karnataka'));
    assert.equal(state.$refs.state.value, 'Karnataka');
});

test('typing or clearing a field while a lookup is pending protects that field', async () => {
    const state = fixture({ pin: '411001' });
    const wait = deferred();
    const task = state.lookup(() => wait.promise);
    assert.equal(state.loading, true);
    state.$refs.city.value = '';
    state.edited('city');
    wait.resolve(found());
    await task;
    assert.equal(state.$refs.city.value, '');
    assert.equal(state.$refs.state.value, 'Maharashtra');
});

test('late replies cannot overwrite a newer PIN or revive a closed/cached form', async () => {
    const state = fixture({ pin: '411001' });
    const first = deferred();
    let signal;
    const task = state.lookup((url, options) => { signal = options.signal; return first.promise; });
    state.$refs.pin.value = '560001';
    await state.lookup(async () => found('Bengaluru', 'Karnataka'));
    first.resolve(found());
    await task;
    assert.equal(signal.aborted, true);
    assert.equal(state.$refs.city.value, 'Bengaluru');
    const last = deferred();
    const closing = state.lookup(() => last.promise);
    state.destroy();
    last.resolve(found('Wrong city', 'Wrong state'));
    await closing;
    assert.equal(state.$refs.city.value, 'Bengaluru');
    assert.equal(state.loading, false);
});

test('clearing PIN cancels pending work without clearing entered address data', async () => {
    const state = fixture({ pin: '411001', city: 'Keep city' });
    const wait = deferred();
    const task = state.lookup(() => wait.promise);
    state.$refs.pin.value = '';
    await state.lookup();
    wait.resolve(found());
    await task;
    assert.equal(state.$refs.city.value, 'Keep city');
    assert.equal(state.$refs.state.value, '');
    assert.equal(state.message, '');
});

test('unavailable, malformed, unknown and unauthenticated lookups preserve manual input', async () => {
    for (const fetcher of [
        async () => { throw new Error('offline'); },
        async () => ({ ok: false }),
        async () => ({ ok: true, json: async () => { throw new Error('HTML login page'); } }),
        async () => ({ ok: true, json: async () => ({ found: false }) }),
        async () => ({ ok: true, json: async () => ({ found: true, city: {}, state: [] }) }),
    ]) {
        const state = fixture({ pin: '411001', city: 'Manual city' });
        await state.lookup(fetcher);
        assert.equal(state.$refs.city.value, 'Manual city');
        assert.equal(state.$refs.state.value, '');
        assert.equal(state.loading, false);
        assert.match(state.message, /type.*city.*state/i);
    }
});

test('lookup distinguishes unknown PIN from temporary failure and can retry without losing input', async () => {
    const state = fixture({ pin: '370201', city: 'Manual locality' });
    await state.lookup(async () => ({ ok: true, json: async () => ({ found: false, reason: 'not_found' }) }));
    assert.match(state.message, /no area found/i);
    assert.equal(state.lookupFailed, true);
    await state.lookup(async () => ({ ok: true, json: async () => ({ found: false, reason: 'unavailable' }) }));
    assert.match(state.message, /temporarily unavailable/i);
    await state.lookup(async () => found('Kachchh', 'Gujarat'));
    assert.equal(state.lookupFailed, false);
    assert.equal(state.$refs.city.value, 'Manual locality');
    assert.equal(state.$refs.state.value, 'Gujarat');
});

function picker() {
    const state = fixture();
    let open = false, focus = 0;
    const scrolled = [];
    state.$nextTick = fn => fn();
    state.$refs.states = { options: ['Gujarat', 'Maharashtra', 'Himachal Pradesh', 'Madhya Pradesh'].map(value => ({ value })) };
    state.$refs.state.removeAttribute = name => { state.removedAttribute = name; };
    state.$refs.state.focus = () => focus++;
    state.$refs.state.getBoundingClientRect = () => ({ left: 280, top: 200, bottom: 244, width: 230 });
    state.$refs.stateMenu = {
        style: {}, scrollHeight: 300,
        matches: () => open,
        showPopover() { open = true; }, hidePopover() { open = false; },
        querySelector(selector) { return { scrollIntoView() { scrolled.push(selector); } }; },
    };
    const place = state.placeStates;
    state.placeStates = win => place.call(state, win ?? { innerWidth: 540, innerHeight: 640 });
    assert.equal(typeof state.init, 'function', 'State suggestions must have a controlled, sized popup');
    state.init();
    return { state, isOpen: () => open, focused: () => focus, scrolled };
}

test('compact State suggestions filter typed text and support arrows, selection and dismissal', () => {
    const { state, isOpen, focused, scrolled } = picker();
    assert.equal(state.removedAttribute, 'list', 'disable the unbounded native list only after enhancement');
    state.openStates();
    assert.equal(isOpen(), true);
    assert.equal(state.stateMatches.length, 4);
    state.$refs.state.value = 'pradesh';
    state.openStates(true);
    assert.deepEqual(state.stateMatches, ['Himachal Pradesh', 'Madhya Pradesh']);
    state.moveState(1);
    assert.equal(state.activeState, 0);
    state.moveState(1);
    assert.equal(state.activeState, 1);
    assert.equal(scrolled.length, 2, 'keyboard highlight must stay visible in the scrolling popup');
    state.chooseState();
    assert.equal(state.$refs.state.value, 'Madhya Pradesh');
    assert.equal(isOpen(), false);
    assert.equal(focused(), 1);
    state.openStates();
    state.cancel();
    assert.equal(isOpen(), false, 'navigation cleanup must close the top-layer popup');
});

test('no-match text and browsers without popovers keep manual State entry intact', () => {
    const { state } = picker();
    state.$refs.state.value = 'Legacy spelling';
    state.openStates(true);
    assert.deepEqual(state.stateMatches, []);
    state.chooseState();
    assert.equal(state.$refs.state.value, 'Legacy spelling');
    const plain = fixture();
    plain.$refs.stateMenu = { matches() { throw new Error('Unsupported popover selector'); } };
    plain.init();
    assert.equal(plain.enhanced, false);
    assert.doesNotThrow(() => plain.cancel());
});

test('State popup stays within viewport edges and caps its scroll height', () => {
    const { state } = picker();
    state.placeStates({ innerWidth: 320, innerHeight: 350 });
    assert.equal(state.$refs.stateMenu.style.left, '82px');
    assert.equal(state.$refs.stateMenu.style.maxHeight, '188px');
    assert.equal(state.$refs.stateMenu.style.top, '8px');
    const css = postcss.parse(readFileSync(new URL('../../resources/css/indian-address.css', import.meta.url), 'utf8'));
    let scrolls = false;
    css.walkRules(rule => {
        if (rule.selector === '.jf-state-menu') rule.walkDecls('overflow-y', decl => { scrolls = decl.value === 'auto'; });
    });
    assert.equal(scrolls, true);
});
