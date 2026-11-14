import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(new URL('../../resources/views/subscription/payment.blade.php', import.meta.url), 'utf8');

// Audit N10: the four-step Razorpay flow was tracked only by btn.disabled and its text. Back (bfcache) could leave
// "Opening Razorpay...", and a 419 showed a raw JSON parse error. One explicit state, reset on pageshow, dismiss and
// failure; plain messages for 419 and non-JSON replies. (The server side is unchanged.)
const start = page.lastIndexOf('<script>');
const script = page.slice(start + 8, page.indexOf('</script>', start))
    .replace(/\{\{ route\('[^']+'\) \}\}/g, '/initiate')
    .replace(/\{\{ number_format\(\$price, 0\) \}\}/g, '999');

function setup(respond) {
    const els = {};
    const el = (id) => (els[id] ??= { id, value: '', textContent: '', disabled: false, style: {}, dataset: {}, listeners: {}, addEventListener(t, f) { this.listeners[t] = f; }, submit() { this.submitted = true; } });
    els['pay-btn'] = Object.assign(el('pay-btn'), { dataset: { label: 'Pay 999' }, textContent: 'Pay 999' });
    const win = { listeners: {}, addEventListener(t, f) { this.listeners[t] = f; } };
    const doc = { listeners: {}, getElementById: el, addEventListener(t, f) { this.listeners[t] = f; }, querySelector: () => ({ content: 'tok' }) };
    const instances = [];
    class Razorpay {
        constructor(options) { this.options = options; this.handlers = {}; instances.push(this); }
        on(name, fn) { this.handlers[name] = fn; }
        open() { this.opened = true; }
    }
    new Function('document', 'window', 'fetch', 'Razorpay', script)(doc, win, respond, Razorpay);
    return { els, win, doc, instances, btn: els['pay-btn'], err: el('pay-error') };
}

const res = (status, body, json = true) => Promise.resolve({
    ok: status < 400, status,
    json: () => (json ? Promise.resolve(body) : Promise.reject(new SyntaxError('Unexpected token <'))),
    text: () => Promise.resolve(json ? JSON.stringify(body) : body),
});
const tick = () => new Promise((r) => setTimeout(r, 0));
const order = { key_id: 'k', amount: 1, currency: 'INR', order_id: 'o', plan_name: 'p' };

test('a 419 says the session expired and re-enables the button', async () => {
    const f = setup(() => res(419, '<html>Page Expired</html>', false));
    await f.btn.listeners.click.call(f.btn);
    assert.match(f.err.textContent, /Your session expired\. Reload the page and try again\./);
    assert.equal(f.btn.disabled, false);
    assert.equal(f.btn.textContent, 'Pay 999');
});

test('a non-JSON reply gets a plain message, not a parse error', async () => {
    const f = setup(() => res(502, '<html>Bad gateway</html>', false));
    await f.btn.listeners.click.call(f.btn);
    assert.doesNotMatch(f.err.textContent, /Unexpected token|JSON/);
    assert.match(f.err.textContent, /Reload the page and try again/);
    assert.equal(f.btn.disabled, false);
});

test('coming back from the browser history resets a stuck button', async () => {
    const f = setup(() => res(200, order));
    await f.btn.listeners.click.call(f.btn);
    assert.equal(f.btn.disabled, true, 'checkout is open');
    f.win.listeners.pageshow({ persisted: true });
    assert.equal(f.btn.disabled, false);
    assert.equal(f.btn.textContent, 'Pay 999');
});

test('Turbo restore also resets it', async () => {
    const f = setup(() => res(200, order));
    await f.btn.listeners.click.call(f.btn);
    f.doc.listeners['turbo:load']();
    assert.equal(f.btn.disabled, false);
});

test('dismissing Razorpay resets; a failed payment shows a message and resets', async () => {
    const f = setup(() => res(200, order));
    await f.btn.listeners.click.call(f.btn);
    f.instances[0].options.modal.ondismiss();
    assert.equal(f.btn.disabled, false);
    await f.btn.listeners.click.call(f.btn);
    f.instances[1].handlers['payment.failed']({ error: { description: 'Card declined' } });
    assert.match(f.err.textContent, /Card declined/);
    assert.equal(f.btn.disabled, false);
});

test('a second click while the flow is running does nothing', async () => {
    let calls = 0;
    const f = setup(() => { calls++; return res(200, order); });
    const first = f.btn.listeners.click.call(f.btn);
    await f.btn.listeners.click.call(f.btn);
    await first;
    assert.equal(calls, 1);
    assert.equal(f.instances.length, 1);
});

test('after the handler submits the callback form the button stays busy until the page leaves', async () => {
    const f = setup(() => res(200, order));
    await f.btn.listeners.click.call(f.btn);
    f.instances[0].options.handler({ razorpay_payment_id: 'p', razorpay_order_id: 'o', razorpay_signature: 's' });
    assert.equal(f.els['payment-form'].submitted, true);
    assert.equal(f.btn.disabled, true);
    assert.match(f.btn.textContent, /Confirming/);
});
