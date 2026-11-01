const initialized = new WeakSet();

// Enhancement only: without enough room (or JavaScript), retain ordinary page scrolling.
export function initializeListWorkspaces(doc = document, win = window) {
    if (initialized.has(doc) || !win.ResizeObserver || !win.MutationObserver) return;
    initialized.add(doc);
    let controllers = [];

    const mount = () => {
        for (const controller of controllers) controller.dispose();
        controllers = [...doc.querySelectorAll('[data-list-workspace]')].map(root => {
            let frame = 0;
            let active = true;
            const scrolls = [...root.querySelectorAll('[data-list-scroll]')];
            const reset = () => {
                root.removeAttribute('data-list-fit');
                root.style.removeProperty('--jf-list-available');
            };
            const measure = () => {
                frame = 0;
                if (!active || !root.isConnected) return;
                const visible = scrolls.filter(el => el.getClientRects().length);
                if (win.innerWidth < 1024 || win.innerHeight < 800 || visible.length !== 1) return reset();

                let shell = root.parentElement;
                while (shell && shell !== doc.body && !/auto|scroll/.test(win.getComputedStyle(shell).overflowY)) shell = shell.parentElement;
                if (shell === doc.body) shell = null;
                const bottom = shell ? Math.min(win.innerHeight, shell.getBoundingClientRect().bottom) : win.innerHeight;
                const top = root.getBoundingClientRect().top + (shell ? shell.scrollTop : win.scrollY);
                const padding = Number.parseFloat(win.getComputedStyle(root.parentElement).paddingBottom) || 0;
                const available = Math.floor(bottom - top - padding);
                if (available <= 0) return reset();

                root.style.setProperty('--jf-list-available', `${available}px`);
                root.setAttribute('data-list-fit', '');
                const head = visible[0].querySelector('thead');
                if (head) visible[0].style.setProperty('--jf-list-head-size', `${head.getBoundingClientRect().height}px`);
                // Enlarged text, wrapped filters or banners must never squeeze rows out of reach.
                if (root.scrollHeight > root.clientHeight + 1) reset();
            };
            const schedule = () => { if (active && !frame) frame = win.requestAnimationFrame(measure); };
            const resize = new win.ResizeObserver(schedule);
            // Headers and banners outside the workspace can wrap independently of it.
            const main = root.closest('main');
            for (let branch = root; branch.parentElement && branch !== main; branch = branch.parentElement) {
                for (const sibling of branch.parentElement.children) {
                    if (sibling !== branch) resize.observe(sibling);
                }
            }
            const containers = [root, ...root.querySelectorAll('[data-list-panel], [data-list-frame]')];
            for (const container of containers) {
                for (const child of container.children) {
                    if (!child.matches('[data-list-panel], [data-list-frame], [data-list-scroll]') && !scrolls.some(s => child.contains(s))) resize.observe(child);
                }
            }
            const mutation = new win.MutationObserver(records => {
                if (records.some(r => r.target !== root && !scrolls.some(s => s.contains(r.target)))) schedule();
            });
            mutation.observe(root, { subtree: true, attributes: true, attributeFilter: ['style', 'hidden'] });
            doc.fonts?.ready.then(schedule);
            schedule();
            return {
                schedule,
                dispose() {
                    active = false;
                    win.cancelAnimationFrame(frame);
                    resize.disconnect(); mutation.disconnect(); reset();
                    for (const scroll of scrolls) scroll.style.removeProperty('--jf-list-head-size');
                },
            };
        });
    };
    const clear = () => { for (const controller of controllers) controller.dispose(); controllers = []; };
    const resize = () => { for (const controller of controllers) controller.schedule(); };
    win.addEventListener('resize', resize);
    win.visualViewport?.addEventListener('resize', resize);
    doc.addEventListener('turbo:load', mount);
    doc.addEventListener('turbo:before-cache', clear);
    doc.addEventListener('turbo:before-render', clear);
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
}
