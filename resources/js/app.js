import './bootstrap';
import * as Turbo from '@hotwired/turbo';
import flatpickr from 'flatpickr';

import Alpine from 'alpinejs';

let toastTimer = null;

window.showToast = function(message, durationOrTone = 4000) {
    const toast = document.getElementById('global-toast');
    if (!toast) {
        console.warn('Cannot show toast: #global-toast element not found.');
        return;
    }

    const duration = typeof durationOrTone === 'number' ? durationOrTone : 4000;

    if (toastTimer) {
        window.clearTimeout(toastTimer);
    }

    toast.textContent = message;
    toast.classList.add('is-visible');
    toast.setAttribute('aria-hidden', 'false');

    toastTimer = window.setTimeout(() => {
        toast.classList.remove('is-visible');
        toast.setAttribute('aria-hidden', 'true');
        toast.textContent = '';
    }, duration);
}

const originalFetch = window.fetch;
window.fetch = function(input, init) {
    return originalFetch(input, init).then(response => {
        if (response.status === 403) {
            // Clone the response so we can read it here and the caller can still read it.
            response.clone().json().then(data => {
                if (data && data.message) {
                    window.showToast(data.message);
                }
            }).catch(() => {
                // The response might not be JSON, which is fine.
                console.log('A 403 response was received but it was not valid JSON.');
            });
        } else if (response.status === 419) {
            window.showToast("Session expired. Please refresh the page.");
        }
        
        // Return the original response to the caller.
        return response;
    });
};

window.Alpine = Alpine;
window.Turbo = Turbo;

Alpine.start();

function showFlashToasts() {
    const success = document.querySelector('meta[name="flash-success"]');
    if (success) {
        window.showToast(success.content);
        success.remove();
    }
    const error = document.querySelector('meta[name="flash-error"]');
    if (error) {
        window.showToast(error.content);
        error.remove();
    }
}

function initAjaxDeletes() {
    document.querySelectorAll('form[data-ajax-delete]').forEach(form => {
        if (form.dataset.ajaxDeleteBound === 'true') return;
        form.dataset.ajaxDeleteBound = 'true';

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const url = form.action;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const redirectUrl = form.dataset.deleteRedirect;

            fetch(url, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
            })
            .then(res => res.json().then(data => ({ ok: res.ok, data })))
            .then(({ ok, data }) => {
                if (!ok) {
                    window.showToast(data.message || 'Something went wrong.');
                    return;
                }
                window.showToast(data.message || 'Deleted successfully.');

                if (redirectUrl) {
                    Turbo.visit(redirectUrl);
                    return;
                }

                const row = form.closest('[data-deletable-row]') || form.closest('tr');
                if (row) {
                    row.style.transition = 'opacity 0.3s ease, max-height 0.3s ease';
                    row.style.opacity = '0';
                    row.style.overflow = 'hidden';
                    setTimeout(() => row.remove(), 300);
                }
            })
            .catch(() => {
                window.showToast('Network error. Please try again.');
            });
        });
    });
}

const SIDEBAR_SCROLL_KEY = 'jewelflow:sidebar-scroll-top';
let translationObserver = null;
const translationCache = new Map();
let confirmDialogEl = null;
let confirmDialogResolve = null;
let confirmDialogFocusReturn = null;

function normalizeLegacyPageHeaders() {
    document.querySelectorAll('.content-header').forEach((header) => {
        if (header.dataset.legacyHeaderNormalized === 'true') return;

        const directChildren = Array.from(header.children).filter((child) => !child.classList.contains('content-header-nav'));
        if (!directChildren.length) return;

        const firstBlock = directChildren[0];
        const title = firstBlock.querySelector('h1, h2, .page-title');
        const subtitle = firstBlock.querySelector('p, .page-subtitle');

        if (title && !title.classList.contains('page-title')) title.classList.add('page-title');
        if (subtitle && !subtitle.classList.contains('page-subtitle')) subtitle.classList.add('page-subtitle');

        const hasActions = header.querySelector(':scope > .page-actions');
        if (!hasActions) {
            const actionCandidate = directChildren.find((node, idx) => idx > 0 && node.matches('div') && node.querySelector('a, button, form'));
            if (actionCandidate) actionCandidate.classList.add('page-actions');
        }

        header.dataset.legacyHeaderNormalized = 'true';
    });
}

function normalizeButtonTypes() {
    document.querySelectorAll('button:not([type])').forEach((button) => {
        if (button.dataset.buttonTypeNormalized === 'true') return;

        const form = button.closest('form');
        if (!form) {
            button.type = 'button';
            button.dataset.buttonTypeNormalized = 'true';
            return;
        }

        const text = (button.textContent || '').trim().toLowerCase();
        const hasInlineHandler = button.hasAttribute('onclick') || button.hasAttribute('@click') || button.hasAttribute('x-on:click');
        const looksSubmitAction = /submit|save|apply|login|log in|logout|search|update|create|add|retry|verify|send|activate|deactivate|print/.test(text);

        button.type = hasInlineHandler ? 'button' : (looksSubmitAction ? 'submit' : 'button');
        button.dataset.buttonTypeNormalized = 'true';
    });
}

function upgradeInlineClickTargetsA11y() {
    const interactiveSelector = 'a, button, input, select, textarea, summary, label, [role], [tabindex]';

    document.querySelectorAll('[onclick]').forEach((element) => {
        if (element.matches(interactiveSelector)) return;

        if (!element.hasAttribute('role')) element.setAttribute('role', 'button');
        if (!element.hasAttribute('tabindex')) element.setAttribute('tabindex', '0');

        if (element.dataset.inlineClickKeybound === 'true') return;
        element.dataset.inlineClickKeybound = 'true';

        element.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            element.click();
        });
    });
}

function decodeHtmlEntities(value) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = value;
    return textarea.value;
}

