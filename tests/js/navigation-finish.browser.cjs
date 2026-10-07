/**
 * Focused FRONTEND fixtures: real built CSS/app.js/Turbo and Categories inline
 * JS, synthetic HTML/responses on loopback. NOT Laravel, DB, auth or route tests.
 * Requires npm run build and an externally installed Playwright browser tool.
 * Run: node tests/js/navigation-finish.browser.cjs
 * Optional: JF_BROWSER_EXECUTABLE, JF_BROWSER_ARGS (JSON), JF_UI_SCREENSHOTS.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const build = path.join(root, 'public/build');
const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json')));
const categoryTemplate = fs.readFileSync(path.join(root, 'resources/views/categories/index.blade.php'), 'utf8');
const css = '/build/' + manifest['resources/css/app.css'].file;
const js = '/build/' + manifest['resources/js/app.js'].file;
let rejected = false;

function categoryScript(invalid = false) {
    const match = categoryTemplate.match(/<script>([\s\S]*?)<\/script>/);
    assert(match, 'Categories script missing');
    const script = match[1]
        .replace(/@js\(url\('categories'\)\)/g, '"/categories"')
        .replace(/@js\(url\('sub-categories'\)\)/g, '"/sub-categories"')
        .replace(/@php \$intent = old\('_intent'\); @endphp/, '')
        .replace(/@if\(\$intent === 'add_category'\)[\s\S]*?@endif/, invalid ? 'openAddCategoryModal();' : '');
    assert(!/@(?:php|if|js|endif)|\{\{/.test(script), 'Fixture substitutions no longer match Categories script');
    return script;
}

function header(invoices) {
    return `<div class="content-header ${invoices ? 'invoices-page-header' : 'categories-page-header'}">
      <div class="content-header-nav"><button class="mobile-menu-btn" aria-label="Menu">☰</button></div>
      <div><h1 class="page-title">${invoices ? 'Invoices' : 'Categories'}</h1></div>
      <div class="page-actions">${invoices
        ? '<a href="/other" class="btn btn-primary invoices-open-pos-btn">Open POS</a>'
        : '<button type="button" class="categories-add-btn btn" onclick="openAddCategoryModal()">Add Category</button>'}</div></div>`;
}

function categories(invalid) {
    const cards = Array.from({ length: 20 }, (_, i) => `<div class="categories-card is-collapsed rounded-2xl border bg-white" data-category-id="${i + 1}">
      <div class="categories-card-head flex items-center justify-between px-5 py-4" data-category-toggle-surface>
      <h3>Band ${String(i + 1).padStart(2, '0')}</h3><div class="categories-card-actions">
      <button class="categories-icon-btn categories-mobile-toggle" data-category-toggle aria-expanded="false">⌄</button></div></div>
      <div class="categories-sub-list p-4"><p>Plain gold — synthetic fixture</p></div></div>`).join('');
    return `${header(false)}<div class="content-inner categories-index-page">
      <a id="leave" href="/other">Leave Categories</a>
      <form method="GET" action="/categories"><input name="q" value="Band" aria-label="Search"><button>Search</button></form>
      <div class="categories-grid grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">${cards}</div></div>
      <div id="addCategoryModal" class="categories-modal fixed inset-0 z-50 hidden">
      <div class="categories-modal-overlay absolute inset-0 bg-slate-950/55" onclick="closeAddCategoryModal()"></div>
      <div class="categories-modal-panel absolute left-1/2 top-1/2 w-full max-w-md -translate-x-1/2 -translate-y-1/2 px-4">
      <div class="categories-modal-card bg-white"><div class="categories-modal-head px-6 py-4"><h3>Add Category</h3></div>
      <form method="POST" action="/categories" class="categories-modal-body p-6"><input name="_intent" value="add_category" type="hidden">
      <label for="name">Name</label><input id="name" name="name" class="categories-input" value="${invalid ? 'Duplicate' : ''}">
      ${invalid ? '<p data-field-error class="text-red-600">Already exists</p>' : ''}
      <div class="categories-modal-actions flex justify-end gap-3"><button type="button" onclick="closeAddCategoryModal()">Cancel</button><button type="submit">Save</button></div>
      </form></div></div></div><script>${categoryScript(invalid)}</script>`;
}

function html(url) {
    const invalid = rejected;
    rejected = false;
    const isCategory = url.pathname === '/categories';
    const isAdmin = url.pathname === '/admin-fixture';
    const inner = isCategory ? categories(invalid) : `${header(true)}<div class="content-inner invoices-index-page">
      <nav class="invoices-page-nav" aria-label="Invoices navigation"><a class="invoices-page-nav-link" href="/other">Invoices</a>
      <a class="invoices-page-nav-link" href="/other">Historical Sales</a><a class="invoices-page-nav-link" href="/other">Returns / Exchange</a></nav>
      <a href="/categories?q=Band&page=2">Categories</a><p>Synthetic frontend fixture — not a Laravel response.</p></div>`;
    return `<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="csrf-token" content="synthetic-only"><title>Frontend component check</title>
      <link rel="stylesheet" href="${css}"><script type="module" src="${js}"></script></head>
      <body class="app-shell" data-fixture-page="${url.pathname}"><div id="global-toast" class="global-toast" role="status" aria-live="polite" aria-atomic="true" aria-hidden="true"></div>
      <div style="display:flex;height:100%"><main ${isAdmin ? '' : 'id="main-content"'} class="content-area"><div class="content-body">${inner}</div></main></div></body></html>`;
}

const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://127.0.0.1');
    if (url.pathname.startsWith('/build/')) {
        const filename = path.resolve(build, url.pathname.slice('/build/'.length));
        if (!filename.startsWith(build + path.sep) || !fs.existsSync(filename)) { res.writeHead(404).end(); return; }
        res.setHeader('Content-Type', filename.endsWith('.css') ? 'text/css' : 'application/javascript');
        res.end(fs.readFileSync(filename)); return;
    }
    if (req.method === 'POST') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            rejected = new URLSearchParams(body).get('name') === 'Duplicate';
            // Deliberately synthetic response contract. PHP redirects are tested by PHPUnit.
            const from = new URL(req.headers.referer || '/categories', 'http://127.0.0.1');
            res.writeHead(303, { Location: '/categories' + from.search }).end();
        }); return;
    }
    res.setHeader('Content-Type', 'text/html');
    res.end(html(url));
});

(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless: true,
        ...(process.env.JF_BROWSER_EXECUTABLE ? { executablePath: process.env.JF_BROWSER_EXECUTABLE } : {}),
        args: JSON.parse(process.env.JF_BROWSER_ARGS || '[]'),
    });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    let passed = 0, failed = 0;
    const errors = [];
    async function check(name, fn) {
        const page = await context.newPage();
        page.setDefaultTimeout(6000);
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', route => route.request().url().startsWith(origin) ? route.continue() : route.abort());
        try { await fn(page); passed++; console.log('PASS', name); }
        catch (e) { failed++; console.error('FAIL', name, '-', e.message); }
        finally { await page.close(); }
    }
    async function open(page, path = '/other') {
        await page.goto(origin + path);
        await page.waitForFunction(() => window.showToast && window.Turbo);
        await page.waitForTimeout(80);
    }
    async function busy(page) {
        await page.evaluate(() => {
            const overlay = document.createElement('div');
            overlay.id = 'busy';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:white;display:flex;flex-direction:column;justify-content:space-between;padding:0 12px';
            overlay.innerHTML = Array.from({ length: 24 }, (_, i) => `<button style="width:100%;height:30px;flex-shrink:0">Control ${i}</button>`).join('');
            document.body.append(overlay);
        });
    }

    try {
        await check('toast pauses expiry while obstructed, then reappears', async page => {
            await open(page);
            await page.evaluate(() => showToast('Save refused', 1500));
            await page.waitForTimeout(250);
            await busy(page);
            await page.evaluate(() => document.body.dispatchEvent(new MouseEvent('click', { bubbles: true })));
            await page.waitForTimeout(1800);
            assert.equal(await page.locator('#global-toast').textContent(), 'Save refused');
            assert.equal(await page.locator('#global-toast').isVisible(), false);
            await page.evaluate(() => { document.getElementById('busy').remove(); window.dispatchEvent(new Event('resize')); });
            await page.waitForTimeout(200);
            assert(await page.locator('#global-toast').isVisible());
        });
        await check('no-main-content layout never forces toast over a full panel', async page => {
            await open(page, '/admin-fixture'); await busy(page);
            await page.evaluate(() => showToast('No free space; keep this message until controls are clear.'));
            await page.waitForTimeout(650);
            assert.equal(await page.locator('#global-toast').isVisible(), false);
        });
        await check('Categories cards still toggle after actual Turbo Back', async page => {
            await open(page, '/categories?q=Band&page=2');
            const toggle = page.locator('[data-category-toggle]').first();
            await toggle.click(); assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
            await page.locator('#leave').click(); await page.waitForURL(origin + '/other');
            await page.goBack(); await page.waitForURL(/\/categories\?/); await page.waitForTimeout(150);
            const before = await toggle.getAttribute('aria-expanded');
            await toggle.click();
            assert.notEqual(await toggle.getAttribute('aria-expanded'), before, 'Cached binding marker prevented restored card from rebinding');
        });
        for (const invalid of [false, true]) await check(`Categories keeps position after ${invalid ? 'rejected' : 'successful'} synthetic POST`, async page => {
            await open(page, '/categories?q=Band&page=2');
            await page.locator('[data-category-toggle]').nth(3).click();
            await page.evaluate(() => { document.querySelector('.content-body').scrollTop = 300; openAddCategoryModal(); });
            await page.locator('#name').fill(invalid ? 'Duplicate' : 'New category');
            const before = await page.locator('.content-body').evaluate(el => el.scrollTop);
            await page.locator('#addCategoryModal button[type=submit]').click();
            await page.waitForTimeout(700);
            assert.equal(new URL(page.url()).search, '?q=Band&page=2');
            assert(Math.abs(await page.locator('.content-body').evaluate(el => el.scrollTop) - before) <= 2);
            assert.equal(await page.locator('[data-category-toggle]').nth(3).getAttribute('aria-expanded'), 'true');
            assert.equal(await page.locator('#addCategoryModal').isVisible(), invalid);
            if (invalid) assert(await page.locator('[data-field-error]').isVisible());
            else assert.equal(await page.locator('#name').inputValue(), '');
        });
        for (const width of [320, 390, 430, 768, 820, 1440]) await check(`Invoices touch target / toast clearance at ${width}px`, async page => {
            await page.setViewportSize({ width, height: 844 }); await open(page);
            const box = await page.locator('.invoices-open-pos-btn').boundingBox();
            assert(box);
            if (width <= 768) {
                for (const y of [box.y - 4, box.y + box.height + 4]) {
                    assert(await page.evaluate(({ x, y }) => !!document.elementFromPoint(x, y)?.closest('.invoices-open-pos-btn'), { x: box.x + box.width / 2, y }), 'Extended hit area not clickable');
                }
            }
            await page.evaluate(() => showToast('Saved successfully.'));
            await page.waitForTimeout(650);
            const overlap = await page.evaluate(() => {
                const toast = document.getElementById('global-toast').getBoundingClientRect();
                return [...document.querySelectorAll('button,a,input')].some(el => {
                    const b = el.getBoundingClientRect();
                    return b.width && b.height && toast.left < b.right && toast.right > b.left && toast.top < b.bottom && toast.bottom > b.top;
                });
            });
            assert.equal(overlap, false);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            if (process.env.JF_UI_SCREENSHOTS && [320, 390].includes(width)) {
                fs.mkdirSync(process.env.JF_UI_SCREENSHOTS, { recursive: true });
                await page.screenshot({ path: path.join(process.env.JF_UI_SCREENSHOTS, `frontend-fixture-${width}.png`) });
            }
        });
        for (const width of [320, 390, 430]) await check(`Category modal controls stay usable under a long toast at ${width}px`, async page => {
            await page.setViewportSize({ width, height: 640 });
            await open(page, '/categories?q=Band&page=2');
            await page.locator('.categories-add-btn').click();
            await page.evaluate(() => showToast('The category could not be saved. Please check the name and try again. '.repeat(3)));
            await page.waitForTimeout(650);
            const overlaps = await page.evaluate(() => {
                const toast = document.getElementById('global-toast');
                if (getComputedStyle(toast).visibility === 'hidden') return false;
                const a = toast.getBoundingClientRect();
                return [...document.querySelectorAll('#addCategoryModal input:not([type=hidden]), #addCategoryModal button')].some(el => {
                    const b = el.getBoundingClientRect();
                    return a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
                });
            });
            assert.equal(overlaps, false, 'Long toast covers a modal control');
            await page.getByRole('button', { name: 'Cancel', exact: true }).click();
            assert.equal(await page.locator('#addCategoryModal').isVisible(), false);
            await page.waitForTimeout(650);
            assert(await page.locator('#global-toast').isVisible(), 'Message did not show once the modal closed');
        });
        await check('real Turbo Back does not resurrect a toast', async page => {
            await open(page, '/categories?q=Band&page=2');
            await page.evaluate(() => showToast('Temporary message', 10000));
            await page.locator('#leave').click();
            await page.waitForURL(origin + '/other');
            await page.goBack();
            await page.waitForURL(/\/categories\?/);
            await page.waitForTimeout(200);
            assert.equal(await page.locator('#global-toast').textContent(), '');
            assert.equal(await page.locator('#global-toast').isVisible(), false);
            assert.equal(await page.evaluate(() => document.documentElement.style.getPropertyValue('--toast-space')), '');
        });
        await check('a new search starts at the top without restoring old expanded cards', async page => {
            await open(page, '/categories?q=Band&page=2');
            await page.locator('[data-category-toggle]').nth(3).click();
            await page.evaluate(() => { document.querySelector('.content-body').scrollTop = 300; });
            await page.getByRole('textbox', { name: 'Search' }).fill('Chain');
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await page.waitForURL(/q=Chain/);
            await page.waitForTimeout(200);
            assert.equal(new URL(page.url()).searchParams.has('page'), false);
            assert.equal(await page.locator('.content-body').evaluate(el => el.scrollTop), 0);
            assert.equal(await page.locator('[data-category-toggle]').nth(3).getAttribute('aria-expanded'), 'false');
        });
        await check('Open POS responds to its extended hit area and keyboard', async page => {
          for (const cached of [true, false]) {
            await open(page, '/invoices');
            const button = page.locator('.invoices-open-pos-btn');
            const box = await button.boundingBox();
            await page.mouse.click(box.x + box.width / 2, box.y - 4);
            await page.waitForURL(origin + '/other');
            // Force an uncached history restoration: URL changes before Turbo
            // finishes replacing the prior page's DOM.
            if (!cached) {
                await page.evaluate(() => Turbo.cache.clear());
                await page.route(origin + '/invoices', async route => {
                    await new Promise(resolve => setTimeout(resolve, 200));
                    await route.continue();
                });
            }
            await page.evaluate(() => {
                window.posHistoryReady = false;
                document.addEventListener('turbo:load', () => {
                    window.posHistoryReady = location.pathname === '/invoices';
                }, { once: true });
            });
            await page.goBack(); await page.waitForURL(origin + '/invoices');
            await page.waitForFunction(() => window.posHistoryReady);
            const restoration = await page.evaluate(() => ({
                url: location.pathname, rendered: document.body.dataset.fixturePage,
                preview: document.documentElement.hasAttribute('data-turbo-preview'),
            }));
            assert.equal(restoration.rendered, '/invoices', 'URL changed before invoices DOM restoration');
            assert.equal(restoration.preview, false, 'Keyboard action requires the final restored DOM');
            await button.focus();
            assert(await button.evaluate(el => document.activeElement === el), 'POS link receives keyboard focus');
            await page.keyboard.press('Enter');
            await page.waitForURL(origin + '/other');
          }
        });
        assert.deepEqual(errors, [], 'Page errors');
    } finally { await browser.close(); await new Promise(resolve => server.close(resolve)); }
    console.log(`frontend browser fixtures: ${passed} passed, ${failed} failed; page errors ${errors.length}`);
    process.exitCode = failed ? 1 : 0;
})().catch(e => { console.error(e); server.close(); process.exitCode = 1; });
