import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { createQuoteState } from '../../resources/js/pos-quote.js';

// Audit H4 (2026-11-13): the POS kept its price quote in loose flags (in-flight, debounce timer, error) and nothing
// tied a quote to the cart and discount it priced. A slow, older answer could overwrite a newer one, and Complete
// Sale could go out while a price was still loading or had failed. One explicit state now decides.

const cart = (ids, discount = 0) => ({ mode: 'retailer', customer_id: 7, item_ids: ids, manual_discount: discount });

test('only the latest request may settle; an older, slower answer is dropped', () => {
    const q = createQuoteState();
    const old = q.request(cart([1], 500));
    const latest = q.request(cart([1], 1000));
    assert.equal(q.settle(latest, true), true);
    assert.equal(q.settle(old, true), false, 'the 500 answer arrives last and is ignored');
    assert.equal(q.readyFor(cart([1], 1000)), true);
    assert.equal(q.readyFor(cart([1], 500)), false);
});

test('a newer request aborts the one before it', () => {
    const q = createQuoteState();
    const first = q.request(cart([1]));
    q.request(cart([1, 2]));
    assert.equal(first.signal.aborted, true);
});

test('nothing is ready while waiting, after a failure, or for inputs the quote did not price', () => {
    const q = createQuoteState();
    assert.equal(q.readyFor(cart([1])), false, 'no quote yet');
    q.wait();
    assert.equal(q.status, 'pending', 'a debounce counts as waiting');
    const t = q.request(cart([1]));
    assert.equal(q.readyFor(cart([1])), false, 'in flight');
    q.settle(t, true);
    assert.equal(q.readyFor(cart([1])), true);
    assert.equal(q.readyFor(cart([1, 2])), false, 'item added since');
    assert.equal(q.readyFor({ ...cart([1]), manual_discount: 5 }), false, 'discount changed since');
    const failed = q.request(cart([1, 2]));
    q.settle(failed, false);
    assert.equal(q.status, 'failed');
    assert.equal(q.readyFor(cart([1, 2])), false, 'a failed refresh never sells');
    assert.equal(q.readyFor(cart([1])), false, 'nor does the older price');
});

test('item order does not matter, the set of items does', () => {
    const q = createQuoteState();
    q.settle(q.request(cart([2, 1])), true);
    assert.equal(q.readyFor(cart([1, 2])), true);
});

test('a fresh quote handed back by the sale (409) is ready for the cart that was sent', () => {
    const q = createQuoteState();
    const pending = q.request(cart([1]));
    q.adopt(cart([1, 2]));
    assert.equal(q.readyFor(cart([1, 2])), true);
    assert.equal(q.settle(pending, true), false, 'a request from before the adopted quote cannot overwrite it');
});

test('emptying the cart clears the quote', () => {
    const q = createQuoteState();
    q.settle(q.request(cart([1])), true);
    q.clear();
    assert.equal(q.status, 'empty');
    assert.equal(q.readyFor(cart([1])), false);
});

test('the retail POS sells only on a ready quote and shows a failed refresh', () => {
    const view = readFileSync(new URL('../../resources/views/pos_customer_retailer.blade.php', import.meta.url), 'utf8');
    assert.match(view, /quote\.readyFor\(this\.quotePayload\(\)\)/, 'canSell asks the quote state');
    assert.match(view, /quote\.settle\(ticket,/);
    assert.doesNotMatch(view, /quoteFetchInFlight|quoteFetchDebounceTimer/, 'no loose flags left');
    assert.match(view, /x-text="quoteError"/, 'a failed price refresh is visible');
    assert.match(read('resources/js/app.js'), /window\.createQuoteState = createQuoteState/);
});

function read(path) {
    return readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
}
