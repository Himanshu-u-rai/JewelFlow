import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { compareReportValues, initializeReportScreens } from '../../resources/js/report-screen.js';

test('report sorting uses raw values, natural order, stable ties and empty values last', () => {
    assert.deepEqual(['1000', '9', '-20', '0'].sort((a, b) => compareReportValues(a, b, true)), ['-20', '0', '9', '1000']);
    assert.deepEqual(['INV-10', 'INV-2', 'INV-1'].sort((a, b) => compareReportValues(a, b, false)), ['INV-1', 'INV-2', 'INV-10']);
    assert.equal(compareReportValues('2', '2', true), 0);
    assert.ok(compareReportValues('', '2', true, true) > 0);
    assert.ok(compareReportValues('2', '1', true, true) < 0);
});

test('shared screen has one canonical row rendering and mobile-safe filters', () => {
    const read = name => readFileSync(new URL('../../' + name, import.meta.url), 'utf8');
    const screen = read('resources/views/reporting/reports/generic-screen.blade.php');
    const filters = read('resources/views/reporting/partials/screen-filters.blade.php');
    const table = read('resources/views/reporting/partials/screen-table.blade.php');
    assert.doesNotMatch(screen, /<style|<script|document.body.classList|mobile-list/);
    assert.match(screen, /x-responsive-filter-panel.*desktop-popup/);
    for (const field of ['date_from', 'date_to', 'fy_name']) assert.ok(filters.includes(`name="${field}"`));
    assert.match(table, /data-sort-value/);
    assert.match(table, /<button[^>]+data-report-sort/);
    assert.match(table, /aria-sort="none"/);
    assert.equal(table.split('@forelse($section[\'rows\'] as $row)').length - 1, 1);
    assert.match(read('resources/js/app.js'), /initializeReportScreens\(\)/);
});

test('report footers align numeric totals with body columns and keep the index separate', () => {
    const css = readFileSync(new URL('../../resources/css/individual-reports.css', import.meta.url), 'utf8');
    const table = readFileSync(new URL('../../resources/views/reporting/partials/screen-table.blade.php', import.meta.url), 'utf8');
    assert.match(css, /tfoot > tr > td\.jf-num\s*\{[^}]*text-align: right/);
    assert.match(css, /tfoot > tr > td\s*\{[^}]*padding: var\(--jf-t-pad-y\) var\(--jf-t-pad-x\)/);
    assert.match(css, /tfoot > tr > td:last-child\s*\{[^}]*padding-right: calc\(var\(--jf-t-pad-x\) \+ 8px\)/);
    assert.match(table, /<th scope="row" role="rowheader" class="ir-index">Total<\/th>/);
    assert.match(table, /data-row-index/);
    assert.doesNotMatch(table, /\$loop->first \? 'Total'/);
});

test('sorting reindexes rows, leaves financial cells unchanged and mounts once across Turbo visits', () => {
    const listeners = [];
    const button = { addEventListener: (_, fn) => listeners.push(fn) };
    const header = { dataset: { numeric: '1' }, attrs: {}, getAttribute(key) { return this.attrs[key]; }, setAttribute(key, value) { this.attrs[key] = value; }, querySelector: () => button };
    const indexHeader = { dataset: {}, setAttribute() {}, querySelector: () => null };
    const number = () => ({ textContent: '' });
    const row = (value, balance) => ({ cells: [{ dataset: {} }, { dataset: { sortValue: value } }, { textContent: balance }], number: number(), hasAttribute: () => false, querySelector() { return this.number; } });
    const a = row('20', '₹120.00'), b = row('10', '₹100.00');
    const body = { rows: [a, b], appendChild(item) { this.rows = this.rows.filter(r => r !== item).concat(item); } };
    const note = { textContent: '' };
    const table = { tHead: { rows: [{ cells: [indexHeader, header] }] }, tBodies: [body], dataset: { hasRunningBalance: '1' }, closest: () => ({ querySelector: () => note }) };
    const events = {};
    initializeReportScreens({ querySelectorAll: () => [table], readyState: 'complete', addEventListener: (name, fn) => events[name] = fn });
    events['turbo:load']();
    assert.equal(listeners.length, 1);
    listeners[0]();
    assert.deepEqual(body.rows, [b, a]);
    assert.equal(b.number.textContent, '1');
    assert.equal(a.number.textContent, '2');
    assert.equal(a.cells[2].textContent, '₹120.00');
    assert.equal(b.cells[2].textContent, '₹100.00');
    assert.equal(header.attrs['aria-sort'], 'ascending');
    listeners[0]();
    assert.deepEqual(body.rows, [a, b]);
    assert.match(note.textContent, /does not recalculate/);
});
