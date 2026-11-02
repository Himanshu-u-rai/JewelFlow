import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const blade = readFileSync(new URL('../../resources/views/returns/create.blade.php', import.meta.url), 'utf8');
const css = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));

test('refund breakdown and authorized adjustments are fixed, visible sections', () => {
    assert.doesNotMatch(blade, /<details\b|<summary\b/);
    for (const heading of ['Refund breakdown', 'Adjust refund']) {
        assert.ok(blade.includes(`<h3 class="returns-create-details__heading">${heading}</h3>`));
        assert.ok(blade.includes(`aria-label="${heading}"`));
    }
    assert.match(blade, /@if\(isset\(\$policyBreakdowns\[\$line->id\]\)\)/);
    assert.match(blade, /@can\('returns\.approve'\)[^]*?<section class="returns-create-details returns-create-adjustments"/);
    assert.match(blade, /@disabled\(!\$isSelected\)/);
});

test('fixed panel headings keep the existing divider without dropdown decoration', () => {
    let heading;
    css.walkRules(rule => {
        if (rule.selector === '.returns-create-details__heading') heading = rule;
        assert.ok(!rule.selector.includes('.returns-create-details summary'), 'no disclosure chevron or pointer styling');
    });
    assert.ok(heading);
    assert.ok(heading.nodes.some(node => node.prop === 'border-bottom'));
    assert.ok(!heading.nodes.some(node => node.prop === 'cursor' && node.value === 'pointer'));
});
