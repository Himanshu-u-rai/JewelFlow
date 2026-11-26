import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const path = new URL('../../resources/css/retail-scrollbars.css', import.meta.url);
const css = postcss.parse(existsSync(path) ? readFileSync(path, 'utf8') : '');

test('Retail scrollbar rules cannot reach admin or other document shells', () => {
    const rules = [];
    css.walkRules(rule => rules.push(rule));
    assert.ok(rules.length > 0, 'The Retail scrollbar theme must exist');
    for (const rule of rules) for (const selector of rule.selectors) {
        assert.ok(selector.startsWith('body.app-shell') || selector.startsWith('html:has(> body.app-shell)')
            || selector === ':where(body.app-shell, body.app-shell *, html:has(> body.app-shell))', selector);
    }
});

test('thin fallback defaults have zero specificity so intentionally hidden bars take precedence', () => {
    const rules = [];
    css.walkDecls('scrollbar-width', decl => { if (decl.value === 'thin') rules.push(decl.parent); });
    assert.ok(rules.length > 0);
    for (const rule of rules) assert.ok(rule.selectors.every(selector => selector.startsWith(':where(')));
});

test('scrollbar theming cannot change container geometry, overflow or intentionally hidden bars', () => {
    const allowed = new Set(['--sb-track-color', '--sb-thumb-color', '--sb-size',
        'scrollbar-color', 'scrollbar-width', 'background', 'border', 'border-radius', 'background-clip']);
    css.walkDecls(decl => {
        if (['width', 'height'].includes(decl.prop)) {
            assert.ok(decl.parent.selectors.every(selector => selector.endsWith('::-webkit-scrollbar')));
        } else assert.ok(allowed.has(decl.prop), decl.prop);
        assert.notEqual(decl.prop, 'display', 'Hidden navigation and dropdown bars must stay hidden');
    });
});

test('the Retail theme is included in the actual application stylesheet', () => {
    const app = postcss.parse(readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8'));
    const imports = [];
    app.walkAtRules('import', rule => imports.push(rule.params));
    assert.ok(imports.includes('"./retail-scrollbars.css"'));
});