function extractConfirmMessage(onsubmitAttr) {
    if (!onsubmitAttr) return 'Are you sure you want to continue?';
    const match = onsubmitAttr.match(/confirm\((['"])([\s\S]*?)\1\)/);
    if (!match) return 'Are you sure you want to continue?';
    return decodeHtmlEntities(match[2]);
}

function ensureConfirmDialog() {
    if (confirmDialogEl) return confirmDialogEl;

    confirmDialogEl = document.createElement('div');
    confirmDialogEl.className = 'fixed inset-0 z-[9999] hidden items-center justify-center px-4';
    confirmDialogEl.setAttribute('aria-hidden', 'true');
    confirmDialogEl.innerHTML = `
        <div class="absolute inset-0 bg-black/45" data-confirm-overlay="true"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="jf-confirm-title" tabindex="-1">
            <div class="p-5 border-b border-slate-100">
                <h3 id="jf-confirm-title" class="text-base font-semibold text-slate-900">Please confirm</h3>
                <p class="mt-2 text-sm text-slate-600" data-confirm-message="true"></p>
            </div>
            <div class="p-4 flex items-center justify-end gap-2">
                <button type="button" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 bg-white hover:bg-slate-50" data-confirm-cancel="true">Cancel</button>
                <button type="button" class="px-4 py-2 rounded-lg border border-amber-700 bg-amber-700 text-white hover:bg-amber-800" data-confirm-accept="true">Continue</button>
            </div>
        </div>
    `;

    const overlay = confirmDialogEl.querySelector('[data-confirm-overlay="true"]');
    const cancelButton = confirmDialogEl.querySelector('[data-confirm-cancel="true"]');
    const acceptButton = confirmDialogEl.querySelector('[data-confirm-accept="true"]');

    const close = (result) => {
        confirmDialogEl.classList.add('hidden');
        confirmDialogEl.classList.remove('flex');
        confirmDialogEl.setAttribute('aria-hidden', 'true');

        if (confirmDialogResolve) {
            const resolver = confirmDialogResolve;
            confirmDialogResolve = null;
            resolver(result);
        }

        if (confirmDialogFocusReturn && typeof confirmDialogFocusReturn.focus === 'function') {
            setTimeout(() => confirmDialogFocusReturn.focus(), 20);
        }
    };

    overlay.addEventListener('click', () => close(false));
    cancelButton.addEventListener('click', () => close(false));
    acceptButton.addEventListener('click', () => close(true));

    confirmDialogEl.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        close(false);
    });

    document.body.appendChild(confirmDialogEl);
    return confirmDialogEl;
}

function openConfirmDialog(message) {
    const dialog = ensureConfirmDialog();
    const messageEl = dialog.querySelector('[data-confirm-message="true"]');
    const cancelButton = dialog.querySelector('[data-confirm-cancel="true"]');

    confirmDialogFocusReturn = document.activeElement;
    messageEl.textContent = message || 'Are you sure you want to continue?';

    dialog.classList.remove('hidden');
    dialog.classList.add('flex');
    dialog.setAttribute('aria-hidden', 'false');

    setTimeout(() => cancelButton.focus(), 20);

    return new Promise((resolve) => {
        confirmDialogResolve = resolve;
    });
}

function initAccessibleFormConfirms() {
    document.querySelectorAll('form[onsubmit*="confirm("], form[data-confirm-message]').forEach((form) => {
        if (form.dataset.confirmInterceptBound === 'true') return;

        const onsubmitAttr = form.getAttribute('onsubmit') || '';
        const message = form.dataset.confirmMessage || extractConfirmMessage(onsubmitAttr);

        if (onsubmitAttr.includes('confirm(')) {
            form.removeAttribute('onsubmit');
        }
        form.dataset.confirmMessage = message;
        form.dataset.confirmInterceptBound = 'true';

        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmAllowSubmit === 'true') {
                form.dataset.confirmAllowSubmit = 'false';
                return;
            }

            event.preventDefault();
            const confirmed = await openConfirmDialog(form.dataset.confirmMessage || 'Are you sure you want to continue?');
            if (!confirmed) return;

            form.dataset.confirmAllowSubmit = 'true';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }
            form.submit();
        });
    });
}

function restoreSidebarScroll() {
    const nav = document.getElementById('sidebar-nav');
    if (!nav) return;
    const saved = sessionStorage.getItem(SIDEBAR_SCROLL_KEY);
    if (saved === null) return;
    const scrollTop = Number(saved);
    if (Number.isNaN(scrollTop)) return;
    nav.scrollTop = scrollTop;
}

function persistSidebarScroll() {
    const nav = document.getElementById('sidebar-nav');
    if (!nav) return;
    sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(nav.scrollTop));
}

function syncActiveNavLink() {
    const currentPath = window.location.pathname;
    const links = Array.from(
        document.querySelectorAll('.sidebar .nav-link[href]')
    );

    let bestMatch = null;
    let bestLength = 0;

    links.forEach(link => {
        link.classList.remove('active');

        let linkPath;
        try {
            linkPath = new URL(link.href).pathname;
        } catch (_) {
            return;
        }

        // Dashboard must be exact match only
        if (linkPath === '/dashboard') {
            if (currentPath === '/dashboard') {
                if (linkPath.length > bestLength) {
                    bestMatch = link;
                    bestLength = linkPath.length;
                }
            }
            return;
        }

        // All other links: current path must start with link path
        if (currentPath === linkPath || currentPath.startsWith(linkPath + '/')) {
            if (linkPath.length > bestLength) {
                bestMatch = link;
                bestLength = linkPath.length;
            }
        }
    });

    if (bestMatch) {
        bestMatch.classList.add('active');
    }
}

function closeMobileMenu() {
    setMobileDrawerState('tenant', false);
}

function toggleMobileMenu() {
    toggleMobileDrawer('tenant');
}

function getDrawerElements(key = 'tenant') {
    if (!key) return { drawer: null, overlay: null };

    return {
        drawer: document.querySelector(`[data-mobile-drawer="${key}"]`)
            || (key === 'tenant' ? document.querySelector('.sidebar') : null),
        overlay: document.querySelector(`[data-mobile-drawer-overlay="${key}"]`)
            || (key === 'tenant' ? document.querySelector('.sidebar-overlay') : null),
    };
}

function setMobileDrawerState(key = 'tenant', isOpen = false) {
    const { drawer, overlay } = getDrawerElements(key);
    if (!drawer) return;

    drawer.classList.toggle('mobile-open', isOpen);
    drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');

    if (overlay) {
        overlay.classList.toggle('active', isOpen);
        overlay.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    document.querySelectorAll(`[data-mobile-drawer-toggle="${key}"], [data-mobile-menu-toggle="${key}"]`).forEach((toggle) => {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen
            ? (key === 'admin' ? 'Close admin navigation' : 'Close navigation')
            : (key === 'admin' ? 'Open admin navigation' : 'Open navigation'));
    });

    if (window.innerWidth <= 1023) {
        const hasOpenDrawer = Array.from(document.querySelectorAll('[data-mobile-drawer]'))
            .some(element => element.classList.contains('mobile-open'));
        document.body.classList.toggle('drawer-open', hasOpenDrawer);
    } else {
        document.body.classList.remove('drawer-open');
    }
}

