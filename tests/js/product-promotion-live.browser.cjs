// Real Laravel HTTP + PostgreSQL workflows. Start the guarded testing server on 8786,
// with HTTPS Retail/Dhiran register targets on the same localhost hosts (never followed).
// NODE_PATH=<installed playwright> JF_BROWSER_EXECUTABLE=/usr/bin/google-chrome node this-file
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const net = require('node:net');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const out = path.join(root, 'output/playwright');
const fixture = (...args) => JSON.parse(execFileSync(process.env.JF_PHP_BINARY || 'php8.2', ['tests/Browser/product_promotion_fixture.php', ...args.map(String)], {
    cwd: root, env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8',
}));
const origins = ['http://localhost:8786', 'http://dhiran.localhost:8786'];
const password = 'Browser-test-42!';
const checks = [];
function pass(label) { checks.push(label); console.log('PASS '+label); }
async function submit(form) {
    const page = form.page();
    const action = await form.getAttribute('action');
    const [response] = await Promise.all([page.waitForResponse(r => r.url() === action && r.request().method() === 'POST'),
        form.locator('button').last().click()]);
    await page.waitForLoadState('networkidle');
    return response;
}
async function login(page, origin, mobile, secret = password) {
    await page.goto(origin+'/login');
    await page.locator('[name=mobile_number]').fill(mobile);
    await page.locator('[name=password]').fill(secret);
    const response = await submit(page.locator('form[action$="/login"]'));
    assert.equal(response.status(), 302, 'Login response status');
    assert(!new URL(response.headers().location).pathname.endsWith('/login'), 'Login rejected credentials');
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    await page.waitForLoadState('networkidle');
    assert(!page.url().includes('/login'), await page.locator('body').innerText());
}
async function consent(form, secret = password) {
    await form.locator('[name=password]').fill(secret);
    await form.locator('[name=consent]').check();
    await submit(form);
}
(async () => {
    fs.mkdirSync(out, { recursive: true });
    // A single proxy, no DIRECT fallback: every hop can only reach this testing port.
    // Browser route handlers alone do not protect all redirect destinations.
    const denied = [];
    const tunnels = new Set();
    const proxy = http.createServer((request, response) => {
        let url;
        try { url = new URL(request.url); } catch { response.writeHead(403).end(); return; }
        if (!origins.includes(url.origin)) {
            denied.push(url.href); response.writeHead(403).end('Testing egress denied'); return;
        }
        if (url.pathname.startsWith('/__jf_egress_')) {
            response.writeHead(302, { location: url.pathname.endsWith('https')
                ? 'https://blocked.invalid/' : 'http://blocked.invalid/' }).end(); return;
        }
        const upstream = http.request({ hostname: '127.0.0.1', port: 8786,
            path: url.pathname+url.search, method: request.method, headers: { ...request.headers, host: url.host } }, result => {
            response.writeHead(result.statusCode, result.headers); result.pipe(response);
        });
        upstream.on('error', error => {
            // Turbo may abort after upstream headers. Close that response rather
            // than trying to send a second status line and crashing the runner.
            if (response.headersSent) response.destroy();
            else response.writeHead(502).end(error.code || 'Testing upstream failed');
        });
        upstream.setTimeout(15000, () => upstream.destroy(new Error('Testing upstream timeout')));
        request.on('aborted', () => upstream.destroy());
        request.pipe(upstream);
    });
    proxy.on('connect', (request, socket, head) => {
        if (!origins.some(origin => new URL(origin).host === request.url)) {
            denied.push('CONNECT '+request.url); socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n'); return;
        }
        // Playwright APIRequestContext tunnels even plain HTTP. Pin local tunnels,
        // never resolve their supplied host or open an arbitrary port.
        const upstream = net.connect(8786, '127.0.0.1', () => {
            socket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
            if (head.length) upstream.write(head);
            socket.pipe(upstream); upstream.pipe(socket);
        });
        for (const connection of [socket, upstream]) {
            tunnels.add(connection);
            connection.on('close', () => { tunnels.delete(connection); socket.destroy(); upstream.destroy(); });
            connection.on('error', () => { socket.destroy(); upstream.destroy(); });
            connection.setTimeout(15000, () => { socket.destroy(); upstream.destroy(); });
        }
    });
    proxy.on('upgrade', (_request, socket) => socket.destroy());
    await new Promise((resolve, reject) => {
        proxy.once('error', reject); proxy.listen(0, '127.0.0.1', resolve);
    });
    let browser, retail, dhiran;
    try {
    browser = await chromium.launch({ headless: true, executablePath: process.env.JF_BROWSER_EXECUTABLE,
        proxy: { server: 'http://127.0.0.1:'+proxy.address().port, bypass: '<-loopback>' } });
    const probe = await browser.newContext({ serviceWorkers: 'block' });
    const probePage = await probe.newPage();
    assert.equal((await probePage.goto(origins[0]+'/__jf_egress_http')).status(), 403);
    assert(denied.includes('http://blocked.invalid/'), 'HTTP redirect did not reach the deny proxy');
    await assert.rejects(probePage.goto(origins[0]+'/__jf_egress_https'), /ERR_TUNNEL_CONNECTION_FAILED/);
    assert(denied.includes('CONNECT blocked.invalid:443'), 'HTTPS redirect did not reach the deny proxy');
    await probe.close();
    pass('independent loopback-only proxy denies HTTP/HTTPS redirect hops before database seeding');
    if (process.argv.includes('--egress-check')) return;
    const data = fixture('seed');
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
    const errors = [];
    retail = await context.newPage();
    dhiran = await context.newPage();
    for (const page of [retail, dhiran]) { page.setDefaultTimeout(10000); page.on('pageerror', e => errors.push(e.message)); }
    // No external destinations, analytics or production access from this harness.
    await context.route('**/*', route => origins.includes(new URL(route.request().url()).origin)
        ? route.continue() : route.abort());
        await retail.goto(origins[0]);
        fs.writeFileSync(path.join(out, 'live-landing.yml'), await retail.locator('body').ariaSnapshot());
        for (const width of [320, 390, 768, 1440]) {
            await retail.setViewportSize({ width, height: 900 });
            assert(await retail.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'landing overflow '+width);
        }
        await retail.locator('.entry-login summary').click();
        assert.equal(await retail.locator('.entry-login a').nth(1).getAttribute('href'), origins[1].replace('http:', 'https:')+'/login');
        await retail.locator('.entry-login a').first().click();
        assert.equal(retail.url(), origins[0]+'/login');
        pass('real Blade landing: four widths and Retail/Dhiran front doors');
        await login(retail, origins[0], data.retail.mobile);
        await login(dhiran, origins[1], data.dhiran.mobile);
        const activate = dhiran.getByRole('button', { name: 'Activate Dhiran Module' });
        if (await activate.isVisible()) {
            await Promise.all([dhiran.waitForNavigation(), activate.click()]);
        }
        const cookies = await context.cookies();
        assert(cookies.some(c => c.domain === 'localhost' && c.name.includes('session')));
        assert(cookies.some(c => c.domain === 'dhiran.localhost' && c.name.includes('session')));
        assert.equal(fixture('report', data.retail.id).active_links, 0);
        pass('same phone/email, independent authenticated host cookies, no inferred recognition');
        for (const [page, owner] of [[retail, data.retail], [dhiran, data.dhiran]]) {
            assert.equal(await page.locator('[data-cross-promo]').count(), 1);
            assert.equal(fixture('report', owner.id).exposures, 1);
            await page.reload();
            assert.equal(await page.locator('[data-cross-promo]').count(), 0);
            assert.equal(fixture('report', owner.id).exposures, 1);
        }
        pass('real dashboard introduction appears once per owner, persisted across refresh');
        await retail.goto(origins[0]+'/settings');
        const rates = retail.locator('.rate-modal');
        if (await rates.isVisible()) {
            await retail.locator('#rm-gold').fill('6000');
            await retail.locator('#rm-silver').fill('75000');
            await rates.locator('button[type=submit]').click();
            await rates.waitFor({ state: 'hidden' });
            await retail.goto(origins[0]+'/settings');
        }
        await retail.getByRole('link', { name: 'Product preferences', exact: true }).click();
        await dhiran.goto(origins[1]+'/dhiran/settings');
        await dhiran.getByRole('link', { name: 'Product preferences', exact: true }).click();
        for (const page of [retail, dhiran]) {
            assert.equal(await page.locator('h1').last().innerText(), 'Product preferences');
            for (const width of [390, 1440]) {
                await page.setViewportSize({ width, height: 900 });
                await page.reload();
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'preferences overflow');
                assert(await page.locator('.product-preferences button, .product-preferences input:not([type=hidden]):not([type=checkbox])').evaluateAll(elements => elements.every(el => {
                    const rect = el.getBoundingClientRect();
                    return rect.height >= 44 && rect.left >= 0 && rect.right <= innerWidth;
                })), 'preferences controls clipped or too small');
                if (width === 390) {
                    const opener = page === retail ? '.content-header .mobile-menu-btn' : '.dh-header-toggle';
                    const closer = page === retail ? '.sidebar-close-btn' : '.dh-sidebar-close';
                    await page.locator(opener).click();
                    await page.locator(closer).click();
                }
                await page.screenshot({ path: path.join(out, `live-${page === retail ? 'retail' : 'dhiran'}-${width}.png`), fullPage: true, animations: 'disabled' });
            }
        }
        pass('Settings links and real application layouts at phone/desktop widths');
        await retail.setViewportSize({ width: 390, height: 900 });
        await dhiran.setViewportSize({ width: 390, height: 900 });
        await consent(retail.locator('form[action$="/start"]'), 'wrong-password');
        assert.match(await retail.locator('.pp-errors').innerText(), /password was not accepted/);
        await consent(retail.locator('form[action$="/start"]'));
        const code = await retail.locator('code[aria-label="One-time confirmation code"]').innerText();
        assert.match(code, /^[A-F0-9]{40}$/);
        await dhiran.locator('[name=code]').fill(code);
        await consent(dhiran.locator('form[action$="/approve"]'));
        assert.equal(fixture('report', data.retail.id).active_links, 0);
        await retail.reload();
        assert.match(await retail.locator('form[action$="/finish"]').innerText(), new RegExp(data.dhiran.name));
        await consent(retail.locator('form[action$="/finish"]'));
        assert.equal(fixture('report', data.retail.id).active_links, 1);
        assert.equal(fixture('report', data.dhiran.id).active_links, 1);
        pass('wrong-password UX, two-owner approval, final consent, persisted recognition');
        assert.equal((await context.request.get(origins[0]+'/customers/'+data.dhiran.customer, { maxRedirects: 0 })).status(), 404);
        assert.equal((await context.request.get(origins[1]+'/dhiran/borrowers/'+data.retail.customer, { maxRedirects: 0 })).status(), 404);
        assert.equal((await context.request.post(origins[0]+'/product-preferences/start', { maxRedirects: 0, form: { password, consent: '1' } })).status(), 419);
        pass('both cross-shop customer reads denied after recognition; missing CSRF rejected');
        await retail.locator('button[value=opt_out]').click();
        await retail.waitForURL('**/dashboard');
        assert.equal(fixture('report', data.retail.id).preferences[0].choice, 'opt_out');
        assert.equal(fixture('report', data.dhiran.id).preferences[0].choice, null);
        await dhiran.reload();
        await submit(dhiran.locator('form[action$="/revoke"]'));
        assert.equal(fixture('report', data.retail.id).active_links, 0);
        assert.equal(fixture('report', data.retail.id).preferences[0].choice, 'opt_out');
        pass('independent permanent preferences; either-owner revoke preserves opt-out');
        await retail.setViewportSize({ width: 1440, height: 900 });
        await dhiran.setViewportSize({ width: 1440, height: 900 });
        // Reconnect in the other direction so the real password reset below
        // must invalidate an established link, while opt-out remains independent.
        await consent(dhiran.locator('form[action$="/start"]'));
        const reverseCode = await dhiran.locator('code[aria-label="One-time confirmation code"]').innerText();
        await retail.goto(origins[0]+'/product-preferences');
        await retail.locator('[name=code]').fill(reverseCode);
        await consent(retail.locator('form[action$="/approve"]'));
        await dhiran.reload();
        await consent(dhiran.locator('form[action$="/finish"]'));
        assert.equal(fixture('report', data.retail.id).active_links, 1);
        await retail.goto(origins[0]+'/categories');
        await retail.getByRole('button', { name: 'Add Category', exact: true }).first().click();
        const category = 'Browser category '+data.retail.id;
        await retail.locator('#addCategoryName').fill(category);
        await submit(retail.locator('#addCategoryModal form'));
        await retail.waitForFunction(() => document.querySelector('#addCategoryModal').classList.contains('hidden'));
        assert.match(await retail.locator('body').innerText(), new RegExp(category));
        await retail.getByRole('link', { name: 'Back to Stock', exact: true }).click();
        await retail.waitForURL('**/items');
        await retail.goBack();
        await retail.waitForURL('**/categories');
        await retail.getByRole('button', { name: 'Add Category', exact: true }).first().click();
        await retail.locator('#addCategoryName').fill(category);
        await submit(retail.locator('#addCategoryModal form'));
        await retail.locator('#addCategoryModal').waitFor({ state: 'visible' });
        assert(await retail.locator('body').evaluate(el => el.classList.contains('categories-modal-open')));
        await retail.locator('#addCategoryModal [data-field-error]').waitFor({ state: 'visible' });
        assert.match(await retail.locator('#addCategoryModal [data-field-error]').innerText(), /already have a category/i);
        pass('category backend save, Turbo Back, duplicate validation and modal scroll lock');
        await retail.locator('#addCategoryModal').getByRole('button', { name: 'Cancel', exact: true }).click();
        await retail.setViewportSize({ width: 390, height: 900 });
        const toggle = retail.locator('[data-category-toggle]').first();
        await retail.waitForFunction(() => document.querySelector('[data-category-toggle]').getAttribute('aria-expanded') === 'false');
        const expanded = await toggle.getAttribute('aria-expanded');
        await toggle.click();
        assert.notEqual(await toggle.getAttribute('aria-expanded'), expanded);
        await retail.getByRole('link', { name: 'Back to Stock', exact: true }).click();
        await retail.waitForURL('**/items');
        await retail.goBack();
        await retail.waitForURL('**/categories');
        await retail.waitForFunction(() => document.querySelector('[data-category-toggle]')?.getAttribute('aria-expanded') === 'false');
        const restored = await toggle.getAttribute('aria-expanded');
        await toggle.click();
        assert.notEqual(await toggle.getAttribute('aria-expanded'), restored);
        assert(!await retail.locator('#global-toast').evaluate(el => el.classList.contains('is-visible')));
        await retail.goto(origins[0]+'/invoices');
        const pos = retail.getByRole('link', { name: 'Open POS', exact: true });
        const hitHeight = await pos.evaluate(el => {
            const pseudo = getComputedStyle(el, '::after');
            return el.getBoundingClientRect().height - parseFloat(pseudo.top || 0) - parseFloat(pseudo.bottom || 0);
        });
        assert(hitHeight >= 44, `Open POS touch height ${hitHeight}`);
        assert(await pos.evaluate(el => {
            const box = el.getBoundingClientRect();
            return el.contains(document.elementFromPoint(box.x + box.width / 2, box.y - 4));
        }), 'Open POS must receive a tap above its visible border');
        const posBox = await pos.boundingBox();
        await retail.mouse.click(posBox.x + posBox.width / 2, posBox.y - 4);
        await retail.waitForURL(origins[0]+'/pos');
        await retail.goBack();
        await pos.waitFor({ state: 'visible' });
        await pos.focus();
        assert(await pos.evaluate(el => document.activeElement === el), 'Actual POS link receives keyboard focus');
        await retail.keyboard.press('Enter');
        await retail.waitForURL(origins[0]+'/pos');
        pass('phone category cards survive Turbo Back; stale toast removed; real Open POS touch target');
        for (const width of [390, 1440]) {
            await retail.setViewportSize({ width, height: 900 });
            await retail.goto(origins[0]+'/historical/manual');
            const surface = retail.locator(`[data-historical-item-grid-${width === 390 ? 'mobile' : 'desktop'}]`);
            const names = surface.locator('input[x-model="line.line_item_name"]');
            await names.first().waitFor({ state: 'visible' });
            assert.equal(await names.count(), 1);
            await names.first().fill('Browser historical item');
            await retail.waitForFunction(selector => document.querySelectorAll(selector).length === 2,
                `[data-historical-item-grid-${width === 390 ? 'mobile' : 'desktop'}] input[x-model="line.line_item_name"]`);
            assert.equal(await names.nth(1).inputValue(), '');
        }
        pass('live Alpine historical typing appends one blank row on phone and desktop');
        await retail.goto(origins[0]+'/product-preferences');
        // Dhiran's fifth/sixth metadata actions stay within its actual allowance;
        // do not spend Retail's remaining slot needed to prove 422 then 429 below.
        await consent(dhiran.locator('form[action$="/start"]'));
        assert.equal(fixture('report', data.dhiran.id).source_pending_requests, 1);
        assert.equal((await submit(dhiran.locator('form[action$="/cancel"]'))).status(), 302);
        const afterCancel = fixture('report', data.dhiran.id);
        assert.equal(afterCancel.source_pending_requests, 0);
        assert.equal(afterCancel.active_links, 1);
        assert.equal(afterCancel.preferences[0].choice, null);
        assert.equal(fixture('report', data.retail.id).preferences[0].choice, 'opt_out');
        assert.equal(await dhiran.locator('form[action$="/cancel"]').count(), 0);
        pass('real cancellation consumes pending consent without revoking an existing pair or changing opt-out');
        const csrf = await retail.locator('form[action$="/start"] [name=_token]').inputValue();
        const statuses = [];
        for (let i = 0; i < 7; i++) statuses.push((await context.request.post(origins[0]+'/product-preferences/start', {
            maxRedirects: 0, headers: { Accept: 'application/json' }, form: { _token: csrf, password: 'wrong', consent: '1' },
        })).status());
        assert(statuses.includes(422) && statuses.includes(429), JSON.stringify(statuses));
        pass('real HTTP password validation and persistent rate limiting');
        const logoutToken = await retail.locator('form[action$="/logout"] [name=_token]').first().inputValue();
        assert.equal((await context.request.post(origins[0]+'/logout', { maxRedirects: 0, form: { _token: logoutToken } })).status(), 302);
        await retail.goto(origins[0]+'/product-preferences');
        assert(retail.url().includes('/login'));
        assert.equal((await dhiran.goto(origins[1]+'/dhiran/product-preferences')).status(), 200);
        pass('Retail logout leaves Dhiran authenticated');
        const token = fixture('reset-token', data.retail.id).token;
        const resetContext = await browser.newContext({ serviceWorkers: 'block' });
        await resetContext.route('**/*', route => origins.includes(new URL(route.request().url()).origin)
            ? route.continue() : route.abort());
        const resetPage = await resetContext.newPage();
        resetPage.setDefaultTimeout(10000);
        resetPage.on('pageerror', e => errors.push(e.message));
        async function resetOn(origin) {
            for (let attempt = 0; attempt < 3; attempt++) {
                await resetPage.goto(origin+'/reset-password/'+token+'?email='+encodeURIComponent(data.retail.email));
                await resetPage.locator('[name=password]').fill('Changed-browser-42!');
                await resetPage.locator('[name=password_confirmation]').fill('Changed-browser-42!');
                const response = await submit(resetPage.locator('form[action$="/reset-password"]'));
                if (response.status() !== 429) return;
                // Existing numeric guest throttles share the IP key across auth
                // routes. Honour the actual server cooldown; never bypass it.
                const seconds = Number(response.headers()['retry-after']);
                assert(seconds > 0 && seconds <= 60);
                console.log(`Waiting ${seconds}s for the existing guest auth throttle`);
                await new Promise(resolve => setTimeout(resolve, (seconds+1)*1000));
            }
            throw new Error('Guest auth throttle did not clear');
        }
        await resetOn(origins[1]);
        assert.match(await resetPage.locator('body').innerText(), /token.*invalid/i);
        await resetOn(origins[0]);
        assert(resetPage.url().endsWith('/login'));
        await login(resetPage, origins[0], data.retail.mobile, 'Changed-browser-42!');
        assert.equal(fixture('report', data.retail.id).active_links, 0);
        assert.equal(fixture('report', data.dhiran.id).active_links, 0);
        await login(retail, origins[0], data.retail.mobile, 'Changed-browser-42!');
        const dhiranLogoutToken = await dhiran.locator('form[action$="/logout"] [name=_token]').first().inputValue();
        assert.equal((await context.request.post(origins[1]+'/logout', { maxRedirects: 0, form: { _token: dhiranLogoutToken } })).status(), 302);
        assert.equal((await retail.goto(origins[0]+'/product-preferences')).status(), 200);
        await login(dhiran, origins[1], data.dhiran.mobile);
        pass('actual password reset form accepts only its realm token');
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(out, 'live-results.json'), JSON.stringify({ checks, pageErrors: errors }, null, 2));
        console.log(`${checks.length} workflow groups passed; zero page errors`);
    } catch (error) {
        for (const [i, page] of (browser?.contexts() || []).flatMap(c => c.pages()).entries()) {
            fs.writeFileSync(path.join(out, `live-failure-${i}.yml`), await page.locator('body').ariaSnapshot());
        }
        if (retail) await retail.screenshot({ path: path.join(out, 'live-failure-retail.png'), fullPage: true });
        if (dhiran) await dhiran.screenshot({ path: path.join(out, 'live-failure-dhiran.png'), fullPage: true });
        console.error(error);
        process.exitCode = 1;
    } finally {
        try { if (browser) await browser.close(); } finally {
            for (const connection of tunnels) connection.destroy();
            proxy.closeAllConnections();
            await new Promise(resolve => proxy.close(resolve));
        }
    }
})();
