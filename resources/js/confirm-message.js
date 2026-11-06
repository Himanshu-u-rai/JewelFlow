const FALLBACK = 'Are you sure you want to continue?';

// The message of an inline `onsubmit="return confirm('…')"`, for the app's confirmation dialog.
// It was written as a JavaScript string literal (can\'t, or a name passed through addslashes), so its
// escapes are undone here; the native confirm() used to do that.
export function extractConfirmMessage(onsubmitAttr, decodeEntities = (value) => value) {
    if (!onsubmitAttr) return FALLBACK;
    const match = onsubmitAttr.match(/confirm\((['"])((?:\\[\s\S]|(?!\1)[\s\S])*)\1\)/);
    if (!match) return FALLBACK;
    return decodeEntities(match[2]).replace(/\\(['"\\])/g, '$1');
}