function toggleMobileDrawer(key = 'tenant') {
    const { drawer } = getDrawerElements(key);
    if (!drawer) return;
    setMobileDrawerState(key, !drawer.classList.contains('mobile-open'));
}

function closeAllMobileDrawers() {
    const keys = new Set(['tenant', 'admin']);
    document.querySelectorAll('[data-mobile-drawer]').forEach((drawer) => {
        if (drawer.dataset.mobileDrawer) {
            keys.add(drawer.dataset.mobileDrawer);
        }
    });

    keys.forEach((key) => setMobileDrawerState(key, false));
}

function normalizeLocale(rawLocale) {
    if (!rawLocale) return 'en';
    return rawLocale.toLowerCase().split('-')[0];
}

function shouldTranslateText(text) {
    if (!text) return false;
    return /[A-Za-z]/.test(text);
}

function applyTranslationMap(root, map) {
    if (!root || !map || Object.keys(map).length === 0) return;

    const translateExact = (value) => {
        if (typeof value !== 'string' || !value.trim()) return value;
        const trimmed = value.trim();
        const translated = map[trimmed];
        if (!translated || translated === trimmed) return value;
        return value.replace(trimmed, translated);
    };

    const translateNode = (node) => {
        if (!node) return;

        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.nodeValue;
            if (!shouldTranslateText(text)) return;
            const translated = translateExact(text);
            if (translated !== text) node.nodeValue = translated;
            return;
        }

        if (node.nodeType !== Node.ELEMENT_NODE) return;

        const tag = node.tagName;
        if (['SCRIPT','STYLE','NOSCRIPT','TEXTAREA','PRE','CODE'].includes(tag)) return;
        if (node.hasAttribute('data-no-auto-translate')) return;

        ['placeholder', 'title', 'aria-label'].forEach((attr) => {
            if (!node.hasAttribute(attr)) return;
            const value = node.getAttribute(attr);
            if (!shouldTranslateText(value)) return;
            const translated = translateExact(value);
            if (translated !== value) node.setAttribute(attr, translated);
        });

        if (node.hasAttribute('value') && (tag === 'INPUT' || tag === 'BUTTON')) {
            const inputType = (node.getAttribute('type') || '').toLowerCase();
            if (tag === 'BUTTON' || ['button','submit','reset'].includes(inputType)) {
                const value = node.getAttribute('value');
                if (shouldTranslateText(value)) {
                    const translated = translateExact(value);
                    if (translated !== value) node.setAttribute('value', translated);
                }
            }
        }

        for (const child of node.childNodes) translateNode(child);
    };

    translateNode(root);
}

async function runLocaleSweep() {
    const locale = normalizeLocale(document.documentElement?.lang);
    if (translationObserver) {
        translationObserver.disconnect();
        translationObserver = null;
    }
    if (locale === 'en') return;

    try {
        let translations = translationCache.get(locale);

        if (!translations) {
            const response = await fetch(`/translations/${encodeURIComponent(locale)}.json`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) return;
            translations = await response.json();
            translationCache.set(locale, translations ?? {});
        }

        if (!translations || Object.keys(translations).length === 0) return;

        applyTranslationMap(document.body, translations);

        translationObserver = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach((node) => applyTranslationMap(node, translations));
            }
        });

        translationObserver.observe(document.body, { childList: true, subtree: true });
    } catch (error) {
        console.error('Locale sweep failed:', error);
    }
}

// ─── Search auto-submit (debounced) ─────────────────────────────
// Attaches a 400 ms debounced auto-submit to every search/filter text
// input inside a GET form — unless the input has data-suggest (handled
// separately) or data-live-filter.
const _searchAutoSubmitInited = new WeakSet();

function initSearchAutoSubmit() {
    document.querySelectorAll('form input[name="search"], form input[name="q"]').forEach(input => {
        const form = input.closest('form');
        if (!form || form.method.toLowerCase() !== 'get') return;
        if (_searchAutoSubmitInited.has(input)) return;
        if (input.hasAttribute('data-suggest') || input.hasAttribute('data-live-filter')) return;
        _searchAutoSubmitInited.add(input);

        let timer = null;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(() => form.requestSubmit(), 400);
        });
    });
}

// ─── Client-side live table filter ──────────────────────────────
// Any <input data-live-filter="tbody-id"> instantly shows/hides <tr
// data-search="..."> rows whose data-search contains the typed query.
const _liveFilterInited = new WeakSet();

function initLiveFilter() {
    document.querySelectorAll('input[data-live-filter]').forEach(input => {
        if (_liveFilterInited.has(input)) return;
        const tbody = document.getElementById(input.dataset.liveFilter);
        if (!tbody) return;
        _liveFilterInited.add(input);

        const noMatchRow = tbody.querySelector('tr[data-no-match-row]');

        input.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let visibleCount = 0;

            tbody.querySelectorAll('tr[data-search]').forEach(row => {
                const match = q === '' || row.dataset.search.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });

            if (noMatchRow) noMatchRow.style.display = visibleCount === 0 && q !== '' ? '' : 'none';
        });
    });
}

// ─── Mobile table shells (fixed-height internal scroll) ─────────────────────
function initMobileTableScrollShells() {
    document.querySelectorAll('.overflow-x-auto, .ui-table-shell, .admin-table-wrap').forEach((shell) => {
        if (!shell.querySelector('table')) return;
        shell.classList.add('mobile-table-scroll-shell');
    });
}

function cleanupMobileHeaderActionFabs() {
    document.querySelectorAll('.jf-header-mobile-fab').forEach((fab) => fab.remove());
    document.querySelectorAll('.jf-header-fab-source').forEach((actions) => actions.classList.remove('jf-header-fab-source'));
    document.querySelectorAll('.jf-header-fab-pinned').forEach((action) => action.classList.remove('jf-header-fab-pinned'));
    document.querySelectorAll('.content-header.jf-header-auto-mobile').forEach((header) => header.classList.remove('jf-header-auto-mobile'));
}

