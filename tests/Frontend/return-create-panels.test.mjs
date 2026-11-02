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

test('refund options share a compact wrapping row and retain full clickable labels', () => {
    const rule = selector => css.nodes.find(node => node.type === 'rule' && node.selector === selector);
    const value = (selector, property) => rule(selector)?.nodes.find(node => node.prop === property)?.value;
    assert.equal(value('.returns-create-adjustment-grid', 'grid-template-columns'), 'repeat(auto-fit, minmax(min(100%, 170px), 1fr))');
    assert.equal(value('.returns-create-adjustment-grid label', 'min-height'), '40px');
    assert.equal(value('.returns-create-adjustment-grid label', 'padding'), '6px 8px');
    assert.equal(value('.returns-create-adjustment-grid input', 'flex-shrink'), '0');
    const phone = css.nodes.find(node => node.type === 'atrule' && node.params === '(max-width: 768px)');
    assert.ok(phone.nodes.some(node => node.selector?.split(',').map(s => s.trim()).includes('.returns-create-adjustment-grid')
        && node.nodes.some(decl => decl.prop === 'grid-template-columns' && decl.value === 'minmax(0, 1fr)')));
});

test('compact refund inputs leave the same row for a vertically resizable reason', () => {
    const value = (selector, property) => css.nodes.find(node => node.type === 'rule' && node.selector === selector)
        ?.nodes.find(node => node.prop === property)?.value;
    assert.equal(value('.returns-create-adjustment-fields', 'grid-template-columns'), 'minmax(90px, 1fr) minmax(140px, 1.4fr) minmax(0, 4fr)');
    const textarea = '.returns-create-page .returns-create-adjustment-fields textarea';
    assert.equal(value(textarea, 'height'), '40px');
    assert.equal(value(textarea, 'min-height'), '40px');
    assert.equal(value(textarea, 'resize'), 'vertical');
    assert.doesNotMatch(blade, /returns-create-adjustment-fields__wide/);
    assert.match(blade, /name="lines\[\{\{ \$idx \}\}\]\[override_reason\]"\s+rows="1"/);
    assert.match(blade, /<span>Manual refund<\/span>/);
    assert.match(blade, /<small>max ₹\{\{ number_format\(\$maxRefundable, 2\) \}\}<\/small>/);
});
