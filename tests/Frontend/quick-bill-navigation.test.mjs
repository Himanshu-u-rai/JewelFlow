import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/quick-bills/form.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(blade.match(/<style>([^]*?)<\/style>/)[1]);

test('the Quick Bill register remains a named touch-sized phone header action', () => {
    // Source contract, not a browser geometry or focus assertion.
    let action;
    let label;
    css.walkAtRules('media', media => {
        if (media.params !== '(max-width: 767px)') return;
        media.walkRules(rule => {
            if (rule.selector === '.qb-form-header .page-actions > .qb-form-back-action') action = rule;
            if (rule.selector === '.qb-form-header .page-actions > .qb-form-back-action span') label = rule;
        });
    });
    assert.ok(action);
    const declarations = Object.fromEntries(action.nodes.map(node => [node.prop, node.value]));
    assert.equal(declarations.display, 'inline-flex');
    assert.equal(declarations.width, '44px');
    assert.equal(declarations['min-height'], '44px');
    assert.equal(label?.nodes.find(node => node.prop === 'display')?.value, 'none');
    assert.match(blade, /<a[^>]+aria-label="Quick Bill register"[^>]*class="qb-form-back-action/);
});