function getHeaderActionLabel(sourceNode) {
    if (!sourceNode || typeof sourceNode.getAttribute !== 'function') {
        return '';
    }

    const nodeTag = sourceNode.tagName.toLowerCase();

    if (nodeTag === 'form') {
        const submitter = sourceNode.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
        const submitterText = (submitter?.textContent || submitter?.value || '').replace(/\s+/g, ' ').trim();
        if (submitterText) return submitterText;
    }

    const ariaLabel = sourceNode.getAttribute('aria-label')?.trim();
    if (ariaLabel) return ariaLabel;

    const title = sourceNode.getAttribute('title')?.trim();
    if (title) return title;

    const text = (sourceNode.textContent || '').replace(/\s+/g, ' ').trim();
    return text;
}

function applyHeaderFabActionContent(targetNode, sourceNode, labelOverride = null) {
    if (!targetNode) return;

    const normalizedSource = sourceNode && typeof sourceNode.querySelector === 'function' ? sourceNode : null;
    const icon = normalizedSource?.querySelector('svg, img, i');
    if (icon) {
        targetNode.appendChild(icon.cloneNode(true));
    }

    const label = (labelOverride || getHeaderActionLabel(normalizedSource) || 'Action').trim();
    const labelNode = document.createElement('span');
    labelNode.className = 'jf-header-mobile-fab-label';
    labelNode.textContent = label;
    targetNode.appendChild(labelNode);
}

function buildHeaderFabAction(sourceNode, closeFab) {
    if (!sourceNode || !sourceNode.tagName) return null;

    const sourceTag = sourceNode.tagName.toLowerCase();

    if (sourceTag === 'a') {
        const link = document.createElement('a');
        link.className = 'jf-header-mobile-fab-link';
        link.href = sourceNode.getAttribute('href') || '#';
        if (sourceNode.getAttribute('target')) link.setAttribute('target', sourceNode.getAttribute('target'));
        if (sourceNode.getAttribute('rel')) link.setAttribute('rel', sourceNode.getAttribute('rel'));
        applyHeaderFabActionContent(link, sourceNode);
        link.addEventListener('click', closeFab);

        const text = getHeaderActionLabel(sourceNode).toLowerCase();
        if (text.includes('delete') || sourceNode.className.toLowerCase().includes('danger')) {
            link.classList.add('is-danger');
        }
        return link;
    }

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'jf-header-mobile-fab-link';

    if (sourceTag === 'button') {
        applyHeaderFabActionContent(button, sourceNode);
        const text = getHeaderActionLabel(sourceNode).toLowerCase();
        if (text.includes('delete') || sourceNode.className.toLowerCase().includes('danger')) {
            button.classList.add('is-danger');
        }
        button.addEventListener('click', (event) => {
            event.preventDefault();
            closeFab();
            sourceNode.click();
        });
        return button;
    }

    if (sourceTag === 'form') {
        const submitter = sourceNode.querySelector('button[type="submit"], button:not([type]), input[type="submit"]');
        const text = (submitter?.textContent || submitter?.value || '').toLowerCase();
        applyHeaderFabActionContent(button, submitter || sourceNode, submitter?.value || submitter?.textContent || 'Submit');
        if (text.includes('delete') || submitter?.className?.toLowerCase().includes('danger')) {
            button.classList.add('is-danger');
        }

        button.addEventListener('click', (event) => {
            event.preventDefault();
            closeFab();
            if (submitter && submitter.tagName.toLowerCase() === 'button') {
                submitter.click();
                return;
            }
            if (typeof sourceNode.requestSubmit === 'function') {
                sourceNode.requestSubmit(submitter || undefined);
                return;
            }
            sourceNode.submit();
        });
        return button;
    }

    return null;
}

function initMobileHeaderActionFabs() {
    cleanupMobileHeaderActionFabs();

    const isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
    if (!isMobileViewport) return;

    let createdFabCount = 0;

    document.querySelectorAll('.content-header').forEach((header) => {
        const nonBaseClasses = Array.from(header.classList).filter((className) => className !== 'content-header');
        if (nonBaseClasses.length > 0) return;
        if (header.closest('.admin-shell')) return;

        const actions = header.querySelector(':scope > .page-actions');
        if (!actions) return;

        const hasFormControls = actions.querySelector('input:not([type="hidden"]), select, textarea');
        if (hasFormControls) return;

        header.classList.add('jf-header-auto-mobile');

        const actionNodes = Array.from(actions.children).filter((node) => {
            const tag = node.tagName.toLowerCase();
            return tag === 'a' || tag === 'button' || tag === 'form';
        });

        if (actionNodes.length <= 1) return;

        // Keep Back action in the mobile header; move remaining actions into FAB.
        const pinnedHeaderAction = actionNodes.find((node) => {
            const label = getHeaderActionLabel(node).toLowerCase();
            return label.startsWith('back') || label.includes('back to');
        });

        if (pinnedHeaderAction) {
            pinnedHeaderAction.classList.add('jf-header-fab-pinned');
        }

        const fabActionNodes = actionNodes.filter((node) => !node.classList.contains('jf-header-fab-pinned'));
        if (!fabActionNodes.length) return;

        actions.classList.add('jf-header-fab-source');

        const fab = document.createElement('div');
        fab.className = 'jf-header-mobile-fab';
        fab.innerHTML = `
            <div class="jf-header-mobile-fab-shell">
                <nav class="jf-header-mobile-fab-nav" aria-label="Header quick actions"></nav>
                <button type="button" class="jf-header-mobile-fab-toggle" aria-expanded="false" aria-label="Toggle header actions">
                    <span class="jf-header-mobile-fab-bars" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </button>
            </div>
        `;

        const shell = fab.querySelector('.jf-header-mobile-fab-shell');
        const nav = fab.querySelector('.jf-header-mobile-fab-nav');
        const toggle = fab.querySelector('.jf-header-mobile-fab-toggle');

        const closeFab = () => {
            shell.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        };

        fabActionNodes.forEach((node) => {
            const action = buildHeaderFabAction(node, closeFab);
            if (action) nav.appendChild(action);
        });

        if (!nav.children.length) return;

        toggle.addEventListener('click', () => {
            const nextState = !shell.classList.contains('is-open');
            shell.classList.toggle('is-open', nextState);
            toggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
        });

        if (createdFabCount > 0) {
            fab.style.display = 'none';
        }

        document.body.appendChild(fab);
        createdFabCount += 1;
    });
}

