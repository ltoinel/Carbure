// Display theme of the portal (portal/theme.js, a plain script of the <head>)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../portal/theme.js', import.meta.url), 'utf8');

/**
 * Runs theme.js in a fake page
 * @param {{stored?: string|null, systemDark?: boolean, metas?: Object[]}} options
 * @returns {{window: Object, html: Object, storage: Map, setSystemDark: Function}}
 */
function load({ stored = null, systemDark = false, metas } = {}) {
    const storage = new Map(stored === null ? [] : [['theme', stored]]);
    const listeners = [];
    const media = { matches: systemDark, addEventListener: (type, listener) => listeners.push(listener) };
    const html = { dataset: {} };
    const window = {
        document: { documentElement: html, ...(metas ? { querySelectorAll: () => metas } : {}) },
        localStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
        matchMedia: () => media,
    };
    vm.runInNewContext(source, { window });
    const setSystemDark = dark => {
        media.matches = dark;
        listeners.forEach(listener => listener());
    };
    return { window, html, storage, setSystemDark };
}

test('theme.js: follows the system preference without a choice', () => {
    const { html, setSystemDark } = load({ systemDark: true });
    assert.equal(html.dataset.theme, 'dark');
    assert.equal(html.dataset.themeChoice, 'system');
    // A change of the system preference applies at once
    setSystemDark(false);
    assert.equal(html.dataset.theme, 'light');
});

test('theme.js: the choice cached in the browser is applied before the first paint', () => {
    assert.equal(load({ stored: 'dark', systemDark: false }).html.dataset.theme, 'dark');
    assert.equal(load({ stored: 'light', systemDark: true }).html.dataset.theme, 'light');
    // An unknown value is the system preference
    assert.equal(load({ stored: 'pink', systemDark: true }).html.dataset.theme, 'dark');
});

test('theme.js: the choice of the profile is applied, kept, and no longer follows the system', () => {
    const { window, html, storage, setSystemDark } = load({ systemDark: false });
    assert.equal(window.carbureTheme.apply('dark'), 'dark');
    assert.equal(html.dataset.theme, 'dark');
    assert.equal(storage.get('theme'), 'dark');
    setSystemDark(false);
    assert.equal(html.dataset.theme, 'dark');

    assert.equal(window.carbureTheme.apply(undefined), 'system');
    assert.equal(html.dataset.theme, 'light');
});

test('theme.js: resolve gives the theme of a choice', () => {
    const { window } = load();
    assert.equal(window.carbureTheme.resolve('system', true), 'dark');
    assert.equal(window.carbureTheme.resolve('system', false), 'light');
    assert.equal(window.carbureTheme.resolve('dark', false), 'dark');
    assert.equal(window.carbureTheme.resolve('light', true), 'light');
});

test('theme.js: the color of the app window follows the theme displayed', () => {
    const meta = () => ({ content: '', setAttribute(name, value) { this[name] = value; } });
    const metas = [meta(), meta()];
    const { window, setSystemDark } = load({ systemDark: true, metas });
    const { light, dark } = window.carbureTheme.THEME_COLORS;
    assert.deepEqual(metas.map(m => m.content), [dark, dark]);
    setSystemDark(false);
    assert.deepEqual(metas.map(m => m.content), [light, light]);
    // The theme of the profile wins over the system
    window.carbureTheme.apply('dark');
    assert.deepEqual(metas.map(m => m.content), [dark, dark]);
});

test('theme.js: the colors of the app window are the ones of index.html and the manifest', () => {
    const { window } = load();
    const html = readFileSync(new URL('../../portal/index.html', import.meta.url), 'utf8');
    const manifest = JSON.parse(readFileSync(new URL('../../portal/manifest.webmanifest', import.meta.url), 'utf8'));
    const { light, dark } = window.carbureTheme.THEME_COLORS;
    assert.ok(html.includes(`<meta name="theme-color" content="${light}" media="(prefers-color-scheme: light)">`));
    assert.ok(html.includes(`<meta name="theme-color" content="${dark}" media="(prefers-color-scheme: dark)">`));
    assert.equal(manifest.theme_color, light);
});
