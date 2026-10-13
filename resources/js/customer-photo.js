export function cameraError(error) {
    if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') {
        return 'Camera permission was refused. Allow it in your browser, or use Upload photo.';
    }
    return 'A camera is unavailable or could not start. You can still use Upload photo.';
}

export function photoFileError(file) {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file?.type)) {
        return 'Choose a JPEG, PNG or WebP image. HEIC and other formats are not supported.';
    }
    return file.size > 5 * 1024 * 1024 ? 'Choose an image no larger than 5 MB.' : '';
}

export class PhotoCamera {
    constructor(video, devices = globalThis.navigator?.mediaDevices) {
        this.video = video;
        this.devices = devices;
        this.stream = null;
        this.generation = 0;
    }

    async start() {
        this.stop();
        const generation = this.generation;
        if (!this.devices?.getUserMedia) throw new Error(cameraError());
        let stream;
        try {
            stream = await this.devices.getUserMedia({
                video: { facingMode: { ideal: 'user' }, width: { ideal: 1600 }, height: { ideal: 1200 } }, audio: false,
            });
        } catch (error) {
            if (generation !== this.generation) return false;
            throw error;
        }
        if (generation !== this.generation) {
            stream.getTracks().forEach(track => track.stop());
            return false;
        }
        this.stream = stream;
        this.video.srcObject = stream;
        try {
            await this.video.play();
            return generation === this.generation;
        } catch (error) {
            if (generation !== this.generation) return false;
            this.stop();
            throw error;
        }
    }

    stop() {
        this.generation++;
        this.stream?.getTracks().forEach(track => track.stop());
        this.stream = null;
        this.video.srcObject = null;
    }
}

function setAvatar(avatar, url) {
    const image = avatar.querySelector('[data-photo-image]');
    const initials = avatar.querySelector('[data-photo-initials]');
    image.hidden = !url;
    initials.hidden = !!url;
    if (url) image.src = url;
    else image.removeAttribute('src');
    delete avatar.dataset.photoStale;
}

