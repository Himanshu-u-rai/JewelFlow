import assert from 'node:assert/strict';
import test from 'node:test';
import { requestReportExport } from '../../resources/js/report-export.js';

test('export popup keeps a server file download and filename intact', async () => {
    const result = await requestReportExport('/reports/cash-flow/export', new FormData(), '/reports/cash-flow/export', async () =>
        new Response('canonical csv', { headers: { 'Content-Disposition': 'attachment; filename="cash.csv"', 'Content-Type': 'text/csv' } }));
    assert.equal(result.kind, 'download');
    assert.equal(result.filename, 'cash.csv');
    assert.equal(await result.blob.text(), 'canonical csv');
});

test('export validation stays readable in the popup, without navigating or dropping inputs', async () => {
    const result = await requestReportExport('/reports/cash-flow/export', new FormData(), '/reports/cash-flow/export', async () =>
        new Response(JSON.stringify({ errors: { date_from: ['Choose a start date.'], date_to: ['Choose an end date.'] } }), { status: 422, headers: { 'Content-Type': 'application/json' } }));
    assert.equal(result.kind, 'errors');
    assert.deepEqual(result.errors, ['Choose a start date.', 'Choose an end date.']);
});

test('export popup handles expired sessions and preserves queued or preset HTML feedback', async () => {
    await assert.rejects(requestReportExport('/export', new FormData(), '/export', async () => new Response('', { status: 419 })), /session|sign in/i);
    const html = '<turbo-frame id="report-export-frame"><p>Your export is being prepared.</p></turbo-frame>';
    const result = await requestReportExport('/export', new FormData(), '/export', async () => new Response(html));
    assert.deepEqual(result, { kind: 'panel', html });
});
