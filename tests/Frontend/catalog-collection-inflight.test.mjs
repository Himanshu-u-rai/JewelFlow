import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const view = readFileSync(new URL('../../resources/views/retailer-reports/whatsapp.blade.php', import.meta.url), 'utf8');
const create = view.slice(view.indexOf('const createOrReuseCollectionLink'), view.indexOf("templateSelect?.addEventListener('change'"));
const ui = view.slice(view.indexOf('const updateSelectionUI'), view.indexOf('if (collectionRow) {'));

// (audit N12) Create Collection and Send on WhatsApp each disabled only their own button, so using both created two
// collections. They now share one in-flight request and both buttons lock while it runs.
test('both buttons share one in-flight collection request', () => {
    assert.match(view, /let collectionRequest = null;/);
    assert.match(create, /if \(collectionRequest\) return collectionRequest;/);
    assert.match(create, /collectionRequest = requestCollectionLink\(\)\.finally\(\(\) => \{\s*collectionRequest = null;/);
});

test('while it runs both buttons are disabled, and a selection refresh does not re-enable them', () => {
    assert.match(ui, /createCollectionBtn\.disabled = count === 0 \|\| !!collectionRequest;/);
    assert.match(ui, /shareSelectedBtn\.disabled = count === 0 \|\| !!collectionRequest;/);
});

test('the click handlers lock both buttons through the shared in-flight state', () => {
    const createH = view.slice(view.indexOf('createCollectionBtn?.addEventListener'), view.indexOf('shareSelectedBtn?.addEventListener'));
    const shareH = view.slice(view.indexOf('shareSelectedBtn?.addEventListener'), view.indexOf('modalCloseBtn?.addEventListener'));
    assert.doesNotMatch(createH, /button\.disabled = (true|false)/);
    assert.doesNotMatch(shareH, /button\.disabled = (true|false)/);
    assert.match(createH, /if \(collectionRequest\) return;/);
    assert.match(shareH, /if \(collectionRequest\) return;/);
});
