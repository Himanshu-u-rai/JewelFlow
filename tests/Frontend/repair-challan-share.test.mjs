import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

// "Share challan": the device's share menu gets the actual PDF; staff choose
// WhatsApp and the recipient and send it themselves. These run the real module
// against a stubbed navigator (the share menu itself is the operating system's).
const source = new URL('../../resources/js/repair-challan-share.js', import.meta.url);
const available = existsSync(source);
const module = available ? await import(source) : {};
const requireModule = () => assert.ok(available, 'Challan sharing is not implemented yet.');

const URL_PDF = '/repairs/7/challan/pdf';
const NAME = 'REP-007-challan.pdf';

function setup({ shareImpl, canShare = () => true, withShare = true, ok = true } = {}) {
    const calls = { fetch: [], share: [] };
    const nav = withShare ? {
        canShare: (data) => canShare(data),
        share: async (data) => { calls.share.push(data); return shareImpl ? shareImpl(data) : undefined; },
    } : {};
    const fetchImpl = async (url, options) => {
        calls.fetch.push({ url, options });
        return { ok, status: ok ? 200 : 403, blob: async () => new Blob(['%PDF-1.4 fake'], { type: 'application/pdf' }) };
    };
    const share = available ? module.createChallanSharer({ url: URL_PDF, filename: NAME, nav, fetchImpl }) : null;
    return { share, calls };
}

test('a phone that can share files gets the actual PDF under the reference name', async () => {
    requireModule();
    const { share, calls } = setup();
    assert.equal(await share(), 'shared');
    assert.equal(calls.fetch.length, 1);
    assert.equal(calls.fetch[0].url, URL_PDF, 'the signed-in PDF route, never a public link');
    assert.equal(calls.fetch[0].options.credentials, 'same-origin');
    assert.equal(calls.share.length, 1);
    const [file] = calls.share[0].files;
    assert.equal(file.name, NAME);
    assert.equal(file.type, 'application/pdf');
    assert.ok(!('url' in calls.share[0]), 'no link is shared alongside the file');
});

test('opening the share menu is reported as that, never as delivery', async () => {
    requireModule();
    const text = module.MESSAGES.shared;
    assert.match(text, /cannot see whether/i);
    assert.doesNotMatch(text, /\b(sent successfully|delivered|message sent)\b/i);
});

test('cancelling the share menu says nothing was sent', async () => {
    requireModule();
    const { share } = setup({ shareImpl: () => { throw Object.assign(new Error('x'), { name: 'AbortError' }); } });
    assert.equal(await share(), 'cancelled');
    assert.match(module.MESSAGES.cancelled, /nothing was sent/i);
});

test('a browser without file sharing gets the download route and WhatsApp attach steps, not a chat link', async () => {
    requireModule();
    const { share, calls } = setup({ withShare: false });
    assert.equal(await share(), 'unsupported');
    assert.equal(calls.fetch.length, 0, 'nothing is fetched when it cannot be shared');
    assert.match(module.MESSAGES.unsupported, /download the pdf/i);
    assert.match(module.MESSAGES.unsupported, /attach/i);
    assert.doesNotMatch(module.MESSAGES.unsupported, /wa\.me|api\.whatsapp/i);
});

test('a device that shares, but not this file type, falls back to download', async () => {
    requireModule();
    const { share, calls } = setup({ canShare: () => false });
    assert.equal(await share(), 'unsupported');
    assert.equal(calls.share.length, 0);
});

test('a refused PDF download (signed out, no permission) is a failure, not a share', async () => {
    requireModule();
    const { share, calls } = setup({ ok: false });
    assert.equal(await share(), 'failed');
    assert.equal(calls.share.length, 0);
});

test('when the tap expires while the PDF downloads, the next tap shares the prepared file', async () => {
    requireModule();
    let first = true;
    const { share, calls } = setup({ shareImpl: () => { if (first) { first = false; throw Object.assign(new Error('x'), { name: 'NotAllowedError' }); } } });
    assert.equal(await share(), 'ready');
    assert.equal(await share(), 'shared');
    assert.equal(calls.fetch.length, 1, 'the PDF is fetched once');
    assert.equal(calls.share.length, 2);
});
