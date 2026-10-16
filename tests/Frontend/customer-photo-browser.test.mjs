import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { after, before, test } from 'node:test';

let chromium;
try {
    ({ chromium } = await import(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright'));
} catch (error) {
    if (process.env.PLAYWRIGHT_MODULE_PATH || error.code !== 'ERR_MODULE_NOT_FOUND') throw error;
}
const options = { skip: !chromium && 'Set PLAYWRIGHT_MODULE_PATH to an existing Playwright installation; no application dependency is required.' };
const javascript = readFileSync(new URL('../../resources/js/customer-photo.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../resources/css/customer-photo.css', import.meta.url), 'utf8');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGrkAAAAASUVORK5CYII=', 'base64');
const uncertainty = 'The photo save could not be confirmed. Refresh to check the current photo before retrying.';
let browser;
before(async () => {
    if (chromium) browser = await chromium.launch({ headless: true, executablePath: process.env.BROWSER_EXECUTABLE, args: ['--no-sandbox'] });
});
after(async () => { await browser?.close(); });

const photo = version => ({ version, url: 'https://photo-review.test/image?version=' + version });
const json = (route, data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });
const gate = () => {
    let release;
    return { promise: new Promise(resolve => { release = resolve; }), release: () => release() };
};
// Real candidate JS/CSS and native DOM; only HTTP/camera boundaries are synthetic.
async function fixture(t, { stale = false, form = false, metadata, mutate, cameras } = {}) {
    const page = await browser.newPage();
    t.after(() => page.close());
    if (cameras) await page.addInitScript(fakeCameras, cameras);
    const state = { gets: 0, posts: 0, revision: null, selected: photo('v1') };
    await page.route('https://photo-review.test/**', async route => {
        const request = route.request();
        if (request.url().includes('/image?')) return route.fulfill({ contentType: 'image/png', body: png });
        if (!request.url().endsWith('/photo')) return route.fulfill({ contentType: 'text/html', body: html(stale, form) });
        if (request.method() === 'GET') {
            state.gets++;
            return metadata ? metadata(route, state) : json(route, { photo: state.selected });
        }
        state.posts++;
        state.revision = request.postData()?.match(/name="customer_photo_version"\r\n\r\n([^\r]*)/)?.[1];
        if (mutate) return mutate(route, state);
        state.selected = request.postData()?.includes('name="_method"') ? null : photo('v' + (state.posts + 1));
        return json(route, { photo: state.selected, message: state.selected ? 'Customer photo saved.' : 'Photo removed. Initials are shown instead.' });
    });
    await page.goto('https://photo-review.test/');
    return { page, state };
}
function html(stale, form) {
    return `<!doctype html><meta name="csrf-token" content="synthetic"><style>${css}</style>
<section class="customer-photo" data-customer-photo data-photo-mode="${form ? 'form' : 'profile'}" data-photo-url="/photo" data-photo-version="v1">
<span class="customer-avatar" data-customer-avatar data-photo-meta="/photo" ${stale ? 'data-photo-stale="true"' : ''}><span data-photo-initials hidden>EX</span><img data-photo-image src="/image?version=v1"></span>
<div class="customer-photo__actions">${['camera', 'upload', 'change', 'remove'].map(action => `<button type="button" data-photo-action="${action}" id="outer-${action}">${action}</button>`).join('')}</div>
<p data-photo-status role="status"></p><input type="file" data-photo-picker hidden>
${form ? '<input type="file" data-photo-form-file hidden><input data-photo-form-version value="v1" hidden><input data-photo-form-remove value="0" hidden><input id="unsaved-name" value="Unsaved customer">' : ''}
<dialog data-photo-dialog><header><h2>Photo</h2><button data-photo-action="cancel">Close</button></header>
<p data-photo-help></p><p data-photo-error tabindex="-1" hidden></p><video data-photo-video hidden></video><img data-photo-preview hidden>
<div class="customer-photo-dialog__choices"><button data-photo-action="upload">Upload</button><button class="btn customer-photo-icon customer-photo-switch" data-photo-action="switch" aria-label="Switch to front camera" hidden>switch</button><button data-photo-action="capture" hidden>Capture</button><button data-photo-action="retake" hidden>Retake</button></div>
<footer><button data-photo-action="cancel">Cancel</button><button data-photo-action="confirm" hidden>Save</button></footer></dialog>
</section><script type="module">${javascript}\ninitializeCustomerPhotos();</script>`;
}
async function choose(page, action = 'upload') {
    await page.locator('#outer-' + action).click();
    await page.locator('[data-photo-picker]').setInputFiles({ name: 'portrait.png', mimeType: 'image/png', buffer: png });
}
async function confirm(page) {
    await page.locator('[data-photo-action="confirm"]').click();
}
const version = page => page.locator('[data-customer-photo]').getAttribute('data-photo-version');
async function closed(page) {
    await page.waitForFunction(() => !document.querySelector('dialog').open);
}
async function errorShown(page) {
    await page.waitForFunction(() => !document.querySelector('[data-photo-error]').hidden);
    await page.locator('[data-photo-action="confirm"]').waitFor({ state: 'visible' });
    await page.waitForFunction(() => !document.querySelector('[data-photo-action="confirm"]').disabled);
}

for (const action of ['upload', 'change', 'camera']) {
    test('successful ' + action + ' returns keyboard focus after enabling the opener', options, async t => {
        const { page } = await fixture(t);
        await choose(page, action); await confirm(page); await closed(page);
        assert.equal(await page.evaluate(() => document.activeElement.id), 'outer-' + action);
        assert.equal(await page.locator('#outer-' + action).isEnabled(), true);
    });
}
test('successful removal focuses a visible enabled fallback and Tab proceeds', options, async t => {
    const { page } = await fixture(t);
    await page.locator('#outer-remove').click(); await confirm(page); await closed(page);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'outer-camera');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'outer-upload');
});
test('cancel returns focus without sending a mutation', options, async t => {
    const { page, state } = await fixture(t);
    await choose(page, 'change'); await page.keyboard.press('Escape');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'outer-change');
    assert.equal(state.posts, 0);
});

