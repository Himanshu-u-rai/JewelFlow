import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));

// Cascade contract for the header's known competing selectors, not a layout engine
// or a substitute for rendered-browser checks. Include the older phone overrides.
const selectors = {
    header: ['.content-header', '.customers-page-header', '.customers-directory-header', '.content-header.customers-directory-header'],
    actions: ['.page-actions', '.content-header .page-actions', '.customers-page-header .page-actions', '.customers-directory-header .page-actions', '.content-header > :nth-child(n+3)', '.customers-page-header > :nth-child(n+3)', '.content-header.customers-directory-header > .page-actions'],
    bell: ['.notice-bell', '.content-header > .notice-bell', '.content-header.customers-directory-header > .notice-bell'],
    add: ['.customers-page-header .customers-add-btn', '.customers-directory-header .customers-add-btn', '.content-header.customers-directory-header .customers-add-btn'],
    directoryTrigger: ['.ui-filter-select-trigger', '.customers-index-page .customers-directory-status .ui-filter-select-trigger'],
    emiTrigger: ['.ui-filter-select-trigger', '.customers-index-page .customers-register-inline-form .ui-filter-select-trigger'],
    directoryMenu: ['.ui-filter-select-menu', '.customers-index-page .customers-directory-status .ui-filter-select-menu'],
    emiMenu: ['.ui-filter-select-menu', '.customers-index-page .customers-register-inline-form .ui-filter-select-menu'],
    directoryChevron: ['.ui-filter-select-chevron', '.customers-index-page .customers-directory-status .ui-filter-select-chevron'],
    emiChevron: ['.ui-filter-select-chevron', '.customers-index-page .customers-register-inline-form .ui-filter-select-chevron'],
    directoryStatus: ['.customers-directory-status'],
    directoryStatusFocus: ['.customers-directory-status', '.customers-directory-status:has(:focus-visible)'],
};

function cascade(part, width) {
    const values = new Map();
    css.walkRules(rule => {
        for (let parent = rule.parent; parent?.type !== 'root'; parent = parent.parent) {
            if (parent.type !== 'atrule') continue;
            if (parent.name !== 'media' || !/^\(max-width: \d+px\)$/.test(parent.params)) return;
            if (width > Number(parent.params.match(/\d+/)[0])) return;
        }
        for (const selector of rule.selectors) {
            if (!selectors[part].includes(selector)) continue;
            const specificity = (selector.match(/\.[\w-]+|:[\w-]+/g) || []).length;
            rule.walkDecls(declaration => {
                const rank = specificity + (declaration.important ? 100 : 0);
                if (rank >= (values.get(declaration.prop)?.rank ?? -1)) {
                    values.set(declaration.prop, { rank, value: declaration.value });
                }
            });
        }
    });
    return Object.fromEntries([...values].map(([property, declaration]) => [property, declaration.value]));
}

for (const width of [320, 390, 427, 640, 768]) {
    test(`customer header keeps phone actions below its title at ${width}px despite legacy overrides`, () => {
        const header = cascade('header', width);
        const actions = cascade('actions', width);
        const bell = cascade('bell', width);
        assert.equal(header.display, 'grid', 'a nowrap header must not squeeze the title beside the complete tab group');
        assert.equal(actions['grid-column'], '1 / -1', 'tabs and Add Customer need the full header width');
        assert.equal(actions['grid-row'], '2', 'actions must not compete with the title row');
        assert.equal(actions.width, '100%');
        assert.equal(bell.position, 'relative', 'the notice bell must reserve its own column instead of overlaying tabs');
        assert.equal(bell['grid-column'], '3');
        assert.equal(bell['grid-row'], '1');
        assert.equal(cascade('add', width)['min-height'], '44px');
        assert.equal(header.position, 'static', 'a multi-row phone header must scroll away on short or zoomed screens');
    });
}

test('desktop keeps the compact flex header and sticky page context', () => {
    const header = cascade('header', 1280);
    assert.equal(header.display, 'flex');
    assert.equal(header.position, 'sticky');
    assert.equal(cascade('actions', 1280)['grid-row'], undefined);
});

for (const part of ['Trigger', 'Menu', 'Chevron']) {
    test(`Directory Status ${part.toLowerCase()} shares the EMI filter treatment`, () => {
        for (const width of [427, 1280]) {
            assert.deepEqual(cascade('directory' + part, width), cascade('emi' + part, width));
        }
    });
}

test('Directory Status stays content-sized and capped instead of inheriting wide EMI form columns', () => {
    for (const width of [320, 427, 1280]) {
        const status = cascade('directoryStatus', width);
        assert.equal(status.width, 'max-content');
        assert.equal(status['min-width'], '8rem');
        assert.equal(status['max-width'], '10rem');
        assert.equal(status.flex, '0 1 auto');
    }
});

test('Directory wrapper does not add a second focus ring around the shared EMI trigger', () => {
    assert.equal(cascade('directoryStatusFocus', 1280).outline, undefined);
});
