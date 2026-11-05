import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

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