function initializePhoto(root) {
    const dialog = root.querySelector('[data-photo-dialog]');
    if (!dialog) return null;
    const video = root.querySelector('[data-photo-video]');
    const camera = new PhotoCamera(video);
    const listeners = new AbortController();
    const picker = root.querySelector('[data-photo-picker]');
    const preview = root.querySelector('[data-photo-preview]');
    const errorBox = root.querySelector('[data-photo-error]');
    const help = root.querySelector('[data-photo-help]');
    const status = root.querySelector('[data-photo-status]');
    const avatar = root.querySelector('[data-customer-avatar]');
    const formMode = root.dataset.photoMode === 'form';
    const formFile = root.querySelector('[data-photo-form-file]');
    const formVersion = root.querySelector('[data-photo-form-version]');
    const formRemove = root.querySelector('[data-photo-form-remove]');
    let version = root.dataset.photoVersion;
    let currentUrl = avatar.querySelector('[data-photo-image]').getAttribute('src');
    let candidate = null;
    let candidateUrl = null;
    let selectedUrl = null;
    let mode = 'choose';
    let busy = false;
    let opener = null;
    let alive = true;
    let generation = 0;
    let needsReconcile = false;

    const buttons = (action) => root.querySelectorAll(`[data-photo-action="${action}"]`);
    const show = (action, visible) => buttons(action).forEach(button => { button.hidden = !visible; });
    const fail = (message) => {
        errorBox.textContent = message;
        errorBox.hidden = false;
        errorBox.focus();
    };
    const clearCandidate = () => {
        if (candidateUrl) URL.revokeObjectURL(candidateUrl);
        candidate = candidateUrl = null;
        preview.removeAttribute('src');
    };
    const view = (state) => {
        mode = state;
        errorBox.hidden = true;
        video.hidden = state !== 'camera';
        preview.hidden = state !== 'preview';
        show('capture', state === 'camera');
        show('retake', state === 'preview' && candidate?.name === 'camera-capture.jpg');
        show('confirm', state === 'preview' || state === 'remove');
        root.querySelector('[data-photo-action="confirm"]').textContent = state === 'remove'
            ? 'Remove photo' : (formMode ? 'Use photo' : 'Save photo');
        help.textContent = state === 'remove'
            ? 'Remove this customer photo? Initials will be shown instead. KYC documents stay untouched.'
            : 'Take a photo or choose an image. Preview it before saving.';
        dialog.querySelector('.customer-photo-dialog__choices').hidden = state === 'remove';
    };
    const open = (button) => {
        if (!dialog.open) {
            opener = button;
            dialog.showModal();
        }
    };
    const restoreFocus = () => {
        if (!alive || dialog.open) return;
        const usable = button => button?.isConnected && !button.disabled && button.getClientRects().length;
        const target = usable(opener) ? opener : [...root.querySelectorAll('.customer-photo__actions button')].find(usable);
        target?.focus();
    };
    const close = () => {
        camera.stop();
        clearCandidate();
        view('choose');
        if (dialog.open) dialog.close();
        if (!busy) restoreFocus();
    };
    const changed = (photo) => {
        generation++;
        version = photo?.version ?? '';
        currentUrl = photo?.url ?? null;
        root.dataset.photoVersion = version;
        if (formVersion) formVersion.value = version;
        setAvatar(avatar, currentUrl);
        show('change', !!currentUrl);
        show('remove', !!currentUrl);
    };
    const reconcile = async () => {
        try {
            const response = await fetch(root.dataset.photoUrl, {
                credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: listeners.signal,
            });
            if (!response.ok) throw new Error('Current photo is unavailable.');
            const data = await response.json();
            if (!alive) return;
            changed(data.photo);
            needsReconcile = false;
            globalThis.Turbo?.cache.clear();
            status.textContent = 'Current photo checked. Review it before retrying the change.';
        } catch {
            if (alive) status.textContent = 'The photo outcome is still unconfirmed. Refresh this page before retrying.';
        }
    };
    const startCamera = async (button) => {
        open(button);
        clearCandidate();
        view('camera');
        buttons('capture').forEach(button => { button.disabled = true; });
        try {
            if (await camera.start()) buttons('capture').forEach(button => { button.disabled = false; });
        } catch (error) {
            if (!dialog.open || !alive) return;
            view('choose');
            fail(cameraError(error));
        }
    };
    const select = (file) => {
        const error = photoFileError(file);
        if (error) { fail(error); return; }
        camera.stop();
        clearCandidate();
        candidate = file;
        candidateUrl = URL.createObjectURL(file);
        preview.src = candidateUrl;
        preview.onerror = () => { clearCandidate(); view('choose'); fail('This image cannot be previewed. Choose another JPEG, PNG or WebP.'); };
        view('preview');
    };
    const confirm = async () => {
        if (formMode) {
            try {
                const transfer = new DataTransfer();
                if (mode !== 'remove') transfer.items.add(candidate);
                formFile.files = transfer.files;
                formRemove.value = mode === 'remove' ? '1' : '0';
                if (selectedUrl) URL.revokeObjectURL(selectedUrl);
                selectedUrl = mode === 'remove' ? null : URL.createObjectURL(candidate);
                setAvatar(avatar, selectedUrl);
                show('change', !!selectedUrl);
                show('remove', !!selectedUrl);
                status.textContent = mode === 'remove' ? 'Photo removal selected. Save the customer to apply it.' : 'Photo selected. It will be saved with this customer.';
                close();
            } catch (error) {
                fail('This browser could not attach the photo. Please use a current browser; your other form fields are unchanged.');
            }
            return;
        }
        const removing = mode === 'remove';
        generation++;
        busy = true;
        root.querySelectorAll('button').forEach(button => { button.disabled = true; });
        let message = 'The photo change could not be confirmed. Check the current photo before retrying.';
        status.textContent = removing ? 'Removing photo…' : 'Processing and saving photo…';
        try {
            if (needsReconcile) {
                await reconcile();
                return;
            }
            const body = new FormData();
            body.set('customer_photo_version', version);
            if (!removing) body.set('customer_photo', candidate);
            if (removing) body.set('_method', 'DELETE');
            const response = await fetch(root.dataset.photoUrl, {
                method: 'POST', credentials: 'same-origin', body,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const data = await response.json();
            if (!response.ok) {
                message = data.errors?.customer_photo?.[0] ?? data.message ?? message;
                throw new Error(message);
            }
            if (!alive) return;
            changed(data.photo);
            globalThis.Turbo?.cache.clear();
            status.textContent = data.message;
            close();
        } catch {
            if (alive) {
                needsReconcile = true;
                status.textContent = 'The photo change could not be confirmed. Checking the current photo…';
                fail(message);
                await reconcile();
            }
        } finally {
            generation++;
            busy = false;
            if (alive) {
                root.querySelectorAll('button').forEach(button => { button.disabled = false; });
                restoreFocus();
            }
        }
    };

    root.addEventListener('click', async event => {
        const button = event.target.closest('[data-photo-action]');
        if (!button || busy) return;
        switch (button.dataset.photoAction) {
            case 'camera': case 'retake': await startCamera(button); break;
            case 'upload':
                open(button); camera.stop(); clearCandidate(); view('choose'); picker.value = ''; picker.click(); break;
            case 'change': open(button); view('choose'); break;
            case 'remove': open(button); camera.stop(); clearCandidate(); view('remove'); break;
            case 'cancel': close(); break;
            case 'confirm': await confirm(); break;
            case 'capture': {
                if (!video.videoWidth || !video.videoHeight) { fail('Wait for the camera preview, then capture again.'); break; }
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const context = canvas.getContext('2d');
                if (!context) { fail('Capture is unavailable. You can still use Upload photo.'); break; }
                context.drawImage(video, 0, 0);
                const generation = camera.generation;
                canvas.toBlob(blob => {
                    if (!dialog.open || !alive || generation !== camera.generation) return;
                    if (blob) select(new File([blob], 'camera-capture.jpg', { type: 'image/jpeg' }));
                    else fail('Capture failed. Try again or use Upload photo.');
                }, 'image/jpeg', .95);
                break;
            }
        }
    }, { signal: listeners.signal });
    picker.addEventListener('change', () => { if (picker.files[0]) select(picker.files[0]); }, { signal: listeners.signal });
    dialog.addEventListener('cancel', event => { event.preventDefault(); if (!busy) close(); }, { signal: listeners.signal });
    dialog.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const focusable = [...dialog.querySelectorAll('button')].filter(button => !button.disabled && button.getClientRects().length);
        const edge = event.shiftKey ? focusable[0] : focusable.at(-1);
        if (document.activeElement === edge) {
            event.preventDefault();
            (event.shiftKey ? focusable.at(-1) : focusable[0])?.focus();
        }
    }, { signal: listeners.signal });
    dialog.addEventListener('close', () => { camera.stop(); clearCandidate(); }, { signal: listeners.signal });

    if (formFile?.files.length) {
        selectedUrl = URL.createObjectURL(formFile.files[0]);
        setAvatar(avatar, selectedUrl);
        show('change', true);
        show('remove', true);
    } else if (formRemove?.value === '1') {
        setAvatar(avatar, null);
    }

    return {
        root,
        get generation() { return generation; },
        refresh(photo, expectedGeneration) {
            if (!alive || busy || expectedGeneration !== generation) return;
            if (!formFile?.files.length && formRemove?.value !== '1') changed(photo);
            delete avatar.dataset.photoStale;
        },
        dispose() {
            alive = false;
            close();
            listeners.abort();
            root.querySelectorAll('button').forEach(button => { button.disabled = false; });
            if (selectedUrl) URL.revokeObjectURL(selectedUrl);
        },
    };
}

