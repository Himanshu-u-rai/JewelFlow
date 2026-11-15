import assert from 'node:assert/strict';
import test from 'node:test';
import { classifyHeaderActions, isParentLink, isRecordPage, subtitleMode } from '../../resources/js/mobile-header-actions.js';

const a = (label, data = {}) => ({ label, data });
const names = (list) => list.map((item) => item.label);

test('a record page is one whose path ends in an id', () => {
    assert.equal(isRecordPage('/invoices/3'), true);
    assert.equal(isRecordPage('/vault/lots/2/'), true);
    assert.equal(isRecordPage('/s/shop/product/01M6R1S87D5DGY2VG5RZ8RHYEN'), true);
    assert.equal(isRecordPage('/invoices/3/edit'), false);
    assert.equal(isRecordPage('/customers'), false);
    assert.equal(isRecordPage('/reorder/create'), false);
});

test('only the main action stays; every other action goes to the floating button', () => {
    const r = classifyHeaderActions([a('Edit Invoice'), a('Print'), a('Add Customer'), a('Historical Sales')], { recordPage: false });
    assert.deepEqual(names(r.keep), ['Add Customer']);
    assert.deepEqual(names(r.fab), ['Edit Invoice', 'Print', 'Historical Sales']);
    assert.deepEqual(r.back, []);
});

test('a lone secondary action still goes to the floating button', () => {
    const r = classifyHeaderActions([a('Product preferences')], { recordPage: false });
    assert.deepEqual(names(r.keep), []);
    assert.deepEqual(names(r.fab), ['Product preferences']);
});

test('only one main action stays in the header; a second main action goes to the floating button', () => {
    const r = classifyHeaderActions([a('Issue Bullion'), a('New Purchase')], { recordPage: false });
    assert.deepEqual(names(r.keep), ['Issue Bullion']);
    assert.deepEqual(names(r.fab), ['New Purchase']);
});

test('Back, Cancel and parent links are hidden on phones except on a record page', () => {
    const actions = [a('Back to Items'), a('Cancel'), a('All reports'), a('← Vault'), a('Edit')];
    const list = classifyHeaderActions(actions, { recordPage: false });
    assert.deepEqual(names(list.back), ['Back to Items', 'Cancel', 'All reports', '← Vault']);
    assert.equal(list.keepBack, null);
    assert.deepEqual(names(list.fab), ['Edit']);

    const record = classifyHeaderActions(actions, { recordPage: true });
    assert.equal(record.keepBack.label, 'Back to Items', 'the first back-like action stays, as an arrow');
    assert.deepEqual(names(record.back), ['Cancel', 'All reports', '← Vault']);
});

test('the search toggle stays beside the main action', () => {
    const r = classifyHeaderActions([a('Open search'), a('Start Sale'), a('Daily Closing')], { recordPage: false });
    assert.deepEqual(names(r.keep), ['Open search', 'Start Sale']);
    assert.deepEqual(names(r.fab), ['Daily Closing']);
});

test('a link up to a parent path counts as Back whatever it is called', () => {
    assert.equal(isParentLink('/job-orders', '/job-orders/1'), true);
    assert.equal(isParentLink('http://shop.test/vault', '/vault/ledger', 'http://shop.test'), true);
    assert.equal(isParentLink('/cashbook', '/cashbook/create'), true);
    assert.equal(isParentLink('/invoices/3/edit', '/invoices/3'), false, 'a child is not a parent');
    assert.equal(isParentLink('/invoice', '/invoices/3'), false, 'whole path segments only');
    assert.equal(isParentLink('/invoices/3', '/invoices/3'), false);
    assert.equal(isParentLink('https://other.test/vault', '/vault/ledger', 'http://shop.test'), false);

    const r = classifyHeaderActions([{ label: 'Job Orders', data: {}, parent: true }, a('Print Challan')], { recordPage: false });
    assert.deepEqual(names(r.back), ['Job Orders']);
    assert.deepEqual(names(r.fab), ['Print Challan']);
});

test('a page about a record keeps its subtitle; any other page tucks it behind the info button', () => {
    assert.equal(subtitleMode('/masters', {}), 'info');
    assert.equal(subtitleMode('/karigars/create', {}), 'info');
    assert.equal(subtitleMode('/dashboard', {}), 'info');
    assert.equal(subtitleMode('/invoices/3', {}), 'keep');
    assert.equal(subtitleMode('/invoices/3/returns/create', {}), 'keep', 'the record id is anywhere in the path');
    assert.equal(subtitleMode('/customers/7/store-credit/adjust', {}), 'keep');
    assert.equal(subtitleMode('/masters', { mobileSubtitle: 'keep' }), 'keep', 'markup decides');
    assert.equal(subtitleMode('/invoices/3', { mobileSubtitle: 'info' }), 'info');
});

test('a subtitle holding a date or number is information and stays; only descriptions go behind the button', () => {
    assert.equal(subtitleMode('/report/gst', {}, '01 Nov 2026 – 30 Nov 2026 · Standard'), 'keep');
    assert.equal(subtitleMode('/report/daily', {}, '15 Nov 2026 · Detailed'), 'keep');
    assert.equal(subtitleMode('/masters', {}, 'Reusable business records used across sales, inventory, purchasing and manufacturing.'), 'info');
    assert.equal(subtitleMode('/job-orders', {}, 'Bullion issued to karigars'), 'info');
});

test('a page may hide its subtitle on phones when the page shows it elsewhere (the dashboard date)', () => {
    assert.equal(subtitleMode('/dashboard', { mobileSubtitle: 'hide' }, 'Sunday, 15 Nov 2026'), 'hide');
});

test('markup decides over the label', () => {
    const r = classifyHeaderActions([
        a('Job Orders', { mobileAction: 'back' }),
        a('Open cash flow', { mobileAction: 'primary' }),
        a('Start Sale', { mobileAction: 'fab' }),
    ], { recordPage: false });
    assert.deepEqual(names(r.back), ['Job Orders']);
    assert.deepEqual(names(r.keep), ['Open cash flow']);
    assert.deepEqual(names(r.fab), ['Start Sale']);
});

test('a page may keep or hide its Back against the record-page default', () => {
    const keep = classifyHeaderActions([a('Back to historical')], { recordPage: false, backPolicy: 'keep' });
    assert.equal(keep.keepBack.label, 'Back to historical');
    const hide = classifyHeaderActions([a('Back to Customers')], { recordPage: true, backPolicy: 'hide' });
    assert.equal(hide.keepBack, null);
    assert.deepEqual(names(hide.back), ['Back to Customers']);
});
