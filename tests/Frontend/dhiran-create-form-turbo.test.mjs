import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// The New Loan form registered its Alpine data only on `alpine:init`, which fires once per full page load. Opened by a
// Turbo visit (clicking New Loan from any Dhiran page) the form had no data at all and was dead; after a refused save
// it kept the values of the first page load instead of what was typed.
const blade = readFileSync(new URL('../../resources/views/dhiran/create.blade.php', import.meta.url), 'utf8');
const script = blade
    .slice(blade.indexOf("@push('scripts')"))
    .match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/\{\{[\s\S]*?\}\}/g, '0'); // Blade output becomes a plain value

function run(context) {
    vm.runInContext(script, context);
}

test('registers at once when Alpine is already running (a Turbo visit), and again on the next visit', () => {
    const registered = [];
    const context = vm.createContext({
        window: {},
        document: { addEventListener: () => assert.fail('waited for alpine:init that will not fire again') },
    });
    context.window.Alpine = { data: (name, factory) => registered.push([name, factory]) };
    context.Alpine = context.window.Alpine;

    run(context);
    run(context); // the second visit must not throw (e.g. a redeclared const) and must register again
    assert.deepEqual(registered.map(([name]) => name), ['dhiranCreateForm', 'dhiranCreateForm']);
});

test('still waits for alpine:init on a full page load', () => {
    const listeners = [];
    const context = vm.createContext({ window: {}, document: { addEventListener: (event) => listeners.push(event) } });
    run(context);
    assert.deepEqual(listeners, ['alpine:init']);
});
