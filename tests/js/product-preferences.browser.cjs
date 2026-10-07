/** Static stress fixture from actual Blade markup/CSS, NOT Laravel/DB verification.
 * Conditional states are expanded together to exercise long/error/pending content.
 * Forms submit only to a local capture server. No real accounts or external writes.
 */
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const out = process.env.JF_PROMOTION_EVIDENCE || path.join(root, '../promotion-evidence');
fs.mkdirSync(out, { recursive: true });
const uuid = '11111111-1111-4111-8111-111111111111';
function render(file, realm) {
    let html = fs.readFileSync(path.join(root, file), 'utf8')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/^<\/?x-dynamic-component.*>\s*$/gm, '')
        .replace(/^\s*<x-page-header.*\/>\s*$/gm, '')
        .replace(/^\s*@(props|if|foreach)\(.*\)\s*$/gm, '')
        .replace(/@end(?:if|foreach)|@else/g, '')
        .replace(/@foreach\(\$errors->all\(\) as \$error\)/g, '')
        .replace(/@csrf/g, '<input type="hidden" name="_token" value="fixture-csrf">')
        .replace(/\{\{([\s\S]*?)\}\}/g, (_, raw) => {
            const expr = raw.trim();
            if (expr.startsWith('route(')) {
                const action = expr.match(/\.'(\.[a-z]+)'/);
                return action ? `${realm === 'dhiran' ? '/dhiran' : ''}/product-preferences/${action[1].slice(1)}` : '/dashboard';
            }
            const values = {
                '$heading': 'Running a jewellery store too?',
                '$body': 'Use JewelFlows Retail as its own separate service. Your accounts and billing stay separate.',
                '$cta': 'Explore JewelFlows Retail', '$url': '/register',
                '$targetLabel': realm === 'dhiran' ? 'JewelFlows Retail' : 'Dhiran',
                '$row->id': uuid, '$recognition->id': uuid,
                '$recognition->business': 'Example jewellery business with a deliberately long name',
                '$row->target_name': 'Example authorised business with a deliberately long name',
                '$error': 'Your current password was not accepted. Please try again.',
                "session('status')": 'Promotion preference saved.',
                "session('recognition_code')": 'A'.repeat(40),
            };
            if (Object.hasOwn(values, expr)) return values[expr];
            if (expr.startsWith('$preference->choice ?')) return 'Offers are switched off for this account and shop.';
            if (expr.startsWith('\\Illuminate\\Support\\Carbon::parse')) return '06 Oct 2026';
            throw new Error(`Unhandled Blade expression: ${expr}`);
        });
    assert(!/@(?:if|endif|foreach|endforeach|props|php)|\{\{/.test(html), 'Unrendered Blade');
    return html;
}
function documentFor(realm) {
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<style>body{margin:0;font:16px system-ui;background:#f5f7f6}main{max-width:1000px;margin:auto;padding:12px}*{box-sizing:border-box}</style>'
        + '</head><body><main>' + render('resources/views/components/cross-promo-card.blade.php', realm)
        + render('resources/views/product-preferences.blade.php', realm) + '</main></body></html>';
}
let submission;
const server = http.createServer((req, res) => {
    if (req.method === 'POST') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => { submission = { url: req.url, fields: Object.fromEntries(new URLSearchParams(body)) }; res.end('<h1>Captured fixture form</h1>'); });
    } else { res.setHeader('Content-Type', 'text/html'); res.end(documentFor(req.url.includes('dhiran') ? 'dhiran' : 'erp')); }
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const browser = await chromium.launch({ headless:true, executablePath:process.env.JF_BROWSER_EXECUTABLE, args:JSON.parse(process.env.JF_BROWSER_ARGS || '[]') });
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = []; let checks = 0;
    page.on('pageerror', e => errors.push(e.message));
    await page.route('**/*', route => route.request().url().startsWith(origin) ? route.continue() : route.abort());
    try {
        for (const realm of ['erp', 'dhiran']) {
            for (const width of [320,360,390,430,768,820,1024,1440]) {
                await page.setViewportSize({ width, height:820 });
                await page.goto(`${origin}/${realm}`);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Overflow: ${realm}/${width}`);
                assert(await page.locator('button,a,input:not([type=hidden]):not([type=checkbox])').evaluateAll(elements => elements.every(el => {
                    const r = el.getBoundingClientRect(); return r.height >= 44 && r.left >= 0 && r.right <= innerWidth;
                })), `Clipped/small controls: ${realm}/${width}`);
                assert(await page.locator('input:not([type=hidden])').evaluateAll(inputs => inputs.every(el => el.labels.length > 0)), 'Inputs need labels');
                assert.equal(await page.locator('input[type=password][value]').count(), 0, 'Password must never be reflected');
                assert.equal(await page.locator('form').evaluateAll(forms => forms.every(form => form.method === 'post' && form.querySelector('input[name=_token]'))), true);
                checks++;
                if (realm === 'erp' && [390,1440].includes(width)) await page.screenshot({ path:path.join(out, `product-preferences-${width}.png`), fullPage:true });
            }
        }
        await page.goto(origin);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.textContent), 'Explore JewelFlows Retail');
        const form = page.locator('form').filter({ has:page.locator('#start-password') });
        await form.locator('button').click();
        assert.equal(submission, undefined, 'Empty password/consent must not submit');
        await form.locator('#start-password').fill('fixture-password');
        await form.locator('input[type=checkbox]').check();
        await Promise.all([page.waitForURL('**/product-preferences/start'), form.locator('button').click()]);
        assert.equal(submission.fields.password, 'fixture-password');
        assert.equal(submission.fields.consent, '1');
        assert.equal(submission.fields._token, 'fixture-csrf');
        checks++;
        assert.deepEqual(errors, []);
        const summary = {checks, errors, scope:'Static expanded Blade fixture only; PHP, controllers, DB, CSRF enforcement and real sessions NOT RUN'};
        fs.writeFileSync(path.join(out, 'browser-results.json'), JSON.stringify(summary, null, 2));
        console.log(JSON.stringify(summary));
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
