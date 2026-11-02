import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/schemes/create.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
const value = (selector, prop, media) => {
    let result;
    css.walkRules(rule => {
        if (!rule.selectors.includes(selector)) return;
        if ((rule.parent.type === 'atrule' ? rule.parent.params : undefined) !== media) return;
        for (const declaration of rule.nodes) if (declaration.prop === prop) result = declaration.value;
    });
    return result;
};

test('create form uses shared scheme surfaces with no inline stylesheet or separate Schedule/intro card', () => {
    assert.doesNotMatch(blade, /<style\b|schemes-create-intro|>Schedule</);
    for (const name of ['schemes-form-page', 'schemes-card', 'schemes-input', 'schemes-select', 'schemes-textarea', 'schemes-btn--primary']) {
        assert.ok(blade.includes(name));
    }
    assert.equal(value('.schemes-create-page .schemes-create-form', 'grid-template-columns'), 'minmax(0, 1fr)');
    assert.equal(value('.schemes-create-page .schemes-create-form', 'grid-template-columns', '(min-width: 1100px)'), 'minmax(0, 2fr) minmax(260px, 1fr)');
    assert.equal(value('.schemes-create-page .schemes-create-grid--basics', 'grid-template-columns', '(min-width: 1100px)'), 'minmax(0, 1.6fr) minmax(0, 1.2fr) repeat(2, minmax(0, 1fr))');
    assert.equal(value('.schemes-create-page .schemes-create-section', 'overflow'), 'visible', 'select menus must not be clipped by the old nested card');
});

test('phone layout keeps full-width readable controls and vertically resizable textareas', () => {
    assert.equal(value('.schemes-create-page .schemes-create-grid', 'grid-template-columns'), 'minmax(0, 1fr)');
    const controls = '.schemes-create-page :is(.schemes-input, .schemes-select, .schemes-textarea, .ui-filter-select-trigger)';
    assert.equal(value(controls, 'font-size', '(max-width: 768px)'), '16px');
    assert.equal(value(controls, 'min-height', '(max-width: 768px)'), '44px');
    assert.equal(value('.schemes-textarea', 'resize'), 'vertical');
    assert.equal(value('.schemes-create-page #description', 'min-height'), '64px');
});

for (const type of ['gold_savings', 'festival_sale', 'discount_offer']) {
    test(`${type} retains the existing conditional settings and category target`, () => {
        const expressions = [...blade.matchAll(/<section x-show="([^"]*)"/g)].map(m => m[1]);
        assert.equal(runInNewContext(expressions[0], { type }), type === 'gold_savings');
        assert.equal(runInNewContext(expressions[1], { type }), type !== 'gold_savings');
        const target = blade.match(/<div x-show="([^"]*)"/)?.[1];
        assert.equal(runInNewContext(target, { appliesTo: 'all_items' }), false);
        assert.equal(runInNewContext(target, { appliesTo: 'category' }), true);
    });
}
