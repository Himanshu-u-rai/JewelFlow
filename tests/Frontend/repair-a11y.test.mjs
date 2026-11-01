import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const blade = readFileSync(new URL('../../resources/views/repairs.blade.php', import.meta.url), 'utf8');
const fn = name => blade.match(new RegExp(`        function ${name}\\([^]*?\\n        \\}`))[0];

test('every rebuilt purity option is a native button, including Custom, across metal changes', () => {
    const elements = Object.fromEntries(['purityDdList', 'purityDdTrigger', 'purity', 'custom_purity', 'purityLabelText'].map(id => [id, {
        innerHTML: '', value: 'old', required: true, classList: { add() {} }, setAttribute() {},
    }]));
    const context = {
        document: { getElementById: id => elements[id] },
        window: { REPAIR_PURITY_OPTIONS: { gold: [{ value: '22', label: '22K' }], silver: [{ value: '925', label: '925' }] } },
        REPAIR_PURITY_SUBS: { silver: { '925': 'Sterling' } },
        escHtml: value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;'),
    };
    for (const metal of ['silver', 'gold', 'other', 'silver']) {
        runInNewContext(fn('rebuildPurityOptions') + `\nrebuildPurityOptions('${metal}');`, context);
        const html = elements.purityDdList.innerHTML;
        assert.equal((html.match(/<button type="button"/g) || []).length, metal === 'other' ? 1 : 2, 'native buttons remain keyboard reachable without the one-time DOM upgrader');
        assert.match(html, /data-value="custom"/);
        assert.equal(elements.purity.value, '');
        assert.equal(elements.custom_purity.required, false);
        if (metal === 'silver') assert.match(html, /data-value="925"/);
    }
});

test('delivery opens a native modal only after filling the selected record and closes through its native API', () => {
    let opens = 0, closes = 0;
    const elements = Object.fromEntries(['deliverModal', 'modalCustomerName', 'modalItemDesc', 'amount', 'include_gst', 'gstRateWrap', 'deliverForm'].map(id => [id, {
        classList: { add() {}, remove() {} },
    }]));
    elements.deliverModal.showModal = () => {
        opens++;
        assert.equal(elements.modalCustomerName.textContent, 'Synthetic customer');
        assert.equal(elements.deliverForm.action, '/repairs/17/deliver');
    };
    elements.deliverModal.close = () => { closes++; };
    const context = { document: { getElementById: id => elements[id] } };
    runInNewContext(fn('openDeliverModal') + fn('closeDeliverModal') + '\nopenDeliverModal(17, "Synthetic customer", "Synthetic ring", 450); closeDeliverModal();', context);
    assert.equal(opens, 1);
    assert.equal(closes, 1);
    assert.equal(elements.amount.value, 450);
    assert.equal(elements.include_gst.checked, false);
});
