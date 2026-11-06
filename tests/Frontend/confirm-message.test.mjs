import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

const path = new URL('../../resources/js/confirm-message.js', import.meta.url);
const { extractConfirmMessage } = existsSync(path) ? await import(path) : {};

test('inline confirm() messages lose their JavaScript escapes before reaching the confirmation dialog', () => {
    assert.equal(typeof extractConfirmMessage, 'function');
    // as rendered by karigars/index: addslashes() on the name and \' in the sentence
    const attr = "return confirm('Disable D\\'Souza Works? They keep all history but can\\'t take new work until re-enabled.');";
    assert.equal(extractConfirmMessage(attr), "Disable D'Souza Works? They keep all history but can't take new work until re-enabled.");
    assert.equal(extractConfirmMessage('return confirm("Say \\"yes\\" to continue")'), 'Say "yes" to continue');
    assert.equal(extractConfirmMessage("return confirm('Delete it (don\\'t keep a copy)?')"), "Delete it (don't keep a copy)?");
});

test('missing or unparseable messages fall back to the generic question', () => {
    assert.equal(extractConfirmMessage(''), 'Are you sure you want to continue?');
    assert.equal(extractConfirmMessage('return doSomething()'), 'Are you sure you want to continue?');
});

test('HTML entities are still decoded by the caller-supplied decoder', () => {
    assert.equal(extractConfirmMessage("return confirm('Tom &amp; Co?')", (s) => s.replace('&amp;', '&')), 'Tom & Co?');
});
