import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

const read = path => {
    const url = new URL(`../../${path}`, import.meta.url);
    return existsSync(url) ? readFileSync(url, 'utf8') : '';
};

test('Vendor Add/Edit links reuse native popup enhancement, preserving modified clicks and frame-specific reads', () => {
    const directory = read('resources/views/vendors/index.blade.php');
    const profile = read('resources/views/vendors/show.blade.php');
    const links = [...(directory + profile).matchAll(/<a\b(?:[^"'<>]|"[^"]*"|'[^']*')*>/g)].map(m => m[0]).filter(tag => tag.includes('data-vendor-open'));
    assert.equal(links.length, 5, 'Add, first-vendor, desktop/mobile Edit, profile Edit');
    for (const tag of links) {
        assert.match(tag, /data-turbo-prefetch="false"/);
        assert.match(tag, /aria-controls="vendor-editor"/);
        const run = new Function('$event', '$dispatch', '$el', 'HTMLDialogElement', tag.match(/@click="([^"]+)"/)[1]);
        let opened = 0, prevented = 0;
        const event = { button: 0, preventDefault() { prevented++; } };
        const element = { href: '/vendors/create', dataset: { editorTitle: 'Add Vendor' } };
        run({ ...event, ctrlKey: true }, () => opened++, element, { prototype: { showModal() {} } });
        run(event, () => opened++, element, undefined);
        assert.equal(opened, 0);
        run(event, () => opened++, element, { prototype: { showModal() {} } });
        assert.equal(opened, 1);
        assert.equal(prevented, 1);
    }
});

test('Vendor popup reuses tested frame lifecycle, close guard, focus return and Turbo cleanup', () => {
    const editor = read('resources/views/vendors/_editor.blade.php');
    assert.match(read('resources/js/app.js'), /Alpine.data\('vendorEditor', createKarigarEditor\)/);
    assert.match(editor, /aria-labelledby="vendor-editor-title"/);
    assert.match(editor, /class="vendor-editor jf-scrollbar"/);
    assert.match(editor, /@cancel="if \(submitting\) \$event.preventDefault\(\)"/);
    assert.match(editor, /@close="dismissed\(\)"/);
    for (const event of ['turbo:before-cache', 'turbo:before-render']) assert.ok(editor.includes(`@${event}.document="cleanup()"`));
    assert.match(editor, /@turbo:frame-missing="failed\(\$event\)"/);
    assert.match(editor, /id="vendor-editor-frame" x-ref="frame"/);
});

test('Popup keeps the nine vendor fields, CSRF, native PUT/POST and explicit read-only availability', () => {
    const form = read('resources/views/vendors/_editor-form.blade.php');
    assert.match(form, /data-turbo-frame="_top"/);
    assert.match(form, /@csrf/);
    assert.match(form, /name="_method" value="PUT"/);
    assert.match(form, /name="_vendor_editor" value="1"/);
    for (const name of ['name', 'contact_person', 'mobile', 'email', 'gst_number', 'address', 'notes']) {
        assert.equal([...form.matchAll(new RegExp(`name="${name}"`, 'g'))].length, 1, name);
    }
    // City and state come from the shared address component (F15), once each, with the saved values passed in.
    assert.equal([...form.matchAll(/<x-indian-address\b/g)].length, 1);
    assert.match(form, /:city="old\('city', \$vendor\?->city\)"/);
    assert.match(form, /:state="old\('state', \$vendor\?->state\)"/);
    const address = read('resources/views/components/indian-address.blade.php');
    for (const name of ['city', 'state']) {
        assert.equal([...address.matchAll(new RegExp(`name="${name}"`, 'g'))].length, 1, name);
    }
    assert.doesNotMatch(form, /name="is_active"/);
    assert.match(form, /data-editor-errors/);
    assert.match(form, /role="alert" tabindex="-1"/);
});

test('Vendor dialog scrolls within viewport, with pinned controls and phone sheet sizing', () => {
    const css = postcss.parse(read('resources/css/vendors-directory.css'));
    const values = (selector, media) => {
        const found = {};
        css.walkRules(rule => {
            if (rule.selectors.includes(selector) && (rule.parent.type === 'atrule' ? rule.parent.params : undefined) === media) {
                rule.walkDecls(decl => { found[decl.prop] = decl.value; });
            }
        });
        return found;
    };
    const desktop = values('body.app-shell .vendor-editor');
    assert.equal(desktop['overflow-y'], 'auto');
    assert.match(desktop['max-height'], /100dvh/);
    assert.equal(desktop['scroll-padding-block'], '80px');
    assert.equal(desktop.height, 'min(600px, calc(100dvh - 32px))', 'reserve the loaded form space before fetching');
    assert.match(values('body.app-shell .vendor-editor[open]').animation, /vendor-editor-enter 200ms/);
    assert.equal(values('body.app-shell .vendor-editor[open]', '(prefers-reduced-motion: reduce)').animation, 'none');
    assert.equal(values('body.app-shell .vendor-editor-head').position, 'sticky');
    assert.equal(values('body.app-shell .vendor-form-actions').position, 'sticky');
    const phone = values('body.app-shell .vendor-editor', 'screen and (max-width: 768px)');
    assert.equal(phone.width, '100%');
    assert.equal(phone.height, 'calc(100dvh - 16px)');
    assert.equal(phone['border-radius'], '16px 16px 0 0');
    assert.equal(values('body.app-shell .vendor-field-input', 'screen and (max-width: 768px)')['font-size'], '16px');
});
