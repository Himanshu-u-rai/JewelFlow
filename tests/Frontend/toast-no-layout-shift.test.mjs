import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

// F05: the shared (non-Settings) toast floats over the page; it never reserves space.
// Settings' own handling lives in settings-workspace.js/css and is not asserted here.

test('no app.css rule pads or animates .content-area from --toast-space', () => {
    const rules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)];
    for (const [, selector, body] of rules) {
        if (/\.content-area\b/.test(selector) && /padding-top/.test(body)) {
            assert.doesNotMatch(body, /--toast-space/, `rule "${selector.trim()}" must not use --toast-space`);
        }
    }
    assert.doesNotMatch(css, /padding-top:\s*var\(--toast-space/);
});

test('the phone toast stays position: fixed over the page', () => {
    assert.match(css, /\.global-toast\s*\{[^}]*position:\s*fixed/);
});

test('placeToast never sets --toast-space (only clears it)', () => {
    const fn = app.slice(app.indexOf('function placeToast'), app.indexOf('function presentToast'));
    assert.ok(fn.length > 50);
    assert.doesNotMatch(fn, /setProperty\(\s*'--toast-space'/);
    assert.match(fn, /removeProperty\(\s*'--toast-space'/);
});
