import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { sectionLabel } from '../../resources/js/section-switch.js';

const item = (text, attrs = {}, classes = '') => ({
    textContent: text,
    getAttribute: (name) => attrs[name] ?? null,
    classList: { contains: (name) => classes.split(' ').includes(name) },
});

test('the switch names the open section, however the page marks it', () => {
    assert.equal(sectionLabel([item(' General '), item('\n Shop Info\n', { 'aria-current': 'page' })]), 'Shop Info', 'Settings and Stock: aria-current');
    assert.equal(sectionLabel([item('Customer Directory', { 'aria-pressed': 'false' }), item('Loyalty Points', { 'aria-pressed': 'true' })]), 'Loyalty Points', 'Customers: aria-pressed');
    assert.equal(sectionLabel([item('General'), item('Profile', {}, 'nav-item active')]), 'Profile', 'a server-rendered active class');
    assert.equal(sectionLabel([item('Stock Items'), item('Reorder Alerts 2', {}, 'items-page-link is-active')], 'Stock'), 'Reorder Alerts 2');
    assert.equal(sectionLabel([item('General'), item('Profile')], 'Settings'), 'Settings', 'nothing open: the fallback');
});

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
const block = css.slice(css.indexOf('/* ── Section switch'));

test('the switch is hidden on wide screens and reads as the title where it shows', () => {
    assert.match(block, /\.jf-section-switch\s*\{[^}]*display:\s*none/);
    assert.match(block, /\.jf-section-switch\s*\{[^}]*border:\s*0/);
    assert.match(block, /\.jf-section-switch\s*\{[^}]*background:\s*none/);
});

test('each page swaps its title and strip for the switch at its own width', () => {
    assert.match(block, /@media \(max-width: 900px\)[^@]*\.settings-page-header \.jf-section-switch\s*\{[^}]*display:\s*inline-flex/);
    for (const page of ['customers-directory-header', 'inventory-items-header']) {
        assert.match(block, new RegExp(`@media \\(max-width: 768px\\)[^@]*\\.${page} \\.jf-section-switch`), page);
    }
    assert.match(block, /\[data-section-list\]:not\(\.is-open\)[^{]*\{[^}]*display:\s*none/, 'the list waits for a tap');
    assert.match(block, /\[data-section-list\]\.is-open\s*\{[^}]*position:\s*fixed/, 'and floats under the header');
    // A phone header is static and its blur makes it a layer of its own, so the list inside it painted under the
    // page's own layered parts (a customer's avatar showed through it, 2026-11-15). Open, the header comes first.
    assert.match(block, /\.content-header:has\(\[data-section-list\]\.is-open\)\s*\{[^}]*position:\s*relative[^}]*z-index:\s*70/);
});

test('the three headers wire a switch to their own section list', () => {
    const read = (path) => readFileSync(new URL(`../../resources/views/${path}`, import.meta.url), 'utf8');
    const pairs = [
        ['settings.blade.php', 'settings-sections'],
        ['customers/index.blade.php', 'customer-views'],
        ['inventory/items/partials/navigation.blade.php', 'stock-sections'],
    ];
    for (const [view, id] of pairs) assert.match(read(view), new RegExp(`id="${id}"[^>]*data-section-list`), view);
    assert.match(read('settings.blade.php'), /data-section-switch[^>]*aria-controls="settings-sections"/);
    assert.match(read('customers/index.blade.php'), /data-section-switch[^>]*aria-controls="customer-views"/);
    assert.match(read('components/stock-workspace-header.blade.php'), /data-section-switch[^>]*aria-controls="stock-sections"/);
});
