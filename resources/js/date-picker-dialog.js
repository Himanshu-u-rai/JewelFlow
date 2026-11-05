// Keep the existing themed picker inside a modal's focus boundary and top layer.
export function configureDialogDatePicker(input, options, win = globalThis) {
    if (input.hasAttribute('data-date-editable')) options.allowInput = true;
    const dialog = input.closest('dialog');
    if (!dialog) return options;

    const ready = options.onReady, open = options.onOpen, close = options.onClose, destroy = options.onDestroy;
    let onScroll;
    const cleanup = instance => {
        if (onScroll) dialog.removeEventListener('scroll', onScroll, true);
        onScroll = null;
        const calendar = instance.calendarContainer;
        if (calendar.hidePopover && calendar.matches(':popover-open')) calendar.hidePopover();
    };
    options.appendTo = dialog;
    options.position = instance => {
        const box = instance.altInput.getBoundingClientRect();
        const calendar = instance.calendarContainer;
        const size = calendar.getBoundingClientRect();
        const above = box.top - size.height - 4;
        calendar.style.left = `${Math.max(12, Math.min(box.left, win.innerWidth - size.width - 12))}px`;
        calendar.style.top = `${Math.max(12, Math.min(above >= 12 ? above : box.bottom + 4, win.innerHeight - size.height - 12))}px`;
    };
    options.onReady = (...args) => {
        ready?.(...args);
        args[2].calendarContainer.setAttribute('popover', 'manual');
    };
    options.onOpen = (...args) => {
        open?.(...args);
        const instance = args[2];
        instance.calendarContainer.showPopover?.();
        options.position(instance);
        onScroll = event => { if (!instance.calendarContainer.contains(event.target)) instance.close(); };
        dialog.addEventListener('scroll', onScroll, true);
    };
    options.onClose = (...args) => { cleanup(args[2]); close?.(...args); };
    options.onDestroy = (...args) => { cleanup(args[2]); destroy?.(...args); };
    return options;
}
