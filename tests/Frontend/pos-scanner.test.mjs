import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const pos = readFileSync(new URL('../../resources/views/pos.blade.php', import.meta.url), 'utf8');
const section = pos.slice(pos.indexOf('function startPolling()'), pos.indexOf('function stopPolling()'));

// Audit N1 (2026-11-13): the phone-scanner poll ran on setInterval and nothing stopped it when Turbo left the POS.
// It kept taking scans on other pages (into the old page's cart) and, back on the POS, a second poller started on
// the same session: scans the phone reported "added" never reached the visible cart.
test('the poll loop belongs to its page and stops itself once Turbo replaced it', () => {
    assert.doesNotMatch(section, /setInterval/, 'no fixed-rate loop');
    assert.match(pos, /const scanPanelEl = document\.getElementById\('scanModal'\);/);
    assert.match(section, /!scanPanelEl\?\.isConnected\) return;/, 'a loop from a replaced page ends quietly');
    assert.match(section, /finally \{\s*if \(epoch === pollEpoch && sessionActive && scanPanelEl\?\.isConnected\) pollTimer = setTimeout\(tick, 1500\);/,
        'the next poll is scheduled only after this one finished: polls never overlap');
});

// Security review of e3c2bd74: the timer handle is null while a poll is in flight, so a second loop could start, and
// a stopped loop's in-flight poll could reschedule itself onto a newer session. Each loop now has an epoch.
test('a stopped or superseded loop never polls again', () => {
    assert.match(section, /const epoch = \+\+pollEpoch;/);
    assert.match(section, /if \(epoch !== pollEpoch \|\| !scanPanelEl\?\.isConnected\) return;/);
    assert.match(section, /if \(epoch === pollEpoch && sessionActive && scanPanelEl\?\.isConnected\) pollTimer = setTimeout\(tick, 1500\);/);
    const stop = pos.slice(pos.indexOf('function stopPolling()'), pos.indexOf('function stopPolling()') + 300);
    assert.match(stop, /pollEpoch\+\+;/);
    assert.match(section, /if \(!scanToken \|\| pollLive\) return;/);
});

// Audit N5: one failed poll painted "expired / Connection issue" and, because mobileConnected stayed true, the next
// good poll never repainted "Connected": the badge lied while scans kept arriving.
test('every good poll repaints the badge from what the server says', () => {
    assert.match(section, /mobileConnected = !!data\.mobile_connected;\s*setScanStatus\(mobileConnected \? 'active' : 'waiting'/);
});

test('the countdown of a replaced page stops too', () => {
    const countdown = pos.slice(pos.indexOf('function startCountdown()'), pos.indexOf('function setScanStatus('));
    assert.match(countdown, /if \(!scanPanelEl\?\.isConnected\) \{ clearInterval\(countdownTimer\); return; \}/);
});

// Live break of the epoch fix: stop, then start again → "Cannot read properties of null (reading 'style')". The QR
// render replaced the whole wrap with a canvas, deleting the spinner the next start resets. Every restart after the
// first session failed; the cashier saw nothing. The canvas now lives in the markup and only its own content changes.
test('starting a second session finds the elements the first one rendered into', () => {
    const open = pos.slice(pos.indexOf('window.openScanModal = async function'), pos.indexOf('window.closeScanModal'));
    assert.doesNotMatch(open, /qrWrap\.innerHTML|scanQrWrap'\)\.innerHTML/, 'the render never rebuilds the wrap');
    assert.match(pos, /<div id="scanQrCanvas"[^>]*><\/div>/, 'the canvas is part of the markup');
    assert.doesNotMatch(pos, /scanQrImg/, 'no dead QR image');
});