for (const remove of [false, true]) {
    test('metadata started before ' + (remove ? 'removal' : 'replacement') + ' cannot overwrite its result', options, async t => {
        const release = gate(); const seen = gate();
        const { page, state } = await fixture(t, { stale: true, metadata: async (route, state) => {
            if (state.gets === 1) { seen.release(); await release.promise; return json(route, { photo: photo('v1') }); }
            return json(route, { photo: state.selected });
        } });
        await seen.promise;
        if (remove) await page.locator('#outer-remove').click(); else await choose(page);
        await confirm(page); await closed(page);
        const finished = page.waitForResponse(response => response.request().method() === 'GET' && response.url().endsWith('/photo'));
        release.release(); await finished;
        assert.equal(await version(page), remove ? '' : 'v2');
        await choose(page); await confirm(page); await closed(page);
        assert.equal(state.revision, remove ? '' : 'v2');
        assert.equal(state.posts, 2);
    });
}
test('a refresh started during a save cannot overwrite its eventual revision', options, async t => {
    const releasePost = gate(); const releaseGet = gate(); const seen = gate();
    const { page } = await fixture(t, { mutate: async route => {
        seen.release(); await releasePost.promise;
        return json(route, { photo: photo('v2'), message: 'Customer photo saved.' });
    }, metadata: async route => { await releaseGet.promise; return json(route, { photo: photo('v1') }); } });
    await choose(page); await confirm(page); await seen.promise;
    await page.evaluate(() => { document.querySelector('[data-customer-avatar]').dataset.photoStale = 'true'; document.dispatchEvent(new Event('turbo:load')); });
    releasePost.release(); await closed(page);
    const done = page.waitForResponse(response => response.request().method() === 'GET' && response.url().endsWith('/photo'));
    releaseGet.release(); await done;
    assert.equal(await version(page), 'v2');
});
test('older refreshes cannot apply to a disposed and recreated Turbo controller', options, async t => {
    const release = gate(); const seen = gate();
    const { page } = await fixture(t, { stale: true, metadata: async (route, state) => {
        if (state.gets === 1) { seen.release(); await release.promise; return json(route, { photo: photo('v1') }); }
        return json(route, { photo: photo('v2') });
    } });
    await seen.promise;
    await page.evaluate(() => { document.dispatchEvent(new Event('turbo:before-cache')); document.dispatchEvent(new Event('turbo:load')); });
    await page.waitForFunction(() => document.querySelector('[data-customer-photo]').dataset.photoVersion === 'v2');
    const done = page.waitForResponse(response => response.request().method() === 'GET' && response.url().endsWith('/photo'));
    release.release(); await done;
    assert.equal(await version(page), 'v2');
});
test('Turbo restores usable controls during an outstanding save without stale focus', options, async t => {
    const release = gate(); const seen = gate();
    const { page } = await fixture(t, { mutate: async route => {
        seen.release(); await release.promise;
        return json(route, { photo: photo('v2'), message: 'Customer photo saved.' });
    } });
    await choose(page); await confirm(page); await seen.promise;
    await page.evaluate(() => { document.dispatchEvent(new Event('turbo:before-cache')); document.dispatchEvent(new Event('turbo:load')); });
    await page.waitForFunction(() => !!document.querySelector('[data-photo-image]').getAttribute('src'));
    assert.equal(await page.locator('#outer-upload').isEnabled(), true);
    await page.locator('#outer-upload').focus();
    const done = page.waitForResponse(response => response.request().method() === 'POST');
    release.release(); await done;
    assert.equal(await page.evaluate(() => document.activeElement.id), 'outer-upload');
    assert.equal(await version(page), 'v1', 'A disposed controller cannot update its restored successor');
});
for (const remove of [false, true]) {
    test('metadata preserves an unsaved full-form ' + (remove ? 'removal' : 'selection'), options, async t => {
        const release = gate(); const seen = gate();
        const { page, state } = await fixture(t, { form: true, stale: true, metadata: async route => { seen.release(); await release.promise; return json(route, { photo: photo('v2') }); } });
        await seen.promise;
        if (remove) await page.locator('#outer-remove').click(); else await choose(page);
        await confirm(page); await closed(page);
        const done = page.waitForResponse(response => response.request().method() === 'GET' && response.url().endsWith('/photo'));
        release.release(); await done;
        assert.equal(await page.locator('#unsaved-name').inputValue(), 'Unsaved customer');
        assert.equal(await page.locator('[data-photo-form-version]').inputValue(), 'v1');
        assert.equal(await page.locator('[data-photo-form-remove]').inputValue(), remove ? '1' : '0');
        assert.equal(await page.locator('[data-photo-form-file]').evaluate(element => element.files.length), remove ? 0 : 1);
        assert.equal(state.posts, 0);
    });
}
test('a known validation refusal stays clear, focusable and retryable', options, async t => {
    const { page, state } = await fixture(t, { mutate: (route, state) => state.posts === 1
        ? json(route, { errors: { customer_photo: ['Choose a valid JPEG, PNG or WebP.'] } }, 422)
        : json(route, { photo: photo('v2'), message: 'Customer photo saved.' }) });
    await choose(page); await confirm(page); await errorShown(page);
    assert.match(await page.locator('[data-photo-error]').textContent(), /valid JPEG/);
    assert.equal(await page.evaluate(() => document.activeElement.matches('[data-photo-error]')), true);
    await confirm(page); await closed(page); assert.equal(state.posts, 2);
});
for (const outcome of ['uncertain-response', 'lost-upload-response', 'lost-removal-response', 'invalid-response-json']) {
    test(outcome + ' reconciles selected metadata without an automatic second mutation', options, async t => {
        const remove = outcome === 'lost-removal-response';
        const { page, state } = await fixture(t, { mutate: (route, state) => {
            state.selected = remove ? null : photo('v2');
            if (outcome === 'uncertain-response') return json(route, { errors: { customer_photo: [uncertainty] } }, 422);
            if (outcome === 'invalid-response-json') return route.fulfill({ status: 200, contentType: 'application/json', body: 'not json' });
            return route.abort('failed');
        } });
        if (remove) await page.locator('#outer-remove').click(); else await choose(page);
        await confirm(page); await errorShown(page);
        assert.doesNotMatch(await page.locator('[data-photo-status]').textContent(), /Photo not saved\./);
        assert.equal(await version(page), remove ? '' : 'v2');
        assert.equal(state.gets, 1); assert.equal(state.posts, 1);
        await page.keyboard.press('Escape');
        assert.equal(await page.evaluate(() => document.activeElement.id), remove ? 'outer-camera' : 'outer-upload');
    });
}
test('failed reconciliation blocks duplicate retries until current metadata is readable', options, async t => {
    let readable = false;
    const { page, state } = await fixture(t, { mutate: route => route.abort('failed'), metadata: route => readable
        ? json(route, { photo: photo('v2') }) : route.abort('failed') });
    await choose(page); await confirm(page); await errorShown(page);
    await confirm(page); await errorShown(page);
    assert.equal(state.posts, 1);
    readable = true;
    await confirm(page); await errorShown(page);
    assert.equal(await version(page), 'v2');
    assert.equal(state.posts, 1, 'Checking metadata must not itself retry the mutation');
});

