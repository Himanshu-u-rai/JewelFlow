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
    assert.match(section, /if \(!scanPanelEl\?\.isConnected\) return;/, 'a loop from a replaced page ends quietly');
    assert.match(section, /finally \{\s*if \(sessionActive && scanPanelEl\?\.isConnected\) pollTimer = setTimeout\(tick, 1500\);/,
        'the next poll is scheduled only after this one finished: polls never overlap');
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
