import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { sectionLabel, stripNeedsSwitch } from '../../resources/js/section-switch.js';

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

// Owner, 2026-11-20: "dont make it go two rows; as soon as it hits another row make it like the drop down we have
// for mobile". Desktop measures the strip as laid out and swaps to the switch the moment it wraps or overflows.
const tab = (top, visible = true) => ({ getClientRects: () => (visible ? [{}] : []), getBoundingClientRect: () => ({ top }) });
const strip = (width, scrollWidth) => ({ clientWidth: width, scrollWidth });

test('a strip that fits on one row keeps its tabs', () => {
    assert.equal(stripNeedsSwitch([tab(24), tab(24), tab(24.5)], strip(600, 600)), false);
    assert.equal(stripNeedsSwitch([tab(24), tab(66, false)], strip(600, 600)), false, 'hidden tabs do not count');
    assert.equal(stripNeedsSwitch([], strip(0, 0)), false, 'nothing to show');
});

test('a strip that wraps onto a second row, or runs past its box, becomes the drop-down', () => {
    assert.equal(stripNeedsSwitch([tab(24), tab(24), tab(66)], strip(600, 600)), true, 'Reorder Alerts dropped to a second row');
    assert.equal(stripNeedsSwitch([tab(24), tab(24)], strip(600, 640)), true, 'one row, but cut off');
});

const stockMobile = readFileSync(new URL('../../resources/css/stock-mobile.css', import.meta.url), 'utf8');

test('tablets keep the phone Stock header on one row: menu, the switch, icon actions, bell', () => {
    const tablet = stockMobile.slice(stockMobile.indexOf('@media screen and (max-width: 1023px)'));
    assert.ok(stockMobile.includes('@media screen and (max-width: 1023px)'));
    assert.match(tablet, /\.inventory-items-header \.page-actions\s*\{\s*display:\s*contents/);
    assert.match(tablet, /\.inventory-items-header \.stock-header-actions\s*\{[^}]*grid-row:\s*1/);
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
    // Customers and Stock: phones AND tablets get the drop-down (owner, 2026-11-19); 1024px+ keeps the strip.
    for (const page of ['customers-directory-header', 'inventory-items-header']) {
        assert.match(block, new RegExp(`@media \\(max-width: 1023px\\)[^@]*\\.${page} \\.jf-section-switch`), page);
    }
    // Desktop: the same swap whenever the strip would wrap (section-switch.js sets data-section-compact).
    for (const page of ['customers-directory-header', 'inventory-items-header']) {
        assert.match(block, new RegExp(`\\.${page}\\[data-section-compact\\] \\.jf-section-switch`), `${page} compact`);
    }
    // The strip's column goes with it: Create Item sits beside the bell, not in the strip's old slot (owner, 2026-11-20).
    assert.match(block, /\.inventory-items-header\[data-section-compact\] \.page-actions\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)\s*;/);
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
