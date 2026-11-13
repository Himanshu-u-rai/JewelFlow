import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

// Audit L3 (2026-11-13), found by trying it with the keyboard: the app's confirm dialog said aria-modal but trapped
// nothing. Tab walked out of it into the page behind (sidebar links, form fields, other submit buttons), so a user
// could edit or submit a form while a confirm was open, and a form changed behind the dialog could fail validation
// after "Continue": its one-shot "already confirmed" mark then stayed, and its next submit skipped the confirm.

test('while the confirm is open, the page behind it is inert', () => {
    assert.match(app, /function setPageBehindConfirmInert\(on\)/);
    assert.match(app, /setPageBehindConfirmInert\(true\);[\s\S]{0,200}return new Promise/, 'opening makes the page inert');
    assert.equal((app.match(/setPageBehindConfirmInert\(false\);/g) || []).length, 2, 'closing and the Turbo cleanup both release it');
});

test('a confirmed form that is no longer valid is reported, not marked as confirmed', () => {
    assert.match(app, /if \(!confirmed\) return;[\s\S]{0,300}?if \(!form\.noValidate && !form\.checkValidity\(\)\) \{\s*form\.reportValidity\(\);\s*return;\s*\}\s*confirmClearedForm = form;/);
});
