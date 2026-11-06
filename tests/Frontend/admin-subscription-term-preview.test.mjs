import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

// The admin "Apply Subscription Change" preview must use the server's inclusive term rule
// (SubscriptionTerm::endsAtFor: To = From + 1 cycle, no overflow, - 1 day; the next term starts at To + 1 day).
// It added a full cycle and called the server's own suggested From (previous To + 1) a "Gap", so accepting the
// suggestion produced a To the server then refused without an override reason.
const blade = readFileSync(new URL('../../resources/views/super-admin/shops/show.blade.php', import.meta.url), 'utf8');
const source = blade.match(/<script>\s*(\(function \(\) \{\s*const from = document\.getElementById\('sub-from'\)[^]*?)<\/script>/)[1];

function preview({ from, cycle, prevTo, today = '2026-11-07', toPicker }) {
    const listeners = [];
    const el = (props = {}) => ({ value: '', textContent: '', dataset: {}, addEventListener: (_, fn) => listeners.push(fn), ...props });
    const items = [];
    const toEl = el();
    if (toPicker) toEl._flatpickr = { setDate: (value, fire) => { toPicker.push([value, fire]); toEl.value = value; } };
    const nodes = {
        'sub-from': el({ value: from }),
        'sub-to': toEl,
        'sub-cycle': el({ value: cycle, querySelector: () => null }),
        'sub-plan': el({ selectedOptions: [{ dataset: { graceDays: '5' } }] }),
        'sub-preview': el({ dataset: { today, prevTo } }),
        'sub-warnings': el({ replaceChildren: (...li) => items.splice(0, items.length, ...li.map(x => x.textContent)) }),
    };
    runInNewContext(source, {
        document: {
            getElementById: id => nodes[id] ?? el(),
            querySelector: () => el({ value: '' }),
            createElement: () => ({ textContent: '' }),
        },
    });
    return { to: nodes['sub-to'].value, warnings: items };
}

test('a yearly term continuing the previous one ends the day before its anniversary, with no warnings', () => {
    const { to, warnings } = preview({ from: '2027-11-07', cycle: 'yearly', prevTo: '2027-11-06' });
    assert.equal(to, '2028-11-06');
    assert.deepEqual(warnings, []);
});

test('monthly terms follow the no-overflow rule', () => {
    assert.equal(preview({ from: '2026-11-07', cycle: 'monthly', prevTo: '' }).to, '2026-12-06');
    assert.equal(preview({ from: '2027-01-31', cycle: 'monthly', prevTo: '' }).to, '2027-02-27');
});

test('starting on the previous end date overlaps; skipping a day leaves a gap', () => {
    assert.match(preview({ from: '2027-11-06', cycle: 'yearly', prevTo: '2027-11-06' }).warnings.join(), /Overlap/);
    assert.match(preview({ from: '2027-11-08', cycle: 'yearly', prevTo: '2027-11-06' }).warnings.join(), /Gap/);
});

test('a suggested To goes through the date picker, so the visible date box shows what will be submitted', () => {
    const calls = [];
    preview({ from: '2027-11-07', cycle: 'yearly', prevTo: '2027-11-06', toPicker: calls });
    assert.deepEqual(calls, [['2028-11-06', false]]);
});
