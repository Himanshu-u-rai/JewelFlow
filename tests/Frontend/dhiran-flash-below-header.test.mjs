import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

// The page header renders in its own band above .dh-scroll (see dhiran-layout.blade.php). On desktop only .dh-scroll
// scrolls, so the scrollbar starts under the header instead of running beside it (owner screenshots 2026-11-08), and
// messages sit inside the scroll area below the header. Phones and print keep normal page flow.
const css = postcss.parse(readFileSync(new URL('../../resources/css/dhiran.css', import.meta.url), 'utf8'));

function desktopDecls(selector) {
    const out = {};
    css.walkAtRules('media', at => {
        if (!/screen and \(min-width: 901px\)/.test(at.params)) return;
        at.walkRules(rule => {
            if (rule.selectors.includes(selector)) rule.walkDecls(d => { out[d.prop] = d.value; });
        });
    });
    return out;
}

test('on desktop the page itself does not scroll', () => {
    assert.equal(desktopDecls('.dh-html').overflow, 'hidden');
});

test('on desktop only the area under the header scrolls', () => {
    const scroll = desktopDecls('.dh-scroll');
    assert.equal(scroll['overflow-y'], 'auto');
    assert.equal(scroll['min-height'], '0');
    assert.equal(desktopDecls('.dh-main > .dh-page-header').margin, '0');
});
