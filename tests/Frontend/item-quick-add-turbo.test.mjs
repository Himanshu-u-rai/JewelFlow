import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Staging, 2026-11-20: opening Create Item a second time in one session threw "Identifier 'quickAdd' has already been
// declared". Turbo runs a page's inline <script> again on every visit in the same document, so a top-level const/let
// in it throws on the second visit and none of the item form's script (net weight, purity, categories, quick-add) runs.
const view = (path) => readFileSync(new URL(`../../resources/views/inventory/items/${path}`, import.meta.url), 'utf8');

test('the shared quick-add script declares nothing that a second Turbo visit would re-declare', () => {
    const source = view('_quick_add_script.blade.php')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/\{\{\s*\$refreshDropdown\s*\}\}/g, 'refreshDropdown')
        .replace(/\{\{\s*route\([^}]*\)\s*\}\}/g, '/route');
    const context = vm.createContext({
        document: { getElementById: () => null, addEventListener() {}, querySelectorAll: () => [] },
        window: {}, refreshDropdown() {}, fetch: async () => ({}), console,
    });
    const run = () => vm.runInContext(source, context);
    run();
    assert.doesNotThrow(run, 'the second visit must not throw a redeclaration');
});

test('the item create and edit pages include it inside their own script', () => {
    for (const page of ['create-retailer.blade.php', 'edit-retailer.blade.php']) {
        assert.match(view(page), /@include\('inventory\.items\._quick_add_script'/, page);
    }
});
