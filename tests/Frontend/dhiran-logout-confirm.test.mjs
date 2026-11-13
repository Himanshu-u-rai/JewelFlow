import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

// Owner 2026-11-13: Dhiran's Sign out logged the user out on the first click, with no question asked. Retail asks
// first; Dhiran now asks through the app's own themed confirm (app.js handles every form with data-confirm-message).
test('Dhiran Sign out asks for confirmation before posting the logout', () => {
    const layout = read('resources/views/components/dhiran-layout.blade.php');
    const form = layout.match(/<form\b[^>]*action="\{\{ route\('logout'\) \}\}"[^>]*>/)?.[0];
    assert.ok(form, 'Dhiran layout has a logout form');
    assert.match(form, /data-confirm-message="[^"]+"/);
    assert.match(form, /data-turbo-frame="_top"/);
    assert.match(read('resources/js/app.js'), /if \(!form\.hasAttribute\('data-confirm-message'\)\) return;/);
});