function initDashboardLoadingStates() {
    document.querySelectorAll('[data-dashboard-shell]').forEach((shell) => {
        if (!shell.classList.contains('dash-loading')) return;

        requestAnimationFrame(() => {
            window.setTimeout(() => {
                shell.classList.remove('dash-loading');
            }, 120);
        });
    });
}

function initSkeletonLoaders() {
    document.querySelectorAll('.jf-skeleton-host.is-loading').forEach((host) => {
        requestAnimationFrame(() => {
            window.setTimeout(() => {
                host.classList.remove('is-loading');
            }, 150);
        });
    });
}

// ─── Live search suggestions (Google-style) ─────────────────────
// Any <input data-suggest="customers"> fetches suggestions from
// /search/suggestions?type=customers&q=... and renders a dropdown.
// Works on any page. Inject once, styles included.
const _suggestInited = new WeakSet();
let _suggestStyleInjected = false;

function injectSuggestStyles() {
    if (_suggestStyleInjected) return;
    _suggestStyleInjected = true;
    const style = document.createElement('style');
    style.textContent = `
.jf-suggest{position:absolute;left:0;right:0;top:calc(100% + 4px);background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.12),0 4px 8px rgba(15,23,42,.06);z-index:500;max-height:320px;overflow-y:auto;overflow-x:hidden;display:none;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent}
.jf-suggest.open{display:block;animation:jf-suggest-in .15s ease-out}
@keyframes jf-suggest-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.jf-suggest-item{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;border-bottom:1px solid #f8fafc;transition:background .12s ease,padding-left .12s ease}
.jf-suggest-item:last-child{border-bottom:none}
.jf-suggest-item:hover,.jf-suggest-item.active{background:#fffbeb;padding-left:18px}
.jf-suggest-item .label{font-size:13px;font-weight:600;color:#0f172a;line-height:1.3}
.jf-suggest-item .sub{font-size:11px;color:#64748b;line-height:1.3}
.jf-suggest-item .arrow{margin-left:auto;color:#d4a017;font-size:14px;opacity:.6;transition:opacity .12s}
.jf-suggest-item:hover .arrow,.jf-suggest-item.active .arrow{opacity:1}
.jf-suggest-empty{padding:16px;text-align:center;font-size:13px;color:#94a3b8}
.jf-suggest-loading{padding:16px;text-align:center;font-size:13px;color:#94a3b8}
.jf-suggest mark{background:#fef3c7;color:#92400e;border-radius:2px;padding:0 2px;font-weight:600}
`;
    document.head.appendChild(style);
}

function escHtmlSuggest(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function highlightMatch(text, q) {
    if (!q) return escHtmlSuggest(text);
    const escaped = escHtmlSuggest(text);
    const qEsc = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return escaped.replace(new RegExp('(' + qEsc + ')', 'gi'), '<mark>$1</mark>');
}

function initSearchSuggestions() {
    injectSuggestStyles();

    document.querySelectorAll('input[data-suggest]').forEach(input => {
        if (_suggestInited.has(input)) return;
        _suggestInited.add(input);

        const type = input.dataset.suggest;
        // Find the best positioned ancestor — prefer a parent that already has
        // position:relative, or the outer filter-bar div, not an inner flex wrapper.
        let wrapper = input.parentElement;
        // Walk up to find a div that has min-width or flex-1 (filter field wrapper)
        // to avoid attaching to a tight inner flex container
        const filterField = input.closest('[class*="min-w-"], [class*="flex-1"]');
        if (filterField && filterField.contains(input)) {
            wrapper = filterField;
        }
        wrapper.style.position = 'relative';

        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'jf-suggest';
        wrapper.appendChild(dropdown);

        let timer = null;
        let activeIdx = -1;
        let items = [];
        let abortCtrl = null;

        function render(results, q) {
            items = results;
            activeIdx = -1;
            if (!results.length) {
                dropdown.innerHTML = '<div class="jf-suggest-empty">No results found</div>';
                dropdown.classList.add('open');
                return;
            }
            dropdown.innerHTML = results.map((r, i) =>
                '<div class="jf-suggest-item" data-idx="' + i + '">' +
                    '<div><div class="label">' + highlightMatch(r.label || '', q) + '</div>' +
                    (r.sub ? '<div class="sub">' + highlightMatch(r.sub, q) + '</div>' : '') +
                    '</div><span class="arrow">\u203A</span></div>'
            ).join('');
            dropdown.classList.add('open');
        }

        function close() {
            dropdown.classList.remove('open');
            activeIdx = -1;
        }

        function navigate(url) {
            close();
            if (window.Turbo) {
                Turbo.visit(url);
            } else {
                window.location.href = url;
            }
        }

        function setActive(idx) {
            dropdown.querySelectorAll('.jf-suggest-item').forEach(el => el.classList.remove('active'));
            if (idx >= 0 && idx < items.length) {
                activeIdx = idx;
                const el = dropdown.querySelector('[data-idx="' + idx + '"]');
                if (el) {
                    el.classList.add('active');
                    el.scrollIntoView({ block: 'nearest' });
                }
            }
        }

        input.addEventListener('input', function () {
            const q = this.value.trim();
            clearTimeout(timer);
            if (!q) { close(); return; }

            timer = setTimeout(() => {
                if (abortCtrl) abortCtrl.abort();
                abortCtrl = new AbortController();

                dropdown.innerHTML = '<div class="jf-suggest-loading">Searching\u2026</div>';
                dropdown.classList.add('open');

                fetch('/search/suggestions?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: abortCtrl.signal,
                })
                .then(r => r.json())
                .then(data => render(data, q))
                .catch(e => {
                    if (e.name !== 'AbortError') close();
                });
            }, 200);
        });

        input.addEventListener('keydown', function (e) {
            if (!dropdown.classList.contains('open') || !items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(activeIdx < items.length - 1 ? activeIdx + 1 : 0);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(activeIdx > 0 ? activeIdx - 1 : items.length - 1);
            } else if (e.key === 'Enter' && activeIdx >= 0) {
                e.preventDefault();
                navigate(items[activeIdx].url);
            } else if (e.key === 'Escape') {
                close();
            }
        });

        dropdown.addEventListener('mousedown', function (e) {
            e.preventDefault(); // keep focus on input
            const item = e.target.closest('.jf-suggest-item');
            if (!item) return;
            const idx = parseInt(item.dataset.idx);
            if (items[idx]) navigate(items[idx].url);
        });

        input.addEventListener('blur', () => setTimeout(close, 150));
        input.addEventListener('focus', function () {
            if (this.value.trim() && items.length) dropdown.classList.add('open');
        });
    });
}