export function initializeCustomerPhotos() {
    const controllers = new Map();
    let refreshGeneration = 0;
    const refresh = async () => {
        const generation = ++refreshGeneration;
        const requests = new Map();
        for (const avatar of document.querySelectorAll('[data-customer-avatar][data-photo-stale]')) {
            const root = avatar.closest('[data-customer-photo]');
            const controller = controllers.get(root);
            const expectedGeneration = controller?.generation;
            const url = avatar.dataset.photoMeta;
            if (!url) continue;
            if (!requests.has(url)) requests.set(url, fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(async response => response.ok ? response.json() : { photo: null }).catch(() => ({ photo: null })));
            const { photo } = await requests.get(url);
            if (!avatar.isConnected || generation !== refreshGeneration) continue;
            if (controller) {
                if (controllers.get(root) === controller) controller.refresh(photo, expectedGeneration);
            }
            else setAvatar(avatar, photo?.url);
        }
    };
    const init = () => {
        document.querySelectorAll('[data-customer-photo]').forEach(root => {
            if (!controllers.has(root)) {
                const controller = initializePhoto(root);
                if (controller) controllers.set(root, controller);
            }
        });
        refresh();
    };
    const dispose = () => {
        refreshGeneration++;
        controllers.forEach(controller => controller.dispose());
        controllers.clear();
        document.querySelectorAll('[data-customer-avatar]').forEach(avatar => {
            setAvatar(avatar, null);
            avatar.dataset.photoStale = 'true';
        });
    };
    document.addEventListener('error', event => {
        if (event.target.matches?.('[data-photo-image]')) setAvatar(event.target.closest('[data-customer-avatar]'), null);
    }, true);
    document.addEventListener('turbo:load', init);
    document.addEventListener('turbo:before-cache', dispose);
    document.addEventListener('turbo:before-render', dispose);
    window.addEventListener('pagehide', dispose);
    window.addEventListener('pageshow', event => { if (event.persisted) init(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}
