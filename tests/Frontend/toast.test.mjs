import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { showFlashes, toastSettings } from '../../resources/js/toast.js';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

const page = (flashes) => {
    const metas = Object.entries(flashes).map(([name, content]) => ({ name, content, removed: false, remove() { this.removed = true; } }));
    return {
        metas,
        querySelector: (selector) => metas.find(m => !m.removed && selector === `meta[name="${m.name}"]`) ?? null,
    };
};

test('a number keeps meaning a duration with the default colour', () => {
    assert.deepEqual(toastSettings(), { tone: null, duration: 4000 });
    assert.deepEqual(toastSettings(8000), { tone: null, duration: 8000 });
});

test('a tone string picks the colour, with an optional duration after it', () => {
    assert.deepEqual(toastSettings('success'), { tone: 'success', duration: 4000 });
    assert.deepEqual(toastSettings('warning', 8000), { tone: 'warning', duration: 8000 });
    assert.deepEqual(toastSettings(''), { tone: null, duration: 4000 });
});

test('each session flash shows with its own tone, and the warning stays up longer', () => {
    const doc = page({ 'flash-success': 'Repair item received', 'flash-error': 'Not saved', 'flash-warning': 'Set a return policy' });
    const shown = [];
    showFlashes(doc, (...args) => shown.push(args));
    assert.deepEqual(shown, [
        ['Repair item received', 'success', 4000],
        ['Not saved', 'error', 4000],
        ['Set a return policy', 'warning', 8000],
    ]);
    assert.ok(doc.metas.every(m => m.removed), 'a flash is shown once, not again on the next Turbo render');
});

test('a page without flashes shows nothing', () => {
    const shown = [];
    showFlashes(page({}), (...args) => shown.push(args));
    assert.deepEqual(shown, []);
});

test('every flash tone has a colour rule and showToast applies it', () => {
    for (const tone of ['success', 'error', 'warning']) {
        assert.match(css, new RegExp(`#global-toast\\[data-tone="${tone}"\\]\\s*\\{[^}]*background:`), tone);
    }
    assert.match(app, /toast\.dataset\.tone = settings\.tone/);
    assert.match(app, /delete toast\.dataset\.tone/);
});
