import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

// Audit L1/L2 (2026-11-13): leaving a POS page to finish a sale sets a "busy" state (POS: Redirecting…; customer
// POS: Processing…) that nothing clears. When the browser restores the page from its back/forward cache, it comes
// back frozen: the button stays disabled. Both pages now reset that state when the page is shown again from cache.

test('POS: a page restored from the back/forward cache can check out again', () => {
    const pos = read('resources/views/pos.blade.php');
    // Only the visit still on screen acts: Turbo keeps earlier visits' listeners, and theirs hold old carts.
    assert.match(pos, /addEventListener\('pageshow', \(event\) => \{\s*if \(!event\.persisted \|\| !checkoutButton\?\.isConnected\) return;\s*resetCheckout\(\);/);
    assert.match(pos, /function resetCheckout\(\) \{\s*checkoutInProgress = false;/);
});

test('customer POS: a restored page is not left "Processing…"', () => {
    const view = read('resources/views/pos_customer_retailer.blade.php');
    assert.match(view, /@pageshow\.window="if \(\$event\.persisted\) \{ selling = false; complianceSaving = false; \}"/);
});

// A sale refused because the session expired (419) or the server failed answered "Network error", which sends the
// cashier to check the wifi. The answer is read whatever its shape, and an expired session says so.
test('customer POS: a non-JSON refusal is reported for what it is', () => {
    const view = read('resources/views/pos_customer_retailer.blade.php');
    assert.match(view, /const data = await res\.json\(\)\.catch\(\(\) => \(\{\}\)\);\s*\n\s*\/\/ Phase 3a: quote went stale/);
    assert.match(view, /res\.status === 419/);
});