// Synthetic cameras: real MediaStreams from a canvas, one per request, slow to open like a phone.
function fakeCameras({ available, inputs }) {
    window.__tracks = [];
    navigator.mediaDevices.enumerateDevices = async () => Array.from({ length: inputs }, (_, i) => ({ kind: 'videoinput', deviceId: 'cam' + i }));
    navigator.mediaDevices.getUserMedia = async constraints => {
        const wanted = constraints.video.facingMode;
        await new Promise(resolve => setTimeout(resolve, 150));
        if (wanted.exact && !available.includes(wanted.exact)) throw new DOMException('No such camera', 'OverconstrainedError');
        const facing = wanted.exact ?? (available.includes(wanted.ideal) ? wanted.ideal : available[0]);
        const canvas = Object.assign(document.createElement('canvas'), { width: 640, height: 480 });
        const context = canvas.getContext('2d');
        setInterval(() => { context.fillStyle = facing === 'user' ? '#c33' : '#3a3'; context.fillRect(0, 0, 640, 480); }, 50);
        const stream = canvas.captureStream(10);
        const track = stream.getVideoTracks()[0];
        track.facing = facing;
        // Phones report which way the opened camera faces.
        const settings = track.getSettings.bind(track);
        track.getSettings = () => ({ ...settings(), facingMode: facing });
        window.__tracks.push(track);
        return stream;
    };
}
const live = page => page.evaluate(() => window.__tracks.filter(track => track.readyState === 'live').map(track => track.facing));
const switchButton = page => page.locator('[data-photo-action="switch"]');
async function cameraReady(page) {
    await page.waitForFunction(() => {
        const capture = document.querySelector('[data-photo-action="capture"]');
        return !capture.hidden && !capture.disabled && document.querySelector('[data-photo-video]').videoWidth > 0;
    });
}

