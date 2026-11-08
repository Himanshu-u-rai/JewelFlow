import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

// A success/error message renders before the page header in the markup. The header pulls itself up with a negative
// top margin (to sit flush as the top band), so it slid over the message's bottom edge and stopped being the top band.
// The main column orders the header first, so messages show just below it.
const css = postcss.parse(readFileSync(new URL('../../resources/css/dhiran.css', import.meta.url), 'utf8'));

function decls(selector) {
    const out = {};
    css.walkRules(rule => {
        if (rule.parent.type === 'root' && rule.selectors.includes(selector)) {
            rule.walkDecls(d => { out[d.prop] = d.value; });
        }
    });
    return out;
}

test('the Dhiran main column is a vertical flex column', () => {
    const main = decls('.dh-main');
    assert.equal(main.display, 'flex');
    assert.equal(main['flex-direction'], 'column');
});

test('the page header comes first, ahead of any message', () => {
    assert.equal(decls('.dh-main > .dh-page-header').order, '-1');
});
