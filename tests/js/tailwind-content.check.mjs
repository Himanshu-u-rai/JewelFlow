/**
 * The stylesheet must be built from tracked sources only, so that any clean
 * checkout of a commit builds the same file. Run: node tests/js/tailwind-content.check.mjs
 */
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

// Read, not imported: the config imports the build tool's own modules.
const source = readFileSync(new URL('../../tailwind.config.js', import.meta.url), 'utf8');
const block = source.match(/\n    content: \[([\s\S]*?)\n    \],/);
assert(block, 'tailwind.config.js: content array not found: update this check.');
const config = { content: [...block[1].matchAll(/'([^']+)'/g)].map((m) => m[1]) };
assert(config.content.length > 0, 'tailwind.config.js: content is empty');

const tracked = execFileSync('git', ['ls-files'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).split('\n');

for (const glob of config.content) {
    assert(!/(^|\/)(storage|vendor|node_modules|public|bootstrap\/cache)\//.test(glob), `${glob}: not a tracked source directory`);
    const prefix = glob.replace(/^\.\//, '').split('*')[0];
    const extension = glob.slice(glob.lastIndexOf('*') + 1);
    const matched = tracked.filter((path) => path.startsWith(prefix) && path.endsWith(extension));
    assert(matched.length > 0, `${glob}: matches no tracked file`);
}

// The framework's pagination and error pages would need the vendor directory
// scanned: the application must keep its own copies under resources/views.
for (const view of ['vendor/pagination/tailwind.blade.php', 'vendor/pagination/simple-tailwind.blade.php', 'errors/404.blade.php', 'errors/500.blade.php', 'errors/503.blade.php']) {
    assert(tracked.includes(`resources/views/${view}`), `resources/views/${view} is missing: the framework's copy is not scanned`);
}

console.log(`tailwind content: ${config.content.join(', ')} (tracked sources only)`);
