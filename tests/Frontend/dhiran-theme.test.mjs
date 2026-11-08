import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

// Owner 2026-11-08: Dhiran gets its own dark navy + bright gold scheme, and no Dhiran colour is hard-coded: every
// colour is written once in dhiran-tokens.css and everything else refers to the tokens.
const read = f => readFileSync(new URL(`../../resources/css/${f}`, import.meta.url), 'utf8');
const literal = /#[0-9a-f]{3,8}\b|rgba?\(/i;

function decls(css, selector, media = null) {
    const out = {};
    postcss.parse(css).walkRules(rule => {
        const inMedia = rule.parent.type === 'atrule' ? rule.parent.params : null;
        if (inMedia !== media || !rule.selectors.includes(selector)) return;
        rule.walkDecls(d => { out[d.prop] = d.value; });
    });
    return out;
}

test('no hard-coded colour outside the token file', () => {
    for (const f of ['dhiran.css', 'dhiran-documents.css']) {
        postcss.parse(read(f)).walkDecls(d => {
            assert.ok(!literal.test(d.value), `${f}: ${d.parent.selector} { ${d.prop}: ${d.value} }`);
        });
    }
    assert.match(read('tables.css'), /\.dh-app \.jf-scrollbar, \.dh-app \[data-list-scroll\] \{ --sb-thumb-color: var\(--dh-scrollbar-thumb\)/);
});

test('both Dhiran stylesheets load the one token file', () => {
    for (const f of ['dhiran.css', 'dhiran-documents.css']) assert.match(read(f), /^@import "\.\/dhiran-tokens\.css";/);
    assert.match(read('dhiran-tokens.css'), /--dh-navy: #0f1b2d;[\s\S]*--dh-gold-bright: #f5b301;/);
});

test('navy sidebar, gold main action with navy text, navy other buttons, blue links', () => {
    const css = read('dhiran.css');
    assert.equal(decls(css, '.dh-sidebar').background, 'var(--dh-sidebar)');
    // (PostCSS keeps "!important" apart from the value.)
    assert.equal(decls(css, '.btn-primary').color, 'var(--dh-on-highlight)');
    assert.equal(decls(css, '.btn-primary').background, 'var(--dh-highlight)');
    assert.equal(decls(css, '.btn-dark').background, 'var(--dh-accent)');
    assert.equal(decls(css, '.dh-app table a:not(.jf-row-action):not(.btn)').color, 'var(--dh-link)');
    assert.equal(decls(css, '.dh-app .jf-row-action').color, 'var(--jf-ra-fg)', 'row action icons keep their own colour');
});

test('phones: closed menu is hidden from keyboard, links are tappable, the New Loan bar stays on the page', () => {
    const css = read('dhiran.css');
    assert.equal(decls(css, '.dh-shell:not(.is-open) .dh-sidebar', '(max-width: 900px)').visibility, 'hidden');
    assert.equal(decls(css, '.dh-app .dh-scroll a:not(.btn):not(.jf-row-action)', '(max-width: 720px)')['padding-block'], '4px');
    assert.equal(decls(css, '.dh-app .dhiran-create-form > .flex.justify-end', '(max-width: 720px)')['margin-inline'], '0');
});
