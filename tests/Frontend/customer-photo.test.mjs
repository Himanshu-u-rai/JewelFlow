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

test('camera requests no microphone and prefers the rear camera, falling back on single-camera devices', async () => {
    requireModule();
    let constraints;
    const f = fixture(async value => { constraints = value; return { getTracks: () => [] }; });
    await f.camera.start();
    assert.equal(constraints.audio, false);
    // `ideal`, not `exact`: a laptop or single-camera phone still opens its only camera.
    assert.deepEqual(constraints.video.facingMode, { ideal: 'environment' });
    f.camera.stop();
});

/** A fake device set: every request gets its own stream; `log` records the order of opens and stops. */
const cameras = ({ available = ['environment', 'user'], inputs = 2 } = {}) => {
    const log = [];
    const live = new Set();
    const pending = [];
    let hold = false;
    const open = constraints => {
        const wanted = constraints.video.facingMode;
        const facing = wanted.exact ?? (available.includes(wanted.ideal) ? wanted.ideal : available[0]);
        if (wanted.exact && !available.includes(wanted.exact)) {
            log.push('refuse ' + wanted.exact);
            return Promise.reject(Object.assign(new Error('no such camera'), { name: 'OverconstrainedError' }));
        }
        const track = { stop() { if (live.delete(stream)) log.push('stop ' + facing); }, getSettings: () => ({ facingMode: facing }) };
        const stream = { facing, getTracks: () => [track], getVideoTracks: () => [track] };
        log.push('open ' + facing);
        live.add(stream);
        return stream;
    };
    const devices = {
        getUserMedia: constraints => hold
            ? new Promise((resolve, reject) => pending.push(() => { try { resolve(open(constraints)); } catch (e) { reject(e); } }))
            : Promise.resolve().then(() => open(constraints)),
        enumerateDevices: async () => Array.from({ length: inputs }, (_, i) => ({ kind: 'videoinput', deviceId: 'cam' + i })),
    };
    const video = { srcObject: null, play: async () => {} };
    return { devices, video, log, live, holdRequests: () => { hold = true; }, release: () => pending.splice(0).forEach(run => run()) };
};

test('switching releases the current camera before opening the other one, both ways', async () => {
    requireModule();
    const c = cameras();
    const camera = new module.PhotoCamera(c.video, c.devices);
    assert.equal(await camera.start(), true);
    assert.equal(camera.facing, 'environment');
    assert.equal(await camera.switchFacing(), 'switched');
    assert.equal(camera.facing, 'user');
    assert.equal(await camera.switchFacing(), 'switched');
    assert.equal(camera.facing, 'environment');
    assert.deepEqual(c.log, ['open environment', 'stop environment', 'open user', 'stop user', 'open environment']);
    assert.equal(c.live.size, 1);
    camera.stop();
    assert.equal(c.live.size, 0);
});

test('retake keeps the camera the user selected', async () => {
    requireModule();
    const c = cameras();
    const camera = new module.PhotoCamera(c.video, c.devices);
    await camera.start();
    await camera.switchFacing();
    camera.stop(); // capture closes the live preview
    await camera.start(); // retake
    assert.equal(c.video.srcObject.facing, 'user');
    camera.stop();
});

test('a missing other camera keeps the current one running and says so', async () => {
    requireModule();
    const c = cameras({ available: ['user'], inputs: 2 });
    const camera = new module.PhotoCamera(c.video, c.devices);
    await camera.start();
    assert.equal(await camera.switchFacing(), 'unavailable');
    assert.equal(c.live.size, 1);
    assert.equal(c.video.srcObject.facing, 'user');
    assert.match(module.cameraSwitchError(), /other camera.*Upload photo/i);
    camera.stop();
});

test('only devices with more than one camera offer switching', async () => {
    requireModule();
    assert.equal(await new module.PhotoCamera({}, cameras({ inputs: 1 }).devices).canSwitch(), false);
    assert.equal(await new module.PhotoCamera({}, cameras({ inputs: 2 }).devices).canSwitch(), true);
    assert.equal(await new module.PhotoCamera({}, { getUserMedia() {} }).canSwitch(), false);
});

test('closing the dialog while a switch is pending leaves no camera running', async () => {
    requireModule();
    const c = cameras();
    const camera = new module.PhotoCamera(c.video, c.devices);
    await camera.start();
    c.holdRequests();
    const switching = camera.switchFacing();
    camera.stop();
    c.release();
    assert.equal(await switching, false);
    assert.equal(c.live.size, 0);
    assert.equal(c.video.srcObject, null);
});

test('rapid switch taps end with exactly one running camera, the last one requested', async () => {
    requireModule();
    const c = cameras();
    const camera = new module.PhotoCamera(c.video, c.devices);
    await camera.start();
    c.holdRequests();
    const first = camera.switchFacing();
    const second = camera.switchFacing();
    const third = camera.switchFacing();
    c.release();
    const results = [await first, await second, await third];
    assert.equal(results.filter(Boolean).length, 1);
    assert.equal(c.live.size, 1);
    assert.equal(c.video.srcObject, [...c.live][0]);
    assert.equal(c.video.srcObject.facing, camera.facing);
    camera.stop();
    assert.equal(c.live.size, 0);
});

test('a refused switch is reported, not retried behind the user’s back', async () => {
    requireModule();
    const c = cameras();
    c.devices.getUserMedia = async () => { throw Object.assign(new Error('denied'), { name: 'NotAllowedError' }); };
    const camera = new module.PhotoCamera(c.video, c.devices);
    await assert.rejects(camera.switchFacing(), { name: 'NotAllowedError' });
    assert.equal(c.live.size, 0);
});

test('client file feedback matches limits while server remains authoritative', () => {
    requireModule();
    assert.match(module.photoFileError({ type: 'image/heic', size: 100 }), /JPEG, PNG or WebP/);
    assert.match(module.photoFileError({ type: 'image/jpeg', size: 5 * 1024 * 1024 + 1 }), /5 MB/);
    for (const type of ['image/jpeg', 'image/png', 'image/webp']) {
        assert.equal(module.photoFileError({ type, size: 1024 }), '');
    }
});
