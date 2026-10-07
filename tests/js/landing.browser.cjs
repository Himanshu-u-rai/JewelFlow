/** Actual landing markup/CSS/JS; Blade URL/config substitutions only.
 * PHP rendering and real authentication are NOT exercised. No remote writes.
 * Uses external Playwright tooling, not an application dependency.
 */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const http = require('node:http');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const out = process.env.JF_LANDING_EVIDENCE || path.join(root, '../landing-evidence');
fs.mkdirSync(out, { recursive: true });
const source = fs.readFileSync(path.join(root, 'resources/views/landing.blade.php'), 'utf8');
function render(enabled = true, external = false) {
    const base = external ? 'https://jewelflows.com' : '';
    const logo = 'data:image/svg+xml;base64,' + fs.readFileSync(path.join(root, 'public/favicon.svg')).toString('base64');
    let html = source.replace(/@php[\s\S]*?@endphp/, '')
        .replace(/@include\('partials.favicon'\)/, `<link rel="icon" href="${logo}">`)
        .replace(/@if \(\$dhiran(?:Register|Login)Url\)([\s\S]*?)@endif/g, (_, body) => {
            const parts = body.split('@else'); return enabled ? parts[0] : (parts[1] || '');
        })
        .replace(/\{\{ route\('([^']+)'\) \}\}/g, (_, name) => base + ({ home: '/', login: '/login', register: '/register' })[name])
        .replace(/\{\{ asset\('favicon.svg'\) \}\}/g, logo)
        .replace(/\{\{ asset\('images\/([^']+)'\) \}\}/g, (_, name) => 'data:image/webp;base64,' + fs.readFileSync(path.join(process.env.JF_LANDING_ASSETS || path.join(root, 'public/images'), name)).toString('base64'))
        .replace(/\{\{ \$dhiranRegisterUrl \}\}/g, 'https://dhiran.jewelflows.com/register')
        .replace(/\{\{ \$dhiranLoginUrl \}\}/g, 'https://dhiran.jewelflows.com/login')
        .replace(/\{\{ config\('app.support_email'\) \}\}/g, 'jewelflows@gmail.com')
        .replace(/\{\{ date\('Y'\) \}\}/g, '2026');
    assert(!/\{\{|@(?:if|endif|php|include)/.test(html), 'Unhandled Blade expression');
    return html;
}
fs.writeFileSync(path.join(out, 'landing-preview.html'), render(true, true));
const server = http.createServer((req, res) => {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.end(req.url.startsWith('/login') || req.url.startsWith('/register') ? '<h1>Route target fixture</h1>' : render(!req.url.includes('disabled')));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({headless:true, executablePath:process.env.JF_BROWSER_EXECUTABLE, args:JSON.parse(process.env.JF_BROWSER_ARGS || '[]')});
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const errors = []; let checks = 0;
    try {
        const page = await context.newPage();
        page.setDefaultTimeout(6000);
        page.on('pageerror', e => errors.push(e.message));
        await page.route('**/*', route => route.request().url().startsWith(origin) || route.request().url().startsWith('data:') ? route.continue() : route.abort());
        for (const width of [320,360,390,430,568,680,768,820,900,1024,1280,1440,1920]) {
            await page.setViewportSize({width, height: width <= 680 ? 740 : 900});
            await page.goto(origin); await page.evaluate(() => document.fonts.ready);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow ${width}`);
            await page.locator('.device-showcase').scrollIntoViewIfNeeded();
            await page.waitForFunction(() => [...document.querySelectorAll('.device-art img')].every(img => img.complete && img.naturalWidth > 0));
            const devices = await page.locator('.device-art img').evaluateAll(images => images.map(img => {
                const a = img.getBoundingClientRect(), b = img.parentElement.getBoundingClientRect();
                return a.width >= 100 && a.height >= 100 && a.left >= b.left && a.right <= b.right + 1 && a.top >= b.top && a.bottom <= b.bottom + 1;
            }));
            assert(devices.every(Boolean), `Device illustration clipped or too small at ${width}`);
            if ([390,1440].includes(width)) await page.locator('.device-showcase').screenshot({path:path.join(out,`landing-devices-${width}.png`),style:'.site-header,.skip{visibility:hidden!important}'});
            await page.evaluate(() => window.scrollTo(0,0));
            for (const name of ['entry-login','entry-register']) {
                const summary = page.locator(`.${name} summary`);
                await summary.click();
                const box = await page.locator(`.${name} .entry-menu`).boundingBox();
                assert(box && box.x >= 0 && box.x + box.width <= width && box.y + box.height <= 740, `Menu clipped ${name} ${width}: ${JSON.stringify(box)}`);
                const button = await summary.boundingBox(); assert(button.height >= 44);
                await page.keyboard.press('Escape');
                assert.equal(await summary.evaluate(el => el.parentElement.open), false);
                assert(await summary.evaluate(el => el === document.activeElement));
            }
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            for (const sel of ['.entry-login summary','.entry-register summary']) {
                const box = await page.locator(sel).boundingBox();
                assert(box.y >= 0 && box.y + box.height < 100, `Sticky access missing ${width}`);
            }
            await page.evaluate(() => window.scrollTo(0,0));
            if ([390,1440].includes(width)) {
                await page.locator('h1').click();
                await page.screenshot({path:path.join(out,`landing-${width}.png`),fullPage:true});
                await page.screenshot({path:path.join(out,`landing-hero-${width}.png`)});
            }
            checks++; console.log(`PASS ${width}px: no overflow, device images loaded/unclipped, both menus fit, 44px controls, Escape/focus, sticky account access`);
        }
        await page.setViewportSize({width:390,height:740}); await page.goto(origin);
        await page.locator('.entry-login summary').focus(); await page.keyboard.press('Enter');
        await page.keyboard.press('Tab'); assert.equal(await page.evaluate(() => document.activeElement.pathname), '/login');
        await page.keyboard.press('Enter'); await page.waitForURL(origin + '/login');
        await page.goto(origin); await page.locator('.entry-register summary').click();
        await page.locator('.entry-register a').first().click(); await page.waitForURL(origin + '/register');
        await page.goto(origin); await page.locator('.entry-login summary').click();
        assert.equal(await page.locator('.entry-login a').nth(1).getAttribute('href'),'https://dhiran.jewelflows.com/login');
        await page.locator('.entry-register summary').click(); await page.waitForTimeout(60);
        assert.equal(await page.locator('.entry-login').evaluate(el => el.open),false);
        assert.equal(await page.locator('.entry-register a').nth(1).getAttribute('href'),'https://dhiran.jewelflows.com/register');
        // The open right-aligned menu overlays the hero on narrow screens; click a
        // guaranteed uncovered page margin to exercise real outside dismissal.
        await page.mouse.click(6, 500); assert.equal(await page.locator('.entry-register').evaluate(el => el.open),false);
        checks++;console.log('PASS Retail clicks/keyboard, Dhiran link targets, one menu at a time, outside dismissal');
        await page.locator('.hero a[href="#dhiran"]').click();
        const section = await page.locator('#dhiran').boundingBox(); assert(section.y >= 74 && section.y < 150, 'Anchor hidden by header');
        await page.locator('.faq-list summary').first().click(); assert(await page.locator('.faq-list details').first().evaluate(el => el.open));
        assert.equal(await page.getByText(/manufacturer/i).count(),0);
        checks++;console.log('PASS section anchor, native FAQ, no Manufacturer promotion');
        await page.goto(origin + '/disabled');
        assert.equal(await page.locator('a[href*="dhiran.jewelflows.com"]').count(),0);
        assert.equal(await page.locator('a').filter({hasText:'Ask about Dhiran'}).count(),1);
        checks++;console.log('PASS disabled-Dhiran markup has no production account link (fixture, not PHP helper execution)');
        const nojs = await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:740}});
        const plain = await nojs.newPage(); await plain.route('**/*',r=>r.request().url().startsWith(origin)?r.continue():r.abort());
        await plain.goto(origin); await plain.locator('.entry-register summary').click();
        assert(await plain.locator('.entry-register a').first().isVisible());
        checks++;console.log('PASS registration chooser works without JavaScript');
        assert.deepEqual(errors,[]);console.log(`${checks} checks passed; 0 page errors. System fonts; no external font or image requests.`);
    } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);server.close();process.exitCode=1});
