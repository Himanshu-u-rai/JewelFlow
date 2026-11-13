import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const page = readFileSync(new URL('../../resources/views/scan/mobile.blade.php', import.meta.url), 'utf8');

// Audit N19 (2026-11-13): the phone page fixed its expiry at load and stopped the camera then, while the server
// kept extending the session as long as the POS polled: "Expired" mid-sale. The server already answers 410 for an
// ended session, so it alone decides.
test('only the server ends a phone scanner session', () => {
    assert.doesNotMatch(page, /checkExpiry|const EXPIRY/, 'no local expiry clock');
    assert.match(page, /res\.status === 410/, 'the server says when it ended');
});

// The page loaded html5-qrcode from unpkg, falling back to cdnjs; the CSP allows scripts only from 'self', so both
// were blocked and the camera never started ("Html5Qrcode is not defined"). The library is vendored like qrcode.min.js.
test('the scanner library comes from this site, which the CSP allows', () => {
    assert.match(page, /<script src="\{\{ asset\('js\/html5-qrcode\.min\.js'\) \}\}"><\/script>/);
    assert.doesNotMatch(page, /unpkg\.com|cdnjs\.cloudflare\.com/);
    assert.ok(readFileSync(new URL('../../public/js/html5-qrcode.min.js', import.meta.url), 'utf8').includes('Html5Qrcode'));
});

// A failed send said "will retry next scan" but never retried, and the 2-second repeat filter then ignored scanning
// the same tag again.
test('a failed send can be scanned again at once and says so', () => {
    const fail = page.slice(page.indexOf('} catch(err) {'), page.indexOf('// ── Start camera'));
    assert.match(fail, /lastBarcode = null;/);
    assert.match(fail, /Not sent/);
    assert.doesNotMatch(page, /will retry/);
});
