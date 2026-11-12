import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// Owner 2026-11-12, state-machine audit (M4). The New Loan form kept the chosen borrower in several fields that could
// disagree (a hidden id posted for a borrower the screen did not show), and the Add Borrower dialog was three loose
// flags: closing it mid-save still selected the borrower later, and an old error came back when it reopened.
const blade = readFileSync(new URL('../../resources/views/dhiran/create.blade.php', import.meta.url), 'utf8');
const script = blade
    .slice(blade.indexOf("@push('scripts')"))
    .match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace(/\{\{[\s\S]*?\}\}/g, '0');

function makeForm(fetchImpl = async () => assert.fail('no request expected')) {
    let factory;
    const context = vm.createContext({
        window: {},
        document: { addEventListener() {}, querySelector: () => ({ content: 'token' }) },
        fetch: (...args) => fetchImpl(...args),
    });
    context.window.Alpine = { data: (name, f) => { factory = f; } };
    context.Alpine = context.window.Alpine;
    vm.runInContext(script, context);
    const form = factory();
    form.items = [];
    form.customers = [{ id: 7, name: 'Asha Rao', mobile: '9810000007', pan: '', id_number: '' }];
    return form;
}

test('the borrower posted is always the borrower shown', () => {
    const ghost = makeForm();
    ghost.initialCustomerId = '99'; // e.g. after a refused save, for a borrower no longer in the list
    ghost.init();
    assert.equal(ghost.selectedCustomer, null);
    assert.equal(ghost.customer_id, '', 'no id is posted for a borrower the screen does not show');

    const known = makeForm();
    known.initialCustomerId = '7';
    known.init();
    assert.equal(known.customer_id, 7);
    known.customerSearch = 'someone else';
    known.handleCustomerSearchInput();
    assert.equal(known.customer_id, '', 'typing over the name clears the choice and the posted id together');
});

test('the add-borrower dialog cannot be closed mid-save and opens clean', async () => {
    let answer;
    const form = makeForm(() => new Promise((resolve) => { answer = resolve; }));
    form.nb = { first_name: 'Ravi', last_name: '', mobile: '9810000008', pan: '', id_number: '', state_code: '', address: '' };

    form.openNewBorrower();
    const saving = form.saveBorrower();
    assert.equal(form.nbSaving, true);
    form.closeNewBorrower();
    assert.equal(form.newBorrowerOpen, true, 'Esc, backdrop or Cancel do nothing while saving');

    answer({ ok: false, json: async () => ({ message: 'Mobile already used' }) });
    await saving;
    assert.equal(form.newBorrowerOpen, true);
    assert.equal(form.nbError, 'Mobile already used');

    form.closeNewBorrower();
    assert.equal(form.newBorrowerOpen, false);
    form.openNewBorrower();
    assert.equal(form.nbError, '', 'reopening starts without the last error');
});