// ─── Global custom date picker layer ───────────────────────────────────────
const DATE_PICKER_SELECTOR = 'input[type="date"]:not([data-native-date])';
let _datePickerObserver = null;

function styleDatePickerInput(nativeInput, instance) {
    const displayInput = instance?.altInput;
    if (!displayInput) return;

    displayInput.classList.add('jf-date-picker-input');
    displayInput.setAttribute('autocomplete', 'off');
    displayInput.readOnly = true;

    if (nativeInput.className) {
        nativeInput.className.split(/\s+/).filter(Boolean).forEach((className) => {
            displayInput.classList.add(className);
        });
    }

    if (nativeInput.getAttribute('aria-label')) {
        displayInput.setAttribute('aria-label', nativeInput.getAttribute('aria-label'));
    }

    displayInput.placeholder = nativeInput.placeholder || 'dd/mm/yyyy';
}

function dispatchDatePickerChange(nativeInput) {
    nativeInput.dispatchEvent(new Event('input', { bubbles: true }));
    nativeInput.dispatchEvent(new Event('change', { bubbles: true }));
}

function buildDatePickerOptions(nativeInput) {
    return {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        allowInput: false,
        disableMobile: true,
        prevArrow: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>',
        nextArrow: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>',
        onReady: (_selectedDates, _dateStr, instance) => {
            nativeInput.dataset.jfDatePicker = 'true';
            instance.calendarContainer.classList.add('jf-date-calendar');
            styleDatePickerInput(nativeInput, instance);
        },
        onOpen: (_selectedDates, _dateStr, instance) => {
            instance.calendarContainer.classList.add('jf-date-calendar');
            styleDatePickerInput(nativeInput, instance);
        },
        onValueUpdate: (_selectedDates, dateStr) => {
            nativeInput.value = dateStr || '';
        },
        onChange: (_selectedDates, dateStr) => {
            nativeInput.value = dateStr || '';
            dispatchDatePickerChange(nativeInput);
        },
    };
}

function initDatePickers(root = document) {
    const nodes = [];

    if (root instanceof Element && root.matches(DATE_PICKER_SELECTOR)) {
        nodes.push(root);
    }

    if (root.querySelectorAll) {
        nodes.push(...root.querySelectorAll(DATE_PICKER_SELECTOR));
    }

    nodes.forEach((nativeInput) => {
        if (!(nativeInput instanceof HTMLInputElement)) return;
        if (nativeInput.dataset.jfDatePicker === 'true' && nativeInput._flatpickr) return;
        if (nativeInput._flatpickr) return;

        flatpickr(nativeInput, buildDatePickerOptions(nativeInput));
    });
}

function destroyDatePickers(root = document) {
    const nodes = [];

    if (root instanceof Element && root.dataset.jfDatePicker === 'true') {
        nodes.push(root);
    }

    if (root.querySelectorAll) {
        nodes.push(...root.querySelectorAll('[data-jf-date-picker="true"]'));
    }

    nodes.forEach((nativeInput) => {
        if (!(nativeInput instanceof HTMLInputElement)) return;
        if (nativeInput._flatpickr) {
            nativeInput._flatpickr.destroy();
        }
        delete nativeInput.dataset.jfDatePicker;
    });
}

function ensureDatePickerObserver() {
    if (_datePickerObserver || !document.body) return;

    _datePickerObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                initDatePickers(node);
            });
        });
    });

    _datePickerObserver.observe(document.body, { childList: true, subtree: true });
}

window.initDatePickers = function (root = document) {
    initDatePickers(root);
};

// ─── Scoped custom selects for opt-in filter/forms ─────────────────────────
const _enhancedFilterSelectInited = new WeakSet();
let _enhancedFilterBindingsDone = false;

window.refreshEnhancedFilterSelect = function (nativeSelect) {
    if (!nativeSelect || typeof nativeSelect._refreshEnhancedFilterSelect !== 'function') return;
    nativeSelect._refreshEnhancedFilterSelect();
};

window.initEnhancedFilterSelects = function () {
    initEnhancedFilterSelects();
};

function closeAllEnhancedFilterSelects() {
    document.querySelectorAll('.ui-filter-select-menu.is-open').forEach((menu) => {
        menu.classList.remove('is-open');
    });
    document.querySelectorAll('.ui-filter-select-trigger.is-open').forEach((trigger) => {
        trigger.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
    });
}

function placeEnhancedFilterMenu(shell) {
    if (!shell) return;
    const trigger = shell.querySelector('.ui-filter-select-trigger');
    const menu = shell.querySelector('.ui-filter-select-menu');
    if (!trigger || !menu) return;

    shell.classList.remove('open-up');
    const triggerRect = trigger.getBoundingClientRect();
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    const roomBelow = viewportHeight - triggerRect.bottom;
    const roomAbove = triggerRect.top;
    const menuHeight = Math.min(menu.scrollHeight || 240, Math.floor(viewportHeight * 0.42));

    const useViewportPlacement = shell.closest('.inventory-item-create-dropdowns') && window.matchMedia('(min-width: 769px)').matches;
    if (useViewportPlacement) {
        const gap = 8;
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const spaceBelow = Math.max(120, roomBelow - gap - 12);
        const spaceAbove = Math.max(120, roomAbove - gap - 12);
        const opensUp = roomBelow < menuHeight + gap && roomAbove > roomBelow;
        const availableHeight = opensUp ? spaceAbove : spaceBelow;
        const fixedMenuHeight = Math.min(menu.scrollHeight || 280, availableHeight, Math.floor(viewportHeight * 0.56));

        shell.classList.toggle('open-up', opensUp);
        menu.style.position = 'fixed';
        menu.style.left = `${Math.max(12, Math.min(triggerRect.left, viewportWidth - triggerRect.width - 12))}px`;
        menu.style.right = 'auto';
        menu.style.width = `${triggerRect.width}px`;
        menu.style.maxHeight = `${fixedMenuHeight}px`;
        menu.style.top = opensUp
            ? `${Math.max(12, triggerRect.top - fixedMenuHeight - gap)}px`
            : `${Math.min(triggerRect.bottom + gap, viewportHeight - fixedMenuHeight - 12)}px`;
        menu.style.bottom = 'auto';
        return;
    }

    menu.style.position = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.width = '';
    menu.style.maxHeight = '';
    menu.style.top = '';
    menu.style.bottom = '';

    if (roomBelow < menuHeight + 14 && roomAbove > roomBelow) {
        shell.classList.add('open-up');
    }
}

