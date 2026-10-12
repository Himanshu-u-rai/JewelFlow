import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import test from 'node:test';

const source = new URL('../../resources/js/customer-photo.js', import.meta.url);
const available = existsSync(source);
const module = available ? await import(source) : {};
const requireModule = () => assert.ok(available, 'Customer photo camera lifecycle is not implemented yet.');

const fixture = (getUserMedia) => {
    const video = { srcObject: null, play: async () => {}, videoWidth: 1200, videoHeight: 800 };
    let stopped = 0;
    const stream = { getTracks: () => [{ stop() { stopped++; } }] };
    const camera = available ? new module.PhotoCamera(video, { getUserMedia: getUserMedia ?? (async () => stream) }) : null;
    return { camera, video, stream, stopped: () => stopped };
};

test('camera stops every track and clears the video on close', async () => {
    requireModule();
    const f = fixture();
    assert.equal(await f.camera.start(), true);
    assert.equal(f.video.srcObject, f.stream);
    f.camera.stop();
    f.camera.stop();
    assert.equal(f.stopped(), 1);
    assert.equal(f.video.srcObject, null);
});

test('a permission request resolving after cancellation cannot restart the camera', async () => {
    requireModule();
    let accept;
    const f = fixture(() => new Promise(resolve => { accept = resolve; }));
    const pending = f.camera.start();
    f.camera.stop();
    accept(f.stream);
    assert.equal(await pending, false);
    assert.equal(f.stopped(), 1);
    assert.equal(f.video.srcObject, null);
});

test('a late permission refusal cannot overwrite a later upload preview', async () => {
    requireModule();
    let refuse;
    const f = fixture(() => new Promise((resolve, reject) => { refuse = reject; }));
    const pending = f.camera.start();
    f.camera.stop();
    refuse({ name: 'NotAllowedError' });
    assert.equal(await pending, false);
    assert.equal(f.video.srcObject, null);
});

test('out-of-order camera requests cannot replace the latest preview', async () => {
    requireModule();
    const accept = [];
    const f = fixture(() => new Promise(resolve => accept.push(resolve)));
    const first = f.camera.start();
    f.camera.stop();
    const second = f.camera.start();
    const newest = { getTracks: () => [{ stop() {} }] };
    accept[1](newest);
    await second;
    accept[0](f.stream);
    assert.equal(await first, false);
    assert.equal(f.stopped(), 1);
    assert.equal(f.video.srcObject, newest);
    f.camera.stop();
});

test('unavailable cameras offer upload instead of throwing an opaque failure', async () => {
    requireModule();
    const f = fixture();
    f.camera = new module.PhotoCamera(f.video, null);
    await assert.rejects(f.camera.start(), /Upload photo/);
});

test('denied camera permission has a clear upload fallback', () => {
    requireModule();
    assert.match(module.cameraError({ name: 'NotAllowedError' }), /permission.*Upload photo/i);
    assert.match(module.cameraError({ name: 'NotFoundError' }), /camera.*Upload photo/i);
});

test('a failed video preview releases the acquired device', async () => {
    requireModule();
    const f = fixture();
    f.video.play = async () => { throw new Error('video unavailable'); };
    await assert.rejects(f.camera.start());
    assert.equal(f.stopped(), 1);
    assert.equal(f.video.srcObject, null);
});

test('an old preview failure cannot stop a newly acquired camera', async () => {
    requireModule();
    let rejectOldPreview;
    const f = fixture();
    f.video.play = () => new Promise((resolve, reject) => { rejectOldPreview = reject; });
    const old = f.camera.start();
    await Promise.resolve();
    f.video.play = async () => {};
    const current = f.camera.start();
    assert.equal(await current, true);
    rejectOldPreview(new Error('old preview interrupted'));
    assert.equal(await old, false);
    assert.equal(f.video.srcObject, f.stream);
    assert.equal(f.stopped(), 1);
    f.camera.stop();
});

test('camera requests no microphone and a phone-facing camera when available', async () => {
    requireModule();
    let constraints;
    const f = fixture(async value => { constraints = value; return { getTracks: () => [] }; });
    await f.camera.start();
    assert.equal(constraints.audio, false);
    assert.equal(constraints.video.facingMode.ideal, 'user');
    f.camera.stop();
});

test('client file feedback matches limits while server remains authoritative', () => {
    requireModule();
    assert.match(module.photoFileError({ type: 'image/heic', size: 100 }), /JPEG, PNG or WebP/);
    assert.match(module.photoFileError({ type: 'image/jpeg', size: 5 * 1024 * 1024 + 1 }), /5 MB/);
    for (const type of ['image/jpeg', 'image/png', 'image/webp']) {
        assert.equal(module.photoFileError({ type, size: 1024 }), '');
    }
});
