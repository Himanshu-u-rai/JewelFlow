import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import postcss from 'postcss';

// Owner 2026-11-08: Dhiran gets its own dark navy + bright gold scheme, and no Dhiran colour is hard-coded: every
// colour is written once in dhiran-tokens.css and everything else refers to the tokens.
const read = f => readFileSync(new URL(`../../resources/css/${f}`, import.meta.url), 'utf8');
const literal = /#[0-9a-f]{3,8}\b|rgba?\(/i;

function decls(css, selector, media = null) {
    const out = {};
    postcss.parse(css).walkRules(rule => {
        const inMedia = rule.parent.type === 'atrule' ? rule.parent.params : null;
        if (inMedia !== media || !rule.selectors.includes(selector)) return;
        rule.walkDecls(d => { out[d.prop] = d.value; });
    });
    return out;
}

test('no hard-coded colour outside the token file', () => {
    for (const f of ['dhiran.css', 'dhiran-documents.css']) {
        postcss.parse(read(f)).walkDecls(d => {
            assert.ok(!literal.test(d.value), `${f}: ${d.parent.selector} { ${d.prop}: ${d.value} }`);
        });
    }
    assert.match(read('tables.css'), /\.dh-app \.jf-scrollbar, \.dh-app \[data-list-scroll\] \{ --sb-thumb-color: var\(--dh-scrollbar-thumb\)/);
});

test('both Dhiran stylesheets load the one token file', () => {
    for (const f of ['dhiran.css', 'dhiran-documents.css']) assert.match(read(f), /^@import "\.\/dhiran-tokens\.css";/);
    assert.match(read('dhiran-tokens.css'), /--dh-navy: #0f1b2d;[\s\S]*--dh-gold-bright: #f5b301;/);
});

test('navy sidebar, gold main action with navy text, navy other buttons, blue links', () => {
    const css = read('dhiran.css');
    assert.equal(decls(css, '.dh-sidebar').background, 'var(--dh-sidebar)');
    // (PostCSS keeps "!important" apart from the value.)
    assert.equal(decls(css, '.btn-primary').color, 'var(--dh-on-highlight)');
    assert.equal(decls(css, '.btn-primary').background, 'var(--dh-highlight)');
    assert.equal(decls(css, '.btn-dark').background, 'var(--dh-accent)');
    assert.equal(decls(css, '.dh-app table a:not(.jf-row-action):not(.btn)').color, 'var(--dh-link)');
    assert.equal(decls(css, '.dh-app .jf-row-action').color, 'var(--jf-ra-fg)', 'row action icons keep their own colour');
});

// Owner 2026-11-08: detail pages stopped at 1180px (73% of a wide screen) while lists were full width, and cards came
// in three looks (corner 12/14/16px, three border greys, two shadows). One width and one card style, from tokens.
test('one content width and one card look, both from tokens', () => {
    const css = read('dhiran.css');
    const tokens = read('dhiran-tokens.css');
    for (const t of ['--dh-content-max', '--dh-card-radius', '--dh-card-border', '--dh-card-shadow']) assert.match(tokens, new RegExp(`${t}:`));
    assert.equal(decls(css, '.dh-app .content-inner')['max-width'], 'var(--dh-content-max)');
    assert.equal(decls(css, '.dh-app .content-inner[data-list-workspace]')['max-width'], 'var(--dh-content-max)');
    const card = decls(css, '.dh-app .dd-kpi-card');
    assert.equal(card['border-radius'], 'var(--dh-card-radius)');
    assert.equal(card['box-shadow'], 'var(--dh-card-shadow)');
    assert.equal(decls(css, '.dh-app div.rounded-2xl.border')['border-radius'], 'var(--dh-card-radius)');
    // app.css forces its card look with !important through these variables; inside Dhiran they must be the Dhiran card.
    const app = decls(css, '.dh-app .content-inner');
    assert.equal(app['--app-card-shadow'], 'var(--dh-card-shadow)');
    assert.equal(app['--app-card-radius'], 'var(--dh-card-radius)');
    assert.equal(app['--app-card-border'], 'var(--dh-card-border)');
});

// Owner 2026-11-08 (Customers list "incomplete", "where is the header"): the shared list layout stretched the table
// box to the window, leaving a big empty white box under a short list, and header cells were painted near-white with
// faint text. Short lists now end at their last row; header cells get a clear band from tokens.
test('lists end at their last row and table headers stand out', () => {
    const css = read('dhiran.css');
    const media = 'screen and (min-width: 1024px) and (min-height: 800px)';
    assert.equal(decls(css, '.dh-app [data-list-workspace][data-list-fit] [data-list-frame]', media).flex, '0 1 auto');
    assert.equal(decls(css, '.dh-app [data-list-workspace][data-list-fit] [data-list-scroll]', media).flex, '0 1 auto');
    // The shared "stable" gutter kept a 14px empty strip right of the header band on lists that do not scroll.
    assert.equal(decls(css, '.dh-app [data-list-workspace][data-list-fit] [data-list-scroll]', media)['scrollbar-gutter'], 'auto');
    const th = decls(css, '.dh-app .dh-scroll table thead th'); // outranks the .dh-app .text-slate-500 class mapping
    assert.equal(th['background-color'], 'var(--dh-table-head)');
    assert.equal(th.color, 'var(--dh-ink-soft)');
    assert.equal(decls(css, '.dh-app [data-list-frame]').overflow, 'hidden');
});

// Owner 2026-11-08 ("table edges not round on left, header misaligned"): app.css un-clips every direct rounded child of
// .content-inner (0,2,1), so the grey header band's square corners stuck out past the card's round corners; and the
// shared "wrapper inherits the card radius" rule rounded the band's own corners when a title sits above the table.
test('table cards clip their header band to the round corners', () => {
    const css = read('dhiran.css');
    const clip = '.dh-app .content-inner > :is([data-list-frame], div.overflow-hidden)'; // 0,3,1 beats app.css 0,2,1
    assert.equal(decls(css, clip).overflow, 'hidden');
    const midCard = decls(css, '.dh-app .content-inner .overflow-x-auto:not(:first-child)');
    assert.equal(midCard['border-top-left-radius'], '0');
    assert.equal(midCard['border-top-right-radius'], '0');
});

// Owner 2026-11-08: the customer photo dialog opened in the top-left corner (app.css `* { margin: 0 }` removes the
// browser's centring margin; only .dh-dialog had put it back), and links underlined on hover looked untidy.
test('every Dhiran dialog is centred and links do not underline', () => {
    const css = read('dhiran.css');
    assert.equal(decls(css, '.dh-app dialog').margin, 'auto');
    assert.equal(decls(css, '.dh-app a:hover')['text-decoration-line'], 'none'); // 0,2,1 beats Tailwind hover:underline
    assert.ok(!/underline/.test(css.replace(/\/\*[\s\S]*?\*\//g, '')), 'no rule in dhiran.css adds an underline');
});

// Owner 2026-11-08: on phones, tagged tables stack into one card per row, each cell showing its column name.
test('phones: stacked tables become cards with column names', () => {
    const css = read('dhiran.css');
    const m = '(max-width: 720px)';
    assert.equal(decls(css, '.dh-app table.dh-stack thead', m).display, 'none');
    assert.equal(decls(css, '.dh-app table.dh-stack tbody tr', m).display, 'block');
    assert.equal(decls(css, '.dh-app table.dh-stack tbody td[data-label]', m).display, 'flex');
    assert.equal(decls(css, '.dh-app table.dh-stack tbody td[data-label]:not([data-label=""])::before', m).content, 'attr(data-label)');
    assert.equal(decls(css, '.dh-app table.dh-stack', m)['min-width'], '0');
});

test('phones: closed menu is hidden from keyboard, links are tappable, the New Loan bar stays on the page', () => {
    const css = read('dhiran.css');
    assert.equal(decls(css, '.dh-shell:not(.is-open) .dh-sidebar', '(max-width: 900px)').visibility, 'hidden');
    assert.equal(decls(css, '.dh-app .dh-scroll a:not(.btn):not(.jf-row-action)', '(max-width: 720px)')['padding-block'], '4px');
    assert.equal(decls(css, '.dh-app .dhiran-create-form > .flex.justify-end', '(max-width: 720px)')['margin-inline'], '0');
});