function initEnhancedFilterSelects() {
    document.querySelectorAll('[data-enhance-selects]').forEach((root, rootIdx) => {
        const formVariant = root.dataset.enhanceSelectsVariant === 'compact' ? 'compact' : 'standard';
        const selectNodes = root.matches('select') ? [root] : Array.from(root.querySelectorAll('select'));

        selectNodes.forEach((nativeSelect, selectIdx) => {
            if (_enhancedFilterSelectInited.has(nativeSelect)) return;
            if (nativeSelect.closest('.ui-filter-select-host')) {
                _enhancedFilterSelectInited.add(nativeSelect);
                return;
            }
            if (nativeSelect.multiple) return;

            _enhancedFilterSelectInited.add(nativeSelect);
            nativeSelect.classList.add('ui-filter-native-select');

            const host = document.createElement('div');
            host.className = 'ui-filter-select-host';
            host.classList.add(formVariant === 'compact' ? 'ui-filter-select-host--compact' : 'ui-filter-select-host--standard');
            nativeSelect.parentNode.insertBefore(host, nativeSelect);
            host.appendChild(nativeSelect);

            const shell = document.createElement('div');
            shell.className = 'ui-filter-select';
            shell.classList.add(formVariant === 'compact' ? 'ui-filter-select--compact' : 'ui-filter-select--standard');

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'ui-filter-select-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');

            const menu = document.createElement('div');
            menu.className = 'ui-filter-select-menu';
            menu.setAttribute('role', 'listbox');

            const menuId = `${nativeSelect.name || 'filter'}-${rootIdx}-${selectIdx}-${Math.random().toString(36).slice(2, 8)}`;
            menu.id = menuId;
            trigger.setAttribute('aria-controls', menuId);

            const triggerText = document.createElement('span');
            triggerText.className = 'ui-filter-select-trigger-text';
            trigger.appendChild(triggerText);

            const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            chevron.setAttribute('viewBox', '0 0 24 24');
            chevron.setAttribute('fill', 'none');
            chevron.setAttribute('stroke', 'currentColor');
            chevron.setAttribute('stroke-width', '2');
            chevron.setAttribute('stroke-linecap', 'round');
            chevron.setAttribute('stroke-linejoin', 'round');
            chevron.classList.add('ui-filter-select-chevron');
            const chevronPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            chevronPath.setAttribute('d', 'M6 9l6 6 6-6');
            chevron.appendChild(chevronPath);
            trigger.appendChild(chevron);

            let options = [];

            const rebuildOptions = () => {
                menu.innerHTML = '';
                options = [];

                Array.from(nativeSelect.options).forEach((opt) => {
                    const optionEl = document.createElement('button');
                    optionEl.type = 'button';
                    optionEl.className = 'ui-filter-select-option';
                    optionEl.textContent = opt.textContent;
                    optionEl.dataset.value = opt.value;
                    optionEl.setAttribute('role', 'option');
                    optionEl.setAttribute('aria-selected', 'false');

                    optionEl.addEventListener('click', (event) => {
                        event.preventDefault();
                        nativeSelect.value = opt.value;
                        nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        closeAllEnhancedFilterSelects();
                        trigger.focus();
                    });

                    menu.appendChild(optionEl);
                    options.push(optionEl);
                });
            };

            const syncFromNative = () => {
                const selectedOption = nativeSelect.options[nativeSelect.selectedIndex] || nativeSelect.options[0] || null;
                trigger.disabled = nativeSelect.disabled;
                trigger.classList.toggle('is-disabled', nativeSelect.disabled);
                triggerText.textContent = selectedOption ? selectedOption.textContent : 'Select';
                options.forEach((optionEl) => {
                    const selected = optionEl.dataset.value === nativeSelect.value;
                    optionEl.classList.toggle('is-selected', selected);
                    optionEl.setAttribute('aria-selected', selected ? 'true' : 'false');
                });
            };

            nativeSelect._refreshEnhancedFilterSelect = () => {
                rebuildOptions();
                syncFromNative();
            };

            const openMenu = () => {
                closeAllEnhancedFilterSelects();
                menu.classList.add('is-open');
                trigger.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
                placeEnhancedFilterMenu(shell);
            };

            const closeMenu = () => {
                menu.classList.remove('is-open');
                trigger.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            };

            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                const shouldOpen = !menu.classList.contains('is-open');
                closeAllEnhancedFilterSelects();
                if (shouldOpen) openMenu();
                else closeMenu();
            });

            trigger.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    openMenu();
                    const selectedIdx = options.findIndex((optionEl) => optionEl.dataset.value === nativeSelect.value);
                    const focusIdx = selectedIdx >= 0 ? selectedIdx : 0;
                    options[focusIdx]?.focus();
                }
            });

            menu.addEventListener('keydown', (event) => {
                const currentIdx = options.findIndex((optionEl) => optionEl === document.activeElement);
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeMenu();
                    trigger.focus();
                    return;
                }
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    const nextIdx = currentIdx < 0 ? 0 : Math.min(currentIdx + 1, options.length - 1);
                    options[nextIdx]?.focus();
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    const prevIdx = currentIdx <= 0 ? 0 : currentIdx - 1;
                    options[prevIdx]?.focus();
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    document.activeElement?.click();
                }
            });

            rebuildOptions();
            nativeSelect.addEventListener('change', syncFromNative);
            syncFromNative();

            shell.appendChild(trigger);
            shell.appendChild(menu);
            host.appendChild(shell);
        });
    });

    if (_enhancedFilterBindingsDone) return;
    _enhancedFilterBindingsDone = true;

    document.addEventListener('click', (event) => {
        if (event.target.closest('.ui-filter-select')) return;
        closeAllEnhancedFilterSelects();
    });

    window.addEventListener('resize', closeAllEnhancedFilterSelects);
    window.addEventListener('scroll', (event) => {
        if (event.target instanceof Element && event.target.closest('.ui-filter-select-menu')) {
            return;
        }

        closeAllEnhancedFilterSelects();
    }, true);
}

