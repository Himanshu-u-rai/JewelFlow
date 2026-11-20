import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import test from 'node:test';

// Turbo runs a page's inline <script> again on every visit in the same document. A top-level const, let or class in
// it throws "Identifier ... has already been declared" on the second visit, before any of that page's script runs
// (staging, 2026-11-20: Create Item, then six older pages with the same pattern). Top-level names use var or live
// inside a function.
const root = new URL('../../resources/views/', import.meta.url).pathname;
const views = (dir) => readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
    entry.isDirectory() ? views(join(dir, entry.name)) : entry.name.endsWith('.blade.php') ? [join(dir, entry.name)] : []);

function topLevelDeclarations(source) {
    const found = [];
    const scripts = source.matchAll(/<script(?![^>]*\b(?:src=|type="module"|type="application\/(?:json|ld\+json)"))[^>]*>([\s\S]*?)<\/script>/g);
    for (const [, body] of scripts) {
        const lines = body.split('\n').filter((line) => line.trim() && !/^\s*(\/\/|\*|\/\*|\{\{--|@)/.test(line));
        if (!lines.length) continue;
        const base = Math.min(...lines.map((line) => line.length - line.trimStart().length));
        for (const line of lines) {
            const match = line.match(/^(\s*)(const|let|class)\s+([A-Za-z_$][\w$]*)/);
            if (match && match[1].length === base) found.push(`${match[2]} ${match[3]}`);
        }
    }
    return found;
}

test('no inline page script declares a top-level const, let or class that a second Turbo visit would re-declare', () => {
    const offenders = views(root)
        .map((file) => [file.slice(root.length), topLevelDeclarations(readFileSync(file, 'utf8'))])
        .filter(([, names]) => names.length)
        .map(([file, names]) => `${file}: ${names.slice(0, 4).join(', ')}`);
    assert.deepEqual(offenders, []);
});

test('the scan finds the pattern it guards against', () => {
    assert.deepEqual(topLevelDeclarations('<script>\n    const a = 1;\n    (() => {\n        const b = 2;\n    })();\n</script>'), ['const a']);
    assert.deepEqual(topLevelDeclarations('<script type="module">\nconst a = 1;\n</script>'), []);
});
