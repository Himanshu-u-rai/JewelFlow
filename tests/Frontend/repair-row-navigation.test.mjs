import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/repairs.blade.php', import.meta.url), 'utf8');
const handler = blade.match(/        function openRepairRow\(event\) \{[\s\S]*?\n        \}/)?.[0];
const app = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const enhance = app.match(/function upgradeInlineClickTargetsA11y\(\) \{[\s\S]*?\n\}/)[0];

function clickRow({ interactive = false, outside = false, selection = '', ...eventOptions } = {}) {
    assert.ok(handler, 'Repair rows need a safe view-navigation handler.');
    let clicks = 0;
    const row = { querySelector: () => ({ click: () => clicks++ }) };
    const event = {
        button: 0,
        target: { closest: selector => selector === '#repairsTbody tr[data-search]' ? (outside ? null : row) : (interactive ? {} : null) },
        currentTarget: row,
        ...eventOptions,
    };
    runInNewContext(handler + '\nopenRepairRow(event);', {
        event, window: { getSelection: () => ({ toString: () => selection }) },
    });
    return clicks;
}

test('a plain row click follows the real repair view link', () => {
    assert.equal(clickRow(), 1);
});

test('status controls, action links and buttons keep their own behavior', () => {
    assert.equal(clickRow({ interactive: true }), 0);
    assert.equal(clickRow({ defaultPrevented: true }), 0);
    assert.equal(clickRow({ outside: true }), 0);
});

test('the global onclick upgrader leaves repair rows as table rows and does not bind descendant keys', () => {
    const markup = blade.match(/<tr[^>]*data-search="\{\{ \$searchBlob \}\}"[^>]*>/)[0];
    const attributes = {};
    const listeners = {};
    const row = {
        dataset: {},
        matches: () => false,
        hasAttribute: name => name in attributes,
        setAttribute: (name, value) => { attributes[name] = value; },
        addEventListener: (type, fn) => { listeners[type] = fn; },
        click: () => { throw new Error('A child key must not activate the row.'); },
    };
    const document = { querySelectorAll: () => markup.includes('onclick=') ? [row] : [] };
    runInNewContext(enhance + '\nupgradeInlineClickTargetsA11y();', { document });
    assert.equal(attributes.role, undefined, 'repair rows must retain native table semantics');
    let prevented = false;
    listeners.keydown?.({ key: ' ', preventDefault: () => { prevented = true; } });
    assert.equal(prevented, false);
    assert.match(blade, /replaceRepairsListener\(document, 'click', '__repairsRowClickHandler', openRepairRow\)/);
});

test('selecting text and modifier or auxiliary clicks do not navigate the row', () => {
    for (const options of [{ selection: 'copy this' }, { ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        assert.equal(clickRow(options), 0);
    }
});