// ─── Event Listeners ────────────────────────────────────────────

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-mobile-drawer-toggle], [data-mobile-drawer-overlay], [data-mobile-menu-toggle], [data-mobile-menu-overlay]');
    if (!trigger) return;
    event.preventDefault();
    const key = trigger.dataset.mobileDrawerToggle
        || trigger.dataset.mobileDrawerOverlay
        || trigger.dataset.mobileMenuToggle
        || trigger.dataset.mobileMenuOverlay
        || 'tenant';

    if (trigger.hasAttribute('data-mobile-drawer-overlay') || trigger.hasAttribute('data-mobile-menu-overlay')) {
        setMobileDrawerState(key, false);
        return;
    }

    toggleMobileDrawer(key);
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('.sidebar .nav-link[href]');
    if (!link) return;
    persistSidebarScroll();
    if (window.innerWidth <= 768) closeMobileMenu();
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('.admin-sidebar .admin-nav-link[href]');
    if (!link || window.innerWidth > 1023) return;
    setMobileDrawerState('admin', false);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeAllMobileDrawers();
    }
});

document.addEventListener('turbo:before-visit', persistSidebarScroll);

// Fix: clean up before Turbo snapshots the page
document.addEventListener('turbo:before-cache', () => {
    closeAllMobileDrawers();
    cleanupMobileHeaderActionFabs();
    closeAllEnhancedFilterSelects();
    destroyDatePickers();
    document.querySelectorAll('[data-dashboard-shell]').forEach((shell) => shell.classList.remove('dash-loading'));
    document.querySelectorAll('.jf-skeleton-host').forEach((host) => host.classList.remove('is-loading'));

    if (confirmDialogEl) {
        confirmDialogEl.classList.add('hidden');
        confirmDialogEl.classList.remove('flex');
        confirmDialogEl.setAttribute('aria-hidden', 'true');
        confirmDialogResolve = null;
    }

    // Stop translation observer so it doesn't interfere with Turbo's
    // own DOM manipulation during the snapshot process
    if (translationObserver) {
        translationObserver.disconnect();
        translationObserver = null;
    }

    // Re-stamp x-cloak on every Alpine root so the cached snapshot
    // has hidden elements. Without this, when the snapshot is shown
    // on back-navigation, Alpine-managed elements (modals, dropdowns)
    // briefly appear before Alpine re-initializes and hides them.
    document.querySelectorAll('[x-data]').forEach(el => {
        el.setAttribute('x-cloak', '');
    });

    document.querySelectorAll('[x-show]').forEach(el => {
        el.style.display = 'none';
    });
});

document.addEventListener('turbo:visit', () => {
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.style.pointerEvents = 'none';
        link.setAttribute('data-turbo', 'false');
    });
});

document.addEventListener('turbo:load', () => {
    normalizeLegacyPageHeaders();
    normalizeButtonTypes();
    upgradeInlineClickTargetsA11y();
    initAccessibleFormConfirms();
    restoreSidebarScroll();
    closeAllMobileDrawers();
    runLocaleSweep();
    syncActiveNavLink();
    initSearchAutoSubmit();
    initLiveFilter();
    initSearchSuggestions();
    initDatePickers();
    ensureDatePickerObserver();
    initEnhancedFilterSelects();
    initMobileTableScrollShells();
    initMobileHeaderActionFabs();
    initDashboardLoadingStates();
    initSkeletonLoaders();
    showFlashToasts();
    initAjaxDeletes();

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.style.pointerEvents = '';
        link.removeAttribute('data-turbo');
    });
});

document.addEventListener('turbo:frame-render', () => {
    showFlashToasts();
    initDatePickers();
});

// Watch for Turbo Stream toast messages
const toastTarget = document.getElementById('turbo-stream-toasts');
if (toastTarget) {
    new MutationObserver((mutations) => {
        mutations.forEach(m => {
            m.addedNodes.forEach(node => {
                if (node.nodeType === 1 && node.dataset.toastMessage) {
                    window.showToast(node.dataset.toastMessage);
                    node.remove();
                }
            });
        });
    }).observe(toastTarget, { childList: true });
}

if (document.readyState !== 'loading') {
    normalizeLegacyPageHeaders();
    normalizeButtonTypes();
    upgradeInlineClickTargetsA11y();
    initAccessibleFormConfirms();
    restoreSidebarScroll();
    closeAllMobileDrawers();
    runLocaleSweep();
    syncActiveNavLink();
    initSearchAutoSubmit();
    initLiveFilter();
    initSearchSuggestions();
    initDatePickers();
    ensureDatePickerObserver();
    initEnhancedFilterSelects();
    initMobileTableScrollShells();
    initMobileHeaderActionFabs();
    initDashboardLoadingStates();
    initSkeletonLoaders();
    showFlashToasts();
    initAjaxDeletes();
} else {
    document.addEventListener('DOMContentLoaded', () => {
        normalizeLegacyPageHeaders();
        normalizeButtonTypes();
        upgradeInlineClickTargetsA11y();
        initAccessibleFormConfirms();
        restoreSidebarScroll();
        closeAllMobileDrawers();
        runLocaleSweep();
        syncActiveNavLink();
        initSearchAutoSubmit();
        initLiveFilter();
        initSearchSuggestions();
        initDatePickers();
        ensureDatePickerObserver();
        initEnhancedFilterSelects();
        initMobileTableScrollShells();
        initMobileHeaderActionFabs();
        initDashboardLoadingStates();
        showFlashToasts();
        initAjaxDeletes();
    });
}

let headerFabResizeTimer = null;
window.addEventListener('resize', () => {
    window.clearTimeout(headerFabResizeTimer);
    headerFabResizeTimer = window.setTimeout(() => {
        initMobileHeaderActionFabs();
    }, 180);
});
