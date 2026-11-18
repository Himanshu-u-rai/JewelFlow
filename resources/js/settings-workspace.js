const boundLogos = new WeakSet();
const boundDocuments = new WeakSet();
const shownValidation = new WeakSet();

export function placeSettingsToast(doc, toast) {
    if (!doc.querySelector('.settings-shell')) return false;
    doc.documentElement.style.removeProperty('--toast-space');
    const header = doc.querySelector('.settings-page-header');
    const top = Math.max(header?.getBoundingClientRect().bottom ?? 0, 0) + 8;
    toast.style.setProperty('--toast-top', `${top}px`);
    return true;
}

export function syncSettingsNavigation(doc, win) {
    const tab = doc.querySelector('#settings-content [data-settings-tab]')?.dataset.settingsTab;
    if (!tab) return;
    let active;
    doc.querySelectorAll('.settings-nav a[data-turbo-frame]').forEach(link => {
        const selected = new URL(link.href, win.location.href).searchParams.get('tab') === tab;
        link.classList.toggle('active', selected);
        if (selected) {
            link.setAttribute('aria-current', 'page');
            active = link;
        } else {
            link.removeAttribute('aria-current');
        }
    });
    if (active && win.matchMedia('(max-width: 900px)').matches) {
        active.scrollIntoView({ block: 'nearest', inline: 'center' });
    }
}

export function bindSettingsLogo(root = globalThis.document) {
    const dropzone = root.getElementById('shop-logo-dropzone');
    const browseBtn = root.getElementById('shop-logo-browse');
    const input = root.getElementById('shop-logo-input');
    const preview = root.getElementById('shop-logo-preview');
    const placeholder = root.getElementById('shop-logo-placeholder');
    const stateText = root.getElementById('shop-logo-state-text');
    const fileName = root.getElementById('shop-logo-file-name');
    const removeInput = root.getElementById('shop-logo-remove');
    const deleteBtn = root.getElementById('shop-logo-delete-btn');
    const deleteLabel = root.getElementById('shop-logo-delete-label');
    const deleteNote = root.getElementById('shop-logo-delete-note');
    const maxFileBytes = 2 * 1024 * 1024;

    if (!dropzone || !browseBtn || !input || !preview || !placeholder || !stateText || !fileName) {
        return;
    }

    if (boundLogos.has(dropzone)) return;
    boundLogos.add(dropzone);

    const initialPreviewSrc = (preview.getAttribute('src') || '').trim();
    const initialFileName = (fileName.textContent || '').trim();
    const initialDeletePending = removeInput ? removeInput.value === '1' : false;

    const showPlaceholder = (status = 'Not uploaded') => {
        preview.style.display = 'none';
        placeholder.style.display = 'flex';
        stateText.textContent = status;
    };

    const showPreview = (src, status = 'Ready to upload') => {
        preview.src = src;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
        stateText.textContent = status;
    };

    const setDeleteIntent = (enabled) => {
        if (!removeInput) {
            return;
        }

        removeInput.value = enabled ? '1' : '0';

        if (deleteBtn) {
            deleteBtn.classList.toggle('is-pending', enabled);
            deleteBtn.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        }

        if (deleteLabel) {
            deleteLabel.textContent = enabled ? 'Undo' : 'Delete';
        }

        if (deleteNote) {
            deleteNote.style.display = enabled ? 'block' : 'none';
        }
    };

    const openPicker = () => {
        input.click();
    };

    const applyFile = (file) => {
        if (!file) {
            return;
        }

        if (!String(file.type || '').startsWith('image/')) {
            input.value = '';
            fileName.textContent = '';
            showPlaceholder('Invalid format');
            return;
        }

        if (file.size > maxFileBytes) {
            input.value = '';
            fileName.textContent = '';
            showPlaceholder('File too large (max 2 MB)');
            return;
        }

        fileName.textContent = file.name;
        setDeleteIntent(false);

        const reader = new FileReader();
        reader.onload = (e) => showPreview(e.target?.result || '');
        reader.readAsDataURL(file);
    };

    const syncInputWithFile = (file) => {
        try {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        } catch (_) {
            // If assignment is blocked, preview still updates but upload falls back to manual pick.
        }
    };

    dropzone.addEventListener('click', (event) => {
        if (event.target === input || event.target.closest('[data-skip-dropzone-click="true"]') || event.target.closest('.logo-remove')) {
            return;
        }
        openPicker();
    });

    browseBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openPicker();
    });

    dropzone.addEventListener('keydown', (event) => {
        if (event.target === dropzone && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openPicker();
        }
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.add('is-dragover');
        });
    });

    ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        const file = event.dataTransfer?.files?.[0];
        if (!file) {
            return;
        }

        syncInputWithFile(file);
        applyFile(file);
    });

    input.addEventListener('change', (event) => {
        const file = event.target.files && event.target.files[0];
        if (!file) {
            fileName.textContent = '';
            if (removeInput && removeInput.value === '1') {
                showPlaceholder('Will be deleted after save');
                return;
            }
            if (initialPreviewSrc) {
                showPreview(initialPreviewSrc, 'Uploaded');
                fileName.textContent = initialFileName;
            } else {
                showPlaceholder();
            }
            return;
        }

        applyFile(file);
    });

    if (deleteBtn && removeInput) {
        deleteBtn.addEventListener('click', (event) => {
            event.preventDefault();

            const willDelete = removeInput.value !== '1';
            setDeleteIntent(willDelete);

            if (willDelete) {
                input.value = '';
                fileName.textContent = '';
                showPlaceholder('Will be deleted after save');
                return;
            }

            if (input.files && input.files[0]) {
                applyFile(input.files[0]);
                return;
            }

            if (initialPreviewSrc) {
                showPreview(initialPreviewSrc, 'Uploaded');
                fileName.textContent = initialFileName;
            } else {
                showPlaceholder();
            }
        });
    }

    if (initialDeletePending) {
        showPlaceholder('Will be deleted after save');
    }
}

export function initializeSettingsWorkspace(doc = globalThis.document, win = globalThis.window) {
    if (!doc || !win || boundDocuments.has(doc)) return;
    boundDocuments.add(doc);
    const mount = () => {
        if (!doc.querySelector('.settings-shell')) return;
        bindSettingsLogo(doc);
        syncSettingsNavigation(doc, win);
        const validation = doc.querySelector('[data-settings-validation]');
        if (validation?.dataset.message && !shownValidation.has(validation)) {
            shownValidation.add(validation);
            // The app's shared toast is assigned after initial module setup.
            queueMicrotask(() => win.showToast?.(validation.dataset.message, 'error', 8000));
        }
    };
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
    doc.addEventListener('turbo:load', mount);
    doc.addEventListener('turbo:render', mount);
    doc.addEventListener('turbo:frame-load', event => {
        if (event.target?.id === 'settings-content') mount();
    });
    win.addEventListener('resize', mount);
}
