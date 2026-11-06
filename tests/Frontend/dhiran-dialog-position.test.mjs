import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

// app.css resets `* { margin: 0 }`, which removes the browser's `margin: auto` that centres a modal <dialog>.
// Every Dhiran loan dialog (Pay Interest, Repay, Release, Pre-Close, Renew) opened pinned to the top-left corner.
const css = postcss.parse(readFileSync(new URL('../../resources/css/dhiran.css', import.meta.url), 'utf8'));

test('Dhiran dialogs restore the centring margin the global reset removes', () => {
    let margin;
    css.walkRules('.dh-dialog', rule => {
        if (rule.parent.type === 'root') rule.walkDecls('margin', decl => { margin = decl.value; });
    });
    assert.equal(margin, 'auto');
});
