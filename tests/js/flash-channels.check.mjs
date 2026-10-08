/**
 * Every flash channel a layout emits must be one the page script shows.
 * A `warning` meta was emitted for months and read by nothing: the message
 * reached the HTML and no human. Run: node tests/js/flash-channels.check.mjs
 * ponytail: the repo's node + assert check pattern; no new test dependency.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const app = read('resources/js/app.js');
const start = app.indexOf('function showFlashToasts()');
const end = app.indexOf('function initAjaxDeletes()');
assert(start >= 0 && end > start, 'showFlashToasts boundaries changed: update this check.');
const source = app.slice(start, end);

const layouts = [
    'resources/views/layouts/app.blade.php',
    'resources/views/super-admin/layout.blade.php',
    'resources/views/components/super-admin/layout.blade.php',
];
const emitted = new Set(layouts.flatMap((path) => [...read(path).matchAll(/<meta name="flash-([a-z]+)"/g)].map((m) => m[1])));
assert(emitted.has('success') && emitted.has('error') && emitted.has('warning'), `layouts emit: ${[...emitted]}`);

function run(present) {
    const metas = new Map(present.map((channel) => [`meta[name="flash-${channel}"]`, { content: `${channel} message`, removed: false }]));
    const shown = [];
    const sandbox = {
        document: {
            querySelector: (selector) => {
                const meta = metas.get(selector);
                if (!meta || meta.removed) return null;
                return { content: meta.content, remove: () => { meta.removed = true; } };
            },
        },
        window: { showToast: (message, duration) => shown.push({ message, duration }) },
    };
    vm.runInNewContext(`${source}\nshowFlashToasts();\nshowFlashToasts();`, sandbox);
    return { shown, left: [...metas.values()].filter((meta) => !meta.removed).length };
}

// Each emitted channel is shown once and its tag consumed, so a Turbo re-render
// of the same page does not repeat it.
for (const channel of emitted) {
    const { shown, left } = run([channel]);
    assert.deepEqual(shown.map((t) => t.message), [`${channel} message`], `flash-${channel} is emitted by a layout but not shown`);
    assert.equal(left, 0, `flash-${channel} tag was not consumed`);
}

// A warning carries an instruction: it stays up longer than a confirmation.
const warning = run(['warning']).shown[0];
const success = run(['success']).shown[0];
assert(warning.duration > (success.duration ?? 4000), 'a warning should stay longer than the default 4 s');

// Nothing flashed, nothing shown.
assert.deepEqual(run([]).shown, []);

console.log(`flash channels: ${[...emitted].sort().join(', ')} all shown and consumed`);
