import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

// Owner 2026-11-08: every Dhiran scrollbar (the page, the sidebar, tables, boxes) looks like the lists' amber bar.
// Only list areas ([data-list-scroll]) had it; the page and other scroll boxes showed the grey browser default.
const css = postcss.parse(readFileSync(new URL('../../resources/css/dhiran.css', import.meta.url), 'utf8'));
const layout = readFileSync(new URL('../../resources/views/components/dhiran-layout.blade.php', import.meta.url), 'utf8');

function decls(selector) {
    const out = {};
    css.walkRules(rule => {
        if (rule.parent.type === 'root' && rule.selectors.includes(selector)) rule.walkDecls(d => { out[d.prop] = d.value; });
    });
    return out;
}

test('the Dhiran page carries the class the scrollbar style hangs on', () => {
    assert.match(layout, /<html[^\n]*class="dh-html"/); // [^\n]: the tag holds Blade's "->"
});

test('the page and every scroll area inside Dhiran use the amber bar', () => {
    assert.equal(decls('.dh-html')['--sb-thumb-color'], '#a77320');
    for (const scope of ['.dh-html', '.dh-app *']) {
        assert.equal(decls(`${scope}::-webkit-scrollbar-thumb`).background, 'var(--sb-thumb-color)', scope);
        assert.equal(decls(`${scope}::-webkit-scrollbar-track`).background, 'var(--sb-track-color)', scope);
    }
});
