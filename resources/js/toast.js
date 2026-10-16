// showToast(message, 5000) keeps the old meaning: a duration, no tone. showToast(message,
// 'success'|'warning'|'error', duration?) picks the colour (#global-toast[data-tone] in
// app.css); with no tone the toast keeps the default .global-toast colour.
export function toastSettings(durationOrTone = 4000, duration) {
    if (typeof durationOrTone === 'number') return { tone: null, duration: durationOrTone };
    return {
        tone: typeof durationOrTone === 'string' && durationOrTone ? durationOrTone : null,
        duration: typeof duration === 'number' ? duration : 4000,
    };
}

// The layouts put each session flash in a meta tag. A warning tells the user what to do
// next (no return policy yet, for one), so it stays up twice as long as a confirmation.
const FLASHES = [
    ['flash-success', 'success', 4000],
    ['flash-error', 'error', 4000],
    ['flash-warning', 'warning', 8000],
];

export function showFlashes(doc, show) {
    for (const [name, tone, duration] of FLASHES) {
        const meta = doc.querySelector(`meta[name="${name}"]`);
        if (!meta) continue;
        show(meta.content, tone, duration);
        meta.remove();
    }
}
