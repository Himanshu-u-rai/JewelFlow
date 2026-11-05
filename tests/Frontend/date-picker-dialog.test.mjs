import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const path = new URL('../../resources/js/date-picker-dialog.js', import.meta.url);
const { configureDialogDatePicker } = existsSync(path) ? await import(path) : {};

function fixture(dialog = true) {
    assert.equal(typeof configureDialogDatePicker, 'function');
    const listeners = new Map();
    const host = { addEventListener(name, handler) { listeners.set(name, handler); }, removeEventListener(name) { listeners.delete(name); } };
    const input = { closest: () => dialog ? host : null, hasAttribute: () => true };
    let open = false, ready = 0, opened = 0, closed = 0;
    const calendar = {
        style: {}, setAttribute(name, value) { this[name] = value; },
        showPopover() { open = true; }, hidePopover() { open = false; }, matches: () => open,
        getBoundingClientRect: () => ({ width: 300, height: 300 }), contains: target => target === calendar,
    };
    const instance = {
        calendarContainer: calendar,
        altInput: { getBoundingClientRect: () => ({ left: 280, top: 350, bottom: 394 }) },
        close() { closed++; options.onClose([], '', instance); },
    };
    const options = configureDialogDatePicker(input, { allowInput: false, onReady() { ready++; }, onOpen() { opened++; } }, { innerWidth: 400, innerHeight: 500 });
    return { options, calendar, instance, host, listeners, counts: () => ({ open, ready, opened, closed }) };
}

test('themed calendar stays inside the dialog, opens as a positioned popover and preserves theme hooks', () => {
    const f = fixture();
    assert.equal(f.options.allowInput, true);
    assert.equal(f.options.appendTo, f.host);
    f.options.onReady([], '', f.instance);
    assert.equal(f.calendar.popover, 'manual');
    f.options.onOpen([], '', f.instance);
    f.options.position(f.instance);
    assert.deepEqual(f.counts(), { open: true, ready: 1, opened: 1, closed: 0 });
    assert.equal(f.calendar.style.left, '88px');
    assert.equal(f.calendar.style.top, '46px');
    f.options.onClose([], '', f.instance);
    assert.equal(f.counts().open, false);
    assert.equal(f.listeners.size, 0);
});

test('scrolling the form closes the calendar; calendar scrolling stays usable; destroy cleans handlers', () => {
    const f = fixture();
    f.options.onReady([], '', f.instance);
    f.options.onOpen([], '', f.instance);
    f.listeners.get('scroll')({ target: f.calendar });
    assert.equal(f.counts().closed, 0);
    f.listeners.get('scroll')({ target: f.host });
    assert.equal(f.counts().closed, 1);
    f.options.onOpen([], '', f.instance);
    f.options.onDestroy([], '', f.instance);
    assert.equal(f.listeners.size, 0);
    assert.equal(f.counts().open, false);
});

test('ordinary pages retain body placement and unmarked dates keep their existing input policy', () => {
    const f = fixture(false);
    assert.equal(f.options.allowInput, true, 'standalone opt-in remains editable');
    assert.equal(f.options.appendTo, undefined);
    const options = configureDialogDatePicker({ closest: () => null, hasAttribute: () => false }, { allowInput: false });
    assert.equal(options.allowInput, false);
});

test('the existing display styling respects the picker input policy instead of forcing readonly', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const fn = source.slice(source.indexOf('function styleDatePickerInput('), source.indexOf('\nfunction dispatchDatePickerChange('));
    const style = new Function(`return (${fn});`)();
    const display = { classList: { add() {} }, setAttribute() {} };
    const input = { getAttribute: () => null };
    style(input, { altInput: display, config: { allowInput: true } });
    assert.equal(display.readOnly, false);
    style(input, { altInput: display, config: { allowInput: false } });
    assert.equal(display.readOnly, true);
});