test('the camera opens rear-first and switches both ways with one camera running', options, async t => {
    const { page } = await fixture(t, { cameras: { available: ['environment', 'user'], inputs: 2 } });
    await page.locator('#outer-camera').click(); await cameraReady(page);
    assert.deepEqual(await live(page), ['environment']);
    await switchButton(page).waitFor({ state: 'visible' });
    assert.equal(await switchButton(page).getAttribute('aria-label'), 'Switch to front camera');
    const box = await switchButton(page).boundingBox();
    assert.ok(box.width >= 44 && box.height >= 44, `switch target is ${box.width}x${box.height}`);
    await switchButton(page).click(); await cameraReady(page);
    assert.deepEqual(await live(page), ['user']);
    assert.equal(await switchButton(page).getAttribute('aria-label'), 'Switch to back camera');
    await switchButton(page).click(); await cameraReady(page);
    assert.deepEqual(await live(page), ['environment']);
});

test('retake keeps the selected camera and capture still previews', options, async t => {
    const { page } = await fixture(t, { cameras: { available: ['environment', 'user'], inputs: 2 } });
    await page.locator('#outer-camera').click(); await cameraReady(page);
    await switchButton(page).click(); await cameraReady(page);
    await page.locator('[data-photo-action="capture"]').click();
    await page.locator('[data-photo-preview]').waitFor({ state: 'visible' });
    assert.deepEqual(await live(page), [], 'the preview must not keep the camera on');
    await page.locator('[data-photo-action="retake"]').click(); await cameraReady(page);
    assert.deepEqual(await live(page), ['user']);
});

test('rapid switch taps and closing mid-switch leave no camera running', options, async t => {
    const { page } = await fixture(t, { cameras: { available: ['environment', 'user'], inputs: 2 } });
    await page.locator('#outer-camera').click(); await cameraReady(page);
    await switchButton(page).click();
    await switchButton(page).click({ force: true });
    await switchButton(page).click({ force: true });
    await page.locator('footer [data-photo-action="cancel"]').click();
    await page.waitForTimeout(600);
    assert.deepEqual(await live(page), []);
    await page.locator('#outer-camera').click(); await cameraReady(page);
    assert.equal((await live(page)).length, 1);
});

test('one camera hides switching; an unavailable other camera keeps this one usable', options, async t => {
    const single = await fixture(t, { cameras: { available: ['user'], inputs: 1 } });
    await single.page.locator('#outer-camera').click(); await cameraReady(single.page);
    assert.deepEqual(await live(single.page), ['user'], 'a laptop opens its only camera despite the rear preference');
    assert.equal(await switchButton(single.page).isVisible(), false);

    const { page } = await fixture(t, { cameras: { available: ['user'], inputs: 2 } });
    await page.locator('#outer-camera').click(); await cameraReady(page);
    await switchButton(page).click(); await cameraReady(page);
    assert.match(await page.locator('[data-photo-error]').innerText(), /other camera is unavailable.*Upload photo/i);
    assert.deepEqual(await live(page), ['user']);
    assert.equal(await page.locator('[data-photo-action="upload"]').last().isEnabled(), true);
});
