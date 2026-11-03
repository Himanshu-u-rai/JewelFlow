import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const appPath = new URL('../../resources/css/app.css', import.meta.url);
const surfacePath = new URL('../../resources/css/workspace-surfaces.css', import.meta.url);
const sheet = postcss.parse(existsSync(surfacePath) ? readFileSync(surfacePath, 'utf8') : '');

test('the shared card treatment ships through the existing stylesheet entry', () => {
    const app = postcss.parse(readFileSync(appPath, 'utf8'));
    const imports = [];
    app.walkAtRules('import', rule => imports.push(rule.params));
    assert.ok(imports.includes('"./workspace-surfaces.css"'));
});

test('card treatment is screen-only and cannot change layout, text, inputs or actions', () => {
    const rules = [];
    sheet.walkRules(rule => rules.push(rule));
    assert.ok(rules.length > 0, 'card treatment must exist');
    for (const rule of rules) {
        assert.equal(rule.parent.name, 'media');
        assert.equal(rule.parent.params, 'screen');
        for (const selector of rule.selectors) {
            assert.ok(selector.startsWith('body.app-shell main .content-inner '));
            assert.doesNotMatch(selector, /(?:^|[\s>,+~])(?:input|select|textarea|button|label|a)(?:[\s.#:[>+~]|$)/);
        }
        for (const declaration of rule.nodes) {
            if (declaration.type !== 'decl') continue;
            assert.ok(['background-color', 'border-color', 'box-shadow'].includes(declaration.prop), declaration.prop);
        }
    }
});

test('neutral surfaces reuse the reference shadow without recolouring product or status components', () => {
    const declarations = [];
    sheet.walkDecls(declaration => declarations.push([declaration.prop, declaration.value]));
    assert.ok(declarations.some(([key, value]) => key === 'box-shadow' && value === '0 12px 22px -22px rgba(15, 23, 42, 0.68)'));
    assert.ok(declarations.some(([key, value]) => key === 'border-color' && value === '#dbe4ef'));
    assert.ok(declarations.some(([key, value]) => key === 'background-color' && value === '#ffffff'));
    const selectors = [];
    sheet.walkRules(rule => selectors.push(...rule.selectors));
    assert.doesNotMatch(selectors.join('\n'), /kpi|badge|status|admin-|dh-app|dropdown|ui-filter|qb-item-card/);
});
